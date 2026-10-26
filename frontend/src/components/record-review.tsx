import {useEffect,useRef,useState} from 'react';
import {api,errorMessage} from '../lib/api';
import {label} from '../lib/workspace';
import {recordName,type DataRecord,type BulkResult} from '../lib/report-data';
import {Button} from './ui/button';
import {Alert} from './common';
import {Field,FormPanel} from './workspace';

export function SourceVerification({row,base,done}:{row:DataRecord;base:string;done:()=>void}){
 const e=row.eligibility;
 return <section className="section-gap"><h3>Human source verification</h3>
 <p className="muted">Inspect the original evidence and values before attesting. Source confidence and opening this panel do not establish verification. Save any corrections as Pending first, then verify the saved values.</p>
 {e?.verified&&<Alert success>Source inspection recorded by reviewer #{e.verified_by}. Approval is a separate decision.</Alert>}
 {e?.reasons.length? <ul>{e.reasons.map(r=><li key={r}>{r}</li>)}</ul>:null}
 <FormPanel key={row.revision} button="Record source verification" done={done} save={async form=>{
  const resolutions=(e?.warnings??[]).filter(w=>w.blocking).map(w=>({warning_id:w.id,reason:String(form['resolution_'+w.id]??'')}));
  return api.post(base+'/records/'+row.id+'/verification',{revision:row.revision,confirmed:form.confirmed==='on',reason:form.verification_reason,resolutions});
 }}>
  {(e?.warnings??[]).map(w=><div key={w.id} className="section-gap"><strong>{label(w.code)} · {w.severity}{w.resolved?' · resolved for these values':''}</strong><p>{w.message}</p>{w.blocking&&<Field name={'resolution_'+w.id} title={'Resolution: '+label(w.code)} required type="textarea" maxLength={2000}/>}</div>)}
  <Field name="verification_reason" title="Source inspection notes" required type="textarea" maxLength={2000}/>
  <label className="review-check"><input type="checkbox" name="confirmed" required/> I inspected the original source, checked these saved values and documented each blocking issue.</label>
 </FormPanel></section>;
}

export function BulkSelection({base,rows,selected,setSelected,reload}:{base:string;rows:DataRecord[];selected:Map<string,DataRecord>;setSelected:(v:Map<string,DataRecord>)=>void;reload:()=>void}){
 const [error,setError]=useState(''),[message,setMessage]=useState(''),[busy,setBusy]=useState(false),[modal,setModal]=useState<{decision:string;preview:BulkResult;ids:string[];revisions:Record<string,number>;key:string}|null>(null),[result,setResult]=useState<BulkResult|null>(null);
 const dialog=useRef<HTMLDialogElement>(null);
 useEffect(()=>{if(modal&&!dialog.current?.open)dialog.current?.showModal();if(!modal&&dialog.current?.open)dialog.current?.close();},[modal]);
 const eligible=[...selected.values()].filter(r=>r.eligibility?.eligible).length;
 async function prepare(decision:string){
  setError('');setBusy(true);const ids=[...selected.keys()];const revisions=Object.fromEntries([...selected.values()].map(r=>[r.id,r.revision]));
  try{const response=await api.post(base+'/records/bulk-preview',{record_ids:ids,revisions,decision});setModal({decision,preview:response.data.data,ids,revisions,key:crypto.randomUUID()});}catch(e){setError(errorMessage(e));}finally{setBusy(false);}
 }
 function selectPage(){const next=new Map(selected);for(const row of rows)next.set(row.id,row);if(next.size>250){setError('Select at most 250 exact records per review.');return;}setSelected(next);}
 return <>
 <div className="bulk-review-toolbar" aria-label="Bulk review selection">
  <Button variant="outline" disabled={busy} onClick={selectPage}>Select All on Current Page</Button>
  <Button variant="outline" disabled={!selected.size||busy} onClick={()=>setSelected(new Map())}>Clear Selection</Button>
  <span role="status">{selected.size} selected · {eligible} eligible · {selected.size-eligible} excluded from approval</span>
  <Button disabled={!selected.size||busy} onClick={()=>void prepare('approved')}>Approve Selected</Button>
  <Button variant="outline" disabled={!selected.size||busy} onClick={()=>void prepare('rejected')}>Reject Selected</Button>
 </div><p className="muted">Select All Verified applies only to the current page and current filters. Exact selections may span pages, up to 250 records. Eligibility is rechecked on the server before saving.</p>
 {selected.size>0&&selected.size!==eligible&&<details><summary>Why selected records are excluded from approval</summary><ul>{[...selected.values()].filter(r=>!r.eligibility?.eligible).map(r=><li key={r.id}><strong>{recordName(r)}</strong>: {r.eligibility?.reasons.join(' ')||'Verification is required.'}</li>)}</ul></details>}
 {busy&&<p role="status">Checking review eligibility…</p>}{error&&<Alert>{error}</Alert>}{message&&<Alert success>{message}</Alert>}
 {result&&result.excluded_count>0&&<details open><summary>Excluded records from the last review</summary><ul>{result.results.filter(r=>r.status==='excluded').map(r=><li key={r.id}>{r.id}: {r.reasons.join(' ')}</li>)}</ul></details>}
 <dialog ref={dialog} className="confirm-dialog bulk-review-dialog" aria-label="Bulk Review confirmation" onCancel={e=>{e.preventDefault();if(!busy)setModal(null);}}>
 {modal&&<form key={modal.key} onSubmit={async e=>{
  e.preventDefault();const form=new FormData(e.currentTarget);setBusy(true);setError('');
  try{const response=await api.post(base+'/records/bulk-review',{record_ids:modal.ids,revisions:modal.revisions,decision:modal.decision,reason:String(form.get('reason')),confirmed:form.get('confirmed')==='on',request_key:modal.key});setResult(response.data.data);setMessage(response.data.message);setModal(null);setSelected(new Map());reload();}catch(err){setError(errorMessage(err));}finally{setBusy(false);}
 }}><h2>{modal.decision==='approved'?'Approve selected records':'Reject selected records'}</h2><p>{modal.preview.report_title} · {modal.preview.period}</p>
 <p>{modal.preview.selected_count} selected · {modal.preview.eligible_count} eligible · {modal.preview.excluded_count} excluded</p>
 <p>Eligible categories: {Object.entries(modal.preview.categories).map(([k,v])=>label(k)+': '+v).join(' · ')||'None'}</p>
 {modal.preview.excluded_count>0&&<details><summary>Exclusion reasons</summary><ul>{modal.preview.results.filter(r=>r.status==='excluded').map(r=><li key={r.id}>{r.id}: {r.reasons.join(' ')}</li>)}</ul></details>}
 {error&&<Alert>{error}</Alert>}<fieldset disabled={busy}><label>Bulk review reason<textarea name="reason" required minLength={10} maxLength={2000}/></label><label className="review-check"><input type="checkbox" name="confirmed" required/> I confirm this {modal.decision==='approved'?'approval':'rejection'} of eligible selected records.</label></fieldset>
 <div className="form-actions"><Button type="button" variant="outline" disabled={busy} onClick={()=>setModal(null)}>Cancel</Button><Button disabled={busy||!modal.preview.eligible_count}>{busy?'Saving…':'Confirm bulk review'}</Button></div></form>}
 </dialog></>;
}