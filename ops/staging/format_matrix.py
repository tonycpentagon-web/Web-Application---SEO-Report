"""Real synthetic-only format/size/resource-cap extraction matrix."""
import json,subprocess,sys,time,shutil
from pathlib import Path
import pymupdf as fitz
ROOT=Path(__file__).resolve().parents[2];stage=ROOT/'.tools/stage8-local-windows';sources=stage/'sources';extras=sources/'supplemental'
large=extras/'large-source.pdf'
if not large.exists():
 doc=fitz.open()
 for i in range(80):p=doc.new_page();p.insert_text((50,50),f'Synthetic page {i+1}\nKeyword,Ranking\nSynthetic large keyword,2nd Page',fontsize=14)
 doc.save(large);doc.close()
jpg=extras/'screenshot.jpg'
if not jpg.exists():shutil.copyfile(extras/'screenshot.jpeg',jpg)
inputs=[sources/n for n in ['Keyword_Ranking.pdf','activities.docx','keywords.xlsx','channels.csv','key events.png']]+[extras/n for n in ['scanned.pdf','screenshot.jpeg','screenshot.jpg','large-keywords.csv','large-source.pdf']]
results=[];run_id=str(time.time_ns())
for i,source in enumerate(inputs+[large]):
 output=stage/'evidence'/f'format-{run_id}-{i}';output.mkdir(exist_ok=False);start=time.perf_counter();opts={'ocr_engine':'auto','max_pages':1 if i==len(inputs) else 500,'max_cells':250000,'max_output_mb':32}
 p=subprocess.run([sys.executable,str(ROOT/'document-processor/processor.py')],input=json.dumps({'input':str(source),'output':str(output),'extension':source.suffix[1:],'options':opts}),text=True,capture_output=True,timeout=120)
 if i==len(inputs):
  assert p.returncode!=0 and 'page limit' in p.stdout
  r={'file':source.name,'status':'rejected_limit','page_cap':1,'seconds':round(time.perf_counter()-start,3),'incomplete_marked_success':False};results.append(r);print(json.dumps(r),flush=True);continue
 assert p.returncode==0,p.stdout
 d=json.loads((output/'raw.json').read_text(encoding='utf-8'));r={'file':source.name,'input_bytes':source.stat().st_size,'seconds':round(time.perf_counter()-start,3),'status':d['status'],'units':len(d['units']),'content':len(d['content']),'assets':len(d['assets']),'warnings':[w['code'] for w in d['warnings']],'page_cap':opts['max_pages']};results.append(r)
 if i==len(inputs):assert d['status']=='partially_extracted'
 else:assert d['status'] in ['extracted','needs_review']
 print(json.dumps(r),flush=True)
(stage/'evidence/format-matrix.json').write_text(json.dumps(results,indent=2))
