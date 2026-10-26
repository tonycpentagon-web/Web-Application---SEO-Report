# Automated validation: actual test results

Completed locally on 20 October 2026. No Docker, demo/production deployment, or public tunnel was used.

| Check | Actual result |
| --- | --- |
| Full Laravel suite, final standalone run | **164 passed, 1 skipped; 2,952 assertions; 293.43 seconds** |
| React / Vitest | **112 passed across 14 files; 10.61 seconds** |
| Python extraction and normalization | **36 passed; 18.203 seconds**, including available real OCR |
| TypeScript | Passed |
| React same-origin production build | Passed; existing large-chunk advisory remains (main entry approximately 528.5 kB, 160.9 kB gzip) |
| Browser generation/exception review/manager approval/publication/archival/next month/revocation | Passed; no uncaught page errors |
| Independent anonymous browser: Draft/admin rejection, published charts/images, real public PDF download, revocation | Passed |
| Desktop/mobile summary layout | Four columns on desktop, two on 390px mobile; no horizontal document overflow |
| PDF artifacts | Both private Draft and published downloads are valid nine-page PDFs |
| Working-data preservation | **19,437 existing rows and 1,463 tracked files unchanged** |
| Original rows in disposable browser copy after re-evaluation | **19,437 existing rows unchanged** |
| Main application enrollment | Zero new validation runs, zero manager approvals; original reports await explicit operator action |

The skipped Laravel test is the existing opt-in MySQL 8 integration gate: `STAGE7_MYSQL_ALLOW=YES` and a separately provisioned `pentagon_test_*` database were not configured. SQLite behavior was exercised; no MySQL result is claimed.

An intermediate overlapping Laravel run collided with another Laravel run in shared temporary fake storage and produced missing-file/verification errors. Those results were discarded. The final full suite ran alone and passed. Two older normalization fixtures were updated to supply internally consistent source-file checksums and an explicit native reporting period, so their intentional manual-correction tests meet the new validation prerequisites. The safeguards were retained.

Commands:

```powershell
# backend working directory
& ../.tools/php/php.exe artisan test --compact

# frontend working directory
npm.cmd test -- --configLoader runner

# repository root
& .tools/python/python.exe document-processor/run_tests.py
node frontend/node_modules/typescript/bin/tsc -b frontend/tsconfig.json
# Vite production build executed with VITE_API_URL=/
```

Private browser evidence and result JSON are in `.tools/automatic-validation/`. The main local frontend still serves browser HTML at http://127.0.0.1:5173/login; protected backend API access returns 401 without login. The existing main worker was gracefully refreshed to load the new generation code. Disposable browser processes were stopped after validation; their copies/artifacts remain available locally.

See [workflow, validation rules, schema, screenshots and limitations](AUTOMATED_SOURCE_VALIDATION.md).
