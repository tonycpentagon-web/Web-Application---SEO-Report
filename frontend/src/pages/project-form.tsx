import {
  Link,
  useNavigate,
  useParams,
  useSearchParams,
} from "react-router-dom";
import { useAuth } from "../auth";
import { api } from "../lib/api";
import { useApi } from "../lib/use-api";
import { isAdmin, useOptions } from "../lib/workspace";
import type { Client, Project } from "../types";
import { Alert, ErrorState, Loading, PageHeading } from "../components/common";
import { Field, FormPanel } from "../components/workspace";
export function EditProjectPage() {
  const { id } = useParams(),
    q = useApi<{ data: Project }>("/api/projects/" + id);
  if (q.loading) return <Loading />;
  if (q.error || !q.data)
    return <ErrorState message={q.error} retry={q.reload} />;
  return <ProjectForm project={q.data.data} />;
}
export function ProjectForm({
  project,
  embedded = false,
}: {
  project?: Project;
  embedded?: boolean;
}) {
  const { user } = useAuth(),
    navigate = useNavigate(),
    [params] = useSearchParams();
  const mayCreate = isAdmin(user),
    mayEdit = !!project?.permissions?.manage;
  const clients = useOptions<Client>(
    mayCreate || mayEdit ? "/api/clients" : null,
  );
  if (project ? !mayEdit : !mayCreate)
    return <Alert>You do not have permission to manage this project.</Alert>;
  if (clients.loading) return <Loading />;
  if (clients.error) return <Alert>{clients.error}</Alert>;
  const clientOptions = clients.data.filter(
    (c) =>
      (c.permissions.manage && c.status === "active") ||
      c.id === project?.client_id,
  );
  return (
    <>
      {!embedded && (
        <>
          <Link
            className="back-link"
            to={project ? "/projects/" + project.id : "/projects"}
          >
            ← Back to projects
          </Link>
          <PageHeading
            eyebrow="CLIENT WORKSPACE"
            title={project ? "Project settings" : "Create a client project"}
            description="Connect a client, website and reporting schedule."
          />
        </>
      )}
      <div className={embedded ? "" : "form-layout"}>
        <FormPanel
          button={project ? "Save changes" : "Create project"}
          save={(d) =>
            project
              ? api.put("/api/projects/" + project.id, d)
              : api.post("/api/projects", d)
          }
          done={() =>
            navigate(
              project
                ? "/projects/" + project.id + "?saved=1"
                : "/projects?created=1",
            )
          }
        >
          <Field
            name="name"
            title="Project name"
            value={project?.name}
            required
            maxLength={150}
          />
          {project && !isAdmin(user) ? (
            <>
              <input
                type="hidden"
                name="client_id"
                value={project.client_id || ""}
              />
              <p className="muted">Client: {project.client_name}</p>
            </>
          ) : (
            <Field
              name="client_id"
              title="Client"
              value={project?.client_id || params.get("client_id")}
              required
              options={[
                { value: "", label: "Select client" },
                ...clientOptions.map((c) => ({ value: c.id, label: c.name })),
              ]}
            />
          )}
          <Field
            name="website"
            title="Website"
            value={project?.website}
            type="url"
            required
            maxLength={2048}
          />
          <Field
            name="description"
            title="Project description"
            type="textarea"
            value={project?.description}
          />
          <Field
            name="reporting_frequency"
            title="Reporting frequency"
            value={project?.reporting_frequency || "monthly"}
            options={["monthly", "quarterly", "custom"].map((r) => ({
              value: r,
              label: r[0].toUpperCase() + r.slice(1),
            }))}
          />
        </FormPanel>
        {!embedded && (
          <aside className="form-note">
            <h2>A dedicated workspace.</h2>
            <p>
              Select an existing client or{" "}
              <Link className="text-link" to="/clients/new">
                create a client
              </Link>{" "}
              first. After creating the project, use its Team tab to assign an
              SEO Manager and Executives.
            </p>
            <p>
              Archive and restore actions are available in Project Settings.
              Each report period will keep its own dates, status and history.
            </p>
          </aside>
        )}
      </div>
    </>
  );
}
