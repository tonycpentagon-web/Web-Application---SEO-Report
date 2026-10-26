import {chromium} from '../../backend/pdf-renderer/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';import assert from 'node:assert/strict';
const b=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});const results={};
try{
 const ctx=await b.newContext({viewport:{width:1920,height:1080}}),p=await ctx.newPage();
 await p.goto('https://pentagon-insight-flow.base44.app',{waitUntil:'domcontentloaded',timeout:20000});const reference=p.getByRole('heading',{name:'Conversion events',exact:true});await reference.waitFor();await reference.scrollIntoViewIfNeeded();
 results.referenceTitle=await p.title();results.referenceHeading=await reference.innerText();await p.screenshot({path:'local/review/conversions-base44-reference.png'});
 const {token}=JSON.parse(await fs.readFile('local/review/report-cover-active-link.json','utf8'));
 const response=await ctx.request.get('http://127.0.0.1:8000/api/public-report',{headers:{'X-Report-Token':token}});assert.equal(response.status(),200);
 const before=(await response.json()).data;const errors=[];p.on('pageerror',e=>errors.push(e.message));
 await p.goto('http://127.0.0.1:5173/public-report#'+token);await p.getByRole('heading',{name:'Conversion events',exact:true}).waitFor();
 const section=p.locator('#client-overview');await section.scrollIntoViewIfNeeded();
 assert.equal(await section.locator('.studio-kpi').count(),6);results.cards=await section.locator('.studio-kpi>strong').allTextContents();assert.deepEqual(results.cards,['97','37','30','17','9','4']);
 assert.equal(await section.locator('tbody>tr').count(),5);assert.equal(await p.locator('#client-events').count(),0);
 for(const label of ['Total users','New users','Views'])assert.equal(await p.locator('.studio-kpi-top>span:first-child').filter({hasText:new RegExp('^'+label+'$','i')}).count(),1);
 results.trafficMetricsNotRepeated=true;results.heading=await section.locator('.report-section-heading').innerText();
 assert.equal(await p.getByRole('navigation',{name:'Report sections'}).getByRole('link',{name:'Conversions',exact:true}).count(),1);
 assert.equal(await section.locator('.conversion-kpi-grid').evaluate(e=>getComputedStyle(e).gridTemplateColumns.split(' ').length),3);results.desktopColumns=3;await section.screenshot({path:'local/review/conversions-after-desktop.png'});
 await p.setViewportSize({width:390,height:844});await section.scrollIntoViewIfNeeded();assert.equal(await section.locator('.conversion-kpi-grid').evaluate(e=>getComputedStyle(e).gridTemplateColumns.split(' ').length),2);results.mobileColumns=2;await section.screenshot({path:'local/review/conversions-after-mobile.png'});assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
 assert.equal(errors.length,0);results.noUncaughtErrors=true;results.mobileOverflow=false;
 const after=await ctx.request.get('http://127.0.0.1:8000/api/public-report',{headers:{'X-Report-Token':token}});assert.deepEqual((await after.json()).data,before);results.publishedSnapshotUnchanged=true;
 await fs.writeFile('local/review/conversions-live-results.json',JSON.stringify(results,null,2));console.log(JSON.stringify(results));
}finally{await b.close();}
