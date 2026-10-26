import {chromium} from '../../backend/pdf-renderer/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const info=JSON.parse(await fs.readFile('local/review/hanns-live-session.json','utf8')),link=JSON.parse(await fs.readFile('local/review/hanns-public-link.json','utf8')),before=JSON.parse(await fs.readFile('local/review/hanns-after-generation.json','utf8'));
const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
try{
 const staff=await browser.newContext({viewport:{width:1440,height:1000}});await staff.addCookies([info.cookie]);const p=await staff.newPage();const root=`http://127.0.0.1:5173/projects/${info.project_id}/reports/${info.report_id}`;await p.goto(root+'/delivery');await p.getByRole('heading',{name:'Share Report',exact:true}).waitFor();await p.screenshot({path:'local/review/hanns-sharing-final.png',fullPage:true});
 await p.route('**/sharing',route=>route.fulfill({contentType:'application/json',body:JSON.stringify(before)}));await p.reload();await p.getByRole('checkbox').waitFor();await p.screenshot({path:'local/review/hanns-draft-sharing-final.png',fullPage:true});const label=await p.getByRole('checkbox').evaluate(e=>e.parentElement.getBoundingClientRect().bottom),expiry=await p.getByLabel('Link expiration').evaluate(e=>e.getBoundingClientRect().top);assert(expiry>label,'Expiration overlapped the confirmation label');
 const anon=await browser.newContext();const client=await anon.newPage();for(const [width,height,name] of [[1440,1000,'desktop'],[390,844,'mobile']]){await client.setViewportSize({width,height});await client.goto(link.url);await client.getByRole('heading',{name:/SEO Performance Report/}).waitFor();assert(await client.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await client.screenshot({path:'local/review/hanns-public-'+name+'-top.png'});}
 console.log(JSON.stringify({livePublicDesktopAndMobile:true,sharingFormSpacing:true,pageOverflow:false}));
}finally{await browser.close();}
