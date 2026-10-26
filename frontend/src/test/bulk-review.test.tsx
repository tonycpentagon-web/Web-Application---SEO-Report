import {beforeEach,describe,expect,it,vi} from 'vitest';
import {render,screen,within,waitFor} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {MemoryRouter,Routes,Route} from 'react-router-dom';
import {ReportDataReview} from '../pages/report-data';
const mock=vi.hoisted(()=>({get:vi.fn(),post:vi.fn(),patch:vi.fn()}));
vi.mock('../lib/api',async original=>({...await original<typeof import('../lib/api')>(),api:mock}));
const base='/api/projects/project-a/reports/report-a/data';
const eligibility={eligible:true,verified:true,verification_id:'verification',verified_by:1,verified_at:'2026-10-16',reasons:[],warnings:[]};
function row(id:string,name:string,eligible=true){return {id,kind:'event_metrics',section_key:'key_events',data:{event_name:name,count:10},source_data:{event_name:name,count:10},raw_value:name,confidence:'source',review_status:'pending',revision:1,sources:[],corrections:[],eligibility:eligible?eligibility:{...eligibility,eligible:false,verified:false,reasons:['Explicit human source verification is required.']}};}
const a=row('record-a','Contact'),b=row('record-b','Phone',false),c=row('record-c','Email');
const run={id:'run-a',status:'completed',processor_version:'normalizer',summary:{classifications:[]}};
let readonly=false;
const page=(rows:unknown[],current=1)=>({data:rows,current_page:current,last_page:2,total:3,per_page:2,from:1,to:2});
function response(path:string){
 if(path===base)return {data:{data:{run,runs:[run],sections:[],counts:{event_metrics:3},stale:false,can_review:!readonly,can_normalize:false}}};
 if(path===base+'/records/record-b')return {data:{data:b}};
 if(path.includes('eligibility=eligible'))return {data:page([a,c])};
 return {data:path.includes('page=2')?page([c],2):page([a,b])};
}
function mount(){render(<MemoryRouter initialEntries={['/projects/project-a/reports/report-a/data']}><Routes><Route path="/projects/:id/reports/:reportId/data" element={<ReportDataReview/>}/></Routes></MemoryRouter>);}
function result(ids:string[],decision='approved'){const records=ids.map(id=>({id,kind:'event_metrics',status:id==='record-b'&&decision==='approved'?'excluded':'eligible',review_status:'pending',revision:1,reasons:id==='record-b'&&decision==='approved'?['Explicit human source verification is required.']:[]}));const count=records.filter(r=>r.status==='eligible').length;return {report_id:'report-a',report_title:'August report',period:'2026-08-01 — 2026-08-31',selected_count:ids.length,eligible_count:count,excluded_count:ids.length-count,categories:{event_metrics:count},results:records};}
beforeEach(()=>{vi.resetAllMocks();readonly=false;mock.get.mockImplementation((p:string)=>Promise.resolve(response(p)));mock.post.mockImplementation((p:string,body:{record_ids?:string[];decision?:string})=>Promise.resolve({data:{data:result(body.record_ids??[],body.decision),message:'Review saved.'}}));mock.patch.mockResolvedValue({data:{message:'Review saved'}});});
describe('bulk source review',()=>{
 it('selects one or multiple exact records and clears the selection',async()=>{
  mount();await screen.findByRole('checkbox',{name:'Select Contact'});
  await userEvent.click(screen.getByRole('checkbox',{name:'Select Contact'}));expect(screen.getByText('1 selected · 1 eligible · 0 excluded from approval')).toBeInTheDocument();
  await userEvent.click(screen.getByRole('checkbox',{name:'Select Phone'}));expect(screen.getByText('2 selected · 1 eligible · 1 excluded from approval')).toBeInTheDocument();
  expect(screen.getByText('Why selected records are excluded from approval')).toBeInTheDocument();
  await userEvent.click(screen.getByRole('button',{name:'Clear Selection'}));expect(screen.getByRole('button',{name:'Approve Selected'})).toBeDisabled();
 });
 it('Select All Verified selects eligible human-verified rows on this page only',async()=>{
  mount();await screen.findByRole('checkbox',{name:'Select All Verified'});await userEvent.click(screen.getByRole('checkbox',{name:'Select All Verified'}));
  expect(screen.getByRole('checkbox',{name:'Select Contact'})).toBeChecked();expect(screen.getByRole('checkbox',{name:'Select Phone'})).not.toBeChecked();
  await userEvent.click(screen.getByRole('button',{name:'Approve Selected'}));
  await screen.findByRole('dialog',{name:'Bulk Review confirmation'});
  expect(mock.post).toHaveBeenCalledWith(base+'/records/bulk-preview',{record_ids:['record-a'],revisions:{'record-a':1},decision:'approved'});
  expect(mock.post.mock.calls.some(([p])=>p.endsWith('/bulk-review'))).toBe(false);
 });
 it('selects the current page without silently selecting all database records',async()=>{
  mount();await screen.findByRole('checkbox',{name:'Select Contact'});await userEvent.click(screen.getByRole('button',{name:'Select All on Current Page'}));
  expect(screen.getByText('2 selected · 1 eligible · 1 excluded from approval')).toBeInTheDocument();
  await userEvent.click(screen.getByRole('button',{name:'Next'}));await screen.findByRole('checkbox',{name:'Select Email'});
  expect(screen.getByRole('checkbox',{name:'Select Email'})).not.toBeChecked();
  await userEvent.click(screen.getByRole('checkbox',{name:'Select Email'}));expect(screen.getByText('3 selected · 2 eligible · 1 excluded from approval')).toBeInTheDocument();
 });
 it('requires confirmation and reason, submits exact IDs/revisions and displays partial results',async()=>{
  mount();await screen.findByRole('checkbox',{name:'Select Contact'});await userEvent.click(screen.getByRole('button',{name:'Select All on Current Page'}));await userEvent.click(screen.getByRole('button',{name:'Approve Selected'}));
  const modal=await screen.findByRole('dialog',{name:'Bulk Review confirmation'});
  expect(within(modal).getByText('2 selected · 1 eligible · 1 excluded')).toBeInTheDocument();
  await userEvent.click(within(modal).getByRole('button',{name:'Confirm bulk review'}));expect(mock.post.mock.calls.some(([p])=>p.endsWith('/bulk-review'))).toBe(false);
  await userEvent.type(within(modal).getByLabelText('Bulk review reason'),'Compared original source evidence.');
  await userEvent.click(within(modal).getByRole('checkbox',{name:'I confirm this approval of eligible selected records.'}));
  await userEvent.click(within(modal).getByRole('button',{name:'Confirm bulk review'}));
  await screen.findByText('Excluded records from the last review');
  const body=mock.post.mock.calls.find(([p])=>p.endsWith('/bulk-review'))![1];
  expect(body).toMatchObject({record_ids:['record-a','record-b'],revisions:{'record-a':1,'record-b':1},decision:'approved',confirmed:true,reason:'Compared original source evidence.'});expect(body.request_key).toMatch(/^[a-f0-9-]{36}$/);
 });
 it('checks rejection eligibility separately and lets the reviewer cancel without saving',async()=>{
  mount();await screen.findByRole('checkbox',{name:'Select Phone'});await userEvent.click(screen.getByRole('checkbox',{name:'Select Phone'}));await userEvent.click(screen.getByRole('button',{name:'Reject Selected'}));
  const modal=await screen.findByRole('dialog',{name:'Bulk Review confirmation'});expect(within(modal).getByText('1 selected · 1 eligible · 0 excluded')).toBeInTheDocument();
  await userEvent.click(within(modal).getByRole('button',{name:'Cancel'}));expect(mock.post.mock.calls.some(([p])=>p.endsWith('/bulk-review'))).toBe(false);
 });
 it('preserves API pagination and adds eligibility/review filters',async()=>{
  mount();await screen.findByLabelText('Verification eligibility');await userEvent.selectOptions(screen.getByLabelText('Verification eligibility'),'eligible');
  await waitFor(()=>expect(mock.get.mock.calls.some(([p])=>p.includes('eligibility=eligible')&&p.includes('page=1'))).toBe(true));
  await userEvent.selectOptions(screen.getByLabelText('Review status'),'pending');await waitFor(()=>expect(mock.get.mock.calls.some(([p])=>p.includes('status=pending'))).toBe(true));
 });
 it('hides selection and bulk actions from read-only users',async()=>{
  readonly=true;mount();await screen.findAllByRole('button',{name:'Inspect record'});expect(screen.queryByRole('checkbox',{name:'Select All Verified'})).not.toBeInTheDocument();expect(screen.queryByRole('button',{name:'Approve Selected'})).not.toBeInTheDocument();
 });
 it('records explicit human inspection independently from an approval decision',async()=>{
  mount();await screen.findAllByRole('button',{name:'Inspect record'});await userEvent.click(screen.getAllByRole('button',{name:'Inspect record'})[1]);
  await screen.findByRole('heading',{name:'Human source verification'});
  await userEvent.type(screen.getByLabelText('Source inspection notes'),'Inspected original source and saved count.');
  await userEvent.click(screen.getByRole('checkbox',{name:'I inspected the original source, checked these saved values and documented each blocking issue.'}));
  await userEvent.click(screen.getByRole('button',{name:'Record source verification'}));
  await waitFor(()=>expect(mock.post).toHaveBeenCalledWith(base+'/records/record-b/verification',{revision:1,confirmed:true,reason:'Inspected original source and saved count.',resolutions:[]}));
  expect(mock.patch).not.toHaveBeenCalled();
 });
 it('retains selection after a server conflict and shows the specific error',async()=>{
  mock.post.mockImplementation((p:string,body:{record_ids:string[]})=>p.endsWith('/bulk-preview')?Promise.reject({isAxiosError:true,response:{status:409,data:{message:'This record changed. Reload before reviewing.'}}}):Promise.resolve({data:{data:result(body.record_ids)}}));
  mount();await screen.findByRole('checkbox',{name:'Select Contact'});await userEvent.click(screen.getByRole('checkbox',{name:'Select Contact'}));await userEvent.click(screen.getByRole('button',{name:'Approve Selected'}));
  await screen.findByText('This record changed. Reload before reviewing.');expect(screen.getByRole('checkbox',{name:'Select Contact'})).toBeChecked();expect(mock.post.mock.calls.some(([p])=>p.endsWith('/bulk-review'))).toBe(false);
 });
 it('requires a specific resolution for an OCR warning during human verification',async()=>{
  const warned={...b,confidence:'ocr',eligibility:{...b.eligibility,warnings:[{id:'warning-ocr',code:'uncertain_ocr',message:'Compare the original event count image.',severity:'blocking',blocking:true,resolved:false}]}};
  mock.get.mockImplementation((p:string)=>Promise.resolve(p===base+'/records/record-b'?{data:{data:warned}}:response(p)));
  mount();await screen.findAllByRole('button',{name:'Inspect record'});await userEvent.click(screen.getAllByRole('button',{name:'Inspect record'})[1]);
  await screen.findByLabelText('Resolution: Uncertain Ocr');
  await userEvent.type(screen.getByLabelText('Resolution: Uncertain Ocr'),'Compared screenshot: event count is clearly 10.');
  await userEvent.type(screen.getByLabelText('Source inspection notes'),'Compared saved values with the original image.');
  await userEvent.click(screen.getByRole('checkbox',{name:'I inspected the original source, checked these saved values and documented each blocking issue.'}));await userEvent.click(screen.getByRole('button',{name:'Record source verification'}));
  await waitFor(()=>expect(mock.post).toHaveBeenCalledWith(base+'/records/record-b/verification',expect.objectContaining({resolutions:[{warning_id:'warning-ocr',reason:'Compared screenshot: event count is clearly 10.'}]})));
 });

});