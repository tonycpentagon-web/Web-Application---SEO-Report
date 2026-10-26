# Shared monthly report presentation

These are application-wide report templates, not client-specific overrides. Existing browser previews/public reports and every future generated monthly report use the same components with their own source data.

- All headings use `Inter, "Segoe UI", Arial, sans-serif`.
- Covers show client, website and actual source-period metadata, followed by report actions. No generic Source coverage or Report coverage/limitations panels appear on the client cover. Source validation and the private Source Audit remain enforced.
- Conversions appear first: source-reported grand total plus the available event categories, followed by event chart/table. Do not invent totals. Traffic/search KPIs stay in their respective sections rather than being repeated in an overview.
- Off-page backlink activity shows total submissions and category cards, followed by initially collapsed category URL accordions with URL-count badges. Categories and counts are computed from each report, including new categories. URL entries retain duplicates and provisional status. Only HTTP/HTTPS links are clickable; missing/invalid URLs remain disclosed.
- Future PDF exports use the same conversion and activity grouping, with category URL lists visible in print. Private audit exports preserve detailed evidence. Previously saved PDF files and immutable publication snapshots are not rewritten by a layout change.
- Keep the data, extraction, report generation, source checks, publication snapshots and sharing-token behavior separate from presentation changes. Never copy Base44 demonstration values over actual source values.

Implementation: `frontend/src/components/published-dashboard.tsx`, `conversion-events.tsx`, `seo-activity-groups.tsx`, and `backend/resources/views/reports/pdf.blade.php` with shared partials.

Verification: `seo-activity-groups.test.tsx` checks changes between monthly datasets, additional categories, repeated URLs and unsafe/missing URLs. Laravel delivery tests check dynamic grouping in client PDF output. Live browser evidence is recorded in `local/review/activity-groups-live-results.json`.
