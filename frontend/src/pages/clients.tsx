import {LifecycleActions} from '../components/lifecycle-actions';
import { useState } from "react";
import {
  Link,
  useNavigate,
  useParams,
  useSearchParams,
} from "react-router-dom";
import { useAuth } from "../auth";
import { useApi } from "../lib/use-api";
import { api, errorMessage, notify } from "../lib/api";
import { isAdmin, logoUrl, useOptions } from "../lib/workspace";
import type { Client, Page, TeamUser } from "../types";
import { Button } from "../components/ui/button";
import {
  Alert,
  Empty,
  ErrorState,
  Loading,
  PageHeading,
  ProjectTable,
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
export function ClientsPage() {
  const { user } = useAuth(),
    { params, change } = useListParams();
  const { data, error, loading, reload } = useApi<Page<Client>>(
    "/api/clients?" + params,
  );
  return (
    <>
      <PageHeading
        eyebrow="CLIENT WORKSPACE"
        title="Clients"
        description="Every relationship, organized in one place."
        action={
          isAdmin(user) && (
            <Button asChild>
              <Link to="/clients/new">New client</Link>
            </Button>
          )
        }
      />
      <section className="panel">
        <SearchFilters params={params} change={change} />
        {loading ? (
          <Loading />
        ) : error || !data ? (
          <ErrorState message={error} retry={reload} />
        ) : (
          <>
            {!data.data.length ? (
              <Empty title="No clients found">
                Create a client or adjust your filters. Only permitted clients
                appear here.
              </Empty>
            ) : (
              <div className="table-scroll">
                <table>
                  <thead>
                    <tr>
                      <th>Client</th>
                      <th>Website</th>
                      <th>Projects</th>
                      <th>Reporting periods</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.data.map((c) => (
                      <tr key={c.id}>
                        <td>
                          <Link
                            className="project-name"
                            to={"/clients/" + c.id}
                          >
                            {c.has_logo ? (
                              <img
                                className="client-logo"
                                src={logoUrl(c.id)}
                                alt=""
                              />
                            ) : (
                              <span
                                className="project-avatar"
                                style={{
                                  borderTop: "3px solid " + c.brand_color,
                                }}
                              >
                                {c.name.slice(0, 2)}
                              </span>
                            )}
                            <span>
                              <strong>{c.name}</strong>
                              <small>{c.company_name}</small>
                            </span>
                          </Link>
                        </td>
                        <td>{c.website ? new URL(c.website).hostname : "—"}</td>
                        <td>{c.projects_count}</td>
                        <td>{c.reporting_periods_count}</td>
                        <td>
                          <Status value={c.status} /><LifecycleActions type="client" id={c.id} status={c.status} manage={!!c.permissions?.manage} view={"/clients/"+c.id} edit={"/clients/"+c.id+"/edit"} done={reload}/>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <Pager page={data} onPage={(n) => change("page", String(n))} />
          </>
        )}
      </section>
    </>
  );
}
export function ClientEditPage() {
  const { id } = useParams();
  const q = useApi<{ data: Client }>("/api/clients/" + id);
  if (q.loading) return <Loading />;
  if (q.error || !q.data)
    return <ErrorState message={q.error} retry={q.reload} />;
  return <ClientForm client={q.data.data} />;
}
export function ClientForm({ client }: { client?: Client }) {
  const { user } = useAuth(),
    navigate = useNavigate();
  if (client ? !client.permissions.manage : !isAdmin(user))
    return <Alert>You do not have permission to manage this client.</Alert>;
  return (
    <>
      <Link
        className="back-link"
        to={client ? "/clients/" + client.id : "/clients"}
      >
        ← Back to clients
      </Link>
      <PageHeading
        eyebrow="CLIENT WORKSPACE"
        title={client ? "Edit client" : "Create client"}
        description="Client details, contact information and branding."
      />
      <div className="form-layout">
        <FormPanel
          button={client ? "Save client" : "Create client"}
          save={(d) =>
            client
              ? api.put("/api/clients/" + client.id, d)
              : api.post("/api/clients", d)
          }
          done={() =>
            navigate(
              client
                ? "/clients/" + client.id + "?saved=1"
                : "/clients?created=1",
            )
          }
        >
          <Field
            name="name"
            title="Client name"
            value={client?.name}
            required
            maxLength={150}
          />
          <Field
            name="company_name"
            title="Company name (if different)"
            value={client?.company_name}
            maxLength={150}
          />
          <Field
            name="website"
            title="Website"
            type="url"
            value={client?.website}
            maxLength={2048}
          />
          <Field
            name="contact_email"
            title="Contact email"
            type="email"
            value={client?.contact_email}
            maxLength={254}
          />
          <Field
            name="contact_phone"
            title="Contact phone"
            value={client?.contact_phone}
            maxLength={40}
          />
          <Field
            name="country"
            title="Country"
            value={client?.country}
            maxLength={100}
          />
          <Field
            name="brand_color"
            title="Brand color"
            type="color"
            value={client?.brand_color || "#087e98"}
          />
          <Field
            name="notes"
            title="Internal notes"
            type="textarea"
            value={client?.notes}
          />
        </FormPanel>
        <aside className="form-note">
          <h2>A consistent client identity.</h2>
          <p>
            Upload a client logo from the details page after saving. Logo files
            stay private and are served only to authorized users.
          </p>
        </aside>
      </div>
    </>
  );
}
function LogoUpload({
  client,
  reload,
}: {
  client: Client;
  reload: () => void;
}) {
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [message, setMessage] = useState("");
  return (
    <section className="panel form-panel">
      <h2>Client logo</h2>
      <p className="muted">
        JPG, PNG or WebP · up to 2 MB · maximum 2048 × 2048 pixels.
      </p>
      {error && <Alert>{error}</Alert>}
      {message && <Alert success>{message}</Alert>}
      <form
        onSubmit={async (e) => {
          e.preventDefault();
          const form = e.currentTarget;
          const body = new FormData(form);
          setBusy(true);
          setError("");
          try {
            const r = await api.post(
              "/api/clients/" + client.id + "/logo",
              body,
            );
            setMessage(r.data.message);
            notify(r.data.message);
            form.reset();
            reload();
          } catch (e) {
            setError(errorMessage(e));
          } finally {
            setBusy(false);
          }
        }}
      >
        <label>
          Logo file
          <input
            className="file-input"
            name="logo"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            required
            disabled={busy}
          />
        </label>
        <Button disabled={busy}>{busy ? "Uploading…" : "Upload logo"}</Button>
      </form>
    </section>
  );
}
function ClientAdmins({
  client,
  reload,
}: {
  client: Client;
  reload: () => void;
}) {
  const options = useOptions<TeamUser>("/api/team/options?role=admin");
  if (options.loading) return <Loading />;
  if (options.error) return <Alert>{options.error}</Alert>;
  return (
    <FormPanel
      button="Save client administrators"
      save={async (d) => {
        const ids = String(d.ids || "")
          .split(",")
          .filter(Boolean)
          .map(Number);
        return api.put("/api/clients/" + client.id + "/admins", {
          user_ids: ids,
        });
      }}
      done={reload}
    >
      <h2>Assigned organizational administrators</h2>
      <p className="muted">
        Only selected Admins and the client creator can manage this client.
        Super Admins retain system access.
      </p>
      <MemberChecks
        name="ids"
        users={options.data}
        selected={client.assigned_admin_ids || []}
      />
    </FormPanel>
  );
}
export function MemberChecks({
  name,
  users,
  selected,
}: {
  name: string;
  users: TeamUser[];
  selected: number[];
}) {
  const [ids, setIds] = useState(selected);
  return (
    <div className="member-checks">
      <input type="hidden" name={name} value={ids.join(",")} />
      {users.length ? (
        users.map((u) => (
          <label className="check-label" key={u.id}>
            <input
              type="checkbox"
              checked={ids.includes(u.id)}
              onChange={(e) =>
                setIds(
                  e.target.checked
                    ? [...ids, u.id]
                    : ids.filter((id) => id !== u.id),
                )
              }
            />
            <span>
              {u.name}
              <small>{u.email}</small>
            </span>
          </label>
        ))
      ) : (
        <p className="muted">No eligible active users found.</p>
      )}
    </div>
  );
}
export function ClientDetails() {
  const { id } = useParams(),
    [params] = useSearchParams();
  const q = useApi<{ data: Client }>("/api/clients/" + id);
  if (q.loading) return <Loading />;
  if (q.error || !q.data)
    return <ErrorState message={q.error} retry={q.reload} />;
  const c = q.data.data;
  return (
    <>
      <Link to="/clients" className="back-link">
        ← All clients
      </Link>
      <PageHeading
        eyebrow="CLIENT PROFILE"
        title={c.name}
        description={c.company_name || "Client details and website projects."}
        action={
          c.permissions.manage && (
            <Button asChild variant="outline">
              <Link to={"/clients/" + id + "/edit"}>Edit client</Link>
            </Button>
          )
        }
      />
      {params.has("saved") && (
        <Alert success>Client updated successfully.</Alert>
      )}
      <div className="stats-grid">
        <div className="stat-card">
          <div className="stat-top">Projects</div>
          <strong>{c.projects_count}</strong>
        </div>
        <div className="stat-card">
          <div className="stat-top">Reporting periods</div>
          <strong>{c.reporting_periods_count}</strong>
        </div>
        <div className="stat-card">
          <div className="stat-top">Client status</div>
          <Status value={c.status} />
        </div>
      </div>
      <section className="panel form-panel">
        <div className="detail-grid">
          {c.has_logo && (
            <img
              className="client-logo large"
              src={logoUrl(c.id)}
              alt={c.name + " logo"}
            />
          )}
          <dl>
            <dt>Website</dt>
            <dd>
              {c.website ? (
                <a href={c.website} target="_blank" rel="noopener noreferrer">
                  {c.website}
                </a>
              ) : (
                "—"
              )}
            </dd>
            <dt>Contact email</dt>
            <dd>{c.contact_email || "—"}</dd>
            <dt>Phone / Country</dt>
            <dd>
              {[c.contact_phone, c.country].filter(Boolean).join(" · ") || "—"}
            </dd>
          </dl>
          <dl>
            <dt>Brand color</dt>
            <dd>
              <span
                className="color-swatch"
                style={{ background: c.brand_color }}
              />
              {c.brand_color}
            </dd>
            <dt>Created / Updated</dt>
            <dd>
              {new Date(c.created_at).toLocaleDateString()} /{" "}
              {new Date(c.updated_at).toLocaleDateString()}
            </dd>
            <dt>Notes</dt>
            <dd className="preserve-lines">{c.notes || "—"}</dd>
          </dl>
        </div>
        {c.permissions.manage && (
          <Action
            title={c.status === "active" ? "Archive client" : "Restore client"}
            path={"/api/clients/" + id}
            body={{
              ...c,
              status: c.status === "active" ? "archived" : "active",
            }}
            done={q.reload}
          >
            Archiving retains all projects and reports. Restore the client
            before creating or changing reports.
          </Action>
        )}
      </section>
      <section className="panel section-gap">
        <div className="panel-heading">
          <h2>Client projects</h2>
          {c.permissions.manage && c.status === "active" && (
            <Button asChild>
              <Link to={"/projects/new?client_id=" + id}>New project</Link>
            </Button>
          )}
        </div>
        {c.projects?.length ? (
          <ProjectTable projects={c.projects} />
        ) : (
          <Empty title="No projects yet">
            This client has no accessible projects.
          </Empty>
        )}
      </section>
      {c.permissions.manage && (
        <div className="section-gap">
          <LogoUpload client={c} reload={q.reload} />
        </div>
      )}
      {c.permissions.assign_admins && (
        <div className="section-gap">
          <ClientAdmins
            key={c.updated_at + String(c.assigned_admin_ids)}
            client={c}
            reload={q.reload}
          />
        </div>
      )}
    </>
  );
}
