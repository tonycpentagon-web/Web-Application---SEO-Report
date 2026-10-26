# Stage 8 PDF verification

PASS for actual Windows synthetic exports; Linux/MySQL-backed PDF worker execution remains BLOCKED. All seven source exports are private synthetic fixtures, not Hann's. Existing ten Hann's export artifacts remain untouched.

| Export | Profile / type | Pages | Bytes | Images |
| --- | --- | --- | --- | --- |
| 01m59dv41s7qr4p1x4xb98r5kv | client_preview / draft | 7 | 144180 | 1 |
| 01m59dv4car36rzm697jvj6nnt | internal_audit / draft | 41 | 286662 | 1 |
| 01m59dv4rrgd0yhxfqpq1hv7jh | client_preview / draft | 2 | 95925 | 0 |
| 01m59dv6v0h5grktcyd5qhhp5t | client_preview / published | 7 | 141450 | 1 |
| 01m59dw33xsnm05y7rgbd2frvh | client_preview / draft | 7 | 144388 | 1 |
| 01m59dw3d46qepqjr1xgecer91 | client_preview / draft | 7 | 144388 | 1 |
| 01m59dzb0bes5sv0h3g5p5yy1c | client_preview / draft | 70 | 285093 | 0 |

Actual checks: checksum matches stored export metadata; correct Draft watermark on every draft page and no Draft watermark on published pages; page numbers on every page; no detected text-boundary clipping; images render; selected-section export contains only selected content after its cover; full audit registry IDs retained. The large export contains every one of 1,009 keywords, with page-group labels preserved. Its repeated table header and readable rows were visually inspected, as was the mobile health screen. Programmatic bounds do not prove every visual nuance across all fonts/hosts.

The synthetic complete workflow extracted source documents, normalized 22 business records, required an explicit AI absence decision, verified source hashes, approved fixture records and source-bound commentary, then used normal status transitions and publication APIs. Published PDF and private download/sharing were tested only for that fixture. Foreign download attempts were denied. No real approvals/publication were changed. Missing/partial extraction warnings remain visible; the large keyword report remains provisional.

Two concurrently submitted queue requests completed; separate actual simultaneous offline renderer processes also completed. Backend tests exercise real renderer failure, explicit frozen-snapshot retry, duplicate job idempotency, audit-profile restrictions and publication races. Multi-worker MySQL PDF execution and full browser-process memory remain NOT TESTED. Successful job completion alone was not used as PDF correctness evidence.

Measured serial render durations 1.394?1.915 seconds; Node RSS 103,908?111,428 KiB. Queue created-to-completed timestamps ranged 2?7 seconds and include waiting/coarse timestamp resolution. Full figures and measurement scope are in STAGE_8_PERFORMANCE_REPORT.md.

Reproduce: invoke ops/staging/fixture.php inspect within the isolated environment, run ops/staging/inspect_pdfs.py, and use fixture.php benchmark-html plus measure_pdfs.py. The inspector honors selected sections rather than incorrectly requiring omitted keyword tables. Existing evidence lives only in ignored .tools/stage8-local-windows/evidence; do not publish the private archive or test credentials.
