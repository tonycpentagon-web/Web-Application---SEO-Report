"""Loss-aware document extraction. Source evidence is never interpreted as instructions."""
from __future__ import annotations
import hashlib, importlib, importlib.util, io, json, mimetypes, os, re, shutil, subprocess, sys, time, zipfile
from datetime import date, datetime
from pathlib import Path

VERSION = "pentagon-extractor/1.3"
FORMATS = {"pdf": "pymupdf", "docx": "docx", "xlsx": "openpyxl", "xls": "xlrd", "csv": "charset_normalizer", "png": "PIL", "jpg": "PIL", "jpeg": "PIL", "webp": "PIL"}
class ExtractionError(Exception): pass

def json_value(value):
    if isinstance(value, (date, datetime)): return value.isoformat()
    if isinstance(value, bytes): return {"sha256": hashlib.sha256(value).hexdigest(), "byte_count": len(value)}
    if hasattr(value, "tolist"): return value.tolist()
    return str(value)

def doctor(tesseract="tesseract"):
    formats, dependencies = {}, {}
    for ext, module in FORMATS.items():
        try:
            imported = importlib.import_module(module)
            dependencies[module] = {"available": True, "version": str(getattr(imported, "__version__", getattr(imported, "VersionBind", "installed")))}
            formats[ext] = True
        except Exception as error:
            dependencies[module] = {"available": False, "error": type(error).__name__}
            formats[ext] = False
    rapid = False
    try:
        mod = importlib.import_module("rapidocr_onnxruntime")
        rapid = len(list((Path(mod.__file__).parent / "models").glob("*.onnx"))) >= 3
    except Exception: pass
    tess = shutil.which(tesseract)
    if tess:
        try: tess = bool(subprocess.run([tess, "--version"], capture_output=True, timeout=5).returncode == 0)
        except Exception: tess = False
    return {"processor_version": VERSION, "formats": formats, "dependencies": dependencies,
            "ocr": {"tesseract": bool(tess), "rapidocr": rapid, "available": bool(tess or rapid)},
            "note": "OCR output and metric/section suggestions require human review."}

