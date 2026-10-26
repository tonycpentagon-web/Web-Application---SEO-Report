"""Restore ONLY the fixed synthetic Stage 8 fixture into a new sibling directory."""
import hashlib,json,sqlite3,sys,time,zipfile,subprocess,os
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]));import staging_setup as s
ROOT=s.ROOT;source=s.STAGE;target=ROOT/'.tools/stage8-local-restored'
assert (source/'.synthetic-stage8').is_file() and target.parent.resolve()==(ROOT/'.tools').resolve()
if target.exists():raise SystemExit('Refusing to overwrite an existing restore directory.')
start=time.perf_counter();snapshot=source/'evidence/consistent-synthetic.sqlite'
if snapshot.exists():raise SystemExit('Snapshot already exists; refusing overwrite.')
live=sqlite3.connect((source/'database.sqlite').as_uri()+'?mode=ro',uri=True);copy=sqlite3.connect(snapshot);live.backup(copy);copy.close();live.close()
files={'database.sqlite':snapshot,'.env':source/'.env','.synthetic-stage8':source/'.synthetic-stage8','credentials.json':source/'credentials.json','accounts.json':source/'accounts.json','workflow-state.json':source/'workflow-state.json'}
files.update({p.relative_to(source).as_posix():p for p in (source/'private').rglob('*') if p.is_file()})
for p in (ROOT/'deploy/staging-local').glob('*.example'):files['templates/'+p.name]=p
manifest={n:hashlib.sha256(p.read_bytes()).hexdigest() for n,p in files.items()};archive=source/'evidence/synthetic-staging-backup.zip'
with zipfile.ZipFile(archive,'x',zipfile.ZIP_DEFLATED) as z:
 for n,p in files.items():z.write(p,n)
 z.writestr('MANIFEST.json',json.dumps(manifest))
sys.path.insert(0,str(ROOT/'ops'));from backup_drill import restore
restore(archive,target)
assert all(hashlib.sha256((target/n).read_bytes()).hexdigest()==h for n,h in manifest.items())
conn=sqlite3.connect(target/'database.sqlite');assert conn.execute('pragma integrity_check').fetchone()[0]=='ok';assert conn.execute('pragma foreign_key_check').fetchall()==[]
counts={t:conn.execute('select count(*) from '+t).fetchone()[0] for t in ['users','clients','projects','reporting_periods','report_documents','normalized_records','record_verifications','report_publications','report_pdf_exports','report_delivery_events']};conn.close()
# Adapt ONLY restored synthetic routing/runtime paths; established staging APP_KEY stays byte-identical.
env=(target/'.env').read_text();env=env.replace(source.as_posix(),target.as_posix()).replace('8188','8288').replace('pentagon_stage8_windows','pentagon_stage8_restored');(target/'.env').write_text(env)
for n in ['cache','runtime/framework/cache/data','runtime/framework/sessions','runtime/framework/views','runtime/logs','evidence']:(target/n).mkdir(parents=True,exist_ok=True)
with open(target/'web.log','ab') as log:
 p=subprocess.Popen([str(s.PHP),'-S','127.0.0.1:8288',str(ROOT/'ops/staging/router.php')],cwd=ROOT/'backend',env=s.environment(target),stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
(target/'processes.json').write_text(json.dumps({'web':p.pid}))
result={'status':'PASS','source':'stage8-local-windows synthetic only','destination':'stage8-local-restored','archive_files':len(manifest),'all_file_hashes_match_before_path_adaptation':True,'integrity':'ok','foreign_key_violations':0,'counts':counts,'recovery_seconds':round(time.perf_counter()-start,3),'encrypted':False,'offsite_tested':False,'development_overwritten':False}
(source/'evidence/backup-restore.json').write_text(json.dumps(result,indent=2));print(json.dumps(result,indent=2))
