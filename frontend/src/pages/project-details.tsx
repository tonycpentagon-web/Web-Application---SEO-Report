import { DocumentList } from "../components/documents";
import { Link, useParams } from "react-router-dom";
import { useApi } from "../lib/use-api";
import { api } from "../lib/api";
import { label, useOptions } from "../lib/workspace";
import type { Project, TeamUser } from "../types";
import { Button } from "../components/ui/button";
import { Alert, Empty, ErrorState, Loading } from "../components/common";
import { Action, Field, FormPanel, Status } from "../components/workspace";
import { MemberChecks } from "./clients";
import { ProjectForm } from "./project-form";
import { ReportsList } from "./reports";
export function ProjectDetails() {
  const { id, tab = "overview" } = useParams(),
    q = useApi<{ data: Project }>("/api/projects/" + id);
  if (q.loading) return <Loading />;
  if (q.error || !q.data)
    return <ErrorState message={q.error} retry={q.reload} />;
  const p = q.data.data;
  const tabs = p.permissions?.view_internal
    ? [
        "overview",
        "reports",
        "files",
        "team",
        ...(p.permissions.manage ? ["settings"] : []),
      ]
    : ["overview", "reports"];
  const selected = tabs.includes(tab) ? tab : "overview";
  return (
    <>
      <Link to="/projects" className="back-link">
        ← All projects
      </Link>
      <section
        className="project-hero"
        style={{ borderTopColor: p.client?.brand_color }}
      >
        <div className="eyebrow">PENTAGON · CLIENT WORKSPACE</div>
        <div className="project-title-row">
          <h1>{p.name}</h1>
          <Status value={p.status} />
        </div>
        <p>{p.description || "Your dedicated SEO project workspace."}</p>
        <div className="project-meta">
          <div>
            <small>CLIENT</small>
            <Link className="text-link" to={"/clients/" + p.client_id}>
              {p.client_name}
            </Link>
          </div>
          <div>
            <small>WEBSITE</small>
            <a href={p.website} target="_blank" rel="noopener noreferrer">
              {new URL(p.website).hostname} ↗
            </a>
          </div>
          <div>
            <small>REPORTING FREQUENCY</small>
            <strong>{label(p.reporting_frequency || "monthly")}</strong>
          </div>
        </div>
      </section>
      <nav className="report-tabs" aria-label="Project tabs">
        {tabs.map((t) => (
          <Link
            key={t}
            className={selected === t ? "selected" : ""}
            aria-current={selected === t ? "page" : undefined}
            to={"/projects/" + id + (t === "overview" ? "" : "/" + t)}
          >
            {label(t)}
          </Link>
        ))}
      </nav>
      {selected === "overview" && (
        <>
          <div className="stats-grid">
            <div className="stat-card">
              <div className="stat-top">Reporting periods</div>
              <strong>{p.reporting_periods_count || 0}</strong>
            </div>
            <div className="stat-card">
              <div className="stat-top">Latest report</div>
              {p.latest_report ? (
                <>
                  <h3>
                    <Link
                      className="text-link"
                      to={"/projects/" + id + "/reports/" + p.latest_report.id + "/generate"}
                    >
                      {p.latest_report.title}
                    </Link>
                  </h3>
                  <Status value={p.latest_report.status} />
                </>
              ) : (
                <p className="muted">No report created yet</p>
              )}
            </div>
          </div>
          <section className="panel form-panel">
            <h2>Project overview</h2>
            <p className="preserve-lines muted">
              {p.description || "No description provided."}
            </p>
            <p className="muted">
              Client: {p.client_name} · Status: {label(p.status)}
            </p>
            {p.permissions?.view_internal && (
              <>
                <h3 className="section-gap">Assigned team</h3>
                <p className="muted">
                  {p.members?.length
                    ? p.members
                        .map(
                          (m) => m.name + " (" + label(m.assignment_role) + ")",
                        )
                        .join(", ")
                    : "No SEO team assigned yet."}
                </p>
              </>
            )}
          </section>
          {p.permissions?.view_internal && (
            <section className="panel section-gap">
              <div className="panel-heading">
                <h2>Recent activity</h2>
              </div>
              {p.recent_activity?.length ? (
                <ul className="record-list">
                  {p.recent_activity.map((a) => (
                    <li key={a.id}>
                      <strong>{label(a.action.replaceAll(".", " "))}</strong>
                      <small>
                        {a.actor?.name || "System"} ·{" "}
                        {new Date(a.created_at).toLocaleString()}
                      </small>
                    </li>
                  ))}
                </ul>
              ) : (
                <Empty title="No activity yet">
                  Future project and report changes will be recorded here.
                </Empty>
              )}
            </section>
          )}
        </>
      )}
      {selected === "reports" && <ReportsList project={p} />}
      {selected === "files" && <section className="panel"><div className="panel-heading"><div><h2>Project documents</h2><p>Open a reporting period to upload documents for that month.</p></div></div><DocumentList path={"/api/projects/"+p.id+"/documents"}/></section>}      {selected === "team" && <ProjectTeam project={p} reload={q.reload} />}
      {selected === "settings" &&
        (p.permissions?.manage ? (
          <>
            <ProjectForm project={p} embedded />
            <section className="panel form-panel section-gap">
              <h2>Project lifecycle</h2>
              <p className="muted">
                Archiving retains all reports and history. Restore the project
                to resume report work.
              </p>
              <Action
                title={
                  p.status === "active" ? "Archive project" : "Restore project"
                }
                path={"/api/projects/" + id}
                body={{
                  ...p,
                  client_id: p.client_id,
                  status: p.status === "active" ? "archived" : "active",
                }}
                done={q.reload}
              />
              {p.client?.permissions.manage && (
                <Button asChild variant="outline">
                  <Link to={"/clients/" + p.client_id + "/edit"}>
                    Configure client branding
                  </Link>
                </Button>
              )}
            </section>
          </>
        ) : (
          <Alert>You do not have permission to change project settings.</Alert>
        ))}
    </>
  );
}
function ProjectTeam({
  project: p,
  reload,
}: {
  project: Project;
  reload: () => void;
}) {
  const options = useOptions<TeamUser>(
    p.permissions?.manage ? "/api/team/options" : null,
  );
  return (
    <>
      <section className="panel">
        <div className="panel-heading">
          <h2>Assigned SEO team</h2>
        </div>
        {p.members?.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Application role</th>
                  <th>Assignment</th>
                </tr>
              </thead>
              <tbody>
                {p.members.map((m) => (
                  <tr key={m.id}>
                    <td>{m.name}</td>
                    <td>{m.email}</td>
                    <td>{label(m.role)}</td>
                    <td>{label(m.assignment_role)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty title="No SEO team assigned">
            Assign a manager and executives below, or ask a permitted
            administrator.
          </Empty>
        )}
      </section>
      {p.permissions?.manage &&
        (options.loading ? (
          <Loading />
        ) : options.error ? (
          <Alert>{options.error}</Alert>
        ) : (
          <div className="section-gap">
            <FormPanel
              button="Save project team"
              save={(d) =>
                api.put("/api/projects/" + p.id + "/team", {
                  manager_id: d.manager_id ? Number(d.manager_id) : null,
                  member_ids: String(d.member_ids || "")
                    .split(",")
                    .filter(Boolean)
                    .map(Number),
                })
              }
              done={reload}
            >
              <Field
                name="manager_id"
                title="Assigned SEO Manager"
                value={String(
                  p.members?.find((m) => m.assignment_role === "manager")?.id ||
                    "",
                )}
                options={[
                  { value: "", label: "No manager" },
                  ...options.data
                    .filter((u) => u.role === "seo_manager")
                    .map((u) => ({ value: String(u.id), label: u.name })),
                ]}
              />
              <h3 className="section-gap">SEO Executives</h3>
              <MemberChecks
                name="member_ids"
                users={options.data.filter((u) => u.role === "seo_executive")}
                selected={
                  p.members
                    ?.filter((m) => m.assignment_role === "member")
                    .map((m) => m.id) || []
                }
              />
              <p className="footnote">
                Only active users in your permitted organization are available.
                Removing a membership revokes project access immediately.
              </p>
            </FormPanel>
          </div>
        ))}
    </>
  );
}
