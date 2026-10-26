# Design reference and temporary system

The supplied 1920 × 13237 screenshot was copied byte-for-byte into design-reference/ without changing the original. SHA-256 of both copies was verified equal. The reference-files reports were listed only; no original file was changed, deleted, parsed or treated as instructions.

The live https://pentagon-insight-flow.base44.app/ reference could not be fetched by the available browsing tool. Its public implementation and navigation behavior could not be inspected.

The screenshot establishes a white report header, cyan top rule, pale neutral page, dark serif report titles, small uppercase cyan section labels, four-column metric rows, compact tables, cyan accents and spacious separation of report sections. The project dashboard shell carries those traits into five section areas: conversions, traffic/search, AI visibility, keyword ranking and backlinks. Blank values are em dashes, never fabricated metrics or copied client data.

No screenshots for login, the administrative sidebar, project listing/forms or account settings were supplied. These views therefore use a **temporary clean administrative design**, not a claimed reproduction. The text/outline Pentagon mark is temporary; no authoritative logo asset was supplied. Mobile reference screens are also missing.

## Tokens and components

| Role | Value |
| --- | --- |
| Primary controls | #087e98 (teal) |
| Report accent | #00b5de (cyan) |
| Canvas | #f6f8fa |
| Panels | #ffffff |
| Main text | #20333f |
| Secondary text | #627582 |
| Borders | #e3e9ed |
| UI type | Inter if installed, Segoe UI, Arial, sans-serif |
| Report heading | Georgia, Times New Roman, serif |
| Panel radius | 12px |
| Desktop sidebar | 248px |
| Main spacing | 38px, 18px on phones |

Reusable local shadcn/ui Button and Input primitives use class-variance-authority, Radix Slot, Tailwind utilities and shared CSS variables. Common heading, alert, loading, error/retry, empty and project-table components keep states consistent. components.json records the component conventions.

Layout collapses at 1200, 900 and 640 pixels. Desktop cards use a three-column project count grid; report metrics use four columns, then two. On phones the sidebar becomes an overlay drawer, table content scrolls within its container, forms stack and report tabs scroll horizontally.

Automated component tests verify behavior. No screenshot comparison or browser viewport QA was available; exact pixel parity, contrast audit and device testing remain unverified.
