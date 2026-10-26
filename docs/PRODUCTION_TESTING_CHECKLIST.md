# Production testing checklist

Do not authorize deployment until every applicable gate has dated evidence and an owner. Local results are recorded separately in STAGE_7_COMPLETION_REPORT.md.

- [ ] Actual target PHP/extensions, Node/Chromium/fonts, Python/OCR and memory/disk verified as service account.
- [ ] MySQL **8** empty-schema opt-in integration passes; strict utf8mb4/InnoDB, FK/JSON/transaction behavior and additive migration rehearsal verified. MariaDB/SQLite do not satisfy this gate.
- [ ] Existing-data conversion rehearsal preserves all IDs, counts, checksums, JSON and histories in separate staging. No reset/import onto active data.
- [ ] Target production:check --production passes; secure env/key permissions and persistent private root verified; debug off, real SMTP configured.
- [ ] TLS, DNS, Nginx/Apache syntax and SPA/auth/API routes checked; cookies/CSRF/CORS, headers, error redaction and nonpublic storage verified externally.
- [ ] Backend suite, React tests, TypeScript, production build and Python extraction tests pass with isolated fixtures.
- [ ] Three-client Admin/manager/executive/viewer/inactive/archive denial matrix verified on target, including files/evidence/export/retry/history.
- [ ] Real upload through OCR/extraction/normalization/review and private Draft PDF works; Unicode, screenshots, long tables, pagination and URL wrapping inspected.
- [ ] Desktop/mobile reporting and health screens verified; no overflow or cross-client data exposure.
- [ ] Source-backed summary, missing/provisional labels, ranking page groups, retained duplicate observations and unreviewed AI evidence remain factual.
- [ ] Synthetic approved publication/client view and expired/revoked share tests pass; unresolved Draft remains private and blocked.
- [ ] Separate worker supervision, graceful restart, timeouts/retries, locks, scheduler and heartbeat stale/busy states verified under realistic load.
- [ ] Controlled test-mailbox password setup/reset delivery succeeds without logging tokens.
- [ ] Encrypted offsite complete backup and isolated MySQL/files restore verified, with measured RPO/RTO and recovery credentials.
- [ ] Host monitoring, failed-job alerts, disk/log rotation and approved capacity baseline established.
- [ ] Source/staged/full-history secret review complete in actual Git repo; no private artifacts or credentials included.
- [ ] Rollback rehearsed against compatible schema; prior release and complete backup retained; explicit deployment authorization obtained.

Hann's local acceptance must preserve its Draft state, every source/evidence hash, decisions and history. A new private Draft PDF is the only authorized export addition; no approval, publication or share is authorized by this checklist.
