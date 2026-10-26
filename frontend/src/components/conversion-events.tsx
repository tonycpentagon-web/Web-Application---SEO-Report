import type {DashboardRecord} from '../lib/report-dashboard';
import {ReportChart,points} from './dashboard/charts';
import {number} from './dashboard/shared';
import {PublicSources} from './dashboard/public-source';

type Total={value:number|null;conflict?:boolean;provisional?:boolean};
export function ConversionEvents({events,total}:{events:DashboardRecord[];total?:Total}){
 const sorted=[...events].sort((a,b)=>(typeof b.data.count==='number'?b.data.count:-1)-(typeof a.data.count==='number'?a.data.count:-1));
 const totalText=total?.conflict?'Conflicting source values':number(total?.value);
 return <><div className="studio-kpi-grid conversion-kpi-grid">
  <article className="studio-card studio-kpi"><div className="studio-kpi-top"><span>Grand total</span></div><strong>{totalText}</strong>{total?.provisional&&<small>Provisional</small>}</article>
  {sorted.map(row=><article className="studio-card studio-kpi" key={row.id}><div className="studio-kpi-top"><span>{String(row.data.event_name??'Unnamed event')}</span></div><strong>{number(row.data.count)}</strong>{row.provisional&&<small>Provisional</small>}</article>)}
 </div>{!sorted.length&&<p className="report-empty-section">No source-supported conversion event observations were supplied.</p>}
 <div className="studio-two-col conversion-details">
  <ReportChart title="Event count by event name" description="Source-reported event counts." unit="events" rows={sorted} points={points(sorted,'event_name','count')} root="" color="#00ade2" categoryWidth={175} showValues/>
  <article className="studio-card conversion-table-card"><table className="conversion-event-table"><caption className="sr-only">Conversion events</caption><thead><tr><th aria-label="Row number"></th><th scope="col">Event name</th><th scope="col">Event count</th></tr></thead><tbody>{sorted.map((row,i)=><tr key={row.id}><td>{i+1}.</td><th scope="row">{String(row.data.event_name??'Unnamed event')}{row.provisional&&<small>Provisional</small>}</th><td>{number(row.data.count)}</td></tr>)}</tbody><tfoot><tr><td></td><th scope="row">Grand total</th><td>{totalText}</td></tr></tfoot></table>
  <details className="conversion-evidence"><summary>Event source details</summary><PublicSources sources={sorted.flatMap(row=>row.public_sources??[])}/></details></article>
 </div><p className="report-explainer">Source-reported events. Counts are not independently verified leads or sales; a grand total is shown only when supplied by the source.</p></>;
}
