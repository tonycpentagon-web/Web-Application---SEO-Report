import { Link } from "react-router-dom";
import { useAuth } from "../auth";
import { isAdmin, useOptions } from "../lib/workspace";
import { useApi } from "../lib/use-api";
import type { Client, Page, Project } from "../types";
import { Button } from "../components/ui/button";
import {
  Alert,
  Empty,
  ErrorState,
  Loading,
  PageHeading,
  ProjectTable,
} from "../components/common";
import { Pager, SearchFilters, useListParams } from "../components/workspace";
export function ProjectsPage() {
  const { user } = useAuth(),
    { params, change } = useListParams(),
    clients = useOptions<Client>("/api/clients");
  const { data, error, loading, reload } = useApi<Page<Project>>(
    "/api/projects?" + params,
  );
  return (
    <>
      <PageHeading
        eyebrow="CLIENT WORKSPACE"
        title="Client projects"
        description="Dedicated websites, teams and reporting periods for every client."
        action={
          isAdmin(user) && (
            <Button asChild>
              <Link to="/projects/new">New project</Link>
            </Button>
          )
        }
      />
      {clients.error && <Alert>{clients.error}</Alert>}
      <section className="panel">
        <SearchFilters
          params={params}
          change={change}
          extra={
            <>
              <select
                aria-label="Filter client"
                value={params.get("client_id") || ""}
                onChange={(e) => change("client_id", e.target.value)}
              >
                <option value="">All clients</option>
                {clients.data.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
              <select
                aria-label="Sort projects"
                value={params.get("sort") || "created_at"}
                onChange={(e) => change("sort", e.target.value)}
              >
                <option value="created_at">Created date</option>
                <option value="updated_at">Updated date</option>
                <option value="name">Project name</option>
              </select>
              <select
                aria-label="Sort direction"
                value={params.get("direction") || "desc"}
                onChange={(e) => change("direction", e.target.value)}
              >
                <option value="desc">Descending</option>
                <option value="asc">Ascending</option>
              </select>
            </>
          }
        />
        {loading ? (
          <Loading />
        ) : error || !data ? (
          <ErrorState message={error} retry={reload} />
        ) : (
          <>
            {data.data.length ? (
              <ProjectTable projects={data.data} reload={reload} />
            ) : (
              <Empty
                title={
                  params.has("search") || params.has("status")
                    ? "No matching projects"
                    : "A new home for your clients"
                }
                action={
                  isAdmin(user) && (
                    <Button asChild variant="outline">
                      <Link to="/projects/new">Create a project</Link>
                    </Button>
                  )
                }
              >
                Only permitted projects appear here. Create a project or adjust
                your filters.
              </Empty>
            )}
            <Pager page={data} onPage={(n) => change("page", String(n))} />
          </>
        )}
      </section>
      <p className="footnote">
        Access follows client and project assignments. Every reporting period
        remains independent.
      </p>
    </>
  );
}
