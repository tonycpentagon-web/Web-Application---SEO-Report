import {expect,it,vi} from 'vitest';
import {render,screen,within} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import {PublishedDashboard,type PublishedSnapshot} from '../components/published-dashboard';
vi.mock('recharts',()=>{const Box=({children}:{children?:React.ReactNode})=><div>{children}</div>;return {ResponsiveContainer:Box,BarChart:Box,Bar:Box,PieChart:Box,Pie:Box,XAxis:()=>null,YAxis:()=>null,CartesianGrid:()=>null,Tooltip:()=>null,Cell:()=>null};});
function fixture():PublishedSnapshot{return {draft:false,publication_version:1,generated_at:'2026-10-22',report:{title:'August 2026',start_date:'2026-08-01',end_date:'2026-08-31'},project:{name:'SEO',client_name:'Client',website:'https://example.test',logo:null},summary:{},methodology:'Source-period totals.',kpis:[{metric_key:'events.reported_total',label:'Reported total',value:97,unit:'count',provisional:true},{metric_key:'ga4.total_users',label:'Total users',value:4515,unit:'count'},{metric_key:'ga4.new_users',label:'New users',value:4383,unit:'count'},{metric_key:'ga4.views',label:'Views',value:6257,unit:'count'}],records:[['contact_us',37],['form_submission',30],['Phone_Click',17],['schedule_compression_clicks',9],['Email_Click',4]].map(([name,count],i)=>({id:String(i),kind:'event_metrics',section_key:'key_events',data:{event_name:name,count},provisional:true,image:null,sources:[]}))};}
it('shows source-backed conversion cards first and traffic KPI cards only in website traffic',()=>{
 render(<MemoryRouter><PublishedDashboard data={fixture()}/></MemoryRouter>);
 const first=document.getElementById('client-overview')!;expect(within(first).getByRole('heading',{name:'Conversion events'})).toBeInTheDocument();
 expect(first.querySelector('.report-section-heading p')).toHaveTextContent('01 \u00b7 CONVERSIONS');
 const cards=[...first.querySelectorAll('.studio-kpi>strong')].map(e=>e.textContent);expect(cards).toEqual(['97','37','30','17','9','4']);
 for(const value of ['4,515','4,383','6,257']){expect(within(first).queryByText(value)).not.toBeInTheDocument();expect(screen.getAllByText(value)).toHaveLength(1);}
 expect(first.querySelector('tbody')?.children).toHaveLength(5);expect(first.querySelector('tfoot')).toHaveTextContent('Grand total97');
 expect(document.getElementById('client-events')).toBeNull();expect(screen.getByRole('navigation',{name:'Report sections'}).querySelectorAll('a')).toHaveLength(6);
});
it('does not invent a grand total from event-category counts',()=>{
 const data=fixture();data.kpis=data.kpis.filter(k=>k.metric_key!=='events.reported_total');
 render(<MemoryRouter><PublishedDashboard data={data}/></MemoryRouter>);
 const first=document.getElementById('client-overview')!;expect(first.querySelector('.studio-kpi>strong')).toHaveTextContent('Not reported');expect(first.querySelector('tfoot')).toHaveTextContent('Not reported');
});
