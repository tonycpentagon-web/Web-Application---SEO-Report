# Product audit and gap analysis

Audit date: 20 October 2026. Existing application retained; no deployment authorized until actual server information and compatibility approval.

## Phase A - existing capabilities

Laravel session authentication; five roles (stored admin = SEO Admin); organization/project assignments; active accounts; private multi-format sources and Python extraction/OCR; retained unclassified content and warnings; automatic monthly generation/commentary; independent monthly periods; optimistic report/record/commentary revisions; verified review; immutable published snapshots; authenticated hashed-token shares; client history/comparisons; protected PDF jobs and offline rendering. Existing Windows runtime remains in use.

Schema inspected: clients -> projects -> reporting_periods -> source documents, generation/normalization records, approvals, publications, exports and shares. Project/client memberships and report-specific viewer grants govern scope. report_delivery_events is append-only audit storage and can hold review acknowledgements without a schema change. No migration/reset is required for the planned restructuring. A fresh SQLite backup and field/file baseline were captured privately under .tools/product-restructuring-* before changes.

## Phase B - gaps and implementation

| Requirement | Audit finding | Incremental change |
|---|---|---|
| Daily employee overview | Project-only counters and obsolete stage copy | Scoped report pipeline counters, recent reports and business actions |
| Three application areas | Client portal exists; advanced tools mixed into sidebar | Workspace navigation, authorized Administration hub, explicit client history |
| Simple monthly generation | Working uploader/generator; advanced links dominate | Primary preview/submit/review flow; retain source audit and detailed review under Advanced Review |
| Manager review | Existing statuses/version checks and source safeguards | Report-level checklist and existing status transitions in Publish and Share |
| Evidence review | Detailed verification already works | Summarize checks; append human dashboard/PDF review evidence bound to current sources; never auto-approve records |
| Client report design | Reference-style report and matching PDF already built | Preserve design; include optional source-provided organic KPI |
| Multi-employee/client isolation | Server policies, assignments and concurrency exist | Add realistic assigned employee/manager scope and dashboard/checklist tests |
| Deployment | Sanitized nginx/Supervisor/env/backup templates exist | Read-only target-host audit, guarded preparation package, hosting decision matrix and operator manuals |
| Target hosting | No server access/specifications provided | Actual compatibility remains pending; no deployment or credentials transfer |

Publication eligibility remains enforced by ReportDelivery/ReportDashboard. Detailed records remain available in Advanced Review. Human visual-review acknowledgements supplement existing safeguards; no uncertain data is automatically approved. Client access is a delivery step after publication, avoiding a circular requirement to share a Draft.
