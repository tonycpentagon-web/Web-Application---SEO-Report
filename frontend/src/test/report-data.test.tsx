import {beforeEach,describe,expect,it,vi} from 'vitest';
import {render,screen,waitFor,within} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {MemoryRouter,Routes,Route} from 'react-router-dom';
import {ReportDataReview} from '../pages/report-data';
const mock=vi.hoisted(()=>({get:vi.fn(),post:vi.fn(),patch:vi.fn()}));
vi.mock('../lib/api',async original=>({...await original<typeof import('../lib/api')>(),api:mock}));
const base='/api/projects/project-a/reports/report-a/data';
const record={id:'record-a',kind:'report_metrics',section_key:'website_analytics',data:{metric_key:'ga4.total_users',label:'Total users',value:800,unit:'count',definition:'ga4'},source_data:{metric_key:'ga4.total_users',label:'Total users',value:800,unit:'count',definition:'ga4'},raw_value:'Total users 800',confidence:'source',review_status:'pending',revision:1,sources:[{id:'reference-a',document_id:'document-a',extraction_run_id:'source-run',content_id:'content-a',asset_id:null,location:{page:2},quote:'Original source says 800 users',raw_value:'800'}],corrections:[]};
const run={id:'run-a',status:'completed',created_at:'2026-10-16T00:00:00Z',started_at:null,completed_at:null,error_message:null,processor_version:'pentagon-normalizer/1.0',summary:{counts:{report_metrics:1},warning_count:1,classifications:[]}};
const summary={run,runs:[run],sections:[],counts:{report_metrics:1},stale:false,can_normalize:true,can_review:true};
const page=(data:unknown[])=>({data,current_page:1,last_page:1,total:data.length});
const preview={kpis:[{metric_key:'ga4.total_users',value:800,unit:'count',conflict:false,observations:[{record_id:'record-a',value:800,review_status:'pending'}]}],keyword_summary:{page_1:2},seo_activity_counts:{article_submission:1},event_count_sum:7,ai_evidence_count:1,approved_only:false,stale:false,granularity:'reported_period_totals_only',review_notice:'Internal structured preview. Pending records are observations.',traffic_channels:[],device_metrics:[],landing_page_metrics:[],event_metrics:[]};
function response(path:string){
 if(path===base)return {data:{data:summary}};
 if(path===base+'/records/record-a')return {data:{data:record}};
 if(path.startsWith(base+'/warnings'))return {data:page([{id:'warning-a',code:'date_range_mismatch',severity:'warning',message:'Source dates end before the selected period.',document_id:'document-a',source:'{"page":2}'}])};
 if(path.startsWith(base+'/preview'))return {data:{data:path.endsWith('=1')?{...preview,kpis:[]}:preview}};
 return {data:page([record])};
}
function mount(){return render(<MemoryRouter initialEntries={['/projects/project-a/reports/report-a/data']}><Routes><Route path="/projects/:id/reports/:reportId/data" element={<ReportDataReview/>}/></Routes></MemoryRouter>);}
beforeEach(()=>{vi.resetAllMocks();mock.get.mockImplementation((p:string)=>Promise.resolve(response(p)));mock.post.mockResolvedValue({data:{message:'Normalization queued'}});mock.patch.mockResolvedValue({data:{data:record,message:'Review saved'}});});
describe('normalized report review',()=>{
 it('routes the nine required review sections and structured preview through APIs',async()=>{
  mount();await screen.findByRole('heading',{name:'Report Data Review'});
  const nav=screen.getByRole('navigation',{name:'Data review sections'});expect(within(nav).getAllByRole('button')).toHaveLength(12);
  await userEvent.click(within(nav).getByRole('button',{name:'Keyword Rankings'}));
  await waitFor(()=>expect(mock.get.mock.calls.some(([p])=>p.includes('kind=keyword_rankings'))).toBe(true));
 });
 it('inspects original evidence and saves a correction with decision reason and revision',async()=>{
  mount();await screen.findByRole('button',{name:'Inspect record'});await userEvent.click(screen.getByRole('button',{name:'Inspect record'}));
  const input=await screen.findByLabelText('Metric value (count)');expect(input).toHaveValue(800);
  expect(screen.getByRole('link',{name:'Open source document'})).toHaveAttribute('href','/projects/project-a/reports/report-a/documents/document-a?run_id=source-run');
  await userEvent.clear(input);await userEvent.type(input,'810');await userEvent.selectOptions(screen.getByLabelText('Review decision'),'approved');
  await userEvent.type(screen.getByLabelText('Reason for this review or correction'),'Verified source and correction');
  await userEvent.click(screen.getByRole('button',{name:'Save review'}));
  await waitFor(()=>expect(mock.patch).toHaveBeenCalledWith(base+'/records/record-a',{data:{value:810},review_status:'approved',reason:'Verified source and correction',revision:1}));
  expect(record.source_data.value).toBe(800);
 });
 it('requires confirmation before queuing a safe normalization rerun',async()=>{
  mount();await screen.findByRole('button',{name:'Re-run normalization'});
  await userEvent.click(screen.getByRole('button',{name:'Re-run normalization'}));expect(mock.post).not.toHaveBeenCalled();
  await userEvent.click(screen.getByRole('button',{name:'Confirm'}));
  await waitFor(()=>expect(mock.post).toHaveBeenCalledWith(base+'/normalize',{}));
 });
 it('shows validation warnings with authorized source routes',async()=>{
  mount();await screen.findByRole('heading',{name:'Report Data Review'});
  await userEvent.click(screen.getByRole('button',{name:'Validation Warnings'}));
  await screen.findByText('Source dates end before the selected period.');
  expect(screen.getByRole('link',{name:'Inspect source evidence'})).toHaveAttribute('href','/projects/project-a/reports/report-a/documents/document-a');
 });
 it('builds the preview from API values and requests approved records explicitly',async()=>{
  mount();await screen.findByRole('heading',{name:'Report Data Review'});await userEvent.click(screen.getByRole('button',{name:'Structured Preview'}));
  await screen.findByText('ga4.total_users');expect(screen.getByText('800')).toBeInTheDocument();
  await userEvent.click(screen.getByRole('checkbox',{name:'Approved records only'}));
  await screen.findByRole('heading',{name:'No KPI observations'});
  expect(mock.get.mock.calls.some(([p])=>p===base+'/preview?approved_only=1')).toBe(true);
 });
 it('allows read-only inspection while hiding review actions from unauthorized users',async()=>{
  mock.get.mockImplementation((p:string)=>Promise.resolve(p===base?{data:{data:{...summary,can_review:false,can_normalize:false}}}:response(p)));
  mount();await screen.findByRole('button',{name:'Inspect record'});expect(screen.queryByRole('button',{name:'Re-run normalization'})).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole('button',{name:'Inspect record'}));await screen.findByRole('heading',{name:'Original source evidence'});
  expect(screen.queryByRole('button',{name:'Save review'})).not.toBeInTheDocument();
 });
});

