import {useEffect,useState} from 'react';
import {useParams} from 'react-router-dom';
import {api,errorMessage} from '../lib/api';
import {Button} from '../components/ui/button';
import {Loading,Alert} from '../components/common';
import {PublishedDashboard,type PublishedSnapshot} from '../components/published-dashboard';
import {downloadPdf} from './report-delivery';
export function ClientReportPage({shared=false,history=false,publicLink=false}:{shared?:boolean;history?:boolean;publicLink?:boolean}){
 const {id,reportId,publicationId}=useParams();
 const [data,setData]=useState<PublishedSnapshot|null>(null),[error,setError]=useState(''),[allowDownload,setAllowDownload]=useState(false),[busy,setBusy]=useState(false),[pdfStatus,setPdfStatus]=useState('');
 const token=shared?window.location.hash.slice(1):'',headers=shared?{'X-Report-Token':token}:undefined;
 const path=shared?(publicLink?'/api/public-report':'/api/shared-report'):history?`/api/projects/${id}/reports/${reportId}/delivery/publications/${publicationId}`:`/api/projects/${id}/reports/${reportId}/client-report`;
 useEffect(()=>{const robots=document.createElement('meta');robots.name='robots';robots.content='noindex,nofollow,noarchive';document.head.appendChild(robots);const ref=document.createElement('meta');ref.name='referrer';ref.content='no-referrer';document.head.appendChild(ref);return()=>{robots.remove();ref.remove();};},[]);
 useEffect(()=>{const controller=new AbortController();setError('');setData(null);setAllowDownload(false);api.get(path,{headers:shared?{'X-Report-Token':token}:undefined,signal:controller.signal}).then(r=>{if(!controller.signal.aborted){setData(r.data.data);setAllowDownload(!!r.data.allow_download);setPdfStatus(r.data.pdf_status||'completed');}}).catch(e=>{if(!controller.signal.aborted)setError(errorMessage(e));});return()=>controller.abort();},[path,token,shared]);
 useEffect(()=>{if(!['queued','rendering'].includes(pdfStatus))return;const controller=new AbortController();const timer=setInterval(()=>{void api.get(path,{headers:shared?{'X-Report-Token':token}:undefined,signal:controller.signal}).then(r=>{setPdfStatus(r.data.pdf_status);}).catch(e=>{if(!controller.signal.aborted)setError(errorMessage(e));});},4000);return()=>{clearInterval(timer);controller.abort();};},[pdfStatus,path,token,shared]);
 if(error&&!data)return <main className="client-report"><Alert>{error}</Alert><p>This report may have expired, been revoked or become unavailable.</p></main>;
 if(!data)return <Loading/>;
 const pending=['queued','rendering'].includes(pdfStatus);
 return <>{error&&<Alert>{error}</Alert>}<PublishedDashboard data={data} download={allowDownload?<><Button disabled={busy||pending||pdfStatus==='failed'||pdfStatus==='unavailable'} onClick={()=>{setBusy(true);void downloadPdf(path+'?pdf=1',headers).catch(e=>setError(errorMessage(e))).finally(()=>setBusy(false));}}>{pending?'Preparing PDF...':'Download PDF'}</Button>{pdfStatus==='failed'&&<p>PDF preparation failed. Ask the reporting team to retry it; the interactive report is available.</p>}</>:undefined}/></>;
}
