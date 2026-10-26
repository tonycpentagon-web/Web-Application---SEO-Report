import {beforeEach,expect,it,vi} from 'vitest';
import {render,screen,waitFor} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {MemoryRouter,Routes,Route} from 'react-router-dom';
import {ReportDraftPreview} from '../pages/report-draft-preview';
import type {PublishedSnapshot} from '../components/published-dashboard';
const mock=vi.hoisted(()=>({get:vi.fn(),post:vi.fn()}));
vi.mock('../lib/api',async original=>({...await original<typeof import('../lib/api')>(),api:mock}));
vi.mock('recharts',()=>{const Box=({children}:{children?:React.ReactNode})=><div>{children}</div>;return {ResponsiveContainer:Box,BarChart:Box,Bar:Box,PieChart:Box,Pie:Box,XAxis:()=>null,YAxis:()=>null,CartesianGrid:()=>null,Tooltip:()=>null,Cell:()=>null};});
let snapshot:PublishedSnapshot;
function mount(){return render(<MemoryRouter initialEntries={['/projects/p/reports/r/preview']}><Routes><Route path="/projects/:id/reports/:reportId/preview" element={<ReportDraftPreview/>}/></Routes></MemoryRouter>);}
beforeEach(()=>{vi.resetAllMocks();snapshot={draft:true,publication_version:0,report:{title:'September 2026',start_date:'2026-09-01',end_date:'2026-09-30'},project:{name:'Project',client_name:'Example',website:'https://example.test',logo:null},kpis:[{metric_key:'ga4.total_users',value:800,unit:'count',label:'Total users'}],records:[],summary:{website_traffic:'Current saved commentary.'},methodology:'Source-period totals.',generated_at:'2026-10-22'};mock.get.mockImplementation((path:string)=>Promise.resolve({data:{data:path.endsWith('/delivery')?{exports:[]}:snapshot}}));mock.post.mockResolvedValue({data:{data:{id:'export-a'}}});});
it('uses the shared six-section report with private actions and no standalone summary or sources section',async()=>{mount();await screen.findByRole('heading',{name:/SEO Performance Report/});expect(screen.getByText('Private Draft Preview')).toBeInTheDocument();expect(screen.getByRole('link',{name:'Share Report'})).toHaveAttribute('href','/projects/p/reports/r/delivery');expect(screen.getByRole('navigation',{name:'Report sections'}).querySelectorAll('a')).toHaveLength(6);expect(screen.queryByRole('heading',{name:'Report summary'})).not.toBeInTheDocument();expect(screen.queryByRole('heading',{name:'Sources & reporting notes'})).not.toBeInTheDocument();});
it('shows current saved commentary in context',async()=>{mount();await screen.findAllByText('Current saved commentary.');expect(screen.getAllByText('SOURCE COMMENTARY').length).toBeGreaterThan(0);});
it('shows a meaningful processing error instead of hiding the keyword section',async()=>{snapshot.processing_issues=[{section:'keywords',document:'keywords.pdf',message:'Keyword source detected, but no usable keyword rows were generated. Inspect the source table and regenerate the report.'}];mount();await screen.findByRole('heading',{name:'Keyword rankings'});expect(screen.getByText(/Inspect the source table and regenerate/)).toBeInTheDocument();});
it('requests only a private client-style export',async()=>{mount();await userEvent.click(await screen.findByRole('button',{name:'Export Draft PDF'}));await waitFor(()=>expect(mock.post).toHaveBeenCalledWith('/api/projects/p/reports/r/delivery/exports',expect.objectContaining({type:'draft',profile:'client_preview',sections:expect.arrayContaining(['keywords','ai_visibility'])})));expect(screen.getByText(/Draft PDF queued/)).toBeInTheDocument();});

it('uses actual partial source dates on a clean cover without coverage panels',async()=>{
 snapshot.source_periods=[{document:'Analytics.pdf',status:'partial',ranges:[{start:'2026-09-01',end:'2026-09-29'}],uncovered_dates:['2026-09-30']}];
 mount();await screen.findByRole('heading',{name:/SEO Performance Report/});
 expect(screen.getByText('1 Sept 2026 – 29 Sept 2026')).toBeInTheDocument();
 expect(screen.queryByText('Source coverage')).not.toBeInTheDocument();expect(screen.queryByText('Report coverage, limitations and source evidence')).not.toBeInTheDocument();
 expect(document.getElementById('report-details')).toBeNull();expect(document.querySelector('a[href="#report-details"]')).toBeNull();
});
it('shows actual cross-month periods in the cover metadata without the extra panels',async()=>{
 snapshot.source_periods=[{document:'Analytics.pdf',status:'mismatch',coverage_accepted:true,ranges:[{start:'2026-08-29',end:'2026-09-27'}]}];
 mount();await screen.findByRole('heading',{name:/SEO Performance Report/});
 expect(screen.getByText('29 Aug 2026 – 27 Sept 2026')).toBeInTheDocument();expect(screen.queryByText('Cross-month source coverage')).not.toBeInTheDocument();
});
it('does not present uncertain dates as confirmed calendar-month coverage',async()=>{
 snapshot.source_periods=[{document:'Screenshot.png',status:'uncertain',ranges:[]}];
 mount();await screen.findByRole('heading',{name:/SEO Performance Report/});
 expect(screen.getByText('Reporting month (source dates unconfirmed)')).toBeInTheDocument();
});