class Extractor:
    def __init__(self, output, options=None, progress=None):
        self.output = Path(output).resolve()
        self.output.mkdir(parents=True, exist_ok=True)
        (self.output / "assets").mkdir(exist_ok=True)
        self.options = options or {}
        self.progress_callback = progress or (lambda event: None)
        self.result = {"schema_version": 1, "processor_version": VERSION, "status": "extracted",
                       "metadata": {}, "units": [], "content": [], "assets": [], "warnings": [], "errors": [], "full_text": ""}
        self.text = []
        self.section = None
        self.asset_bytes = 0
        self.ocr_engine = None
        self.ocr_used = False
        self.incomplete = False
        self.ocr_cache = {}
        self.ocr_cache_hits = 0

    def progress(self, message, completed=0, total=None):
        self.progress_callback({"event": "progress", "message": message, "completed": completed, "total": total})

    def warning(self, code, message, source=None, incomplete=False):
        self.result["warnings"].append({"code": code, "message": message, "source": source or {}})
        self.incomplete = self.incomplete or incomplete

    def error(self, code, message, source=None):
        self.result["errors"].append({"code": code, "message": message, "source": source or {}})
        self.incomplete = True

    def add(self, kind, text="", data=None, source=None, confidence="native", enrich=True):
        item = {"type": kind, "text": text, "data": data or {}, "source": source or {}, "section": self.section, "confidence": confidence}
        self.result["content"].append(item)
        if kind in ("text", "ocr_text", "paragraph", "heading", "worksheet_text"):
            self.text.append(text)
        if kind == "heading": self.section = text
        if enrich and text and kind not in ("url", "candidate"):
            for match in re.finditer(r"https?://[^\s<>\"\x00-\x1f]+", text):
                self.add("url", match.group(0), {"url": match.group(0)}, source, confidence, False)
            labels = re.findall(r"\b(?:users|sessions|clicks|impressions|ctr|conversions|events|rankings?|backlinks|engagement|traffic)\b", text, re.I)
            numbers = re.findall(r"(?<!\w)[#]?[+-]?\d[\d,.]*(?:%|:[\d:]+)?", text)
            if labels and numbers:
                self.add("candidate", text, {"labels": labels, "raw_numbers": numbers, "review_required": True, "interpretation": "Unverified candidate only; no normalized metric has been generated."}, source, "unverified", False)
        return item

    def asset(self, data, extension, kind, source=None, metadata=None):
        extension = re.sub(r"[^a-z0-9]", "", extension.lower())[:12] or "bin"
        limit = int(self.options.get("max_expanded_mb", 100)) * 1024 * 1024
        self.asset_bytes += len(data)
        if self.asset_bytes > limit: raise ExtractionError("Generated evidence exceeds the configured output asset limit; increase it for this trusted document and retry.")
        name = f"asset-{len(self.result['assets']) + 1:05d}.{extension}"
        path = self.output / "assets" / name
        path.write_bytes(data)
        item = {"path": "assets/" + name, "type": kind, "source": source or {}, "metadata": metadata or {},
                "sha256": hashlib.sha256(data).hexdigest(), "bytes": len(data), "mime": mimetypes.guess_type(name)[0] or "application/octet-stream"}
        self.result["assets"].append(item)
        return item

    def record_ocr(self, text, data, source, required):
        self.ocr_used = True
        self.add("ocr_text", text, data, source, "ocr")
        if not text.strip() and required:
            self.warning("ocr_no_text", "OCR found no readable text in this image; inspect the retained evidence.", source, True)
        if any(w["confidence"] < .8 for w in data["items"]):
            self.warning("ocr_low_confidence", "Some OCR text has low confidence. Inspect numeric values and labels against the image.", source)

    def ocr(self, image_bytes, source, required=True):
        if self.options.get("ocr_engine", "auto") == "none":
            if required: self.warning("ocr_unavailable", "OCR is disabled; image evidence is preserved but its text has not been read.", source, True)
            return
        cache_key = hashlib.sha256(image_bytes).hexdigest()
        if cache_key in self.ocr_cache:
            text, data = self.ocr_cache[cache_key]
            self.ocr_cache_hits += 1
            self.record_ocr(text, data, source, required)
            return
        try:
            from PIL import Image
            original = Image.open(io.BytesIO(image_bytes))
            if original.width * original.height > 40000000:
                self.warning("ocr_image_limit", "Image exceeds the 40-megapixel OCR limit; original image evidence is preserved.", source, required)
                return
            image = original.convert("RGB")
            engine = self.options.get("ocr_engine", "auto")
            binary = self.options.get("tesseract", "tesseract")
            tess = shutil.which(binary)
            words, text = [], ""
            if engine in ("auto", "tesseract") and tess:
                import pytesseract
                pytesseract.pytesseract.tesseract_cmd = tess
                data = pytesseract.image_to_data(image, output_type=pytesseract.Output.DICT, timeout=int(self.options.get("ocr_timeout", 90)))
                lines = {}
                for i, value in enumerate(data["text"]):
                    if not value.strip(): continue
                    score = max(0.0, float(data["conf"][i]) / 100)
                    location = [data[k][i] for k in ("left", "top", "width", "height")]
                    words.append({"text": value, "confidence": score, "bbox": location})
                    key = (data["block_num"][i], data["par_num"][i], data["line_num"][i])
                    lines.setdefault(key, []).append(value)
                text = "\n".join(" ".join(values) for values in lines.values())
                used = "tesseract"
            elif engine in ("auto", "rapidocr") and importlib.util.find_spec("rapidocr_onnxruntime"):
                import numpy as np
                from rapidocr_onnxruntime import RapidOCR
                if self.ocr_engine is None:
                    self.ocr_engine = RapidOCR(intra_op_num_threads=2, inter_op_num_threads=1)
                rows, elapsed = self.ocr_engine(np.array(image))
                for bbox, value, score in rows or []:
                    words.append({"text": str(value), "confidence": float(score), "bbox": bbox})
                text = "\n".join(row["text"] for row in words)
                used = "rapidocr"
            else:
                if required: self.warning("ocr_unavailable", "OCR engine is unavailable. Install RapidOCR or configure Tesseract; original images are preserved.", source, True)
                return
            data = {"engine": used, "items": words, "review_required": True}
            self.ocr_cache[cache_key] = (text, data)
            self.record_ocr(text, data, source, required)
        except Exception as error:
            self.warning("ocr_failed", "OCR failed (" + type(error).__name__ + "); original image evidence is preserved.", source, required)

    def office_archive(self, path):
        archive = zipfile.ZipFile(path)
        entries = archive.infolist()
        if len(entries) > 5000 or sum(item.file_size for item in entries) > int(self.options.get("max_expanded_mb", 100)) * 1024 * 1024:
            archive.close(); raise ExtractionError("Office archive exceeds the configured expanded-size or entry limit.")
        for item in entries:
            parts = Path(item.filename.replace("\\", "/")).parts
            if item.flag_bits & 1 or ".." in parts or item.filename.startswith(("/", "\\")):
                archive.close(); raise ExtractionError("Encrypted or unsafe Office archive rejected.")
            if re.search(r"vbaProject\.bin|\.(exe|dll|bat|cmd|ps1|js|vbs)$", item.filename, re.I):
                archive.close(); raise ExtractionError("Macro-enabled or executable Office content rejected.")
            if item.filename.lower().endswith((".xml", ".rels")):
                xml = archive.read(item).lower()
                if b"<!doctype" in xml or b"<!entity" in xml:
                    archive.close(); raise ExtractionError("Office XML entity declarations are not accepted.")
        return archive

    def extract(self, path, extension=None):
        started = time.perf_counter()
        path = Path(path).resolve()
        ext = (extension or path.suffix.lstrip(".")).lower()
        if ext not in FORMATS: raise ExtractionError("Unsupported document format.")
        if not path.is_file(): raise ExtractionError("The private source file is unavailable.")
        self.result["metadata"].update({"format": ext, "file_size": path.stat().st_size, "sha256": hashlib.sha256(path.read_bytes()).hexdigest()})
        self.progress("Opening document")
        try:
            if ext == "pdf":
                from formats_pdf import extract_pdf
                extract_pdf(self, path)
            elif ext in ("docx", "xlsx"):
                from formats_office import extract_docx, extract_xlsx
                (extract_docx if ext == "docx" else extract_xlsx)(self, path)
            else:
                from formats_data import extract_csv, extract_xls, extract_image
                (extract_csv if ext == "csv" else extract_xls if ext == "xls" else extract_image)(self, path)
        except ModuleNotFoundError as error:
            raise ExtractionError("Missing extraction dependency: " + str(error.name) + ". Install document-processor requirements.") from None
        except ExtractionError as error:
            if not self.result["content"] and not self.result["assets"]: raise
            self.error("extraction_interrupted", str(error))
        except Exception as error:
            message = "Unable to read document (" + type(error).__name__ + "). It may be damaged, encrypted or unsupported."
            if not self.result["content"] and not self.result["assets"]: raise ExtractionError(message) from None
            self.error("extraction_interrupted", message)
        self.result["full_text"] = "\n\f\n".join(self.text)
        if not self.result["content"] and not self.result["assets"]:
            self.warning("empty_document", "No readable content was found; the original document is retained for review.")
        self.result["status"] = "partially_extracted" if self.incomplete else "needs_review" if self.ocr_used or self.result["warnings"] else "extracted"
        self.result["statistics"] = {"units": len(self.result["units"]), "text_blocks": sum(i["type"] in ("text","paragraph","heading","ocr_text","worksheet_text") for i in self.result["content"]),
            "tables": sum(i["type"] == "table" for i in self.result["content"]), "images": sum(i["type"] in ("image","page_preview") for i in self.result["assets"]),
            "embedded_images": sum(i["type"] == "image" for i in self.result["assets"]), "urls": sum(i["type"] == "url" for i in self.result["content"]),
            "candidates": sum(i["type"] == "candidate" for i in self.result["content"]), "ocr_used": self.ocr_used}
        self.result["metadata"]["processing_seconds"] = round(time.perf_counter() - started, 3)
        self.result["metadata"]["ocr_cache_hits"] = self.ocr_cache_hits
        raw = self.output / "raw.json"
        raw.write_text(json.dumps(self.result, ensure_ascii=False, default=json_value, allow_nan=False), encoding="utf-8")
        if raw.stat().st_size > int(self.options.get("max_output_mb", 32)) * 1024 * 1024:
            raise ExtractionError("Extraction JSON exceeds the configured output limit. Evidence is retained; increase the limit and retry.")
        self.progress("Extraction completed" if self.result["status"] == "extracted" else "Extraction completed with review flags", len(self.result["units"]), len(self.result["units"]))
        return self.result
