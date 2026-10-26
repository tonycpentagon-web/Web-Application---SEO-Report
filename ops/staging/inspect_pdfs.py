"""Inspect generated synthetic exports; fixture.php inspect must first capture the catalogue."""
import hashlib,json,re
from pathlib import Path
import pymupdf as fitz
root=Path('.tools/stage8-local-windows');results=[]
for e in json.loads((root/'evidence/export-artifacts.json').read_text()):
 p=Path(e['path']);assert hashlib.sha256(p.read_bytes()).hexdigest()==e['checksum'];doc=fitz.open(p);texts=[page.get_text() for page in doc];text='\n'.join(texts);flat=re.sub(r'\s+','',text);overflow=[]
 for i,page in enumerate(doc):
  for b in page.get_text('blocks'):
   if b[0]<30 or b[2]>page.rect.width-30 or b[3]>page.rect.height-10:overflow.append(i+1)
 keyword_missing=[r['data']['keyword'] for r in e['payload']['records'] if 'keywords' in e['sections'] and r['kind']=='keyword_rankings' and re.sub(r'\s+','',r['data']['keyword']) not in flat]
 assert not keyword_missing and not overflow
 assert all('Page ' in t for t in texts)
 if e['type']=='draft':assert all('DRAFT' in t for t in texts)
 else:assert all('DRAFT' not in t for t in texts)
 if e['profile']=='internal_audit':assert all(r['id'] in text for r in e['payload']['_audit']['registry'])
 results.append({'id':e['id'],'profile':e['profile'],'type':e['type'],'pages':len(doc),'bytes':p.stat().st_size,'images':sum(len(page.get_images()) for page in doc),'missing_keywords':keyword_missing,'overflow_pages':overflow,'watermark_and_page_numbers_correct':True})
(root/'evidence/pdf-verification-repeat.json').write_text(json.dumps(results,indent=2));print(json.dumps(results,indent=2))
