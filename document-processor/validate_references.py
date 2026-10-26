import argparse, hashlib, json, sys
from pathlib import Path
root=Path(__file__).resolve().parent
sys.path.insert(0,str(root))
from engine import Extractor
parser=argparse.ArgumentParser();parser.add_argument("--output",required=True);parser.add_argument("--ocr-engine",default="auto");args=parser.parse_args()
output=Path(args.output).resolve();output.mkdir(parents=True,exist_ok=True)
summaries=[]
for index,path in enumerate(sorted((root.parent/"reference-files").iterdir())):
 if not path.is_file():continue
 before=hashlib.sha256(path.read_bytes()).hexdigest()
 print("Validating source "+str(index+1)+": "+path.name,flush=True)
 try:
  result=Extractor(output/str(index+1),{"ocr_engine":args.ocr_engine}).extract(path)
  summary={"filename":path.name,"status":result["status"],"page_count":result["metadata"].get("page_count"),"statistics":result["statistics"],"warnings":result["warnings"],"failed_content":result["errors"],"all_pages_processed":result["metadata"].get("page_count",len(result["units"]))==len(result["units"]),"reference_unchanged":before==hashlib.sha256(path.read_bytes()).hexdigest()}
  text=result["full_text"].lower()
  summary["retained_terms"]={term:term in text for term in ["keyword","rank","users","clicks","impressions","ctr","landing","submission","pharmacy","event"]}
 except Exception as error:summary={"filename":path.name,"status":"failed","error":str(error),"reference_unchanged":before==hashlib.sha256(path.read_bytes()).hexdigest()}
 summaries.append(summary)
 (output/"summary.json").write_text(json.dumps(summaries,ensure_ascii=False,indent=2),encoding="utf-8")
 print(json.dumps({k:v for k,v in summary.items() if k not in ("warnings","failed_content")},ensure_ascii=True),flush=True)
print("Reference validation summary: "+str(output/"summary.json"),flush=True)