it('defaults to attention without triggering retrospective validation automatically',async()=>{
 mount();await screen.findByRole('heading',{name:'Report Data Review'});
 expect(screen.getByLabelText('Validation status')).toHaveValue('attention');
 expect(mock.get.mock.calls.some(([path])=>path.includes('validation=attention'))).toBe(true);
 expect(mock.post).not.toHaveBeenCalled();
 await userEvent.selectOptions(screen.getByLabelText('Validation status'),'AUTO_VALIDATED');
 await waitFor(()=>expect(mock.get.mock.calls.some(([path])=>path.includes('validation=AUTO_VALIDATED'))).toBe(true));
});
it('shows separate machine and human counts and requires confirmation for admin re-evaluation',async()=>{
 const validation={id:'v1',current:true,fingerprint:'f'.repeat(64),before:{},evaluated_at:'2026-10-20',summary:{counts:{AUTO_VALIDATED:100,HUMAN_VERIFIED:2,NEEDS_REVIEW:1,INVALID:0,NOT_APPLICABLE:800},documents:5,successfully_extracted:5,material_issues:1,data_ready:false,missing_metrics:[],missing_sections:[],document_issues:[],evidence_coverage:{supported:102,business_records:103}}};
 mock.get.mockImplementation((path:string)=>Promise.resolve(path===base?{data:{data:{...summary,validation,can_reevaluate:true,report_revision:4}}}:response(path)));
 mount();await screen.findByRole('region',{name:'Evidence validation summary'});expect(within(screen.getByRole('region',{name:'Evidence validation summary'})).getByText('Human-Verified')).toBeInTheDocument();
 await userEvent.click(screen.getByRole('button',{name:'Re-evaluate Validation'}));expect(mock.post).not.toHaveBeenCalled();
 await userEvent.click(screen.getByRole('button',{name:'Confirm'}));await waitFor(()=>expect(mock.post).toHaveBeenCalledWith(base+'/re-evaluate',{confirmed:true,version:4}));
});
