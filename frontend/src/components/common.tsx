import {LifecycleActions} from './lifecycle-actions';
import type { ReactNode } from "react";
import {
  AlertCircle,
  ArrowUpRight,
  FolderOpen,
  LoaderCircle,
} from "lucide-react";
import { Link } from "react-router-dom";
import { Button } from "./ui/button";
import type { Project } from "../types";
export function Loading() {
  return (
    <div className="loading" role="status">
      <LoaderCircle className="animate-spin" size={22} />
      Loading workspace…
    </div>
  );
}
export function Alert({
  children,
  success = false,
}: {
  children: ReactNode;
  success?: boolean;
}) {
  return (
    <div
      className={success ? "alert success" : "alert"}
      role={success ? "status" : "alert"}
    >
      <AlertCircle size={18} />
      <span>{children}</span>
    </div>
  );
}
export function ErrorState({
  message,
  retry,
}: {
  message: string;
  retry: () => void;
}) {
  return (
    <div className="panel empty">
      <AlertCircle size={30} />
      <h2>We couldn’t load this view</h2>
      <p>{message}</p>
      <Button variant="outline" onClick={retry}>
        Try again
      </Button>
    </div>
  );
}
export function Empty({
  title,
  children,
  action,
}: {
  title: string;
  children: ReactNode;
  action?: ReactNode;
}) {
  return (
    <div className="empty">
      <div className="empty-icon">
        <FolderOpen size={28} />
      </div>
      <h2>{title}</h2>
      <p>{children}</p>
      {action}
    </div>
  );
}
export function PageHeading({
  eyebrow,
  title,
  description,
  action,
}: {
  eyebrow: string;
  title: string;
  description: string;
  action?: ReactNode;
}) {
  return (
    <div className="page-heading">
      <div>
        <div className="eyebrow">{eyebrow}</div>
        <h1>{title}</h1>
        <p>{description}</p>
      </div>
      {action}
    </div>
  );
}
export function ProjectTable({ projects, reload }: { projects: Project[];reload?:()=>void }) {
  return (
    <div className="table-scroll">
      <table>
        <thead>
          <tr>
            <th>Client / project</th>
            <th>Website</th>
            <th>Status</th>
            <th>Created</th>
            <th>
              <span className="sr-only">Open project</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {projects.map((p) => (
            <tr key={p.id}>
              <td>
                <Link className="project-name" to={"/projects/" + p.id}>
                  <span className="project-avatar">
                    {p.client_name.slice(0, 2).toUpperCase()}
                  </span>
                  <span>
                    <strong>{p.name}</strong>
                    <small>{p.client_name}</small>
                  </span>
                </Link>
              </td>
              <td>
                <a
                  className="website"
                  href={p.website}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {new URL(p.website).hostname}
                  <ArrowUpRight size={13} />
                </a>
              </td>
              <td>
                <span className={"badge " + p.status}>{p.status}</span><LifecycleActions type="project" id={p.id} status={p.status} manage={!!p.permissions?.manage} view={"/projects/"+p.id} edit={"/projects/"+p.id+"/edit"} done={reload}/>
              </td>
              <td className="muted nowrap">
                {new Date(p.created_at).toLocaleDateString("en-GB", {
                  day: "2-digit",
                  month: "short",
                  year: "numeric",
                })}
              </td>
              <td>
                <Button asChild variant="ghost" size="icon">
                  <Link aria-label={"Open " + p.name} to={"/projects/" + p.id}>
                    <ArrowUpRight size={18} />
                  </Link>
                </Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
