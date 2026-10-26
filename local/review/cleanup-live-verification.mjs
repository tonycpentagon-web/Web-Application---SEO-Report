import {chromium} from '../../backend/pdf-renderer/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';import assert from 'node:assert/strict';
const read=async p=>JSON.parse(await fs.readFile('local/review/'+p,'utf8'));
const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
const result={replacementRecordsCreated:false,api:{},pages:[],oldLinks:[],screenshots:[]};
try{
 const auth=await read('cleanup-verification-session.json');
 const staff=await browser.newContext({viewport:{width:1440,height:1000},extraHTTPHeaders:{Origin:'http://127.0.0.1:5173',Referer:'http://127.0.0.1:5173/'}});await staff.addCookies([auth.cookie]);
 const get=async path=>{const r=await staff.request.get('http://127.0.0.1:8000'+path);assert.equal(r.status(),200,path);return r.json();};
 const user=await get('/api/user');assert.equal(user.id,1);assert.equal(user.is_super_admin,true);result.existingAdministratorAuthenticated=true;
 const d=await get('/api/dashboard');for(const k of ['total_clients','total_projects','active_projects','generated_this_month','draft_reports','published_reports','recently_shared_reports','active_reports'])assert.equal(d[k],0,k);result.api.dashboard=d;
 for(const path of ['/api/clients','/api/projects','/api/monthly-reports?tab=active','/api/monthly-reports?tab=history','/api/monthly-reports?tab=trash',...['client','project','report','document'].map(t=>'/api/trash?type='+t)]){const j=await get(path);assert.equal(j.data.length,0,path);if(j.total!==undefined)assert.equal(j.total,0);result.api[path]={count:j.data.length,total:j.total??0};}
 const p=await staff.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
 for(const [path,name,empty] of [['/','dashboard','Total Clients'],['/clients','clients','No clients found'],['/projects','projects','A new home for your clients'],['/monthly-reports','reports','No active reports'],['/monthly-reports?tab=history','shared','No published or archived reports'],['/monthly-reports?tab=trash','report-trash','Trash is empty'],['/trash','trash','Trash is empty']]){
  await p.goto('http://127.0.0.1:5173'+path);if(empty)await p.getByText(empty,{exact:true}).first().waitFor();else await p.getByRole('heading',{name:/No .*projects/i}).waitFor();
  if(name==='dashboard'){await p.locator('.stat-card strong').first().waitFor();const values=await p.locator('.stat-card strong').allTextContents();assert.equal(values.length,5);assert(values.every(v=>v==='0'),JSON.stringify(values));result.visibleDashboardValues=values;}
  const screenshot='local/review/cleanup-zero-'+name+'.png';await p.screenshot({path:screenshot,fullPage:true});result.screenshots.push(screenshot);result.pages.push({path,empty:true});
 }
 assert.equal(errors.length,0,JSON.stringify(errors));result.noUncaughtStaffErrors=true;
 const anon=await browser.newContext();const pub=await anon.newPage();
 for(const link of await read('cleanup-old-link-tokens.json')){
  const statuses=[];for(const suffix of ['', '?pdf=1']){const r=await anon.request.get('http://127.0.0.1:8000/api/public-report'+suffix,{headers:{'X-Report-Token':link.token}});assert.equal(r.status(),404);statuses.push(r.status());}
  await pub.goto('http://127.0.0.1:5173/public-report#'+link.token);await pub.getByText('This report may have expired, been revoked or become unavailable.').waitFor();assert.equal(await pub.locator('section[id^="client-"]').count(),0);
  result.oldLinks.push({id:link.id,reportStatus:statuses[0],pdfStatus:statuses[1],browserUnavailable:true});
 }
 await pub.screenshot({path:'local/review/cleanup-old-public-link-unavailable.png',fullPage:true});result.screenshots.push('local/review/cleanup-old-public-link-unavailable.png');
 const privateResponse=await anon.request.get('http://127.0.0.1:8000/api/clients');assert.equal(privateResponse.status(),401);result.anonymousPrivateAccessStatus=privateResponse.status();
 const up=await anon.request.get('http://127.0.0.1:8000/up');assert.equal(up.status(),200);result.backendHealthStatus=up.status();
 await fs.writeFile('local/review/cleanup-live-verification-results.json',JSON.stringify(result,null,2));
 console.log(JSON.stringify({verified:true,dashboard:result.visibleDashboardValues,emptyPages:result.pages.length,oldPublicLinks404:result.oldLinks.length,oldPdfLinks404:result.oldLinks.length,administratorAuthenticated:true,noReplacementRecords:true}));
}finally{await browser.close();}
