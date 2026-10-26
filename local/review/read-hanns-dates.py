import json,re,sqlite3
from pathlib import Path
src=sqlite3.connect('backend/database/database.sqlite');dst=sqlite3.connect('local/review/hanns-period-before.sqlite');src.backup(dst);dst.close();src.close()
d=json.loads(Path('local/review/hanns-period-before.json').read_text(encoding='utf-8-sig'))
for item in d['documents']:
 doc=item['document']
 if 'Analytics' not in doc['original_filename'] and not doc['original_filename'].startswith('key events'):continue
 print('\nDOCUMENT:',doc['original_filename'],'PATH:',sqlite3.connect('backend/database/database.sqlite').execute('select storage_path from report_documents where id=?',(doc['id'],)).fetchone()[0])
 for c in item['content']:
  t=c['original_text'] or ''
  if c['content_type'] in ['text','ocr_text']:
   if re.search('Aug|2026|2025|Jul|Sept|30|31',t,re.I):
    print(json.dumps({'id':c['id'],'type':c['content_type'],'confidence':c['confidence_status'],'location':c['source_location'],'text':t[:16000]},ensure_ascii=True))
 print('CLASSIFICATION:',next((x for x in d['normalizations'][0]['summary']['classifications'] if x['document_id']==doc['id']),None))
 print('ASSETS:',[(x['asset_type'],x['storage_path'],x['metadata']) for x in item['assets'][:4]])
print('validation issues',d['validation']['summary']['document_issues'])
