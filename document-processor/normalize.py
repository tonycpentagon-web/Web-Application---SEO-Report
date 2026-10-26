"""Private stdin/stdout normalization protocol; source content never becomes instructions."""
import sys,json
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
from normalization import Normalizer
if __name__=="__main__":
 try:
  result=Normalizer(json.load(sys.stdin)).run()
  print(json.dumps(result,ensure_ascii=True,allow_nan=False))
 except Exception as error:
  print(json.dumps({"error":"Normalization failed ("+type(error).__name__+"). Source evidence is unchanged."}))
  sys.exit(2)
