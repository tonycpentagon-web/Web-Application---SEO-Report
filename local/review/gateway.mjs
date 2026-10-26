import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
export function allowed(method,p,s){
 if(!['GET','HEAD','POST'].includes(method)||p.includes('\\')||p.includes('..')||/[\x00-\x1f]/.test(p))return false;
 if(method==='POST')return ['/login','/logout'].includes(p)||p===`/api/projects/${s.project}/reports/${s.report}/delivery/exports`;
 if(['/sanctum/csrf-cookie','/api/user','/api/dashboard','/api/projects','/api/monthly-reports'].includes(p))return true;
 if(p===`/api/clients/${s.client}/logo`||s.assets.includes(p))return true;
 const r=`/api/projects/${s.project}/reports`;
 return p===r||p===`/api/projects/${s.project}`||[r+`/${s.report}`,r+`/${s.report}/dashboard`,r+`/${s.report}/summary`,r+`/${s.report}/delivery`].includes(p)||new RegExp('^'+r+`/${s.report}/delivery/exports/[a-zA-Z0-9]+/download$`).test(p);
}
export function frontend(p,s){return ['/','/login','/monthly-reports','/projects'].includes(p)||p===`/projects/${s.project}`||new RegExp(`^/projects/${s.project}/reports/${s.report}/(preview|dashboard|delivery)$`).test(p);}
export function start(configFile){
 const c=JSON.parse(fs.readFileSync(configFile));const downloads=new Set();const dist=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../frontend/dist');
 const server=http.createServer((req,res)=>{
  const headers={...(c.localOnly?{}:{'Strict-Transport-Security':'max-age=3600'}),'Cache-Control':'private, no-store','X-Content-Type-Options':'nosniff','Referrer-Policy':'no-referrer','X-Robots-Tag':'noindex, nofollow, noarchive','X-Frame-Options':'DENY'};
  const fail=(status)=>{res.writeHead(status,{'Content-Type':'application/json',...headers});res.end(JSON.stringify({message:status===404?'Not available in the temporary review workspace.':'Review service unavailable.'}));};
  if(req.headers.host!==c.host)return fail(404);
  let p;try{p=decodeURIComponent(new URL(req.url,'https://'+c.host).pathname);}catch{return fail(404);}
  if(p.includes('..')||p.includes('\\')||/[\x00-\x1f]/.test(p))return fail(404);
  const api=p.startsWith('/api/')||p.startsWith('/sanctum/')||(['POST'].includes(req.method)&&['/login','/logout'].includes(p));
  if(api){
   if(!allowed(req.method,p,c))return fail(404);
   if(p.endsWith('/download')&&!downloads.has(p.split('/').at(-2)))return fail(404);
   if(req.method==='POST'&&req.headers.origin!==(c.localOnly?'http://':'https://')+c.host)return fail(403);
   if(Number(req.headers['content-length']||0)>16384)return fail(413);
   const out={host:c.host,accept:req.headers.accept||'application/json','x-review-origin':c.secret};
   for(const k of ['cookie','content-type','content-length','x-xsrf-token','x-requested-with','origin','referer'])if(req.headers[k])out[k]=req.headers[k];
   const upstream=http.request({host:'127.0.0.1',port:c.backendPort,path:req.url,method:req.method,headers:out,timeout:240000},r=>{
    const responseHeaders={...r.headers,...headers};delete responseHeaders['x-powered-by'];delete responseHeaders['server'];
    if(req.method==='POST'&&p.endsWith('/delivery/exports')){
     const chunks=[];r.on('data',b=>chunks.push(b));r.on('end',()=>{const body=Buffer.concat(chunks);if(r.statusCode===202){try{const id=JSON.parse(body).data.id;if(/^[a-zA-Z0-9]+$/.test(id))downloads.add(id);}catch{}}
      res.writeHead(r.statusCode,responseHeaders);res.end(body);});
    }else{res.writeHead(r.statusCode,responseHeaders);r.pipe(res);}
   });upstream.on('error',()=>{if(!res.headersSent)fail(502);else res.destroy();});upstream.on('timeout',()=>upstream.destroy());req.on('aborted',()=>upstream.destroy());if(req.method==='POST'){
    let size=0;const chunks=[];req.on('data',chunk=>{size+=chunk.length;if(size>16384){upstream.destroy();if(!res.headersSent)fail(413);req.destroy();}else chunks.push(chunk);});req.on('end',()=>{if(size<=16384)upstream.end(Buffer.concat(chunks));});
   }else req.pipe(upstream);return;
  }
  if(!['GET','HEAD'].includes(req.method))return fail(404);
  let file,type;
  if(/^\/assets\/[a-zA-Z0-9_.-]+\.(js|css|woff2|png|jpg|svg)$/.test(p)){file=path.join(dist,p);type={js:'application/javascript',css:'text/css',woff2:'font/woff2',png:'image/png',jpg:'image/jpeg',svg:'image/svg+xml'}[p.split('.').at(-1)];}
  else if(frontend(p,c)){file=path.join(dist,'index.html');type='text/html; charset=utf-8';}else return fail(404);
  if(!fs.existsSync(file)||!fs.statSync(file).isFile())return fail(404);
  res.writeHead(200,{'Content-Type':type,...headers,'Content-Security-Policy':"default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'"});
  if(req.method==='HEAD')res.end();else fs.createReadStream(file).pipe(res);
 });server.maxHeadersCount=50;server.requestTimeout=30000;server.listen(c.proxyPort,'127.0.0.1');return server;
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url))start(process.argv[2]);
