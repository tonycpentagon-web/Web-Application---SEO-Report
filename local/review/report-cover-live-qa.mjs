import {chromium} from '../../backend/pdf-renderer/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';import assert from 'node:assert/strict';
const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});const results={};
try{
 const context=await browser.newContext({viewport:{width:1920,height:1080}});const page=await context.newPage();
 try{await page.goto('https://pentagon-insight-flow.base44.app',{waitUntil:'domcontentloaded',timeout:20000});await page.waitForTimeout(1500);results.referenceTitle=await page.title();results.referenceHasReport=await page.getByRole('heading',{name:/SEO Performance Report/}).count()>0;await page.screenshot({path:'local/review/base44-cover-reference-current.png'});}catch(e){results.referenceUnavailable=e.message.split('\n')[0];}
 const {token}=JSON.parse(await fs.readFile('local/review/report-cover-active-link.json','utf8'));
 const r=await context.request.get('http://127.0.0.1:8000/api/public-report',{headers:{'X-Report-Token':token}});assert.equal(r.status(),200);const data=(await r.json()).data;
 results.sourceRanges=data.source_periods?.map(p=>({status:p.status,ranges:p.ranges}));
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:5173/public-report#'+token);await page.getByRole('heading',{name:/SEO Performance Report/}).waitFor();
 assert.equal(await page.locator('.report-cover .source-coverage-notice,.report-cover .report-source-register').count(),0);
 assert.equal(await page.getByText('Report coverage, limitations and source evidence',{exact:true}).count(),0);
 assert.equal(await page.locator('a[href="#report-details"]').count(),0);
 assert.equal(await page.locator('section[id^="client-"]').count(),7);
 results.coverPeriod=await page.locator('.report-cover-details>div').last().innerText();assert(results.coverPeriod.includes('30 Aug 2026'));
 await page.screenshot({path:'local/review/report-cover-after-desktop.png'});
 await page.setViewportSize({width:390,height:844});await page.screenshot({path:'local/review/report-cover-after-mobile.png'});
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
 assert.equal(errors.length,0);results.noUncaughtErrors=true;results.extraCoverPanelsRemoved=true;results.sevenReportSectionsPreserved=true;results.mobileOverflow=false;
 await fs.writeFile('local/review/report-cover-live-results.json',JSON.stringify(results,null,2));console.log(JSON.stringify(results));
}finally{await browser.close();}
