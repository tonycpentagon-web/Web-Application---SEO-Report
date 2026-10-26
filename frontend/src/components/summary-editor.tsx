import {useState} from 'react';
import {useApi} from '../lib/use-api';
import {api,errorMessage} from '../lib/api';
import {Button} from './ui/button';
import {Alert} from './common';
type Summary={can_edit:boolean;revision:number;source_fingerprint:string;stale:boolean;sections:Record<string,string>};
export function SummaryEditor({root}:{root:string}){
 const q=useApi<{data:Summary}>('/api'+root+'/summary',true),s=q.data?.data;
 const [editing,setEditing]=useState<Record<string,string>|null>(null),[busy,setBusy]=useState(false),[error,setError]=useState(''),[message,setMessage]=useState('');
 if(!s?.can_edit)return null;
 async function save(){if(!s||!editing)return;setBusy(true);setError('');try{await api.post('/api'+root+'/summary',{revision:s.revision,source_fingerprint:s.source_fingerprint,sections:editing,confirmed:true,reason:'Employee edited the monthly report commentary before sharing.'});setEditing(null);setMessage('Report summary saved.');q.reload();}catch(e){setError(errorMessage(e));}finally{setBusy(false);}}
 async function regenerate(){if(!s)return;setBusy(true);setError('');try{await api.post('/api'+root+'/summary/generate',{revision:s.revision,source_fingerprint:s.source_fingerprint,replace_manual:true,confirmed:true});setEditing(null);setMessage('Current source summary generated.');q.reload();}catch(e){setError(errorMessage(e));}finally{setBusy(false);}}
 return <details className="panel form-panel section-gap"><summary>Optional: edit report summary</summary><p>Adjust the narrative before sharing. Numeric source observations remain linked to their evidence.</p>{s.stale&&<Alert>Commentary refers to older sources. Review and save it against the current report, or generate a new source summary.</Alert>}{error&&<Alert>{error}</Alert>}{message&&<p role="status">{message}</p>}{editing?<>{Object.entries(editing).map(([key,value])=><label key={key}>{key.replaceAll('_',' ')}<textarea rows={4} maxLength={3000} value={value} onChange={e=>setEditing({...editing,[key]:e.target.value})}/></label>)}<Button disabled={busy} onClick={()=>void save()}>Save summary</Button><Button variant="ghost" onClick={()=>setEditing(null)}>Cancel</Button></>:<div className="action-row"><Button variant="outline" disabled={busy} onClick={()=>setEditing({...s.sections})}>Edit summary</Button><Button variant="outline" disabled={busy} onClick={()=>void regenerate()}>Generate source summary</Button></div>}</details>;
}
