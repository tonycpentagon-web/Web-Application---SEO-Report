import { useState } from 'react';
import { useAuth } from '../auth';
import { api, errorMessage } from '../lib/api';
import { useApi } from '../lib/use-api';
import { Alert, Loading, ErrorState, PageHeading } from '../components/common';
import { Button } from '../components/ui/button';
type Check={status:'healthy'|'warning'|'unavailable'|'not_configured'|'not_verified';message:string;last_seen_at?:string|null};
type Health={checked_at:string;runtime_checked_at:string|null;checks:Record<string,Check>;queues:Record<string,Check>;counts:Record<string,number>;deployment:string};
const label=(s:string)=>s.replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
export function SystemHealthPage(){const {user}=useAuth();return user?.is_super_admin?<HealthPanel/>:<Alert>System diagnostics require an active Super Admin account.</Alert>;}
function HealthPanel(){
 const q=useApi<{data:Health}>('/api/system/health',true);const [busy,setBusy]=useState(false),[error,setError]=useState('');
 async function probe(){setBusy(true);setError('');try{await api.post('/api/system/health/probe');q.reload();}catch(e){setError(errorMessage(e));}finally{setBusy(false);}}
 if(q.loading&&!q.data)return <Loading/>;if(q.error||!q.data)return <ErrorState message={q.error||'Health checks unavailable.'} retry={q.reload}/>;
 const d=q.data.data;
 return <><PageHeading eyebrow="OPERATIONS" title="System Health" description="Observed runtime status. Successful local checks do not certify a production deployment." action={<Button variant="outline" onClick={q.reload}>Refresh status</Button>}/>
 {error&&<Alert>{error}</Alert>}<section className="panel form-panel"><div className="action-row"><Button disabled={busy} onClick={()=>void probe()}>{busy?'Testing Python and Chromium…':'Run runtime probes'}</Button><span className="muted">Runs a local extraction/OCR capability check and renders a real offline A4 PDF. No reporting data is changed.</span></div><p className="muted">Last status: {new Date(d.checked_at).toLocaleString()} · Runtime probes: {d.runtime_checked_at?new Date(d.runtime_checked_at).toLocaleString():'Not verified'}</p></section>
 <div className="health-grid">{Object.entries(d.checks).map(([key,c])=><article key={key} className="panel form-panel health-card"><div className="health-card-heading"><h2>{label(key)}</h2><span className={'health-state health-'+c.status}>{label(c.status)}</span></div><p>{c.message}</p></article>)}</div>
 <section className="panel form-panel section-gap"><h2>Queue workers</h2><p className="muted">Recent heartbeats confirm an observed process, not completion of every queued job. Busy workers use the configured job timeout window. Missing heartbeats remain Not verified.</p>{Object.entries(d.queues).map(([name,c])=><article className="health-queue" key={name}><div className="health-card-heading"><h3>{label(name)}</h3><span className={'health-state health-'+c.status}>{label(c.status)}</span></div><p>{c.message}</p>{c.last_seen_at&&<small>Last observed: {c.last_seen_at}</small>}</article>)}</section>
 <section className="panel form-panel section-gap"><h2>Backlog and retained failures</h2><div className="delivery-stats">{Object.entries(d.counts).map(([key,count])=><div key={key}><strong>{count.toLocaleString()}</strong><span>{label(key)}</span></div>)}</div><p className="muted">Counts are observations only. This screen never retries, deletes, approves or publishes reports. Review failed/stuck work before an explicit retry. Redis queue sizes require separate broker monitoring.</p></section><p className="muted section-gap">Production deployment: {label(d.deployment)}. Complete the production testing checklist before release approval.</p></>;
}
