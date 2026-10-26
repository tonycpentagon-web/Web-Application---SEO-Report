import json
from pathlib import Path
f=json.loads(Path('local/review/simplified-frontend-tests.json').read_text(encoding='utf-8-sig'))
print(json.dumps({'frontend_tests':f['numTotalTests'],'passed':f['numPassedTests'],'failed':f['numFailedTests']}))
p=Path('local/review/simplified-client.pdf')
if p.exists():
 import fitz
 d=fitz.open(p);text='\n'.join(page.get_text() for page in d)
 result={'pages':len(d),'bytes':p.stat().st_size,'has_uncertainty_disclosures':'Extraction notes & uncertainty' in text,'has_provisional_labels':'Provisional' in text,'contains_old_approval_instruction':'explicit approval required' in text or 'is pending approval' in text}
 print(json.dumps(result));Path('local/review/simplified-pdf-inspection.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
