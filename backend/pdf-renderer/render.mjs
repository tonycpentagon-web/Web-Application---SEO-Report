import { chromium } from 'playwright';
import fs from 'node:fs/promises';

const [input, output, executablePath] = process.argv.slice(2);
let browser;
try {
  const html = await fs.readFile(input, 'utf8');
  browser = await chromium.launch({headless:true, ...(executablePath ? {executablePath} : {})});
  const context = await browser.newContext({javaScriptEnabled:false, serviceWorkers:'block'});
  // Rendering is offline. Never fetch URLs, local files, credentials or source documents.
  await context.route('**/*', route => route.abort());
  const page = await context.newPage();
  await page.setContent(html, {waitUntil:'load', timeout:60000});
  await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.all(Array.from(document.images, img => img.decode()));
  });
  await page.pdf({path:output, format:'A4', printBackground:true, displayHeaderFooter:true,
    margin:{top:'18mm', bottom:'18mm', left:'14mm', right:'14mm'},
    headerTemplate:'<div style="font-size:9px;color:#536072;width:100%;margin:0 14mm">PENTAGON · INSIGHT FLOW</div>',
    footerTemplate:'<div style="font-size:9px;color:#536072;width:100%;margin:0 14mm;text-align:right">Page <span class="pageNumber"></span> of <span class="totalPages"></span></div>'});
} catch {
  process.stderr.write('PDF renderer failed. Verify Chromium installation and private storage.\n');
  process.exitCode = 1;
} finally { await browser?.close(); }
