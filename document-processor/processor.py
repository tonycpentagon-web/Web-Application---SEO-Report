"""Private local-process protocol: JSON manifest on stdin, progress-only NDJSON on stdout."""
import json, os, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
from engine import Extractor, ExtractionError, doctor

def emit(event):
    print(json.dumps(event, ensure_ascii=True), flush=True)

if __name__ == "__main__":
    if "--doctor" in sys.argv:
        emit(doctor(os.environ.get("DOCUMENT_TESSERACT","tesseract"))); sys.exit(0)
    try:
        manifest = json.load(sys.stdin)
        engine = Extractor(manifest["output"], manifest.get("options", {}), emit)
        result = engine.extract(manifest["input"], manifest.get("extension"))
        emit({"event": "complete", "status": result["status"], "statistics": result["statistics"]})
    except Exception as error:
        message = str(error) if isinstance(error, ExtractionError) else "Processor failed (" + type(error).__name__ + "). Check dependencies and source format."
        emit({"event": "error", "message": message})
        sys.exit(2)
