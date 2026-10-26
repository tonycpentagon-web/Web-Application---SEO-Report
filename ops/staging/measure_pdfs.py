import concurrent.futures,json,subprocess,time
from pathlib import Path
root=Path('.tools/stage8-local-windows');inputs=json.loads((root/'evidence/render-inputs.json').read_text())
def render(item):
 metric=root/'evidence'/('metrics-'+item['id']+'.json')
 subprocess.run(['node','ops/staging/measure-render.mjs',item['html'],item['output'],'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',str(metric)],check=True)
 return {'id':item['id'],**json.loads(metric.read_text())}
rows=[render(i) for i in inputs];start=time.perf_counter()
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:parallel=list(pool.map(render,inputs[:2]))
result={'serial':rows,'two_concurrent_renderer_processes':parallel,'concurrent_wall_seconds':round(time.perf_counter()-start,3),'scope':'Offline actual renderer; Node RSS only, not Chromium process tree; not a queue concurrency benchmark'}
(root/'evidence/render-measurements.json').write_text(json.dumps(result,indent=2));print(json.dumps(result,indent=2))
