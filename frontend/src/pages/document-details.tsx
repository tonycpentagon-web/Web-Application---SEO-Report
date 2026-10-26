import {LifecycleActions} from '../components/lifecycle-actions';
import {DocumentUpload} from '../components/documents';
import {useEffect,useState} from 'react';
import {Link,useNavigate,useParams,useSearchParams} from 'react-router-dom';
import {api,errorMessage} from '../lib/api';
import {useApi} from '../lib/use-api';
import {type ReportDocument,type ExtractedContent,type DocumentAsset,type ExtractionRun,size} from '../lib/documents';
import type {Page} from '../types';
import {label} from '../lib/workspace';
import {downloadDocument} from '../components/documents';
import {Button} from '../components/ui/button';
import {Action,Pager,Status} from '../components/workspace';
import {Alert,Empty,ErrorState,Loading,PageHeading} from '../components/common';

function PrivatePreview({path,name,pdf=false}:{path:string;name:string;pdf?:boolean}){
 const [url,setUrl]=useState(''),[error,setError]=useState('');
 useEffect(()=>{let active=true;let object='';const controller=new AbortController();
  api.get(path,{responseType:'blob',signal:controller.signal}).then(r=>{object=URL.createObjectURL(r.data);if(active)setUrl(object);else URL.revokeObjectURL(object);}).catch(e=>{if(active)setError(errorMessage(e));});
  return()=>{active=false;controller.abort();if(object)URL.revokeObjectURL(object);};
 },[path]);
 if(error)return <Alert>{error}</Alert>;if(!url)return <Loading/>;
 return pdf?<object className="document-pdf" data={url} type="application/pdf" aria-label={name}><p>Use Download original if your browser cannot preview PDFs.</p></object>:<img className="document-image" src={url} alt={name} loading="lazy"/>;
}
function Source({item}:{item:{page_number?:number|null;source_page?:number|null;worksheet_name?:string|null;section_name?:string|null}}){
 return <small className="muted">{item.page_number||item.source_page?'Page '+(item.page_number||item.source_page):''}{item.worksheet_name?'Worksheet '+item.worksheet_name:''}{item.section_name?' · '+item.section_name:''}</small>;
}
function TableContent({item}:{item:ExtractedContent}){
 const [page,setPage]=useState(0);const rows=(item.structured_json.rows??[]) as unknown[][];
 return <article className="extraction-block"><Source item={item}/><p>{item.confidence_status==='ambiguous'?'Column boundaries require review.':'Original row order retained.'}</p>{rows.length?<><div className="table-scroll"><table><tbody>{rows.slice(page*100,(page+1)*100).map((row,i)=><tr key={i}>{row.map((cell,j)=><td key={j}>{cell==null?'':typeof cell==='object'?JSON.stringify(cell):String(cell)}</td>)}</tr>)}</tbody></table></div><div className="pagination"><span>Rows {page*100+1}–{Math.min((page+1)*100,rows.length)} of {rows.length}</span><Button variant="outline" disabled={!page} onClick={()=>setPage(p=>p-1)}>Previous rows</Button><Button variant="outline" disabled={(page+1)*100>=rows.length} onClick={()=>setPage(p=>p+1)}>Next rows</Button></div></>:<pre>{JSON.stringify(item.structured_json,null,2)}</pre>}</article>;
}
function ContentTab({base,run,tables}:{base:string;run:string;tables:boolean}){
 const [page,setPage]=useState(1),[search,setSearch]=useState(''),[query,setQuery]=useState(''),[type,setType]=useState(tables?'table':'');
 const q=useApi<{run:ExtractionRun;content:Page<ExtractedContent>;types:string[]}>(base+'/extraction?run_id='+run+'&page='+page+'&type='+encodeURIComponent(type)+'&search='+encodeURIComponent(query));
 return <><form className="filter-bar" onSubmit={e=>{e.preventDefault();setQuery(search);setPage(1);}}><label>Search extracted text<input value={search} maxLength={200} onChange={e=>setSearch(e.target.value)}/></label><Button variant="outline">Search</Button>{!tables&&<label>Content type<select value={type} onChange={e=>{setType(e.target.value);setPage(1);}}><option value="">All content</option>{q.data?.types.map(t=><option key={t} value={t}>{label(t)}</option>)}</select></label>}</form>
 {q.loading?<Loading/>:q.error||!q.data?<ErrorState message={q.error} retry={q.reload}/>:<>{q.data.content.data.length?q.data.content.data.map(i=>tables?<TableContent key={i.id} item={i}/>:<article className="extraction-block" key={i.id}><div className="action-row"><strong>{label(i.content_type)}</strong><Status value={i.confidence_status}/></div><Source item={i}/><pre>{i.original_text||JSON.stringify(i.structured_json,null,2)}</pre><details><summary>Source details</summary><pre>{JSON.stringify(i.source_location,null,2)}</pre></details></article>):<Empty title={tables?'No tables found':'No matching text'}>Original files and available image evidence remain accessible. OCR and detected tables require human review.</Empty>}<Pager page={q.data.content} onPage={setPage}/></>}
 </>;
}
function ImagesTab({base,run,previews=false}:{base:string;run:string;previews?:boolean}){
 const [page,setPage]=useState(1);const q=useApi<Page<DocumentAsset>>(base+'/assets?run_id='+run+'&page='+page+(previews?'&type=page_preview':''));
 const [error,setError]=useState('');
 return q.loading?<Loading/>:q.error||!q.data?<ErrorState message={q.error} retry={q.reload}/>:<>{error&&<Alert>{error}</Alert>}<div className="evidence-grid">{q.data.data.map(a=><article className="extraction-block" key={a.id}><h3>{label(a.asset_type)}</h3><Source item={a}/>{typeof (a.metadata.details as Record<string,unknown>|undefined)?.associated_heading === 'string' && <p className="muted">Source heading: {String((a.metadata.details as Record<string,unknown>).associated_heading)}</p>}{['image/png','image/jpeg','image/webp'].includes(a.mime_type)?<PrivatePreview path={base+'/assets/'+a.id} name={label(a.asset_type)+' '+(a.source_page??a.worksheet_name??'')}/>:<p>Original evidence retained as an attachment.</p>}<Button variant="outline" onClick={()=>void downloadDocument(base+'/assets/'+a.id,'evidence-'+a.id).catch(e=>setError(errorMessage(e)))}>Download evidence</Button><details><summary>Context and source</summary><pre>{JSON.stringify(a.metadata,null,2)}</pre></details></article>)}</div>{!q.data.total&&<Empty title="No image evidence found">Inspect extracted text and tables, or download the original document.</Empty>}<Pager page={q.data} onPage={setPage}/></>;
}
export function DocumentDetails(){
 const {id,reportId,documentId}=useParams();const base='/api/documents/'+documentId;
 const q=useApi<{data:ReportDocument}>(base, true),navigate=useNavigate();
 const [sourceParams]=useSearchParams(); const [tab,setTab]=useState('preview'),[runId,setRunId]=useState(sourceParams.get('run_id')??''),[error,setError]=useState('');
 const d=q.data?.data;const active=d&&['queued','processing'].includes(d.extraction_status);
 useEffect(()=>{if(!active)return;const timer=setInterval(q.reload,4000);return()=>clearInterval(timer);},[active,q.reload]);
 if(q.loading&&!d)return <Loading/>;if(q.error||!d)return <ErrorState message={q.error} retry={q.reload}/>;
 if(d.project_id!==id||d.reporting_period_id!==reportId)return <Alert>This document does not belong to this reporting period.</Alert>;
 const run=d.runs?.find(r=>r.id===runId)??d.current_run;
 return <><Link className="back-link" to={'/projects/'+id+'/reports/'+reportId}>← Back to report</Link><PageHeading eyebrow="SOURCE DOCUMENT" title={d.original_filename} description={size(d.file_size)+' · '+d.extension.toUpperCase()}/>
 <section className="panel form-panel"><div className="action-row"><span>Upload:</span><Status value={d.upload_status}/><span>Extraction:</span><Status value={d.extraction_status}/></div><p>{d.current_run?.stage}</p>{active&&<><progress aria-label="Extraction activity" value={d.current_run?.total_units?d.current_run.processed_units:undefined} max={d.current_run?.total_units??undefined}/><p className="muted">{d.current_run?.total_units?d.current_run.processed_units+' / '+d.current_run.total_units+' source units':'Waiting for actual processor activity. Start the document queue worker if no activity appears.'}</p></>}{d.stale&&<Alert>This attempt is stale. Check the queue worker and retry if needed.</Alert>}{d.current_run?.error_message&&<Alert>{d.current_run.error_message}</Alert>}{error&&<Alert>{error}</Alert>}
 {run?.warnings?.length ? <details className="extraction-block"><summary>Extraction warnings ({run.warnings.length}) — review source evidence</summary><ul className="record-list">{run.warnings.map((w,i)=><li key={i}><strong>{w.code}</strong><p>{w.message}</p><small>{JSON.stringify(w.source)}</small></li>)}</ul></details> : null}
 <div className="action-row"><Button variant="outline" onClick={()=>void downloadDocument(base+'/download',d.original_filename).catch(e=>setError(errorMessage(e)))}>Download original</Button>{run?.completed_at&&run.status!=='failed'&&<Button variant="outline" onClick={()=>void downloadDocument(base+'/raw?run_id='+run.id,'extraction-'+run.id+'.json').catch(e=>setError(errorMessage(e)))}>Download raw JSON</Button>}{d.can_retry&&<Action title="Retry extraction" method="post" path={base+'/retry'} body={{}} done={()=>{setRunId('');q.reload();}}>Create another attempt. Previous results and evidence are retained.</Action>}{d.can_remove&&<LifecycleActions type="document" id={d.id} done={()=>navigate('/projects/'+id+'/reports/'+reportId+'/generate')}/>}</div><details><summary>File details</summary><p>SHA-256: <code>{d.checksum}</code></p><p>Uploaded {new Date(d.created_at).toLocaleString()} · {d.mime_type}</p></details>
 {d.can_remove&&<details className="advanced-review"><summary>Replace source file</summary><p>The old source will move to Trash only if the new file is stored successfully. Choose one file. This Draft must be regenerated and verified.</p><DocumentUpload path={base+'/replace?confirmed=1'} done={()=>navigate('/projects/'+id+'/reports/'+reportId+'/generate')} uploadLabel="Confirm and replace source" inputLabel="Replacement source file"/></details>}</section><nav className="report-tabs" aria-label="Document tabs">{['preview','text','tables','images','processing log'].map(t=><Button key={t} variant="ghost" aria-current={tab===t?'page':undefined} className={tab===t?'selected':''} onClick={()=>setTab(t)}>{label(t)}</Button>)}</nav>
 <section className="panel form-panel"><label>Extraction attempt<select aria-label="Extraction attempt" value={run?.id??''} onChange={e=>setRunId(e.target.value)}>{d.runs?.map((r,i)=><option key={r.id} value={r.id}>{new Date(r.created_at).toLocaleString()} · {label(r.status)} · {d.runs!.length-i}</option>)}</select></label>
 {tab==='preview'&&(['pdf','png','jpg','jpeg','webp'].includes(d.extension)?<PrivatePreview path={base+'/preview'} name={d.original_filename} pdf={d.extension==='pdf'}/>:<Empty title="Review the extracted document">Office and CSV files are available through Text, Tables and Images. Download the original to open it in its native application.</Empty>)}
 {run&&tab==='text'&&<ContentTab key={run.id+'text'} base={base} run={run.id} tables={false}/>}
 {run&&tab==='tables'&&<ContentTab key={run.id+'tables'} base={base} run={run.id} tables/>}
 {run&&tab==='images'&&<ImagesTab key={run.id} base={base} run={run.id}/>}
 {run&&tab==='processing log'&&<><h2>Processing log</h2><p>{run.processor_version||'Processor has not started'} · {label(run.status)}</p><p>Started: {run.started_at?new Date(run.started_at).toLocaleString():'Not started'} · Completed: {run.completed_at?new Date(run.completed_at).toLocaleString():'Not completed'}</p>{run.error_message&&<Alert>{run.error_message}</Alert>}<h3>Warnings and unreadable content</h3>{run.warnings?.length?<ul className="record-list">{run.warnings.map((w,i)=><li key={i}><strong>{w.code}</strong><p>{w.message}</p><small>{JSON.stringify(w.source)}</small></li>)}</ul>:<p>No warnings recorded for this attempt.</p>}<h3>Actual activity</h3><ul className="record-list">{run.events?.map((e,i)=><li key={i}><strong>{e.message}</strong><small>{new Date(e.at).toLocaleString()} · {e.completed}{e.total!=null?' / '+e.total:''}</small></li>)}</ul><details><summary>Extraction counts and metadata</summary><pre>{JSON.stringify(run.statistics,null,2)}</pre></details></>}
 </section></>;
}
