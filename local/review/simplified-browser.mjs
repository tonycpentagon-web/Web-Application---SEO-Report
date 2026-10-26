import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import {chromium} from '../../backend/pdf-renderer/node_modules/playwright/index.mjs';
const root=process.cwd(),dir=path.join(root,'local/review/simplified-dist'),out=path.join(root,'docs/screenshots/simplified');
const fixture=JSON.parse(await fs.readFile(path.join(root,'local/review/simplified-browser-fixture.json'),'utf8'));
const mime={'.html':'text/html','.js':'text/javascript','.css':'text/css','.png':'image/png','.svg':'image/svg+xml'};
const server=http.createServer(async(req,res)=>{try{const pathname=new URL(req.url,'http://localhost').pathname;let file=path.resolve(dir,'.'+pathname);if(!file.startsWith(dir+path.sep))file=path.join(dir,'index.html');let body;try{body=await fs.readFile(file);}catch{file=path.join(dir,'index.html');body=await fs.readFile(file);}res.setHeader('Content-Type',mime[path.extname(file)]||'application/octet-stream');res.end(body);}catch(e){res.writeHead(500);res.end('Unavailable');}});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const origin='http://127.0.0.1:'+server.address().port;
let browser;const checks=[],errors=[];
try{
 browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true,args:['--no-first-run']});
 const context=await browser.newContext();
 let user={...fixture.user,is_super_admin:true,role:'super_admin',name:'Synthetic SEO Team'};
 const rid=fixture.report.id,pid=fixture.project.id,reportRoot=`/projects/${pid}/reports/${rid}`;
 const draftSharing={...fixture.sharing,status:'draft',version:1,links:[],exports:[]};
 await context.route('**/api/**',async route=>{
  const u=new URL(route.request().url());let data,status=200;
  if(u.pathname==='/api/user')data=user;
  else if(u.pathname==='/api/dashboard')data=fixture.overview;
  else if(u.pathname==='/api/monthly-reports')data=fixture.monthly;
  else if(u.pathname==='/api/public-report')data={data:fixture.snapshot,allow_download:true,pdf_status:'completed'};
  else if(u.pathname.endsWith('/sharing'))data={data:draftSharing};
  else if(u.pathname.endsWith('/share-report')){status=201;data={data:{url:origin+'/public-report#'+'a'.repeat(64)}};}
  else if(u.pathname.endsWith('/dashboard'))data={data:fixture.dashboard};
  else if(u.pathname.endsWith('/summary'))data={data:{...fixture.summary,can_edit:true}};
  else if(u.pathname.endsWith('/delivery'))data={data:{readiness:{commentary_approved:false,summary_revision:1},exports:[]}};
  else if(u.pathname.endsWith('/generation'))data={data:{generation:{id:'synthetic-generation',status:'completed',stage:'Your SEO report is ready. Preview, optionally edit, then Share Report.'},can_generate:true,classifications:[],documents:['ga4.pdf','search-console.csv','keywords.xlsx','activities.docx','events.png'].map((name,i)=>({id:'source-'+i,name,status:'extracted',stage:'Extraction complete'}))}};
  else if(u.pathname.includes('/lifecycle/'))data={data:{counts:{},documents:[]}};
  else if(u.pathname.endsWith('/reports/'+rid))data={data:{...fixture.report,status:'draft',can_share:true,can_upload_documents:true}};
  else {status=404;data={message:'Synthetic visual fixture unavailable for '+u.pathname};}
  await route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
 });
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 const views=[['overview','/','h1'],['generate',reportRoot+'/generate','h1'],['preview',reportRoot+'/preview','.report-cover'],['sharing',reportRoot+'/delivery','h1'],['public', '/public-report#'+'a'.repeat(64),'.report-cover'],['history','/monthly-reports?tab=history','table']];
 for(const [width,height,label] of [[1440,1000,'desktop'],[768,1024,'tablet'],[390,844,'mobile']]){
  await page.setViewportSize({width,height});
  for(const [name,url,selector] of views){
   await page.goto(origin+url);await page.waitForSelector(selector);await page.waitForTimeout(250);
   const size=await page.evaluate(()=>({viewport:innerWidth,width:document.documentElement.scrollWidth,title:document.querySelector('h1')?.textContent}));
   if(size.width>width+1)throw Error('Horizontal page overflow: '+label+' '+name+' '+JSON.stringify(size));
   checks.push({view:name,device:label,...size});
   await page.screenshot({path:path.join(out,label+'-'+name+'.png'),fullPage:name==='preview'||name==='public'});
  }
 }
 await page.setViewportSize({width:1440,height:1000});await page.goto(origin+reportRoot+'/delivery');
 const button=page.getByRole('button',{name:'Share Report',exact:true});await button.waitFor();
 if(!await button.isDisabled())throw Error('Uncertainty acknowledgment was not enforced');
 await page.getByRole('checkbox').check();await button.click();await page.getByLabel('Public report link').waitFor();
 await page.screenshot({path:path.join(out,'desktop-link-created.png'),fullPage:true});checks.push({action:'single acknowledgment and share link UI',passed:true});
 await page.goto(origin+'/public-report#'+'a'.repeat(64));await page.waitForSelector('.report-cover');
 if(await page.getByRole('link',{name:'My Reports / Reporting Months'}).count())throw Error('Public dashboard exposed account navigation');
 user={...fixture.user,is_super_admin:false,role:'seo_executive',name:'Synthetic SEO Executive'};
 for(const [width,height,label] of [[1440,1000,'desktop'],[390,844,'mobile']]){await page.setViewportSize({width,height});await page.goto(origin+'/');await page.waitForSelector('h1');await page.waitForTimeout(200);if(await page.getByRole('link',{name:'Administration',exact:true}).count())throw Error('Employee saw Administration navigation');await page.screenshot({path:path.join(out,label+'-employee-overview.png'),fullPage:true});checks.push({view:'employee-overview',device:label,adminNavigation:false});}
 await fs.writeFile(path.join(out,'results.json'),JSON.stringify({fixture:'Synthetic records extracted and published by the isolated Laravel integration test; browser API interception used for visual checks.',checks,errors},null,2));
 if(errors.length)throw Error(errors.join('; '));console.log(JSON.stringify({checks:checks.length,errors:errors.length,screenshots:out}));
}finally{await browser?.close();server.close();}
