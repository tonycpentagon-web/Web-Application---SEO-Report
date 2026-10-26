import json
from pathlib import Path
j=json.loads(Path('local/review/period-frontend-tests.json').read_text(encoding='utf-8-sig'));print(json.dumps({'total':j['numTotalTests'],'passed':j['numPassedTests'],'failed':j['numFailedTests']}))
