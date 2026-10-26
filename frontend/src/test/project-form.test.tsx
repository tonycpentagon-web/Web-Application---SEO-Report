import {beforeEach,expect,it,vi} from 'vitest';
import {render,screen,waitFor} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {MemoryRouter,Routes,Route} from 'react-router-dom';
import {ProjectForm} from '../pages/project-form';
import {ReportCreatePage} from '../pages/reports';
import {ProjectDetails} from '../pages/project-details';
import type {Project} from '../types';
const mock=vi.hoisted(()=>({get:vi.fn(),post:vi.fn(),put:vi.fn()}));
vi.mock('../auth',()=>({useAuth:()=>({user:{id:1,role:'admin'}})}));
vi.mock('../lib/api',async original=>({...await original<typeof import('../lib/api')>(),api:mock}));
const project:Project={id:'p',client_id:'c',name:'Existing SEO',client_name:'Client',website:'https://example.test',description:'Existing description',status:'active',reporting_frequency:'monthly',permissions:{manage:true,work_reports:true,view_internal:true},created_at:'2026-01-01',updated_at:'2026-01-01'};
beforeEach(()=>{
 vi.resetAllMocks();mock.get.mockResolvedValue({data:{data:[{id:'c',name:'Client',status:'active',permissions:{manage:true}}],last_page:1}});
 mock.post.mockResolvedValue({data:{data:{id:'r'}}});mock.put.mockResolvedValue({data:{data:{id:'p'}}});
});
it('creates a project with exactly five fields, monthly frequency and no start date in the request',async()=>{
 const {container}=render(<MemoryRouter><ProjectForm/></MemoryRouter>);
 await screen.findByLabelText('Project name');
 expect(screen.queryByLabelText(/start date/i)).not.toBeInTheDocument();
 expect([...container.querySelectorAll('form [name]')].map(e=>e.getAttribute('name'))).toEqual(['name','client_id','website','description','reporting_frequency']);
 expect(screen.getByLabelText('Reporting frequency')).toHaveValue('monthly');
 await userEvent.type(screen.getByLabelText('Project name'),'New SEO');await userEvent.selectOptions(screen.getByLabelText('Client'),'c');
 await userEvent.type(screen.getByLabelText('Website'),'https://example.test');
 await userEvent.click(screen.getByRole('button',{name:'Create project'}));
 await waitFor(()=>expect(mock.post).toHaveBeenCalledWith('/api/projects',{name:'New SEO',client_id:'c',website:'https://example.test',description:'',reporting_frequency:'monthly'}));
});
it('edits a historical project without submitting or displaying its stored start date',async()=>{
 const historical={...project,start_date:'2020-02-17'};
 render(<MemoryRouter><ProjectForm project={historical}/></MemoryRouter>);await screen.findByLabelText('Project name');
 expect(screen.queryByLabelText(/start date/i)).not.toBeInTheDocument();
 await userEvent.clear(screen.getByLabelText('Project name'));await userEvent.type(screen.getByLabelText('Project name'),'Updated SEO');
 await userEvent.click(screen.getByRole('button',{name:'Save changes'}));
 await waitFor(()=>expect(mock.put).toHaveBeenCalledWith('/api/projects/p',expect.objectContaining({name:'Updated SEO',reporting_frequency:'monthly'})));
 expect(mock.put.mock.calls[0][1]).not.toHaveProperty('start_date');
});
it('keeps reporting month required and separate on the monthly report form',async()=>{
 mock.get.mockResolvedValue({data:{data:project}});
 render(<MemoryRouter initialEntries={['/projects/p/reports/new']}><Routes><Route path="/projects/:id/reports/new" element={<ReportCreatePage/>}/></Routes></MemoryRouter>);
 expect(await screen.findByLabelText('Reporting month')).toBeRequired();expect(screen.getByLabelText('Reporting month')).toHaveAttribute('type','month');
 expect(screen.queryByLabelText('Start date')).not.toBeInTheDocument();
});

it('removes the historical start-date display from project details',async()=>{
 mock.get.mockResolvedValue({data:{data:{...project,start_date:'2020-02-17'}}});
 render(<MemoryRouter initialEntries={['/projects/p']}><Routes><Route path="/projects/:id" element={<ProjectDetails/>}/></Routes></MemoryRouter>);
 await screen.findByRole('heading',{name:'Existing SEO'});
 expect(screen.queryByText(/start date/i)).not.toBeInTheDocument();expect(screen.queryByText('2020-02-17')).not.toBeInTheDocument();
});
