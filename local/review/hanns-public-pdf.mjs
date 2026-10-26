import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const link=JSON.parse(await fs.readFile('local/review/hanns-public-link.json','utf8'));
const response=await fetch('http://127.0.0.1:8000/api/public-report?pdf=1',{headers:{'X-Report-Token':link.url.split('#')[1]}});
assert.equal(response.status,200);const bytes=Buffer.from(await response.arrayBuffer());assert(bytes.subarray(0,5).toString()==='%PDF-');await fs.writeFile('local/review/hanns-published-period-fixed.pdf',bytes);console.log(JSON.stringify({anonymousPdfStatus:response.status,bytes:bytes.length}));
