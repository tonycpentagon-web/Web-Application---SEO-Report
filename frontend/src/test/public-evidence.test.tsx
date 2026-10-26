import {PublishedDashboard} from '../components/published-dashboard';
import {it,expect,vi} from 'vitest';
import {render,screen} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {MemoryRouter} from 'react-router-dom';
import {ObservationTable} from '../components/dashboard/table';
import {ReportPresentation} from '../components/dashboard/presentation';
import {ReportChart} from '../components/dashboard/charts';
vi.mock('recharts',()=>{const Box=({children}:{children?:React.ReactNode})=><div>{children}</div>;return {ResponsiveContainer:Box,BarChart:Box,Bar:Box,PieChart:Box,Pie:Box,XAxis:()=>null,YAxis:()=>null,CartesianGrid:()=>null,Tooltip:()=>null,Cell:()=>null};});
it('shows public evidence without private document links and preserves page ranking labels',async()=>{
 const row={id:'one',kind:'keyword_rankings',section_key:'keywords',data:{keyword:'Fertility Pharmacy',ranking_label:'1st Page',category:'primary'},revision:0,review_status:'',human_verified:false,provisional:true,issues:[],sources:[],public_sources:[{document:'Original keywords.pdf',location:{page:2,start_line:7},declared_month:'2026-08'}]};
 render(<MemoryRouter><ReportPresentation value="client"><ObservationTable title="Keywords" kind="keyword_rankings" root="" rows={[row]}/></ReportPresentation></MemoryRouter>);
 expect(screen.getByText('1st Page')).toBeInTheDocument();await userEvent.click(screen.getByRole('button',{name:'Expand Fertility Pharmacy'}));expect(screen.getByText('Original keywords.pdf')).toBeVisible();expect(screen.getByText(/Page: 2/)).toBeVisible();expect(screen.getByText('Declared report month: 2026-08')).toBeVisible();expect(screen.queryByRole('link')).not.toBeInTheDocument();
});
it('calculates donut percentages from its own counts',()=>{
 render(<MemoryRouter><ReportPresentation value="client"><ReportChart title="Clicks" description="Reported clicks" rows={[]} root="" donut unit="clicks" points={[{name:'Mobile',value:75,provisional:false},{name:'Desktop',value:25,provisional:false}]}/></ReportPresentation></MemoryRouter>);
 expect(screen.getByText('75%')).toBeInTheDocument();expect(screen.getByText('25%')).toBeInTheDocument();
});

it('preserves the secret share fragment when navigating report sections',async()=>{
 const token='a'.repeat(64);window.history.replaceState(null,'','/public-report#'+token);
 Object.defineProperty(window,'matchMedia',{configurable:true,value:()=>({matches:true})});const scroll=vi.fn();Element.prototype.scrollIntoView=scroll;
 render(<MemoryRouter><PublishedDashboard data={{draft:false,publication_version:1,report:{title:'August 2026',start_date:'2026-08-01',end_date:'2026-08-31'},project:{client_name:'Example',name:'SEO',website:'https://example.test',logo:null},summary:{},kpis:[],records:[],methodology:'Source totals only.',generated_at:'2026-10-22'}}/></MemoryRouter>);
 await userEvent.click(screen.getByRole('link',{name:'Keyword Rankings'}));expect(window.location.hash).toBe('#'+token);expect(scroll).toHaveBeenCalled();
});
