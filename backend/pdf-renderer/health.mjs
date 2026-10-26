import {chromium} from 'playwright';
const executablePath=process.argv[2];let browser;
try {
 browser=await chromium.launch({headless:true,...(executablePath?{executablePath}:{})});
 const context=await browser.newContext({javaScriptEnabled:false,serviceWorkers:'block'});
 await context.route('**/*',route=>route.abort());const page=await context.newPage();
 await page.setContent('<html><body><h1>Offline PDF readiness probe</h1></body></html>');
 const pdf=await page.pdf({format:'A4'});if(pdf.subarray(0,5).toString()!=='%PDF-')throw Error();
 process.stdout.write(JSON.stringify({ready:true,browser_version:browser.version(),pdf_bytes:pdf.length}));
}catch{process.stdout.write(JSON.stringify({ready:false}));process.exitCode=1;}finally{await browser?.close();}
