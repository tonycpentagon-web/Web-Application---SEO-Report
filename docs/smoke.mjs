// Real HTTP integration check; never touches the configured application database.
import {spawn,spawnSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import {mkdtempSync,writeFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
import net from 'node:net';
const root=resolve(fileURLToPath(new URL('..',import.meta.url)));
const backend=join(root,'backend'),php=process.env.PHP_BINARY||join(root,'.tools','php','php.exe');
const temp=mkdtempSync(join(tmpdir(),'insight-smoke-')),db=join(temp,'database.sqlite');
writeFileSync(db,'');
const password='Smoke!'+randomBytes(20).toString('hex');
const env={...process.env,APP_ENV:'local',APP_DEBUG:'false',APP_KEY:'base64:'+randomBytes(32).toString('base64'),DB_CONNECTION:'sqlite',DB_DATABASE:db,DB_URL:'',SESSION_DRIVER:'database',SESSION_ENCRYPT:'true',CACHE_STORE:'array',MAIL_MAILER:'array',INSIGHT_SMOKE:'1',SMOKE_PASSWORD:password,FRONTEND_URL:'http://127.0.0.1:5173',SANCTUM_STATEFUL_DOMAINS:'127.0.0.1:5173'};
function command(args){const result=spawnSync(php,args,{cwd:backend,env,encoding:'utf8'});if(result.status!==0)throw Error('PHP setup failed: '+result.stderr+' '+result.stdout);}
const port=await new Promise((ok,fail)=>{const server=net.createServer();server.on('error',fail);server.listen(0,'127.0.0.1',()=>{const value=server.address().port;server.close(()=>ok(value));});});
let child;let serverError='';
try{
 command(['artisan','migrate','--force']);command(['tests/provision-smoke.php']);
 child=spawn(php,['-S','127.0.0.1:'+port,'-t','.','../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],{cwd:join(backend,'public'),env,windowsHide:true,stdio:['ignore','ignore','pipe']});
 child.stderr.on('data',s=>{serverError+=s;});child.on('error',e=>{serverError+=e.message;});
 const base='http://127.0.0.1:'+port;
 let ready=false;for(let attempt=0;attempt<40;attempt++){try{await fetch(base+'/up');ready=true;break;}catch{await new Promise(r=>setTimeout(r,150));}}
 if(!ready)throw Error('HTTP server unavailable: '+serverError);
 const jar=new Map();
 async function request(path,method='GET',body,csrf=true){
  const headers={Accept:'application/json',Origin:env.FRONTEND_URL,Referer:env.FRONTEND_URL+'/',Cookie:[...jar].map(([k,v])=>k+'='+v).join('; ')};
  if(body)headers['Content-Type']='application/json';
  if(csrf&&jar.has('XSRF-TOKEN'))headers['X-XSRF-TOKEN']=decodeURIComponent(jar.get('XSRF-TOKEN'));
  const response=await fetch(base+path,{method,headers,body:body?JSON.stringify(body):undefined});
  for(const cookie of response.headers.getSetCookie()){const pair=cookie.split(';')[0],index=pair.indexOf('=');jar.set(pair.slice(0,index),pair.slice(index+1));}
  return response;
 }
 assert.equal((await request('/api/user')).status,401);
 assert.equal((await request('/login','POST',{email:'smoke@example.test',password},false)).status,419);
 assert.equal((await request('/sanctum/csrf-cookie')).status,204);
 assert(jar.has('XSRF-TOKEN'));
 const login=await request('/login','POST',{email:'smoke@example.test',password});
 assert.equal(login.status,200);
 const identity=(await login.json()).user;
 assert.equal(identity.is_super_admin,true);
 assert(identity.last_login_at);
 assert.equal(identity.password,undefined);
 assert.equal((await request('/api/user')).status,200);
 const project=await request('/api/projects','POST',{name:'HTTP smoke project',client_name:'Smoke Client',website:'https://example.test'});
 assert.equal(project.status,201);
 const id=(await project.json()).data.id;
 assert.equal((await request('/api/projects/'+id+'/dashboard')).status,200);

 const clientResponse=await request('/api/clients','POST',{name:'HTTP client',website:'https://example.test',brand_color:'#087e98'});
 assert.equal(clientResponse.status,201);const clientId=(await clientResponse.json()).data.id;
 const periodResponse=await request('/api/projects/'+id+'/reports','POST',{title:'August 2026 SEO Report',reporting_month:'2026-08',kind:'monthly'});
 assert.equal(periodResponse.status,201);const period=(await periodResponse.json()).data;
 assert.equal(period.start_date,'2026-08-01');assert.equal(period.end_date,'2026-08-31');assert.equal(period.has_data,false);
 assert.equal((await request('/api/projects/'+id+'/reports','POST',{title:'Duplicate',reporting_month:'2026-08',kind:'monthly'})).status,422);
 assert.equal((await request('/api/projects/'+id+'/reports/'+period.id+'/status','PATCH',{status:'needs_review',version:1})).status,200);
 const teamResponse=await request('/api/team','POST',{name:'HTTP Executive',email:'http-executive@example.test',role:'seo_executive'});
 assert.equal(teamResponse.status,201);const member=(await teamResponse.json()).data;assert.equal(member.password,undefined);
 assert.equal((await request('/api/projects/'+id+'/team','PUT',{manager_id:null,member_ids:[member.id]})).status,200);
 assert.equal((await request('/api/clients/'+clientId,'PUT',{name:'HTTP client',status:'archived'})).status,200);
 assert.equal((await request('/api/clients/'+clientId,'PUT',{name:'HTTP client',status:'active'})).status,200);

 assert.equal((await request('/logout','POST',{})).status,200);
 assert.equal((await request('/api/user')).status,401);
 command(['tests/provision-smoke.php','enable']);
 await request('/sanctum/csrf-cookie');
 assert.equal((await request('/login','POST',{email:'smoke@example.test',password})).status,200);
 command(['tests/provision-smoke.php','disable']);
 assert.equal((await request('/api/user')).status,401);
 assert.equal((await request('/api/projects')).status,401);
 await request('/sanctum/csrf-cookie');
 assert.equal((await request('/login','POST',{email:'smoke@example.test',password})).status,422);
 console.log('HTTP smoke passed (including Stage 2 clients, reporting periods, teams, Super Admin verification and disabled-account session rejection): CSRF enforcement, cookies, login, authenticated API, project creation/dashboard, logout.');
}finally{
 if(child&&child.exitCode===null){child.kill();await new Promise(r=>child.once('exit',r));}
 rmSync(temp,{recursive:true,force:true});
}
