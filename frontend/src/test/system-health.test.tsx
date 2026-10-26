import {beforeEach,describe,it,expect,vi} from 'vitest';
import {render,screen,waitFor} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {SystemHealthPage} from '../pages/system-health';
const mock=vi.hoisted(()=>({get:vi.fn(),post:vi.fn(),user:{is_super_admin:true}}));
vi.mock('../auth',()=>({useAuth:()=>({user:mock.user})}));
vi.mock('../lib/api',async original=>({...await original<typeof import('../lib/api')>(),api:mock}));
const fixture={checked_at:'2026-10-19T06:00:00Z',runtime_checked_at:null,checks:{database:{status:'healthy',message:'Database connection observed.'},python:{status:'not_verified',message:'Run an explicit probe.'}},queues:{documents:{status:'not_verified',message:'No recent worker heartbeat.'}},counts:{failed_jobs:3},deployment:'not_verified'};
beforeEach(()=>{vi.resetAllMocks();mock.user={is_super_admin:true};mock.get.mockResolvedValue({data:{data:fixture}});mock.post.mockResolvedValue({data:{}});});
describe('protected operational health',()=>{
 it('denies non-super-admins without requesting global diagnostics',()=>{mock.user={is_super_admin:false};render(<SystemHealthPage/>);expect(screen.getByText(/require an active Super Admin/)).toBeInTheDocument();expect(mock.get).not.toHaveBeenCalled();});
 it('keeps unobserved workers and deployment explicitly unverified',async()=>{render(<SystemHealthPage/>);await screen.findByRole('heading',{name:'System Health'});expect(screen.getByText('No recent worker heartbeat.')).toBeInTheDocument();expect(screen.getByText('3')).toBeInTheDocument();expect(screen.getAllByText('Not Verified').length).toBeGreaterThan(0);expect(mock.post).not.toHaveBeenCalled();});
 it('runs only the explicitly requested runtime probe and refreshes',async()=>{render(<SystemHealthPage/>);await screen.findByRole('heading',{name:'System Health'});await userEvent.click(screen.getByRole('button',{name:'Run runtime probes'}));await waitFor(()=>expect(mock.post).toHaveBeenCalledWith('/api/system/health/probe'));await waitFor(()=>expect(mock.get.mock.calls.length).toBe(2));expect(mock.post).toHaveBeenCalledTimes(1);});
 it('reports a failed probe without fabricating successful state',async()=>{mock.post.mockRejectedValue(new Error('Probe unavailable'));render(<SystemHealthPage/>);await screen.findByRole('heading',{name:'System Health'});await userEvent.click(screen.getByRole('button',{name:'Run runtime probes'}));await screen.findByText('Something went wrong. Please try again.');expect(screen.getByText('No recent worker heartbeat.')).toBeInTheDocument();expect(screen.getByRole('button',{name:'Run runtime probes'})).toBeEnabled();});
});

