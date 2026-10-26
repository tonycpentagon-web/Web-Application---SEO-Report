import {beforeEach,describe,expect,it,vi} from 'vitest';
import {render,screen,waitFor,fireEvent} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {MemoryRouter,Routes,Route} from 'react-router-dom';
import {DocumentUpload,DocumentList} from '../components/documents';
import {DocumentDetails} from '../pages/document-details';
const mock=vi.hoisted(()=>({get:vi.fn(),post:vi.fn(),delete:vi.fn()}));
vi.mock('../lib/api',async original=>({...await original<typeof import('../lib/api')>(),api:mock}));
const health={available:true,max_file_mb:25,max_batch:10,max_total_mb:100,accepted_extensions:['pdf','csv','png','docx','xlsx'],formats:{pdf:true},ocr:{available:true}};
const run={id:'run-a',status:'failed',stage:'Extraction failed',created_at:'2026-10-16T00:00:00Z',started_at:null,completed_at:null,error_message:'Unable to read document.',processed_units:0,total_units:null,warnings:[],events:[],statistics:null};
const doc={id:'document-a',project_id:'project-a',reporting_period_id:'report-a',original_filename:'August.pdf',extension:'pdf',mime_type:'application/pdf',file_size:1024,checksum:'a'.repeat(64),upload_status:'uploaded',extraction_status:'failed',created_at:'2026-10-16T00:00:00Z',current_run:run,runs:[run],can_retry:true,can_remove:true,stale:false};
const page=(data:unknown[])=>({data,current_page:1,last_page:1,total:data.length});
beforeEach(()=>{vi.resetAllMocks();mock.get.mockImplementation((path:string)=>Promise.resolve({data:path.includes('/health')?health:path.includes('/assets?')?page([]):path.includes('/extraction?')?{run,content:page([]),types:[]}:path.includes('/api/documents/')?{data:doc}:page([doc])}));mock.post.mockResolvedValue({data:{message:'Queued',data:[{...doc,extraction_status:'queued'}]}});mock.delete.mockResolvedValue({data:{message:'Removed'}});});
describe('document ingestion',()=>{
 it('uploads five selected documents in one action with actual per-file progress and separate extraction message',async()=>{
  const done=vi.fn();render(<DocumentUpload path="/api/projects/p/reports/r/documents" done={done}/>);
  await screen.findByText(/Private originals/);
  mock.post.mockImplementation(async(_p,body,options)=>{expect(body).toBeInstanceOf(FormData);options.onUploadProgress({loaded:40,total:100});return {data:{data:[{...doc,extraction_status:'queued'}]}};});
  const files=Array.from({length:5},(_,i)=>new File(['%PDF-1.7'],'file-'+i+'.pdf',{type:'application/pdf'}));
  fireEvent.change(screen.getByLabelText('Select report documents'),{target:{files}});
  await userEvent.click(screen.getByRole('button',{name:'Upload documents'}));
  await waitFor(()=>expect(done).toHaveBeenCalledTimes(5));expect(mock.post).toHaveBeenCalledTimes(5);
  expect(screen.getAllByText('File uploaded. Waiting for extraction.')).toHaveLength(5);
  expect(screen.queryByText('Successfully extracted')).not.toBeInTheDocument();
  expect(mock.post.mock.calls.every(c=>c[1].get('batch_id')===mock.post.mock.calls[0][1].get('batch_id'))).toBe(true);
 });
 it('rejects unsupported selections and oversized batches before calling the server',async()=>{
  render(<DocumentUpload path="/upload" done={()=>{}}/>);
  fireEvent.change(screen.getByLabelText('Select report documents'),{target:{files:[new File(['MZ'],'run.exe')]}});
  expect(await screen.findByRole('alert')).toHaveTextContent('Choose supported documents');
  fireEvent.change(screen.getByLabelText('Select report documents'),{target:{files:Array.from({length:11},()=>new File(['x'],'a.csv'))}});
  expect(await screen.findByRole('alert')).toHaveTextContent('at most 10');expect(mock.post).not.toHaveBeenCalled();
 });
 it('shows upload failures and permits a second attempt without losing selected files',async()=>{
  mock.post.mockRejectedValueOnce(new Error('upload failure'));
  render(<DocumentUpload path="/upload" done={()=>{}}/>);
  fireEvent.change(screen.getByLabelText('Select report documents'),{target:{files:[new File(['a,b'],'data.csv')]}});
  await userEvent.click(screen.getByRole('button',{name:'Upload documents'}));
  expect(await screen.findByRole('alert')).toHaveTextContent('Something went wrong');
  await userEvent.click(screen.getByRole('button',{name:'Upload documents'}));
  await screen.findByText('File uploaded. Waiting for extraction.');expect(mock.post).toHaveBeenCalledTimes(2);
 });
 it('renders server extraction status, failure and a real document route',async()=>{
  render(<MemoryRouter><DocumentList path="/api/projects/p/documents"/></MemoryRouter>);
  const link=await screen.findByRole('link',{name:'August.pdf'});expect(link).toHaveAttribute('href','/projects/project-a/reports/report-a/documents/document-a');
  expect(screen.getByText('Failed')).toBeInTheDocument();expect(screen.getByText('Uploaded')).toBeInTheDocument();expect(screen.getByRole('alert')).toHaveTextContent('Unable to read');
 });
 it('offers five review tabs and requires confirmation for retry and removal',async()=>{
  render(<MemoryRouter initialEntries={['/projects/project-a/reports/report-a/documents/document-a']}><Routes><Route path="/projects/:id/reports/:reportId/documents/:documentId" element={<DocumentDetails/>}/></Routes></MemoryRouter>);
  await screen.findByRole('heading',{name:'August.pdf'});
  await userEvent.click(screen.getByRole('button',{name:'Text'}));await screen.findByText('No matching text');
  await userEvent.click(screen.getByRole('button',{name:'Tables'}));await screen.findByText('No tables found');
  await userEvent.click(screen.getByRole('button',{name:'Images'}));await screen.findByText('No image evidence found');
  await userEvent.click(screen.getByRole('button',{name:'Processing Log'}));await screen.findByRole('heading',{name:'Processing log'});
  await userEvent.click(screen.getByRole('button',{name:'Retry extraction'}));expect(mock.post).not.toHaveBeenCalled();
  await userEvent.click(screen.getByRole('button',{name:'Confirm'}));await waitFor(()=>expect(mock.post).toHaveBeenCalledWith('/api/documents/document-a/retry',{}));
  await userEvent.click(screen.getByText('Actions',{selector:'summary'}));
  mock.get.mockResolvedValueOnce({data:{data:{id:doc.id,type:'document',name:doc.original_filename,status:'failed',deleted_at:null,counts:{uploaded_documents:1},documents:[],retention_days:30,can_purge:true,busy:false,preview_token:'a'.repeat(64)}}});
  await userEvent.click(screen.getByRole('button',{name:'Move to Trash'}));expect(mock.delete).not.toHaveBeenCalled();
  expect(await screen.findByText(/recoverable Trash/)).toBeInTheDocument();
 });
 it('keeps upload and extraction failure distinct when queue dispatch fails',async()=>{
  mock.post.mockResolvedValue({data:{data:[doc]}});
  render(<DocumentUpload path="/upload" done={()=>{}}/>);
  fireEvent.change(screen.getByLabelText('Select report documents'),{target:{files:[new File(['a,b'],'data.csv')]}});
  await userEvent.click(screen.getByRole('button',{name:'Upload documents'}));
  expect(await screen.findByText(/File uploaded, but queue dispatch failed/)).toBeInTheDocument();
 });
 it('polls queued documents and displays the actual completed server status',async()=>{
  mock.get.mockResolvedValueOnce({data:page([{...doc,extraction_status:'queued',current_run:{...run,status:'queued',stage:'Waiting for worker',error_message:null}}])});
  mock.get.mockResolvedValue({data:page([{...doc,extraction_status:'extracted',current_run:{...run,status:'extracted',stage:'Extraction completed',error_message:null}}])});
  render(<MemoryRouter><DocumentList path="/api/projects/p/documents"/></MemoryRouter>);
  await screen.findByText('Queued');
  await screen.findByText('Extracted',{}, {timeout:6000});
  expect(mock.get).toHaveBeenCalledTimes(2);
 },10000);

});
