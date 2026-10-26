import type {SourceCoverage} from '../components/report-source-coverage';

// The report month is a filing label; cover dates retain the actual source ranges.
export function reportCoverPeriod(report:{start_date:string;end_date:string}, periods:SourceCoverage[]=[]){
 const uncertain=periods.some(p=>p.status==='uncertain');
 const ranges=[...new Map(periods.filter(p=>p.status!=='uncertain').flatMap(p=>p.ranges).map(r=>[r.start+'|'+r.end,r])).values()].sort((a,b)=>a.start.localeCompare(b.start)||a.end.localeCompare(b.end));
 const date=(value:string)=>new Date(value.slice(0,10)+'T12:00:00').toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'});
 if(!ranges.length&&uncertain)return {label:'Reporting month (source dates unconfirmed)',text:new Date(report.start_date.slice(0,10)+'T12:00:00').toLocaleDateString('en-GB',{month:'long',year:'numeric'})};
 const shown=ranges.length?ranges:[{start:report.start_date,end:report.end_date}];
 return {label:uncertain?'Source periods (some dates unconfirmed)':shown.length>1?'Source periods':'Reporting period',text:shown.map(r=>date(r.start)+' – '+date(r.end)).join('; ')};
}
