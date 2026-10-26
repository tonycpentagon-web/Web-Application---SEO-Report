import {Link} from 'react-router-dom';
import {api} from '../lib/api';
import {Field,FormPanel} from './workspace';
import type {ValidationOverview,ValidationStatus} from '../lib/report-data';
import {label} from '../lib/workspace';
import {Alert} from './common';
export function ValidationOverviewPanel({data,sourceScope}:{data?:ValidationOverview|null;sourceScope?:{base:string;revision:number;done:()=>void}}){
 if(!data)return <Alert>Existing report decisions are preserved. An administrator can explicitly Re-evaluate Validation to use automated checks without uploading sources again.</Alert>;
 const s=data.summary,c=s.counts;
 const cards:[ValidationStatus,string,number][]=[['AUTO_VALIDATED','Auto-Validated',c.AUTO_VALIDATED],['HUMAN_VERIFIED','Human-Verified',c.HUMAN_VERIFIED],['NEEDS_REVIEW','Requires Attention',c.NEEDS_REVIEW],['INVALID','Invalid / Missing',c.INVALID+s.missing_metrics.length+s.missing_sections.length]];
 return <section className="section-gap" aria-label="Evidence validation summary">
 {!data.current&&<Alert>Validation is stale. The previous counts below cannot authorize approval. Re-evaluate the current sources and saved values.</Alert>}
 <div className="validation-summary-grid">{cards.map(([key,title,value])=><article className="panel form-panel" key={key}><span>{title}</span><strong>{value}</strong></article>)}</div>
 <p>{s.documents} uploaded documents; {s.successfully_extracted} readable documents; {s.evidence_coverage.supported} of {s.evidence_coverage.business_records} business observations supported. {c.NOT_APPLICABLE} contextual, redundant or rejected representations retained outside the review task list.</p>
 <p className="muted">Machine validation is separate from human source inspection. A partial document does not invalidate every readable observation. Uncertain OCR and material source gaps still require attention.</p>
 {!!s.document_issues.length&&<div className="validation-exceptions"><h3>Source exceptions</h3><ul>{s.document_issues.map((issue,i)=><li key={i}><strong>{label(issue.code)}</strong><p>{issue.message}</p>{issue.source_period!=null&&<pre>{JSON.stringify(issue.source_period,null,2)}</pre>}<small>{issue.document_name??'Uploaded source'}</small>{issue.source_url&&<Link className="text-link" to={issue.source_url}>Inspect original evidence</Link>}{sourceScope&&data.current&&issue.document_id&&['period_not_reported','period_requires_inspection'].includes(issue.code)&&!s.document_issues.some(other=>other.document_id===issue.document_id&&['date_range_mismatch','client_project_mismatch'].includes(other.code))&&<FormPanel button="Confirm source scope" done={sourceScope.done} save={form=>api.post(sourceScope.base+'/source-scope/'+issue.document_id,{version:sourceScope.revision,fingerprint:data.fingerprint,confirmed:form.scope_confirmed==='on',reason:form.scope_reason})}><p>Confirm the source belongs to the selected client and reporting month after inspecting its original evidence. This records document scope once, preserves missing printed dates and does not verify individual values.</p><Field name="scope_reason" title="Evidence supporting this source association" required type="textarea" maxLength={2000}/><label><input type="checkbox" name="scope_confirmed" required/> I inspected this source and confirmed its client and reporting-period association. No contradictory dates are being waived.</label></FormPanel>}</li>)}</ul></div>}
 {!!s.missing_metrics.length&&<Alert>Missing validated mandatory metrics: {s.missing_metrics.join(', ')}</Alert>}
 {!!s.missing_sections.length&&<Alert>Missing validated mandatory sections: {s.missing_sections.map(label).join(', ')}</Alert>}
 <details><summary>Re-evaluation before and after summary</summary><h4>Before</h4><pre>{JSON.stringify(data.before,null,2)}</pre><h4>After</h4><pre>{JSON.stringify(s,null,2)}</pre></details>
 </section>;
}
