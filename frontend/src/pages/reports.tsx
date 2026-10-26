import {LifecycleActions} from '../components/lifecycle-actions';
import { ReportDocuments } from "../components/documents";
import { useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api } from "../lib/api";
import { useApi } from "../lib/use-api";
import { label, useOptions } from "../lib/workspace";
import type { Page, Project, ReportPeriod, TeamUser } from "../types";
import { Button } from "../components/ui/button";
import {
  Alert,
  Empty,
  ErrorState,
  Loading,
  PageHeading,
} from "../components/common";
import {
  Action,
  Field,
  FormPanel,
  Pager,
  Status,
} from "../components/workspace";
import { MemberChecks } from "./clients";
export function ReportsList({ project: p }: { project: Project }) {
  const [page, setPage] = useState(1),
    [status, setStatus] = useState("");
  const q = useApi<Page<ReportPeriod>>(
    "/api/projects/" + p.id + "/reports?page=" + page + "&status=" + status,
  );
  return (
    <section className="panel">
      <div className="panel-heading">
        <div>
          <h2>Monthly reports</h2>
          <p>Manage monthly reports and their preserved history.</p>
        </div>
        {p.permissions?.work_reports &&
          p.status === "active" &&
          p.client?.status !== "archived" && (
            <div><Button asChild>
              <Link to={"/monthly-reports/new?project=" + p.id}>Create Monthly Report</Link>
            </Button><details className="advanced-review"><summary>Other reporting options</summary><Link className="text-link" to={"/projects/" + p.id + "/reports/new"}>Custom reporting period</Link></details></div>
          )}
      </div>
      <div className="filter-bar">
        <label>
          Report status{" "}
          <select
            aria-label="Report status filter"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
          >
            <option value="">All statuses</option>
            {[
              "draft",
              "processing",
              "needs_review",
              "approved",
              "published",
              "failed",
              "archived",
            ].map((s) => (
              <option key={s} value={s}>
                {label(s)}
              </option>
            ))}
          </select>
        </label>
      </div>
      {q.loading ? (
        <Loading />
      ) : q.error || !q.data ? (
        <ErrorState message={q.error} retry={q.reload} />
      ) : (
        <>
          {q.data.data.length ? (
            <div className="table-scroll">
              <table>
                <thead>
                  <tr>
                    <th>Report / period</th>
                    <th>Date range</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Updated</th>
                  </tr>
                </thead>
                <tbody>
                  {q.data.data.map((r) => (
                    <tr key={r.id}>
                      <td>
                        <Link
                          className="text-link"
                          to={"/projects/" + p.id + "/reports/" + r.id}
                        >
                          {r.title}
                        </Link>
                        <small className="block muted">
                          {r.reporting_month}
                        </small>
                      </td>
                      <td>
                        {r.start_date} — {r.end_date}
                      </td>
                      <td>
                        <Status value={r.status} /><LifecycleActions type="report" id={r.id} status={r.status} filed={!!r.workspace_archived_at} manage={!!r.can_share} view={"/projects/"+p.id+"/reports/"+r.id+"/generate"} done={q.reload}/>
                      </td>
                      <td>{new Date(r.created_at).toLocaleDateString()}</td>
                      <td>{new Date(r.updated_at).toLocaleDateString()}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <Empty title="No reporting periods">
              Create a reporting period to start organizing this project's
              monthly work. Viewers see only explicitly shared published
              reports.
            </Empty>
          )}
          <Pager page={q.data} onPage={setPage} />
        </>
      )}
    </section>
  );
}
export function ReportCreatePage() {
  const { id } = useParams(),
    q = useApi<{ data: Project }>("/api/projects/" + id);
  if (q.loading) return <Loading />;
  if (q.error || !q.data)
    return <ErrorState message={q.error} retry={q.reload} />;
  if (!q.data.data.permissions?.work_reports)
    return <Alert>You do not have permission to create reports.</Alert>;
  return <ReportForm projectId={id!} />;
}
export function ReportEditPage() {
  const { id, reportId } = useParams(),
    q = useApi<{ data: ReportPeriod }>(
      "/api/projects/" + id + "/reports/" + reportId,
    );
  if (q.loading) return <Loading />;
  if (q.error || !q.data)
    return <ErrorState message={q.error} retry={q.reload} />;
  if (!q.data.data.can_edit)
    return (
      <Alert>
        This report cannot be edited. Return it to draft where permitted.
      </Alert>
    );
  return <ReportForm projectId={id!} report={q.data.data} />;
}
function ReportForm({
  projectId,
  report,
}: {
  projectId: string;
  report?: ReportPeriod;
}) {
  const navigate = useNavigate(),
    [kind, setKind] = useState(report?.kind || "monthly");
  return (
    <>
      <Link
        className="back-link"
        to={
          "/projects/" +
          projectId +
          "/reports" +
          (report ? "/" + report.id : "")
        }
      >
        ← Back to reports
      </Link>
      <PageHeading
        eyebrow="REPORT WORKSPACE"
        title={report ? "Edit reporting period" : "Create reporting period"}
        description="Organize a month or a custom date range. Each report keeps its own history."
      />
      <div className="form-layout">
        <FormPanel
          button={report ? "Save reporting period" : "Create reporting period"}
          save={async (d) => {
            const response = report
              ? await api.put(
                  "/api/projects/" + projectId + "/reports/" + report.id,
                  { ...d, version: report.version },
                )
              : await api.post("/api/projects/" + projectId + "/reports", d);
            navigate(
              "/projects/" + projectId + "/reports/" + response.data.data.id,
            );
            return response;
          }}
        >
          <Field
            name="title"
            title="Report title"
            value={report?.title}
            required
            maxLength={180}
          />
          <Field
            name="reporting_month"
            title="Reporting month"
            type="month"
            value={report?.reporting_month}
            required
          />
          <label>
            Period type
            <select
              name="kind"
              value={kind}
              onChange={(e) => setKind(e.target.value as "monthly" | "custom")}
            >
              <option value="monthly">Full calendar month</option>
              <option value="custom">Custom date range</option>
            </select>
          </label>
          {kind === "custom" && (
            <>
              <Field
                name="start_date"
                title="Start date"
                type="date"
                value={report?.start_date}
                required
              />
              <Field
                name="end_date"
                title="End date"
                type="date"
                value={report?.end_date}
                required
              />
            </>
          )}
        </FormPanel>
        <aside className="form-note">
          <h2>Every period stands on its own.</h2>
          <p>
            A calendar month automatically uses its first and last dates. Custom
            ranges may share a reporting month, but an identical date range
            cannot be created twice, even if archived.
          </p>
          <p>
            All new reports start in Draft. Upload source documents to extract and review their contents; open the internal Draft Dashboard Preview after normalization.
          </p>
        </aside>
      </div>
    </>
  );
}
export function ReportDetails() {
  const { id, reportId } = useParams(),
    q = useApi<{ data: ReportPeriod }>(
      "/api/projects/" + id + "/reports/" + reportId,
    );
  if (q.loading) return <Loading />;
  if (q.error || !q.data)
    return <ErrorState message={q.error} retry={q.reload} />;
  return (
    <ReportContent
      key={q.data.data.version + String(q.data.data.shared_user_ids)}
      projectId={id!}
      report={q.data.data}
      reload={q.reload}
    />
  );
}
function ReportContent({
  projectId,
  report: r,
  reload,
}: {
  projectId: string;
  report: ReportPeriod;
  reload: () => void;
}) {
  const [next, setNext] = useState(""),
    options = useOptions<TeamUser>(
      r.can_share ? "/api/team/options?role=client_viewer" : null,
    );
  return (
    <>
      <Link className="back-link" to={"/projects/" + projectId + "/reports"}>
        ← Project reports
      </Link>
      <PageHeading
        eyebrow="REPORTING PERIOD"
        title={r.title}
        description={
          r.start_date + " — " + r.end_date + " · " + r.reporting_month
        }
        action={
          r.can_edit && (
            <Button asChild variant="outline">
              <Link
                to={"/projects/" + projectId + "/reports/" + r.id + "/edit"}
              >
                Edit period
              </Link>
            </Button>
          )
        }
      />
      <section className="panel form-panel">
        <div className="action-row">
          <Status value={r.status} />
          <span className="muted">Revision {r.version}</span>
        </div>
        <p className="muted">
          Created {new Date(r.created_at).toLocaleString()} · Updated{" "}
          {new Date(r.updated_at).toLocaleString()}
        </p>
        {r.allowed_transitions.length > 0 && (
          <div className="action-row">
            <label className="sr-only" htmlFor="next-status">
              Next report status
            </label>
            <select
              id="next-status"
              value={next}
              onChange={(e) => setNext(e.target.value)}
            >
              <option value="">Choose status transition</option>
              {r.allowed_transitions.filter(s=>s!=="published").map((s) => (
                <option key={s} value={s}>
                  {label(s)}
                </option>
              ))}
            </select>
            {next && (
              <Action
                title={"Move to " + label(next)}
                method="patch"
                path={
                  "/api/projects/" + projectId + "/reports/" + r.id + "/status"
                }
                body={{ status: next, version: r.version }}
                done={reload}
              >
                This change is recorded in the report history. Publication
                allows access only for explicitly selected Client Viewers.
              </Action>
            )}
          </div>
        )}
      </section>
      {r.view_documents&&<section className="panel form-panel section-gap"><h2>Report Delivery</h2><p>Publication readiness, PDF export, version history and secure client sharing.</p><Button asChild><Link to={"/projects/"+projectId+"/reports/"+r.id+"/delivery"}>Share Report</Link></Button>{r.status==='published'&&r.can_share&&<Button asChild><Link to={"/projects/"+projectId+"/reports/"+r.id+"/delivery#client-sharing"}>Share Report with Client</Link></Button>}</section>}
      {r.view_documents&&<section className="panel form-panel section-gap"><h2>Internal Draft Preview</h2><Link className="text-link" to={"/projects/"+projectId+"/reports/"+r.id+"/generate"}>Upload Documents & Generate SEO Report</Link><p>Explore current approved and provisional observations with source evidence. Preview the report, optionally edit the narrative, then share a published snapshot.</p><Button asChild><Link to={"/projects/"+projectId+"/reports/"+r.id+"/dashboard"}>Open Draft Dashboard Preview</Link></Button> <Button asChild variant="outline"><Link to={"/projects/"+projectId+"/reports/"+r.id+"/preview"}>Client-style Draft Preview</Link></Button></section>}
      {r.view_documents && <section className="panel form-panel section-gap"><h2>Advanced Review</h2><p className="muted">Inspect extracted report data, resolve source warnings and review evidence before publication.</p><Button asChild variant="outline"><Link to={"/projects/"+projectId+"/reports/"+r.id+"/data"}>Extracted Report Data</Link></Button></section>}
      {r.view_documents ? <ReportDocuments projectId={projectId} reportId={r.id} canUpload={!!r.can_upload_documents}/> : <section className="panel section-gap"><Empty title={r.status === "published" ? "Published client report" : "Dashboard data is not yet available"} action={<Button asChild><Link to={"/projects/"+projectId+"/reports/"+r.id+"/client"}>Open Client Report</Link></Button>}>Open the published report to view the approved client dashboard.</Empty></section>}      {r.can_share && (
        <section className="section-gap">
          {options.loading ? (
            <Loading />
          ) : options.error ? (
            <Alert>{options.error}</Alert>
          ) : (
            <FormPanel
              button="Save report sharing"
              save={(d) =>
                api.put(
                  "/api/projects/" + projectId + "/reports/" + r.id + "/shares",
                  {
                    expires_at: d.expires_at ? new Date(String(d.expires_at)).toISOString() : null,
                    allow_download: d.allow_download === "on",
                    user_ids: String(d.viewer_ids || "")
                      .split(",")
                      .filter(Boolean)
                      .map(Number),
                  },
                )
              }
              done={reload}
            >
              <h2>Explicit client report sharing</h2>{r.publication&&!r.publication.allowed&&<Alert>New client sharing is blocked until validation and review are complete. Clearing all selected viewers is still permitted.</Alert>}
              <p className="muted">
                Only selected active Client Viewers can read this report, and
                only while its status is Published. Other periods remain
                private.
              </p>
              <Field title="Access expires at" name="expires_at" type="datetime-local"/>
              <label><input type="checkbox" name="allow_download"/> Allow published PDF download</label>
              <MemberChecks
                name="viewer_ids"
                users={options.data}
                selected={r.shared_user_ids || []}
              />
            </FormPanel>
          )}
        </section>
      )}
      {!!r.history?.length && (
        <section className="panel section-gap">
          <div className="panel-heading">
            <h2>Report history</h2>
          </div>
          <ul className="record-list">
            {r.history.map((a) => (
              <li key={a.id}>
                <strong>{label(a.action.replaceAll(".", " "))}</strong>
                <small>
                  {a.actor?.name || "System"} ·{" "}
                  {new Date(a.created_at).toLocaleString()}
                </small>
                <details>
                  <summary>Change details</summary>
                  <pre>{JSON.stringify(a.properties, null, 2)}</pre>
                </details>
              </li>
            ))}
          </ul>
        </section>
      )}
    </>
  );
}
