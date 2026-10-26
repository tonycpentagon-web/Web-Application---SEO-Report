import {
  useEffect,
  useRef,
  useState,
  type FormEvent,
  type ReactNode,
} from "react";
import { useSearchParams } from "react-router-dom";
import { api, errorMessage, notify } from "../lib/api";
import { label } from "../lib/workspace";
import { Button } from "./ui/button";
import { Input } from "./ui/input";
import { Alert } from "./common";
import type { Page } from "../types";
export function Field({
  name,
  title,
  value,
  required = false,
  type = "text",
  options,
  maxLength,
  step,
}: {
  name: string;
  title: string;
  value?: string | null;
  required?: boolean;
  type?: string;
  options?: { value: string; label: string }[];
  maxLength?: number;
  step?: string | number;
}) {
  return (
    <label>
      {title}
      {options ? (
        <select name={name} defaultValue={value || ""} required={required}>
          {options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      ) : type === "textarea" ? (
        <textarea
          name={name}
          defaultValue={value || ""}
          required={required}
          rows={4}
          maxLength={maxLength || 5000}
        />
      ) : (
        <Input
          name={name}
          type={type}
          step={step}
          defaultValue={value || ""}
          required={required}
          maxLength={maxLength}
        />
      )}
    </label>
  );
}
export function FormPanel({
  children,
  save,
  done,
  button = "Save changes",
}: {
  children: ReactNode;
  save: (data: Record<string, FormDataEntryValue>) => Promise<unknown>;
  done?: () => void;
  button?: string;
}) {
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [success, setSuccess] = useState("");
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setBusy(true);
    setError("");
    setSuccess("");
    const values = Object.fromEntries(new FormData(e.currentTarget));
    try {
      const result = (await save(values)) as { data?: { message?: string } };
      setSuccess(result?.data?.message || "Changes saved successfully.");
      notify(result?.data?.message || "Changes saved successfully.");
      done?.();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="panel form-panel">
      {error && <Alert>{error}</Alert>}
      {success && <Alert success>{success}</Alert>}
      <form onSubmit={submit}>
        <fieldset disabled={busy}>{children}</fieldset>
        <div className="form-actions">
          <Button disabled={busy}>{busy ? "Saving…" : button}</Button>
        </div>
      </form>
    </div>
  );
}
export function Status({ value }: { value: string }) {
  return <span className={"badge " + value}>{label(value)}</span>;
}
export function Confirm({
  open,
  title,
  children,
  onCancel,
  onConfirm,
  busy = false,
  confirmDisabled = false,
}: {
  open: boolean;
  title: string;
  children: ReactNode;
  onCancel: () => void;
  onConfirm: () => void;
  busy?: boolean;
  confirmDisabled?: boolean;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    if (open && !ref.current?.open) ref.current?.showModal();
    if (!open && ref.current?.open) ref.current?.close();
  }, [open]);
  return (
    <dialog
      className="confirm-dialog"
      ref={ref}
      onCancel={(e) => {
        e.preventDefault();
        if (!busy) onCancel();
      }}
    >
      <h2>{title}</h2>
      <div className="confirm-body">{children}</div>
      <div className="form-actions">
        <Button variant="outline" disabled={busy} onClick={onCancel}>
          Cancel
        </Button>
        <Button disabled={busy || confirmDisabled} onClick={onConfirm}>
          {busy ? "Saving…" : "Confirm"}
        </Button>
      </div>
    </dialog>
  );
}
export function Action({
  title,
  path,
  method = "put",
  body,
  done,
  children,
}: {
  title: string;
  path: string;
  method?: "put" | "post" | "patch" | "delete";
  body: unknown;
  done: () => void;
  children?: ReactNode;
}) {
  const [open, setOpen] = useState(false),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [message, setMessage] = useState("");
  async function commit() {
    setBusy(true);
    setError("");
    try {
      const r = method === "delete" ? await api.delete(path) : await api[method](path, body);
      setOpen(false);
      setMessage(r.data.message || "Changes saved successfully.");
      notify(r.data.message || "Changes saved successfully.");
      done();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="inline-action">
      <Button variant="outline" onClick={() => setOpen(true)}>
        {title}
      </Button>
      <Confirm
        open={open}
        title={title}
        onCancel={() => setOpen(false)}
        onConfirm={commit}
        busy={busy}
      >
        {children || "Confirm this change. Existing records will be retained."}
        {error && <Alert>{error}</Alert>}
      </Confirm>
      {message && (
        <span role="status" className="muted">
          {message}
        </span>
      )}
    </div>
  );
}
export function Pager({
  page,
  onPage,
}: {
  page: Page<unknown>;
  onPage: (n: number) => void;
}) {
  return (
    <div className="pagination">
      <span>
        {page.total} records · Page {page.current_page} of {page.last_page}
      </span>
      <div>
        <Button
          size="sm"
          variant="outline"
          disabled={page.current_page <= 1}
          onClick={() => onPage(page.current_page - 1)}
        >
          Previous
        </Button>
        <Button
          size="sm"
          variant="outline"
          disabled={page.current_page >= page.last_page}
          onClick={() => onPage(page.current_page + 1)}
        >
          Next
        </Button>
      </div>
    </div>
  );
}
export function useListParams() {
  const [params, setParams] = useSearchParams();
  function change(key: string, value: string) {
    const next = new URLSearchParams(params);
    value ? next.set(key, value) : next.delete(key);
    if (key !== "page") next.delete("page");
    setParams(next);
  }
  return { params, change };
}
export function SearchFilters({
  params,
  change,
  extra,
  statuses = ["active", "archived"],
}: {
  params: URLSearchParams;
  change: (key: string, value: string) => void;
  extra?: ReactNode;
  statuses?: string[];
}) {
  const [search, setSearch] = useState(params.get("search") || "");
  return (
    <div className="filter-bar">
      <form
        className="search-form"
        onSubmit={(e) => {
          e.preventDefault();
          change("search", search.trim());
        }}
      >
        <Input
          aria-label="Search records"
          value={search}
          maxLength={150}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search…"
        />
        <Button size="sm" variant="outline">
          Search
        </Button>
      </form>
      <div className="filter-controls">
        {extra}
        {statuses.length > 0 && (
          <select
            aria-label="Filter status"
            value={params.get("status") || ""}
            onChange={(e) => change("status", e.target.value)}
          >
            <option value="">All statuses</option>
            {statuses.map((v) => (
              <option key={v} value={v}>
                {label(v)}
              </option>
            ))}
          </select>
        )}
      </div>
    </div>
  );
}
