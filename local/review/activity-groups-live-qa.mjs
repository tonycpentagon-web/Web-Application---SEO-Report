import {chromium} from '../../backend/pdf-renderer/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';import assert from 'node:assert/strict';
const b=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true}),results={};
try{
 const c=await b.newContext({viewport:{width:1920,height:1080}}),p=await c.newPage();
 await p.goto('https://pentagon-insight-flow.base44.app',{waitUntil:'domcontentloaded',timeout:20000});await p.getByText('Article Submission',{exact:true}).last().scrollIntoViewIfNeeded();await p.screenshot({path:'local/review/activity-groups-base44-reference.png'});results.referenceTitle=await p.title();
 const {token}=JSON.parse(await fs.readFile('local/review/report-cover-active-link.json','utf8'));
 const r=await c.request.get('http://127.0.0.1:8000/api/public-report',{headers:{'X-Report-Token':token}});assert.equal(r.status(),200);const before=(await r.json()).data;
 const errors=[];p.on('pageerror',e=>errors.push(e.message));
 await p.goto('http://127.0.0.1:5173/public-report#'+token);const section=p.locator('#client-activities');await section.getByRole('heading',{name:'Off-page backlink activity',exact:true}).waitFor();await section.scrollIntoViewIfNeeded();
 results.cards=await section.locator('.studio-kpi>strong').allTextContents();const expected=before.records.filter(r=>r.kind==='seo_activities');assert.equal(Number(results.cards[0]),expected.length);
 const groups=section.locator('details.activity-group');assert.equal(await groups.count(),6);results.categories=await groups.locator('summary h3').allTextContents();results.urlBadges=await groups.locator('.activity-url-count').allTextContents();
 assert.equal(await section.locator('details.activity-group[open]').count(),0);assert.equal(await section.locator('.studio-chart-card,.studio-table-card').count(),0);
 assert.equal(await section.locator('.activity-kpi-grid').evaluate(e=>getComputedStyle(e).gridTemplateColumns.split(' ').length),3);
 await section.screenshot({path:'local/review/activity-groups-after-desktop.png',style:'.report-section-nav{visibility:hidden!important}'});
 await p.evaluate(()=>window.dispatchEvent(new Event('beforeprint')));await p.emulateMedia({media:'print'});assert((await section.locator('.activity-group-content').first().boundingBox()).height>0);await p.emulateMedia({media:'screen'});await p.evaluate(()=>window.dispatchEvent(new Event('afterprint')));assert.equal(await section.locator('details.activity-group[open]').count(),0);results.closedListsVisibleInPrint=true;
 for(const group of await groups.all()){await group.locator(':scope>summary').click();assert(await group.evaluate(e=>e.open));}
 assert.equal(await section.locator('.activity-group-content ol>li').count(),expected.length);
 for(const a of await section.locator('.activity-group-content ol a').all())assert(/^https?:/.test(await a.getAttribute('href')));
 results.expandedEntries=await section.locator('.activity-group-content ol>li').count();await section.screenshot({path:'local/review/activity-groups-expanded-desktop.png'});
 await p.emulateMedia({media:'print'});assert.equal(await section.locator('.activity-group-content').first().evaluate(e=>getComputedStyle(e).display),'block');await p.emulateMedia({media:'screen'});
 for(const group of await groups.all())await group.locator(':scope>summary').click();
 await p.setViewportSize({width:390,height:844});await section.scrollIntoViewIfNeeded();assert.equal(await section.locator('.activity-kpi-grid').evaluate(e=>getComputedStyle(e).gridTemplateColumns.split(' ').length),2);
 await section.screenshot({path:'local/review/activity-groups-after-mobile.png'});assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));assert.equal(errors.length,0);
 const after=await c.request.get('http://127.0.0.1:8000/api/public-report',{headers:{'X-Report-Token':token}});assert.deepEqual((await after.json()).data,before);results.publishedDataUnchanged=true;results.noUncaughtErrors=true;results.mobileOverflow=false;
 await fs.writeFile('local/review/activity-groups-live-results.json',JSON.stringify(results,null,2));console.log(JSON.stringify(results));
}finally{await b.close();}
