# Synthetic monthly SEO fixtures

These twelve PDFs are artificial test data, not client documents. Each month contains six supported source types: GA4, GSC, keyword rankings, SEO work, AI screenshot evidence and event counts. They exercise the real upload validator, Python extraction, normalization, source references, Draft renderer, review workflow and client authorization in `MonthlyAutomationTest`.

August: 812 users, 701 new users, 91 clicks, 2,100 impressions, 13 events.
September: 937 users, 820 new users, 118 clicks, 2,600 impressions, 19 events.

Both contain a page-group keyword ranking and duplicate activity observations. The AI image is explicitly labeled synthetic test evidence. No Hann's Pharmacy source data is included.

All fixture records are reviewed only inside an isolated test database. The application generation job never approves, verifies, rejects or publishes source records automatically.
