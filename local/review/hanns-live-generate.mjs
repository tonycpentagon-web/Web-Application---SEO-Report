import {chromium} from '../../backend/pdf-renderer/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';
const info=JSON.parse(await fs.readFile('local/review/hanns-live-session.json','utf8'));
const origin='http://127.0.0.1:5173',root=`/projects/${info.project_id}/reports/${info.report_id}`;
const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
const errors=[];
try{
 const staff=await browser.newContext({viewport:{width:1440,height:1000}});await staff.addCookies([info.cookie]);await staff.request.get('http://127.0.0.1:8000/sanctum/csrf-cookie');
 const page=await staff.newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.goto(origin+root+'/delivery');await page.getByRole('heading',{name:'Share Report',exact:true}).waitFor();
 await page.screenshot({path:'local/review/hanns-share-before-refresh.png',fullPage:true});
 await page.goto(origin+root+'/generate');const responsePromise=page.waitForResponse(r=>r.url().endsWith('/generation')&&r.request().method()==='POST');await page.getByRole('button',{name:'Generate SEO Report',exact:true}).click();const response=await responsePromise;
 if(response.status()!==202)throw Error('Generation request failed: '+response.status());
 await page.waitForURL('**/preview',{timeout:120000});await page.getByRole('heading',{name:/SEO Performance Report/}).waitFor();
 await page.screenshot({path:'local/review/hanns-draft-period-fixed.png',fullPage:true});
 await page.goto(origin+root+'/delivery');await page.getByRole('heading',{name:'Share Report',exact:true}).waitFor();await page.getByText('Source reporting periods',{exact:true}).waitFor();
 const state=await (await staff.request.get('http://127.0.0.1:8000/api'+root+'/sharing')).json();
 await fs.writeFile('local/review/hanns-after-generation.json',JSON.stringify(state,null,2));
 await page.screenshot({path:'local/review/hanns-share-period-fixed.png',fullPage:true});
 console.log(JSON.stringify({generationRequested:true,previewOpened:true,version:state.data.version,eligible:state.data.assessment.eligible,blockers:state.data.assessment.blockers,periods:state.data.assessment.source_periods.map(p=>({document:p.document,status:p.status,ranges:p.ranges,missing:p.uncovered_dates})),errors}));
}finally{await browser.close();}
