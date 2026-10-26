#!/usr/bin/env python3
"""Package a private-data-free Windows evaluation handoff, including installed redistributable dependencies."""
import argparse,hashlib,importlib.util,json,re,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('source_package',ROOT/'ops/package_release.py');source=importlib.util.module_from_spec(spec);spec.loader.exec_module(source)
DOCS=['README-FIRST.md','LOCAL-INSTALLATION.md','LOCAL-START.md','LOCAL-STOP.md','WINDOWS-REQUIREMENTS.md','TEST-ACCOUNTS-SETUP.md','BACKUP-RESTORE.md','TROUBLESHOOTING.md']
def collect():
 files={p.relative_to(ROOT).as_posix():p for p in source.candidates() if p.relative_to(ROOT).parts[0] not in {'deploy','ops'}}
 for folder in ['local','backend/vendor','backend/pdf-renderer/node_modules','frontend/dist']:
  for p in (ROOT/folder).rglob('*'):
   if not p.is_file():continue
   if ROOT not in p.resolve().parents:raise ValueError('Dependency path escapes workspace')
   rel=p.relative_to(ROOT)
   if any(x in {'.git','__pycache__','.cache'} for x in rel.parts) or p.name in {'auth.json','.env'} or p.suffix in {'.log','.sqlite','.sqlite3','.sql','.pem','.pfx','.key','.pyc'}:continue
   files[rel.as_posix()]=p
 for name in DOCS:
  files[name]=ROOT/'docs/local'/name
  files['docs/local/'+name]=ROOT/'docs/local'/name
 # Preserve frontend runtime dependency license notices with the compiled application.
 notice_count=0
 lock=json.loads((ROOT/'frontend/package-lock.json').read_text(encoding='utf-8-sig'))
 for name,meta in lock['packages'].items():
  if not name or meta.get('dev'):continue
  directory=ROOT/'frontend'/name
  for p in directory.glob('*'):
   if p.is_file() and p.name.upper().startswith(('LICENSE','LICENCE','NOTICE','COPYING')):
    files['local/third-party-notices/'+name.removeprefix('node_modules/')+'/'+p.name]=p;notice_count+=1
 if not notice_count:raise ValueError('Frontend dependency notices missing')
 return files,lock

def main():
 parser=argparse.ArgumentParser();parser.add_argument('--output',required=True,type=Path);args=parser.parse_args();output=args.output.resolve()
 if output.exists():raise SystemExit('Refusing to overwrite an existing package')
 if not (ROOT/'.tools/releases').resolve() in output.parents:raise SystemExit('Output must be under the private .tools/releases directory')
 files,lock=collect()
 html=(ROOT/'frontend/dist/index.html').read_text(encoding='utf-8')
 entry=re.search(r'src="(/assets/[^" ]+\.js)"',html)
 if not entry or 'baseURL:"/"' not in (ROOT/'frontend/dist'/entry.group(1).lstrip('/')).read_text(encoding='utf-8'):raise SystemExit('Build frontend with VITE_API_URL=/ before packaging; refusing machine-specific API configuration')
 first_party=[p for name,p in files.items() if not name.startswith(('backend/vendor/','backend/pdf-renderer/node_modules/','frontend/dist/','local/third-party-notices/'))]
 findings=source.scan(first_party)
 if findings:raise SystemExit(json.dumps({'blocked':findings},indent=2))
 manifests={name:hashlib.sha256(p.read_bytes()).hexdigest() for name,p in files.items()}
 required=['backend/vendor/autoload.php','frontend/dist/index.html','backend/pdf-renderer/node_modules/playwright/package.json']
 assert all(name in files for name in required)
 components={'purpose':'Windows local evaluation only; no accounts/data/secrets','bundled':['application source','Composer vendor dependencies','React production build','Node PDF renderer modules','synthetic fixtures','Windows scripts and manuals'],'external_prerequisites':['PHP 8.2+ with installed dependency platform compatibility','Python 3.12 x64 with pinned Windows packages and RapidOCR','Node 22+','Microsoft Edge'],'frontend_runtime_dependencies':{name:{'version':m.get('version'),'license':m.get('license')} for name,m in lock['packages'].items() if name and not m.get('dev')},'database':'Fresh SQLite by default; no service or migration of real data is bundled','limitations':['not an offline runtime installer','no LAN/remote deployment','no universal license or hosting compatibility certification']}
 output.parent.mkdir(parents=True,exist_ok=True)
 with zipfile.ZipFile(output,'x',zipfile.ZIP_DEFLATED) as z:
  for name,p in files.items():z.write(p,name)
  z.writestr('SOURCE_MANIFEST.json',json.dumps(manifests,indent=2));z.writestr('LOCAL-COMPONENTS.json',json.dumps(components,indent=2))
 print(json.dumps({'package':str(output),'files':len(files),'bytes':output.stat().st_size,'sha256':hashlib.sha256(output.read_bytes()).hexdigest(),'first_party_secret_findings':0,'runtimes_included':False},indent=2))
if __name__=='__main__':main()
