import {LifecycleActions} from './lifecycle-actions';
import {useEffect,useRef,useState} from 'react';
import {Link} from 'react-router-dom';
import {FileText,Image as ImageIcon,Sheet,Upload} from 'lucide-react';
import {api,errorMessage,notify} from '../lib/api';
import {useApi} from '../lib/use-api';
import {documentPath,size,type ReportDocument,type ProcessorHealth} from '../lib/documents';
import type {Page} from '../types';
import {Button} from './ui/button';
import {Alert,Empty,ErrorState,Loading} from './common';
import {Pager,Status} from './workspace';
import {label} from '../lib/workspace';

type Selected={id:string;file:File;status:'pending'|'uploading'|'uploaded'|'upload_failed';progress:number|null;message?:string};
export function DocumentUpload({path,done,afterUpload,disabled=false,uploadLabel='Upload documents',inputLabel='Select report documents',onSelectionChange}:{path:string;done:()=>void;afterUpload?:()=>Promise<void>;disabled?:boolean;uploadLabel?:string;inputLabel?:string;onSelectionChange?:(pending:boolean)=>void}){
 const health=useApi<ProcessorHealth>('/api/document-processor/health');
 const [files,setFiles]=useState<Selected[]>([]),[error,setError]=useState(''),[busy,setBusy]=useState(false);
 const input=useRef<HTMLInputElement>(null);
 useEffect(()=>onSelectionChange?.(files.some(f=>f.status!=='uploaded')),[files,onSelectionChange]);
 function select(incoming:FileList|null){
  if(!incoming||busy||disabled)return;
  const values=Array.from(incoming),h=health.data;const max=h?.max_batch??10;
  if(files.length+values.length>max){setError('Select at most '+max+' files per batch.');return;}
  const allowed=h?.accepted_extensions??['pdf','docx','xlsx','xls','csv','png','jpg','jpeg','webp'];
  if(values.some(f=>!allowed.includes(f.name.split('.').pop()!.toLowerCase())||f.size>(h?.max_file_mb??25)*1024*1024)){setError('Choose supported documents within the '+(h?.max_file_mb??25)+' MB per-file limit.');return;}
  if([...files.map(f=>f.file),...values].reduce((n,f)=>n+f.size,0)>(h?.max_total_mb??100)*1024*1024){setError('This batch exceeds the total upload limit.');return;}
  setError('');setFiles(old=>[...old,...values.map(file=>({id:crypto.randomUUID(),file,status:'pending' as const,progress:null}))]);
 }
 function update(id:string,changes:Partial<Selected>){setFiles(old=>old.map(f=>f.id===id?{...f,...changes}:f));}
 async function upload(onlyId?:string){
  setBusy(true);let failed=false;const batch=crypto.randomUUID();
  for(const item of files.filter(f=>(!onlyId||f.id===onlyId)&&(f.status==='pending'||f.status==='upload_failed'))){
   update(item.id,{status:'uploading',progress:null,message:undefined});
   const body=new FormData();body.append('files[]',item.file);body.append('batch_id',batch);
   try{
    const response = await api.post(path,body,{onUploadProgress:e=>update(item.id,{progress:e.total?Math.round(e.loaded/e.total*100):null})});
    update(item.id,{status:'uploaded',progress:100,message:response.data.data?.[0]?.extraction_status==='failed'?'File uploaded, but queue dispatch failed. Open the document and retry after checking the worker.':'File uploaded. Waiting for extraction.'});done();
   }catch(e){failed=true;update(item.id,{status:'upload_failed',message:errorMessage(e)});}
  }
  if(!failed&&afterUpload&&(!onlyId||!files.some(f=>f.id!==onlyId&&f.status!=='uploaded'))){try{await afterUpload();}catch(e){setError(errorMessage(e));}}
  setBusy(false);
 }
 return <div className="form-panel document-upload">
  <div className="drop-zone" onDragOver={e=>e.preventDefault()} onDrop={e=>{e.preventDefault();select(e.dataTransfer.files);}}>
   <Upload size={26}/><h3>Upload documents</h3><p>Drop PDF, Word, Excel, CSV or images here. Select up to {health.data?.max_batch??10} files in one batch.</p>
   <p className="muted">Private originals · {health.data?.max_file_mb??25} MB per file · {health.data?.max_total_mb??100} MB per batch</p>
   <input ref={input} aria-label={inputLabel} type="file" multiple accept=".pdf,.docx,.xlsx,.xls,.csv,.png,.jpg,.jpeg,.webp" hidden disabled={busy||disabled} onChange={e=>{select(e.target.files);e.target.value='';}}/>
   <Button variant="outline" disabled={busy||disabled} onClick={()=>input.current?.click()}>Browse files</Button>
  </div>
  {error&&<Alert>{error}</Alert>}
  {health.data&&!health.data.available&&<Alert>{health.data.message} Files can be stored, but extraction will fail until the processor is configured.</Alert>}
  {health.data?.available&&!health.data.ocr.available&&<Alert>OCR is unavailable. Image text and scanned pages will be flagged as partially extracted.</Alert>}
  {!!files.length&&<><ul className="record-list">{files.map(f=><li key={f.id}><strong>{f.file.name}</strong><small>{size(f.file.size)} · {label(f.status)}</small>{f.status==='uploading'&&<progress aria-label={'Uploading '+f.file.name} value={f.progress??undefined} max={100}/>}<p role={f.status==='upload_failed'?'alert':undefined}>{f.message}</p>{f.status==='upload_failed'&&!busy&&<Button variant="outline" disabled={disabled} onClick={()=>void upload(f.id)}>Retry upload: {f.file.name}</Button>}{!busy&&<Button variant="ghost" onClick={()=>setFiles(old=>old.filter(x=>x.id!==f.id))}>Remove selection</Button>}</li>)}</ul>
  <Button disabled={busy||disabled||files.every(f=>f.status==='uploaded')} onClick={()=>void upload()}>{busy?'Uploading…':uploadLabel}</Button></>}
 </div>;
}
export function DocumentList({path}:{path:string}){
 const [page,setPage]=useState(1);const q=useApi<Page<ReportDocument>>(path+'?page='+page, true);
 const active=q.data?.data.some(d=>['queued','processing'].includes(d.extraction_status));
 useEffect(()=>{if(!active)return;const t=setInterval(q.reload,4000);return()=>clearInterval(t);},[active,q.reload]);
 if(q.loading&&!q.data)return <Loading/>;
 if(q.error||!q.data)return <ErrorState message={q.error} retry={q.reload}/>;
 return <>{q.data.data.length?<div className="table-scroll"><table><thead><tr><th>Document</th><th>Upload</th><th>Extraction</th><th>Activity</th></tr></thead><tbody>{q.data.data.map(d=><tr key={d.id}><td><div className="action-row">{d.mime_type.startsWith('image/')?<ImageIcon size={18}/>:['xlsx','xls','csv'].includes(d.extension)?<Sheet size={18}/>:<FileText size={18}/>}<Link className="text-link" to={documentPath(d)}>{d.original_filename}</Link></div><small>{size(d.file_size)} · {d.extension.toUpperCase()}</small></td><td><Status value={d.upload_status}/><LifecycleActions type="document" id={d.id} manage={d.can_remove} view={documentPath(d)} done={q.reload}/></td><td><Status value={d.extraction_status}/></td><td><p>{d.current_run?.stage}</p>{d.current_run?.total_units!=null&&<small>{d.current_run.processed_units} / {d.current_run.total_units} source units</small>}{d.stale&&<p className="muted">Worker may be stopped. Open the document to retry this stale attempt.</p>}{d.current_run?.error_message&&<p role="alert">{d.current_run.error_message}</p>}</td></tr>)}</tbody></table></div>:<Empty title="No documents uploaded">Upload monthly sources, then generate the consolidated SEO report.</Empty>}<Pager page={q.data} onPage={setPage}/></>;
}
export function ReportDocuments({projectId,reportId,canUpload}:{projectId:string;reportId:string;canUpload:boolean}){
 const [revision,setRevision]=useState(0);const path='/api/projects/'+projectId+'/reports/'+reportId+'/documents';
 return <section className="panel section-gap"><div className="panel-heading"><div><h2>Report documents</h2><p>Upload, extract and inspect the original reporting evidence.</p></div></div>{canUpload?<DocumentUpload path={path} done={()=>{setRevision(n=>n+1);notify('File uploaded. Extraction is queued separately.');}}/>:<p className="form-panel muted">Uploads and document changes require an active project and Draft report.</p>}<DocumentList key={revision} path={path}/></section>;
}
export async function downloadDocument(path:string,name:string){
 const response=await api.get(path,{responseType:'blob'});const url=URL.createObjectURL(response.data);const a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),30000);
}
