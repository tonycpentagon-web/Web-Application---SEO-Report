#!/usr/bin/env python3
"""Read-only compatibility inventory. Never installs, migrates, starts services or loads application .env."""
import argparse,importlib.metadata,json,os,platform,re,shutil,subprocess,sys,tempfile
from pathlib import Path

def run(argv,timeout=20):
    try:
        p=subprocess.run(argv,capture_output=True,text=True,timeout=timeout,encoding='utf-8',errors='replace')
        return {'ok':p.returncode==0,'output':p.stdout.strip()[:6000]}
    except (OSError,subprocess.TimeoutExpired):return {'ok':False,'output':''}

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root',type=Path,default=Path(__file__).resolve().parents[1])
    parser.add_argument('--php',default='php');parser.add_argument('--node',default='node')
    parser.add_argument('--mysql-defaults',type=Path,help='Private MySQL option file; credentials are never read or printed by this script')
    parser.add_argument('--probe-runtime',action='store_true');parser.add_argument('--chromium',default='')
    parser.add_argument('--output',type=Path);args=parser.parse_args();root=args.root.resolve()
    if not (root/'backend/artisan').is_file():parser.error('Root must contain the application backend/artisan.')
    disk=shutil.disk_usage(root);ram=None
    if Path('/proc/meminfo').exists():
        match=re.search(r'MemTotal:\s+(\d+)',Path('/proc/meminfo').read_text());ram=round(int(match[1])/1024) if match else None
    php=run([args.php,'-r',"echo json_encode(['version'=>PHP_VERSION,'extensions'=>get_loaded_extensions(),'proc_open'=>function_exists('proc_open'),'pcntl'=>function_exists('pcntl_signal'),'posix'=>function_exists('posix_getuid'),'memory_limit'=>ini_get('memory_limit'),'upload_max_filesize'=>ini_get('upload_max_filesize'),'post_max_size'=>ini_get('post_max_size')]);"])
    try:php_info=json.loads(php['output']) if php['ok'] else None
    except ValueError:php_info=None
    packages={}
    for name in ['PyMuPDF','pdfplumber','python-docx','openpyxl','Pillow','xlrd','charset-normalizer','defusedxml','pytesseract','rapidocr-onnxruntime']:
        try:packages[name]=importlib.metadata.version(name)
        except importlib.metadata.PackageNotFoundError:packages[name]=None
    mysql={'verified':False,'note':'Client version does not establish the database server version or configuration.'}
    if args.mysql_defaults:
        option=args.mysql_defaults.resolve()
        if not option.is_file() or (os.name!='nt' and option.stat().st_mode & 0o077):parser.error('MySQL option file must exist and be private (0600 on Unix).')
        probe=run(['mysql','--defaults-extra-file='+str(option),'--batch','--skip-column-names','--execute','SELECT VERSION(), @@sql_mode, @@character_set_server, @@default_storage_engine;'])
        mysql={'verified':probe['ok'],'server':probe['output'] if probe['ok'] else None,'note':'Read-only server inventory; application schema, grants and migration compatibility require separate tests.'}
    report={'audit_only':True,'deployment_approved':False,'host':{'os':platform.system(),'release':platform.release(),'architecture':platform.machine(),'cpu_count':os.cpu_count(),'ram_mb':ram,'disk_free_gb':round(disk.free/1024**3,2),'control_panel': 'cPanel' if Path('/usr/local/cpanel').exists() else 'Plesk' if Path('/usr/local/psa').exists() else 'Not detected; confirm with provider'},'php_cli':php_info,'python':{'version':platform.python_version(),'packages':packages},'node':run([args.node,'--version']),'composer':run(['composer','--version']),'mysql':mysql,'mysql_client':run(['mysql','--version']),'worker_tools':{name:bool(shutil.which(name)) for name in ['supervisorctl','systemctl','crontab']},'manual_gates':['Actual hosting plan permits Python/Chromium subprocesses and durable workers','PHP-FPM configuration and writable private persistent storage','Actual MySQL 8 strict utf8mb4 InnoDB schema and least-privilege grants','HTTPS certificate, DNS and configured application origins','SMTP invitation/reset delivery to an authorized test recipient','Worker/scheduler heartbeats and 10-30 session load test on target resources','Encrypted database/files backup and isolated restore drill','Explicit deployment approval after compatibility review']}
    if args.probe_runtime:
        doctor=run([sys.executable,str(root/'document-processor/processor.py'),'--doctor'],60)
        try:report['processor_doctor']=json.loads(doctor['output']) if doctor['ok'] else {'available':False}
        except ValueError:report['processor_doctor']={'available':False}
        with tempfile.TemporaryDirectory(prefix='pentagon-host-audit-') as temp:
            html=Path(temp)/'probe.html';pdf=Path(temp)/'probe.pdf';html.write_text('<!doctype html><meta charset="utf-8"><h1>Synthetic PDF compatibility probe</h1><p>No client data.</p>')
            result=run([args.node,str(root/'backend/pdf-renderer/render.mjs'),str(html),str(pdf),args.chromium],60)
            report['pdf_probe']={'available':result['ok'] and pdf.exists() and pdf.read_bytes().startswith(b'%PDF-')}
    body=json.dumps(report,indent=2)
    if args.output:
        dest=args.output.resolve()
        for public in [root/'backend/public',root/'frontend/dist']:
            if dest==public or public in dest.parents:parser.error('Audit output must stay outside public document roots.')
        with dest.open('x',encoding='utf-8') as f:f.write(body+'\n')
        if os.name!='nt':dest.chmod(0o600)
    print(body)
    return 0
if __name__=='__main__':raise SystemExit(main())
