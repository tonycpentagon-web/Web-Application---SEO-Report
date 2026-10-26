// Real HTTP integration check; never touches the configured application database.
import {spawn,spawnSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import {mkdtempSync,writeFileSync,rmSync,readFileSync,readdirSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,resolve,relative,isAbsolute} from 'node:path';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
import net from 'node:net';
const root=resolve(fileURLToPath(new URL('..',import.meta.url)));
const backend=join(root,'backend'),php=process.env.PHP_BINARY||join(root,'.tools','php','php.exe');
const temp=mkdtempSync(join(tmpdir(),'insight-smoke-')),db=join(temp,'database.sqlite');
writeFileSync(db,'');
const password='Smoke!'+randomBytes(20).toString('hex');
const env={...process.env,APP_ENV:'local',APP_DEBUG:'false',APP_KEY:'base64:'+randomBytes(32).toString('base64'),DB_CONNECTION:'sqlite',DB_DATABASE:db,DB_URL:'',LOCAL_PRIVATE_ROOT:join(temp,'private'),QUEUE_CONNECTION:'database',SESSION_DRIVER:'database',SESSION_ENCRYPT:'true',CACHE_STORE:'array',MAIL_MAILER:'array',INSIGHT_SMOKE:'1',SMOKE_PASSWORD:password,FRONTEND_URL:'http://127.0.0.1:5173',SANCTUM_STATEFUL_DOMAINS:'127.0.0.1:5173'};
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
  if(body && !(body instanceof FormData))headers['Content-Type']='application/json';
  if(csrf&&jar.has('XSRF-TOKEN'))headers['X-XSRF-TOKEN']=decodeURIComponent(jar.get('XSRF-TOKEN'));
  const response=await fetch(base+path,{method,headers,body:body instanceof FormData?body:body?JSON.stringify(body):undefined});
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
 // Stage 3: actual multipart HTTP upload, stopped-worker state, failure/retry, real queue worker.
 const documentUrl='/api/projects/'+id+'/reports/'+period.id+'/documents';
 const files=readdirSync(join(root,'reference-files')).filter(n=>/\.(pdf|png)$/i.test(n));
 assert.equal(files.length,5);
 const payload=new FormData();for(const name of files)payload.append('files[]',new Blob([readFileSync(join(root,'reference-files',name))]),name);
 const upload=await request(documentUrl,'POST',payload);assert.equal(upload.status,201,await upload.clone().text());
 const documents=(await upload.json()).data;assert.equal(documents.length,5);assert(documents.every(d=>d.extraction_status==='queued'));
 const duplicate=new FormData();duplicate.append('files[]',new Blob([readFileSync(join(root,'reference-files',files[0]))]),files[0]);
 assert.equal((await request(documentUrl,'POST',duplicate)).status,422);
 const bad=new FormData();bad.append('files[]',new Blob(['MZ executable']),'bad.pdf');assert.equal((await request(documentUrl,'POST',bad)).status,422);
 const failing=new FormData();failing.append('files[]',new Blob(['Keyword,Rank\nPharmacy,3']),'retry.csv');
 const retryDocument=(await (await request(documentUrl,'POST',failing)).json()).data[0];
 async function worker(extra={},args=[]){
  return await new Promise((ok,fail)=>{
   const job=spawn(php,['artisan','queue:work','--queue=documents','--stop-when-empty','--tries=1','--timeout=960',...args],{cwd:backend,env:{...env,...extra},windowsHide:true,stdio:['ignore','pipe','pipe']});
   let logs='';job.stdout.on('data',v=>{logs+=v;});job.stderr.on('data',v=>{logs+=v;});job.on('error',fail);job.on('exit',code=>code===0?ok(logs):fail(Error('Worker failed: '+logs)));
  });
 }
 // A missing interpreter fails one real queued job without pretending it was extracted.
 await worker({DOCUMENT_PYTHON:'missing-python-executable'},['--max-jobs=1']);
 const failed=(await (await request('/api/documents/'+documents[0].id)).json()).data;
 assert.equal(failed.extraction_status,'failed');assert.equal(failed.can_retry,true);
 assert.equal((await request('/api/documents/'+failed.id+'/retry','POST',{})).status,200);
 console.log('Five reference files uploaded; missing-Python failure and retry verified. Processing real queued documents with local OCR.');
 await worker();
 const summaries=[];
 for(const d of documents){
  const details=(await (await request('/api/documents/'+d.id)).json()).data;
  assert(['extracted','needs_review','partially_extracted'].includes(details.extraction_status),JSON.stringify(details));
  const rawResponse=await request('/api/documents/'+d.id+'/raw');assert.equal(rawResponse.status,200);const raw=await rawResponse.json();
  assert.equal(raw.metadata.sha256,d.checksum);assert.equal(raw.metadata.page_count??1,raw.units.length);
  assert.equal((await request('/api/documents/'+d.id+'/download')).status,200);
  if(d.extension==='png'){assert(raw.statistics.ocr_used);for(const term of ['contact_us','37','form_submission','30','97'])assert(raw.full_text.includes(term));}
  summaries.push({filename:d.original_filename,status:details.extraction_status,pages:raw.metadata.page_count??null,statistics:raw.statistics,warnings:raw.warnings,errors:raw.errors});
 }
 const after=(await (await request('/api/projects/'+id+'/reports/'+period.id)).json()).data;assert.equal(after.status,'draft');assert.equal(after.version,1);
 const removed=await request('/api/documents/'+retryDocument.id,'DELETE',{});assert.equal(removed.status,200);assert.equal((await request('/api/documents/'+retryDocument.id+'/download')).status,404);
 writeFileSync(join(root,'.tools','stage3-http-result.json'),JSON.stringify({checks:'HTTP multipart, CSRF, queue worker, missing Python, retry, duplicate, invalid upload, private originals, OCR, removal and report preservation passed',documents:summaries},null,2));
 console.log('Stage 3 HTTP upload and real queue extraction passed for all five references.');
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
 const cleanupTarget=relative(tmpdir(),temp);
 assert(cleanupTarget && !cleanupTarget.startsWith('..') && !isAbsolute(cleanupTarget),'Temporary cleanup target must stay inside the OS temporary directory');
 rmSync(temp,{recursive:true,force:true});
}
