import {useEffect,useState} from 'react';
import {Link,useParams} from 'react-router-dom';
import {api,errorMessage} from '../lib/api';
import {useApi} from '../lib/use-api';
import {Alert,ErrorState,Loading} from '../components/common';
import {Button} from '../components/ui/button';
import {PublishedDashboard,type PublishedSnapshot} from '../components/published-dashboard';
import {downloadPdf} from './report-delivery';
type Delivery={exports:{id:string;status:string;error?:string|null}[]};
export function ReportDraftPreview(){
 const {id,reportId}=useParams(),root=`/projects/${id}/reports/${reportId}`,base='/api'+root;
 const q=useApi<{data:PublishedSnapshot}>(base+'/presentation',true),delivery=useApi<{data:Delivery}>(base+'/delivery',true);
 const [exportId,setExportId]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState(''),[notice,setNotice]=useState('');
 const current=delivery.data?.data.exports.find(e=>e.id===exportId),pending=!!exportId&&(!current||['queued','rendering'].includes(current.status));
 useEffect(()=>{if(!pending)return;const timer=setInterval(delivery.reload,2500);return()=>clearInterval(timer);},[pending,delivery.reload]);
 async function exportDraft(){setBusy(true);setError('');try{const r=await api.post(base+'/delivery/exports',{type:'draft',profile:'client_preview',sections:['overview','website_traffic','organic_search','keywords','seo_work','ai_visibility','key_events','sources']});setExportId(r.data.data.id);setNotice('Draft PDF queued. You can continue reading while it is prepared.');delivery.reload();}catch(e){setError(errorMessage(e));}finally{setBusy(false);}}
 if(q.loading&&!q.data)return <Loading/>;if(q.error||!q.data)return <ErrorState message={q.error} retry={q.reload}/>;
 return <PublishedDashboard data={q.data.data} toolbar={<div className="report-private-toolbar"><Link to={root+'/generate'}>Sources &amp; optional edits</Link><span>Private Draft Preview</span><div><Link to={root+'/source-audit'}>Source audit</Link><button onClick={q.reload}>Refresh report</button></div></div>} notices={<>{notice&&<Alert success>{notice}</Alert>}{error&&<Alert>{error}</Alert>}{current?.status==='failed'&&<Alert>{current.error||'PDF preparation failed. Retry from Share Report.'}</Alert>}</>} download={<><Button asChild><Link to={root+'/delivery'}>Share Report</Link></Button><Button disabled={busy||pending} onClick={()=>void exportDraft()}>{pending?'Preparing Draft PDF...':'Export Draft PDF'}</Button>{current?.status==='completed'&&<Button variant="outline" onClick={()=>void downloadPdf(base+'/delivery/exports/'+current.id+'/download').catch(e=>setError(errorMessage(e)))}>Download ready PDF</Button>}<span className="report-draft-pill">PRIVATE PREVIEW</span></>}/>;
}
