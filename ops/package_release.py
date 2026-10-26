#!/usr/bin/env python3
"""Allowlisted source packaging and secret/path checks; never reads client storage or actual .env files."""
import argparse,hashlib,json,re,subprocess,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
TOP={'backend','frontend','document-processor','docs','deploy','ops'}
DENY_DIR={'node_modules','vendor','storage','dist','__pycache__','.venv','venv','.git','.tools','.pytest_cache','backups','reference-files','design-reference'}
PUBLIC_DOCS={'LOCAL_EVALUATION_COMPLETION.md','PRODUCT_RESTRUCTURING_AUDIT.md','DEMO_SERVER_DEPLOYMENT.md','PRODUCT_USER_MANUALS.md','SERVER_REQUIREMENTS.md','PRODUCTION_DEPLOYMENT.md','PRODUCTION_TESTING_CHECKLIST.md','BACKUP_AND_RECOVERY.md','ROLLBACK_PROCEDURE.md','CPANEL_DEPLOYMENT.md','VPS_DEPLOYMENT.md','ARCHITECTURE.md','AUTHENTICATION.md'}
DENY_EXT={'.sql','.dump','.sqlite','.sqlite3','.log','.zip','.gz','.pdf','.pem','.key','.pfx','.p12','.pyc','.tsbuildinfo'}
def candidates():
 for top in sorted(TOP):
  for path in (ROOT/top).rglob('*'):
   if not path.is_file():continue
   rel=path.relative_to(ROOT)
   if any(part in DENY_DIR for part in rel.parts):continue
   if ROOT.resolve() not in path.resolve().parents:raise ValueError('Source path escapes the workspace allowlist.')
   if rel.parts[0]=='docs' and path.name not in PUBLIC_DOCS:continue
   synthetic_pdf=(rel.parts[:4]==('backend','tests','fixtures','monthly') and re.fullmatch(r'(08|09)-(ga4|gsc|keywords|activities|ai|events)\.pdf',path.name) and path.stat().st_size<1024*1024 and path.read_bytes().startswith(b'%PDF-'))
   if (path.suffix.lower() in DENY_EXT and not synthetic_pdf) or '.sqlite' in path.name:continue
   if path.name.startswith('.env') and path.name!='.env.example':continue
   if rel.parts[:3]==('backend','bootstrap','cache'):continue
   if path.name in {'auth.json','.phpunit.result.cache','Homestead.yaml'}:continue
   if any(p.startswith('.env.') and not path.name.endswith('.example') for p in rel.parts):continue
   yield path
 for name in ['README.md','.gitignore','.dockerignore','.htaccess']:
  yield ROOT/name
PATTERNS=[('share-token',re.compile(r'(?:(?:shared|public)-report#|/r/)[a-f0-9]{64}')),('password-setup-token',re.compile(r'reset-password\?token=[a-f0-9]{64}')),('private-key',re.compile(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----')),('credential-token',re.compile(r'\b(?:gh[pousr]_[A-Za-z0-9]{30,}|AKIA[0-9A-Z]{16}|sk-[A-Za-z0-9]{32,})\b')),('environment-secret',re.compile(r'^[ \t]*(?:APP_KEY|DB_PASSWORD|MAIL_PASSWORD|AWS_SECRET_ACCESS_KEY|RESTIC_PASSWORD)[ \t]*=[ \t]*([^\r\n]*)',re.M))]
def scan(paths):
 findings=[]
 for path in paths:
  if path.suffix in {'.png','.jpg','.jpeg','.webp','.ico','.woff','.woff2'}:continue
  try:text=path.read_text(encoding='utf-8-sig')
  except (UnicodeError,OSError):continue
  for kind,pattern in PATTERNS:
   for match in pattern.finditer(text):
    if kind=='environment-secret' and match.group(1).strip() in {'','null','""',"''"}:continue
    findings.append({'file':str(path.relative_to(ROOT)),'line':text.count('\n',0,match.start())+1,'kind':kind})
 return findings

def main():
 parser=argparse.ArgumentParser();parser.add_argument('--output',type=Path);parser.add_argument('--check-staged',action='store_true');args=parser.parse_args()
 paths=list(candidates());findings=scan(paths)
 if args.check_staged:
  result=subprocess.run(['git','-C',str(ROOT),'diff','--cached','--name-only','-z'],capture_output=True)
  if result.returncode:raise SystemExit('Git index unavailable: historical/staged secret status NOT VERIFIED.')
  safe={p.relative_to(ROOT).as_posix() for p in paths}
  for raw in result.stdout.decode().split('\0'):
   if raw and raw not in safe:findings.append({'file':raw,'kind':'not-in-source-allowlist'})
 if findings:print(json.dumps({'status':'blocked','findings':findings},indent=2));return 1
 manifest={p.relative_to(ROOT).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in paths}
 if args.output:
  output=args.output.resolve()
  if ROOT==output or ROOT in output.parents and not any(part in {'.tools','releases'} for part in output.relative_to(ROOT).parts):raise SystemExit('Write packages outside source or in ignored .tools/releases only.')
  if output.exists():raise SystemExit('Refusing to overwrite an existing release package.')
  output.parent.mkdir(parents=True,exist_ok=True)
  with zipfile.ZipFile(output,'x',zipfile.ZIP_DEFLATED) as archive:
   for path in paths:archive.write(path,path.relative_to(ROOT).as_posix())
   archive.writestr('SOURCE_MANIFEST.json',json.dumps(manifest,indent=2))
 print(json.dumps({'status':'passed','source_files':len(paths),'secret_findings':0,'package':str(args.output) if args.output else None,'git_history':'not_verified'},indent=2));return 0
if __name__=='__main__':raise SystemExit(main())
