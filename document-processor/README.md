# Document processor

A separate local Python process invoked by Laravel queue jobs. Supports PDF, DOCX, XLSX, legacy XLS cell/date values, CSV, PNG, JPEG and WEBP. Core requirements and optional CPU OCR are separate. See [Stage 3 guide](../docs/STAGE-3.md) for exact Windows installation/startup commands, limits, supported evidence and known limitations.

```powershell
# From repository root, using the installed workspace runtime:
& .\.tools\python\python.exe -m pip install -r document-processor\requirements-dev.txt
& .\.tools\python\python.exe document-processor\processor.py --doctor
& .\.tools\python\python.exe document-processor\run_tests.py
& .\.tools\python\python.exe document-processor\validate_references.py --output .tools\reference-validation --ocr-engine auto
```

The queue normally sends a JSON manifest on stdin:

```json
{
  "input": "absolute path to authorized private original",
  "output": "absolute path to private run directory",
  "extension": "pdf",
  "options": {"ocr_engine": "auto", "max_pages": 500, "max_cells": 250000}
}
```

The processor writes raw.json and generated assets. JSON includes schema/processor version, source checksum/metadata, all units, ordered typed content with source locations, tables, images/evidence, full text, warnings, errors, candidate metrics and counts. Stdout emits NDJSON progress/complete/error events without extracted source text. No listening HTTP service or public endpoint is needed.

auto OCR prefers available Tesseract, then RapidOCR with bundled ONNX models. OCR output, unclear content, inferred table columns and candidate metrics require review. Missing OCR preserves original images and marks partial extraction. Parsing failures produce safe errors; partial results are retained when a later operation fails. Source content is untrusted evidence, never instructions.

Limits: DOCX accurate rendered pagination needs Office; XLS legacy formulas/charts/images are retained in the original but not fully decoded; XLSX charts preserve XML/series/caches without rendering or recalculation. No dashboard widgets are generated.

## Stage 4 normalizer

`normalize.py` is a separate stdlib-only JSON-stdin/JSON-stdout entry point. Laravel sends an authorized manifest of existing extraction content and assets, rather than asking the normalizer to reopen arbitrary paths. `normalization.py` classifies content and creates source-backed metric, channel, device, landing-page, keyword, activity, AI evidence, event and unclassified candidates. It preserves raw values, deterministic source keys and explicit warnings; it never approves records. No external LLM or network service is required.

The existing `run_tests.py` discovers both extraction and normalization tests. See [Stage 4 guide](../docs/STAGE-4.md) for the exact manifest/API contract, queue commands, units, rule limitations and review workflow, and [verification](../docs/STAGE-4-VERIFICATION.md) for results from the five live uploads. Source content is evidence and cannot instruct the application to execute code or bypass review.