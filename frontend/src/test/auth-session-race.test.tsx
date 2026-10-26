import {StrictMode} from 'react';
import {beforeEach,it,expect,vi} from 'vitest';
import {render,screen,waitFor} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {AuthProvider,useAuth} from '../auth';
const mock=vi.hoisted(()=>({get:vi.fn(),post:vi.fn(),csrf:vi.fn()}));
vi.mock('../lib/api',()=>({api:{get:mock.get,post:mock.post},csrf:mock.csrf,errorMessage:()=>''}));
beforeEach(()=>{vi.resetAllMocks();});
function LoginProbe(){const a=useAuth();return <><button onClick={()=>void a.login('employee@example.test','Test-only9!password')}>Login</button><p>{a.user?.name||'Guest'}</p></>;}
it('serializes initial session discovery and CSRF login even through StrictMode effect replay',async()=>{let rejectInitial:(reason:unknown)=>void=()=>{};mock.get.mockReturnValue(new Promise((_,reject)=>{rejectInitial=reject;}));mock.csrf.mockResolvedValue(undefined);mock.post.mockResolvedValue({data:{user:{id:1,name:'Employee',role:'seo_executive'}}});render(<StrictMode><AuthProvider><LoginProbe/></AuthProvider></StrictMode>);await userEvent.click(screen.getByRole('button',{name:'Login'}));expect(mock.get).toHaveBeenCalledTimes(1);expect(mock.csrf).not.toHaveBeenCalled();expect(mock.post).not.toHaveBeenCalled();rejectInitial({response:{status:401}});await screen.findByText('Employee');await waitFor(()=>expect(mock.post).toHaveBeenCalledTimes(1));expect(mock.csrf).toHaveBeenCalledTimes(1);});

it('opens public reports without probing staff login and discovers the session when returning to the workspace',async()=>{mock.get.mockResolvedValue({data:{id:1,name:'Employee'}});const view=render(<AuthProvider anonymous><LoginProbe/></AuthProvider>);expect(mock.get).not.toHaveBeenCalled();expect(screen.getByText('Guest')).toBeInTheDocument();view.rerender(<AuthProvider anonymous={false}><LoginProbe/></AuthProvider>);await screen.findByText('Employee');expect(mock.get).toHaveBeenCalledTimes(1);});
