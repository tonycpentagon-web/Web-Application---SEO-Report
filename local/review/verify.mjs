import {chromium} from '../../backend/pdf-renderer/node_modules/playwright/index.mjs';
import fs from 'node:fs';import assert from 'node:assert/strict';
const dir='.tools/https-review',base=fs.readFileSync(dir+'/url.txt','utf8').trim(),s=JSON.parse(fs.readFileSync(dir+'/snapshot.json')),u=JSON.parse(fs.readFileSync(dir+'/credentials.json'));
const root=`/api/projects/${s.project}/reports/${s.report}`,result={base,transport:base.startsWith('https:')?'external HTTPS':'local HTTP only',checks:[],errors:[],success:false};
let browser,page;
try{
 browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
 const anonymous=await browser.newContext();
 const ap=await anonymous.newPage();await ap.goto(base+root.replace('/api','')+'/preview');await ap.getByRole('button',{name:'Sign in',exact:true}).waitFor();
 for(const p of [root+'/dashboard',root+'/delivery',...s.assets.slice(0,1)]){const r=await anonymous.request.get(base+p);assert.equal(r.status(),401,p);}
 for(const p of ['/.env','/backend/.env','/storage/logs/laravel.log','/api/system/health','/api/administration','/@vite/client','/api/documents/anything/download','/api/public-report','/api/documents/anything/raw'])assert.equal((await anonymous.request.get(base+p)).status(),404,p);
 const csrfMissing=await anonymous.request.post(base+'/login',{headers:{Origin:base},data:{email:u.email,password:u.password}});assert.equal(csrfMissing.status(),419);
 result.checks.push('Fresh guest session cannot read Draft data or evidence images; secrets, originals, internal endpoints, public sharing and CSRF-less login blocked');
 const context=await browser.newContext({viewport:{width:1440,height:1000}});page=await context.newPage();page.on('pageerror',e=>result.errors.push(e.message));
 await page.goto(base+'/login');await page.getByLabel('Email address',{exact:true}).fill(u.email);await page.getByLabel('Password',{exact:true}).fill(u.password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL(base+'/');
 const cookies=await context.cookies();const session=cookies.find(c=>c.name==='__Host-pentagon_review');assert.ok(session);assert.equal(session.secure,true);assert.equal(session.httpOnly,true);assert.equal(session.sameSite,'Lax');assert.equal(session.path,'/');
 result.checks.push('Real login through React succeeds in a new browser context; host-only Secure HttpOnly SameSite=Lax session');
 await page.goto(base+root.replace('/api','')+'/preview');await page.getByRole('heading',{name:/SEO Performance Report/}).waitFor();await page.locator('#report-ai').scrollIntoViewIfNeeded();await page.waitForFunction(()=>document.querySelectorAll('.studio-ai-card img').length>0&&[...document.querySelectorAll('.studio-ai-card img')].every(i=>i.complete&&i.naturalWidth>0));
 result.images=await page.locator('.studio-ai-card img').count();result.charts=await page.locator('.recharts-surface').count();assert.ok(result.charts>0);assert.ok(result.images>0);await page.screenshot({path:dir+'/report-review.png',fullPage:true});
 for(const p of ['/api/administration','/api/team','/api/documents/anything/download','/api/documents/anything/preview','/api/documents/anything/extraction'])assert.equal((await context.request.get(base+p)).status(),404,p);
 for(const p of [root+'/delivery/publish',root+'/delivery/shares',root+'/summary/generate','/api/lifecycle/report/'+s.report])assert.equal((await context.request.post(base+p,{headers:{Origin:base},data:{}})).status(),404,p);
 const deniedExport=await page.evaluate(async(root)=>{const token=decodeURIComponent(document.cookie.split('; ').find(v=>v.startsWith('XSRF-TOKEN='))?.slice(11)||'');const response=await fetch(root+'/delivery/exports',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json','X-XSRF-TOKEN':token},body:JSON.stringify({type:'draft',profile:'internal_audit',sections:['sources']})});return response.status;},root);assert.equal(deniedExport,422);
 result.checks.push('Interactive Draft renders charts and report evidence images; original documents and publication mutations blocked even after login');
 await page.getByRole('button',{name:'Export Draft PDF',exact:true}).click();await page.getByRole('button',{name:'Download ready PDF',exact:true}).waitFor({timeout:240000});
 const downloadEvent=page.waitForEvent('download');await page.getByRole('button',{name:'Download ready PDF',exact:true}).click();const download=await downloadEvent;await download.saveAs(dir+'/review-draft.pdf');assert.equal(fs.readFileSync(dir+'/review-draft.pdf').subarray(0,5).toString(),'%PDF-');result.pdfBytes=fs.statSync(dir+'/review-draft.pdf').size;
 result.checks.push('Isolated exports queue generates and browser downloads a valid private Draft PDF');
 await anonymous.close();await context.close();assert.equal(result.errors.length,0);result.success=true;
}catch(e){result.failure=e.message;if(page)await page.screenshot({path:dir+'/verification-failure.png',fullPage:true}).catch(()=>{});}finally{fs.writeFileSync(dir+'/verification.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result,null,2));if(browser)await browser.close();if(!result.success)process.exitCode=1;}
