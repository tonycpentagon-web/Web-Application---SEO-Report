"""Create/start a synthetic-only Windows fallback or generate Compose credentials; never reads development .env."""
import argparse,base64,json,os,secrets,subprocess,time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
STAGE=ROOT/'.tools/stage8-local-windows'
PHP=ROOT/'.tools/php/php.exe'
def environment(stage=STAGE):
 e=os.environ.copy();e['STAGE8_ROOT']=str(stage)
 # Laravel checks cache paths before it loads the shadow .env.
 for key,name in {'APP_CONFIG_CACHE':'config.php','APP_ROUTES_CACHE':'routes.php','APP_SERVICES_CACHE':'services.php','APP_PACKAGES_CACHE':'packages.php','APP_EVENTS_CACHE':'events.php'}.items():e[key]=str(Path('..')/'.tools'/stage.name/'cache'/name)
 return e
def run(*args):
 return subprocess.run([str(PHP),str(ROOT/'ops/staging/console.php'),*args],cwd=ROOT/'backend',env=environment(),check=True)
def initialize():
 if STAGE.exists():raise SystemExit('Refusing to overwrite an existing staging environment. Use its retained state or a separately reviewed new name.')
 STAGE.mkdir();(STAGE/'.synthetic-stage8').write_text('synthetic-only')
 for n in ['cache','private','runtime/framework/cache/data','runtime/framework/sessions','runtime/framework/views','runtime/logs','evidence']:(STAGE/n).mkdir(parents=True,exist_ok=True)
 (STAGE/'database.sqlite').touch()
 cred={'password':secrets.token_urlsafe(24)+'A1!'};(STAGE/'credentials.json').write_text(json.dumps(cred));os.chmod(STAGE/'credentials.json',0o600)
 env={'APP_NAME':'Pentagon Stage8 Synthetic','APP_ENV':'staging','APP_DEBUG':'false','APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'APP_URL':'http://127.0.0.1:8188','FRONTEND_URL':'http://127.0.0.1:8188','SANCTUM_STATEFUL_DOMAINS':'127.0.0.1:8188','SESSION_COOKIE':'pentagon_stage8_windows','SESSION_DRIVER':'database','SESSION_ENCRYPT':'true','SESSION_SECURE_COOKIE':'false','SESSION_DOMAIN':'null','CACHE_STORE':'database','DB_CONNECTION':'sqlite','DB_DATABASE':(STAGE/'database.sqlite').as_posix(),'DB_URL':'','QUEUE_CONNECTION':'database','DB_QUEUE_RETRY_AFTER':'1200','MAIL_MAILER':'log','MAIL_LOG_CHANNEL':'single','MAIL_FROM_ADDRESS':'stage8@example.test','LOG_CHANNEL':'single','LOCAL_PRIVATE_ROOT':(STAGE/'private').as_posix(),'DOCUMENT_PYTHON':(ROOT/'.tools/python/python.exe').as_posix(),'DOCUMENT_PROCESSOR_SCRIPT':'../document-processor/processor.py','DOCUMENT_OCR_ENGINE':'auto','DOCUMENT_TESSERACT':'C:/Program Files/Tesseract-OCR/tesseract.exe','PDF_NODE_BINARY':'C:/Program Files/nodejs/node.exe','PDF_CHROMIUM_PATH':'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','PDF_TIMEOUT':'180','STAGE8_DISABLE_EXPORT_CLEANUP':'true','BCRYPT_ROUNDS':'4'}
 (STAGE/'.env').write_text(''.join(k+'="'+v+'"\n' for k,v in env.items()));os.chmod(STAGE/'.env',0o600)
 run('migrate','--force','--no-interaction')
 subprocess.run([str(PHP),str(ROOT/'ops/staging/fixture.php'),'seed'],cwd=ROOT/'backend',env=environment(),check=True)
 import sys
 sys.path.insert(0,str(ROOT/'ops/staging'));from synthetic_files import generate
 generate(STAGE/'sources')
 print('Initialized synthetic Windows staging; credentials retained privately, never printed.')
def start():
 if not (STAGE/'.synthetic-stage8').exists():raise SystemExit('Initialize first.')
 pidfile=STAGE/'processes.json'
 if pidfile.exists():
  import ctypes
  from ctypes import wintypes
  kernel=ctypes.WinDLL('kernel32');kernel.OpenProcess.restype=wintypes.HANDLE;kernel.GetExitCodeProcess.argtypes=[wintypes.HANDLE,ctypes.POINTER(wintypes.DWORD)];kernel.CloseHandle.argtypes=[wintypes.HANDLE]
  for old in json.loads(pidfile.read_text()).values():
   handle=kernel.OpenProcess(0x1000,False,old)
   if not handle:continue
   status=wintypes.DWORD();ok=kernel.GetExitCodeProcess(handle,ctypes.byref(status));kernel.CloseHandle(handle)
   if not ok or status.value==259:raise SystemExit('A recorded PID is still active; inspect/stop owned processes first.')
  with (STAGE/'process-history.ndjson').open('a') as history:history.write(json.dumps({'replaced_at':time.time(),'previous':json.loads(pidfile.read_text())})+'\n')
 import socket
 with socket.socket() as probe:
  try:probe.bind(('127.0.0.1',8188))
  except OSError:raise SystemExit('Port 8188 is already occupied; no process was replaced.')
 commands={'web':[str(PHP),'-S','127.0.0.1:8188',str(ROOT/'ops/staging/router.php')],'worker':[str(PHP),str(ROOT/'ops/staging/console.php'),'queue:work','database','--queue=documents,exports','--tries=1','--timeout=960','--memory=512','--sleep=1']}
 pids={}
 for name,args in commands.items():
  with open(STAGE/f'{name}.log','ab') as log:
   p=subprocess.Popen(args,cwd=ROOT/'backend',env=environment(),stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0);pids[name]=p.pid
 pidfile.write_text(json.dumps(pids,indent=2));print(json.dumps({'started':pids,'url':'http://127.0.0.1:8188'}))
def main():
 p=argparse.ArgumentParser();p.add_argument('command',choices=['init','start','schedule','compose-env','load-users','inspect','benchmark-html','health','queue-restart']);a=p.parse_args()
 if a.command=='init':initialize()
 elif a.command=='start':start()
 elif a.command=='schedule':run('schedule:run')
 elif a.command in ['load-users','inspect','benchmark-html']:
  subprocess.run([str(PHP),str(ROOT/'ops/staging/fixture.php'),a.command],cwd=ROOT/'backend',env=environment(),check=True)
 elif a.command=='health':run('system:health','--probe')
 elif a.command=='queue-restart':run('queue:restart')
 else:
  target=ROOT/'deploy/staging-local/.env'
  with target.open('x') as f:f.write('STAGE8_APP_KEY=base64:'+base64.b64encode(secrets.token_bytes(32)).decode()+'\nSTAGE8_DB_PASSWORD='+secrets.token_urlsafe(32)+'\nSTAGE8_MYSQL_ROOT_PASSWORD='+secrets.token_urlsafe(32)+'\nSTAGE8_FIXTURE_PASSWORD='+secrets.token_urlsafe(24)+'A1!\n')
  os.chmod(target,0o600);print('Generated local-only Compose credentials without printing values.')
if __name__=='__main__':main()
