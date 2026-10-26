import {chromium} from '../../backend/pdf-renderer/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';
const phase=process.argv[2]||'before';
const link=JSON.parse(await fs.readFile(phase==='after'?'local/review/hanns-public-link-v2.json':'local/review/hanns-public-link.json','utf8'));
const token=new URL(link.url).hash.slice(1);
const response=await fetch('http://127.0.0.1:8000/api/public-report',{headers:{'X-Report-Token':token}});
const payload=await response.json();await fs.writeFile(`local/review/public-${phase}-payload.json`,JSON.stringify(payload,null,2));
const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
const results=[];
try{for(const width of [1440,768,390]){
 const context=await browser.newContext({viewport:{width,height:1000}}),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(link.url);await page.getByRole('heading',{name:/SEO Performance Report/}).waitFor();await page.waitForTimeout(1500);
 await page.screenshot({path:`local/review/public-${phase}-${width}-cover.png`});
 const sections=[];for(const section of await page.locator('section[id^="client-"]').all()){await section.scrollIntoViewIfNeeded();await page.waitForTimeout(100);sections.push(await section.getAttribute('id'));await section.screenshot({path:`local/review/public-${phase}-${width}-${await section.getAttribute('id')}.png`});}
 const images=await page.locator('.studio-thumbnail img').evaluateAll(imgs=>imgs.map(i=>({loaded:i.complete&&i.naturalWidth>0,width:i.naturalWidth,height:i.naturalHeight,fit:getComputedStyle(i).objectFit})));
 results.push({width,sections,images,errors,overflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1)});await context.close();
}await fs.writeFile(`local/review/public-${phase}-browser.json`,JSON.stringify(results,null,2));console.log(JSON.stringify(results));}finally{await browser.close();}
