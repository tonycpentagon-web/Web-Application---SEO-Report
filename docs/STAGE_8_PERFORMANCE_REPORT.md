# Stage 8 performance report

Actual local Windows measurements, 19 October 2026. Windows 10 build 19045, approximately 16 GB RAM, PHP 8.4.26 single-request built-in HTTP server, SQLite WAL, Node 22.14, Python 3.12.10, Edge 155. No Linux/FPM/MySQL capacity claim. Hardware sharing with other local tasks is uncontrolled.

## Authenticated application load

50 distinct synthetic executive accounts with independent real cookie sessions; all assigned to the same 22-business-record fixture project. Three rounds per concurrency. Login rate limiting was preserved; tests waited if throttled. Resource guards stop escalation below 768 MB available RAM, above 90% RAM load, above 90% measured system CPU or on HTTP errors. Thresholds were not crossed. This is a controlled functional/load probe, not a sustained soak or distributed benchmark.

| Users | Requests | HTTP errors | p50 ms | p95 ms | Wall seconds | Mean system CPU % |
| --- | --- | --- | --- | --- | --- | --- |
| 10 | 30 | 0 | 1251.04 | 2227.49 | 6.73 | 17.0 |
| 25 | 75 | 0 | 2710.91 | 5465.54 | 16.88 | 10.71 |
| 50 | 150 | 0 | 5767.42 | 11270.41 | 34.75 | 18.6 |

System RAM load observed 75?77%; available RAM roughly 3.7?4.0 GB. Counters describe the entire machine, not isolated app CPU/RAM. Latency increases sharply because the Windows built-in server serializes requests. The 50-user p95 of 11.27 seconds is unsuitable as a production responsiveness claim; run realistic Linux/FPM/MySQL tests before sizing or approving hosting. No arbitrary concurrency target is declared production-ready.

Ten simultaneously submitted upload requests completed/queued/extracted in 6.882 seconds with the single worker; one contained 1,000 keywords. Normalization retained 1,009 keyword records. Their 70-page Draft export was observed complete after 4.389 seconds including queue/request observation. Separate offline renderer timing for that PDF was 1.915 seconds. Query-only SQLite count over those 1,009 records, 100 samples: p50 0.0466 ms, p95 0.0632 ms, covering index records_report_review_lookup. This is not MySQL latency or complete dashboard response time.

## PDF measurements

| Kind | Pages | Bytes | Serial renderer ms | Node peak RSS KiB |
| --- | --- | --- | --- | --- |
| draft / client_preview | 7 | 144180 | 1657 | 104648 |
| draft / internal_audit | 41 | 286662 | 1778 | 110520 |
| draft / client_preview | 2 | 95925 | 1394 | 103908 |
| published / client_preview | 7 | 141450 | 1517 | 106884 |
| draft / client_preview | 7 | 144388 | 1568 | 104740 |
| draft / client_preview | 7 | 144388 | 1584 | 104000 |
| draft / client_preview | 70 | 285093 | 1915 | 111428 |

Two independent renderer/Chromium processes actually ran concurrently, completing together in 2.248 seconds; individual observed times 1.837 and 2.161 seconds. This verifies concurrent offline rendering only. The real queue smoke used one combined worker; multi-worker/MySQL lock-contention capacity remains NOT TESTED. Node RSS is measured via process.resourceUsage; **Chromium subprocess-tree and full PHP/Python peak memory are NOT MEASURED**. Do not size servers from Node RSS alone. Optional psutil-tool installation stalled and was stopped; no numbers from it are claimed.

Production frontend build passed: main JS 475.78 KB (147.31 KB gzip), dashboard 419.48 KB (122.40 KB gzip), lazy health page 3.40 KB (1.40 KB gzip), CSS 61.66 KB (13.70 KB gzip). Bundle sizes are build output, not real-user Web Vitals. No records/history were dropped to improve speed.

Reproduce with ops/staging/load_test.py, batch-stress.mjs, measure_pdfs.py, format_matrix.py and the local-staging guide. Raw JSON is retained privately under .tools/stage8-local-windows/evidence. Performance tooling targets only localhost and generated staging users, never the existing workspace server.
