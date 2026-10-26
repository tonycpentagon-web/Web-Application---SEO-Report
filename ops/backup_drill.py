#!/usr/bin/env python3
"""Self-contained synthetic SQLite/files recovery drill. Never accepts the live DB/storage as inputs."""
import hashlib,json,sqlite3,tempfile,zipfile
from pathlib import Path,PurePosixPath

def digest(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def restore(archive,destination):
 destination=destination.resolve()
 if destination.exists() and any(destination.iterdir()):raise ValueError('Restore requires an empty disposable destination.')
 destination.mkdir(parents=True,exist_ok=True)
 with zipfile.ZipFile(archive) as backup:
  manifest=json.loads(backup.read('MANIFEST.json'))
  for name,checksum in manifest.items():
   part=PurePosixPath(name)
   if part.is_absolute() or '..' in part.parts or '\\' in name or ':' in name:raise ValueError('Unsafe backup member.')
   target=(destination/Path(*part.parts)).resolve()
   if destination not in target.parents:raise ValueError('Restore path escaped its destination.')
   body=backup.read(name)
   if hashlib.sha256(body).hexdigest()!=checksum:raise ValueError('Backup integrity mismatch.')
   target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(body)
 return manifest

def main():
 with tempfile.TemporaryDirectory(prefix='pentagon-synthetic-drill-') as directory:
  root=Path(directory).resolve();assert root.parent==Path(tempfile.gettempdir()).resolve() and root.name.startswith('pentagon-synthetic-drill-');source=root/'source';source.mkdir();db=sqlite3.connect(source/'database.sqlite');db.execute('CREATE TABLE clients(id INTEGER PRIMARY KEY,name TEXT)');db.executemany('INSERT INTO clients(name) VALUES (?)',[('Synthetic Pharmacy',),('Test SEO Company',),('Test Retail Business',)]);db.commit()
  private=source/'private';private.mkdir();(private/'original.txt').write_text('Synthetic private source only.');(private/'report.pdf').write_bytes(b'%PDF-1.7\nsynthetic fixture\n')
  consistent=root/'snapshot.sqlite';snapshot=sqlite3.connect(consistent);db.backup(snapshot);snapshot.close();db.close()
  files={'database.sqlite':consistent,'private/original.txt':private/'original.txt','private/report.pdf':private/'report.pdf'};manifest={name:digest(path) for name,path in files.items()};archive=root/'synthetic-test-backup.zip'
  with zipfile.ZipFile(archive,'x') as out:
   for name,path in files.items():out.write(path,name)
   out.writestr('MANIFEST.json',json.dumps(manifest))
  target=root/'disposable-restore';restore(archive,target)
  with sqlite3.connect(target/'database.sqlite') as recovered:
   assert recovered.execute('PRAGMA integrity_check').fetchone()[0]=='ok';assert recovered.execute('SELECT count(*) FROM clients').fetchone()[0]==3
  recovered.close()
  assert all(digest(target/name)==checksum for name,checksum in manifest.items())
  try:restore(archive,target);raise AssertionError('Nonempty restore was allowed')
  except ValueError:pass
  malicious=root/'traversal.zip'
  with zipfile.ZipFile(malicious,'x') as out:out.writestr('MANIFEST.json',json.dumps({'../escape':'x'}))
  try:restore(malicious,root/'bad-restore');raise AssertionError('Traversal allowed')
  except ValueError:pass
  print(json.dumps({'status':'passed','synthetic_clients':3,'verified_files':3,'database_integrity':'ok','nonempty_destination_rejected':True,'path_traversal_rejected':True,'live_data_touched':False,'encryption':'production restic workflow separately required'},indent=2))
if __name__=='__main__':main()
