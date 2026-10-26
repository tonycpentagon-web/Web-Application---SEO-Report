import {useEffect,useRef} from 'react';
import {ChevronDown} from 'lucide-react';
import type {DashboardRecord} from '../lib/report-dashboard';
import {label} from '../lib/workspace';
import {PublicSources} from './dashboard/public-source';
const titles:Record<string,string>={article_submission:'Article Submission',profile_creation:'Profile Creation',guest_post:'Guest Post',social_bookmarking:'Social Bookmarking',ppt_submission:'PPT Submission',image_submission:'Image Submission'};
const order=Object.keys(titles);
export function activityUrl(value:unknown):string|null{
 if(typeof value!=='string')return null;
 try{const url=new URL(value);return ['http:','https:'].includes(url.protocol)?value:null;}catch{return null;}
}
export function SeoActivityGroups({activities}:{activities:DashboardRecord[]}){
 const accordion=useRef<HTMLDivElement>(null);
 useEffect(()=>{
  let openedForPrint:HTMLDetailsElement[]|null=null;
  const beforePrint=()=>{if(openedForPrint)return;openedForPrint=[...(accordion.current?.querySelectorAll<HTMLDetailsElement>('details.activity-group:not([open])')??[])];for(const group of openedForPrint)group.open=true;};
  const afterPrint=()=>{for(const group of openedForPrint??[])group.open=false;openedForPrint=null;};
  window.addEventListener('beforeprint',beforePrint);window.addEventListener('afterprint',afterPrint);
  return()=>{window.removeEventListener('beforeprint',beforePrint);window.removeEventListener('afterprint',afterPrint);afterPrint();};
 },[]);
 const grouped=new Map<string,DashboardRecord[]>();
 for(const row of activities){const key=String(row.data.category||'uncategorized');grouped.set(key,[...(grouped.get(key)??[]),row]);}
 const categories=[...grouped].sort(([a],[b])=>{const ai=order.indexOf(a),bi=order.indexOf(b);return (ai<0?order.length:ai)-(bi<0?order.length:bi)||a.localeCompare(b);});
 const title=(key:string)=>titles[key]??label(key);
 if(!activities.length)return <p className="report-empty-section">No source-backed SEO activities were supplied.</p>;
 return <><div className="studio-kpi-grid activity-kpi-grid">
 <article className="studio-card studio-kpi"><div className="studio-kpi-top"><span>Total submissions</span></div><strong>{activities.length}</strong></article>
 {categories.map(([key,rows])=><article key={key} className="studio-card studio-kpi"><div className="studio-kpi-top"><span>{title(key)}</span></div><strong>{rows.length}</strong></article>)}
 </div><div className="activity-accordion" ref={accordion}>{categories.map(([key,rows])=>{
 const urlCount=rows.filter(row=>activityUrl(row.data.url)).length;
 return <details key={key} className="activity-group"><summary><h3>{title(key)}</h3><span className="activity-url-count">{urlCount} {urlCount===1?'URL':'URLs'}</span><ChevronDown aria-hidden="true" size={18}/></summary>
 <div className="activity-group-content"><ol>{rows.map(row=>{const url=activityUrl(row.data.url);return <li key={row.id}>{url?<a href={url} target="_blank" rel="noopener noreferrer">{url}</a>:<span>URL not supplied or invalid{row.data.url?': '+String(row.data.url):''}</span>}{row.provisional&&<small>Provisional source observation</small>}</li>;})}</ol>
 <details className="activity-evidence"><summary>Source details</summary><PublicSources sources={rows.flatMap(row=>row.public_sources??[])}/></details></div></details>;
 })}</div><p className="report-explainer">Counts reflect listed source observations, including repeated URLs. Submitted URLs are not independently verified live backlinks.</p></>;
}
