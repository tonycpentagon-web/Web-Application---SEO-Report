import { Link, useNavigate, useParams } from "react-router-dom";
import { useAuth } from "../auth";
import { api } from "../lib/api";
import { useApi } from "../lib/use-api";
import { isAdmin, label, roles, roleOf } from "../lib/workspace";
import type { Page, TeamUser } from "../types";
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
  SearchFilters,
  Status,
  useListParams,
} from "../components/workspace";
export function TeamPage() {
  const { user } = useAuth();
  if (!isAdmin(user))
    return <Alert>You do not have permission to manage team members.</Alert>;
  return <TeamList />;
}
function TeamList() {
  const { params, change } = useListParams(),
    q = useApi<Page<TeamUser>>("/api/team?" + params);
  return (
    <>
      <PageHeading
        eyebrow="PEOPLE & ACCESS"
        title="Team management"
        description="Give every employee the right access to their work."
        action={
          <Button asChild>
            <Link to="/team/new">New team member</Link>
          </Button>
        }
      />
      <section className="panel">
        <SearchFilters
          params={params}
          change={change}
          statuses={[]}
          extra={
            <select
              aria-label="Filter role"
              value={params.get("role") || ""}
              onChange={(e) => change("role", e.target.value)}
            >
              <option value="">All roles</option>
              {roles.map((r) => (
                <option key={r} value={r}>
                  {label(r)}
                </option>
              ))}
            </select>
          }
        />
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
                      <th>Name</th>
                      <th>Email</th>
                      <th>Role</th>
                      <th>Access</th>
                      <th>Assigned projects</th>
                    </tr>
                  </thead>
                  <tbody>
                    {q.data.data.map((u) => (
                      <tr key={u.id}>
                        <td>
                          <Link className="text-link" to={"/team/" + u.id}>
                            {u.name}
                          </Link>
                        </td>
                        <td>{u.email}</td>
                        <td>{label(u.role)}</td>
                        <td>
                          <Status value={u.is_active ? "active" : "inactive"} />
                        </td>
                        <td>{u.projects?.length || 0}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <Empty title="No team members found">
                Create an employee account or adjust your search.
              </Empty>
            )}
            <Pager page={q.data} onPage={(n) => change("page", String(n))} />
          </>
        )}
      </section>
    </>
  );
}
export function TeamEditPage() {
  const { id } = useParams();
  const q = useApi<{ data: TeamUser }>("/api/team/" + id);
  if (q.loading) return <Loading />;
  if (q.error || !q.data)
    return <ErrorState message={q.error} retry={q.reload} />;
  return <TeamForm member={q.data.data} />;
}
export function TeamForm({ member }: { member?: TeamUser }) {
  const { user } = useAuth(),
    navigate = useNavigate();
  if (!isAdmin(user))
    return <Alert>You do not have permission to manage team members.</Alert>;
  const allowed =
    roleOf(user) === "super_admin"
      ? roles
      : ["seo_manager", "seo_executive", "client_viewer"];
  return (
    <>
      <Link className="back-link" to="/team">
        ← Team management
      </Link>
      <PageHeading
        eyebrow="PEOPLE & ACCESS"
        title={member ? "Edit team member" : "Create team member"}
        description="Passwords are set privately using a secure email link."
      />
      <div className="form-layout">
        <FormPanel
          button={member ? "Save team member" : "Create team member"}
          save={(d) =>
            member
              ? api.put("/api/team/" + member.id, d)
              : api.post("/api/team", d)
          }
          done={() =>
            navigate(member ? "/team/" + member.id : "/team?created=1")
          }
        >
          <Field
            name="name"
            title="Full name"
            value={member?.name}
            required
            maxLength={255}
          />
          <Field
            name="email"
            title="Email address"
            type="email"
            value={member?.email}
            required
            maxLength={254}
          />
          <Field
            name="role"
            title="Role"
            value={member?.role || "seo_executive"}
            required
            options={allowed.map((r) => ({ value: r, label: label(r) }))}
          />
        </FormPanel>
        <aside className="form-note">
          <h2>Access follows assignments.</h2>
          <p>
            Admin users manage assigned clients and users they provision. Assign
            SEO Managers and Executives from a project's Team tab. Share
            individual published reports explicitly with Client Viewers.
          </p>
          <p>
            Local email uses the configured mail transport. No password is
            displayed or shared here.
          </p>
        </aside>
      </div>
    </>
  );
}
export function TeamDetails() {
  const { id } = useParams(),
    { user } = useAuth(),
    q = useApi<{ data: TeamUser }>("/api/team/" + id);
  if (q.loading) return <Loading />;
  if (q.error || !q.data)
    return <ErrorState message={q.error} retry={q.reload} />;
  const u = q.data.data;
  return (
    <>
      <Link className="back-link" to="/team">
        ← Team management
      </Link>
      <PageHeading
        eyebrow="PEOPLE & ACCESS"
        title={u.name}
        description={u.email}
        action={
          <Button asChild variant="outline">
            <Link to={"/team/" + id + "/edit"}>Edit account</Link>
          </Button>
        }
      />
      <section className="panel form-panel">
        <h2>{label(u.role)}</h2>
        <Status value={u.is_active ? "active" : "inactive"} />
        <div className="action-row">
          {user?.id !== u.id && (
            <Action
              title={u.is_active ? "Deactivate access" : "Activate access"}
              path={"/api/team/" + id}
              body={{
                name: u.name,
                email: u.email,
                role: u.role,
                is_active: !u.is_active,
              }}
              done={q.reload}
            >
              This changes account access without deleting the user's projects
              or reporting history.
            </Action>
          )}
          {u.is_active && (
            <Action
              title="Send password setup link"
              method="post"
              path={"/api/team/" + id + "/password-reset"}
              body={{}}
              done={() => {}}
            >
              A private password setup email will be sent to this account's
              address. No password or token is displayed here.
            </Action>
          )}
        </div>
      </section>
      <section className="panel section-gap">
        <div className="panel-heading">
          <h2>Assigned projects</h2>
          <Button asChild variant="outline">
            <Link to="/projects">Assign from project Team tab</Link>
          </Button>
        </div>
        {u.projects?.length ? (
          <ul className="record-list">
            {u.projects.map((p) => (
              <li key={p.id}>
                <Link className="text-link" to={"/projects/" + p.id}>
                  {p.name}
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <Empty title="No assigned projects">
            Assign this user from the Team tab of a permitted project.
          </Empty>
        )}
      </section>
    </>
  );
}
