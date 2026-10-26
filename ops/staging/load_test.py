"""Controlled localhost-only load with real synthetic cookie sessions and Windows resource guard."""
import concurrent.futures,ctypes,json,os,statistics,time,urllib.request,urllib.error,http.cookiejar
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];STAGE=ROOT/'.tools/stage8-local-windows';BASE='http://127.0.0.1:8188'
class Memory(ctypes.Structure):
 _fields_=[('length',ctypes.c_ulong),('load',ctypes.c_ulong),('total',ctypes.c_ulonglong),('available',ctypes.c_ulonglong),('page_total',ctypes.c_ulonglong),('page_avail',ctypes.c_ulonglong),('virtual',ctypes.c_ulonglong),('virtual_avail',ctypes.c_ulonglong),('extended',ctypes.c_ulonglong)]
def memory():
 m=Memory();m.length=ctypes.sizeof(m);ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(m));return {'ram_load_percent':m.load,'available_mb':round(m.available/1048576),'total_mb':round(m.total/1048576)}
def cpu():
 vals=[ctypes.c_ulonglong() for _ in range(3)];ctypes.windll.kernel32.GetSystemTimes(*[ctypes.byref(v) for v in vals]);return [v.value for v in vals]
def login(i,password):
 jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
 opener.open(BASE+'/sanctum/csrf-cookie').read();xsrf=urllib.parse.unquote(next(c.value for c in jar if c.name=='XSRF-TOKEN'))
 req=urllib.request.Request(BASE+'/login',data=json.dumps({'email':f'load{i}@stage8.test','password':password}).encode(),headers={'Accept':'application/json','Content-Type':'application/json','Origin':BASE,'Referer':BASE+'/login','X-XSRF-TOKEN':xsrf})
 for attempt in range(4):
  try:opener.open(req,timeout=30).read();return opener
  except urllib.error.HTTPError as e:
   if e.code!=429:raise
   delay=min(int(e.headers.get('Retry-After','60'))+1,61);print('Login limiter observed; waiting before continuing synthetic sessions.',flush=True);time.sleep(delay)
 raise RuntimeError('Login limiter remained active')
def main():
 assert os.name=='nt' and (STAGE/'.synthetic-stage8').is_file()
 state=json.loads((STAGE/'workflow-state.json').read_text());p=state['tenants'][0];url=BASE+f"/api/projects/{p['project']}/reports/{p['report']}/dashboard";password=json.loads((STAGE/'credentials.json').read_text())['password'];sessions=[];results=[]
 for n in [10,25,50]:
  while len(sessions)<n:sessions.append(login(len(sessions),password))
  before=memory()
  if before['available_mb']<768 or before['ram_load_percent']>90:results.append({'users':n,'status':'BLOCKED','reason':'Resource guard threshold'});break
  a=cpu();start=time.perf_counter()
  def get(i):
   tick=time.perf_counter()
   try:
    response=sessions[i].open(urllib.request.Request(url,headers={'Accept':'application/json','Origin':BASE}),timeout=60);body=json.loads(response.read());assert body['data']['coverage']['business_records']==22;code=response.status
   except urllib.error.HTTPError as e:code=e.code
   return {'ms':(time.perf_counter()-tick)*1000,'status':code}
  samples=[]
  for _ in range(3):
   with concurrent.futures.ThreadPoolExecutor(max_workers=n) as pool:samples+=list(pool.map(get,range(n)))
  elapsed=time.perf_counter()-start;b=cpu();total=(b[1]-a[1])+(b[2]-a[2]);pct=100*(1-(b[0]-a[0])/total) if total else None;values=sorted(s['ms'] for s in samples)
  row={'users':n,'requests':len(samples),'status':'PASS' if all(s['status']==200 for s in samples) else 'FAIL','errors':sum(s['status']!=200 for s in samples),'p50_ms':round(statistics.median(values),2),'p95_ms':round(values[max(0,__import__('math').ceil(len(values)*.95)-1)],2),'elapsed_seconds':round(elapsed,2),'system_cpu_mean_percent':round(pct,2) if pct is not None else None,'memory_before':before,'memory_after':memory()};results.append(row);print(json.dumps(row),flush=True)
  if row['errors'] or (pct is not None and pct>90):break
 (STAGE/'evidence/load-results.json').write_text(json.dumps({'environment':'Windows PHP built-in single request server / SQLite WAL / 22 business-record dashboard; no Linux capacity claim','results':results},indent=2))
if __name__=='__main__':main()
