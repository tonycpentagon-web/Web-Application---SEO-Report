import { useEffect, useState } from "react";
import { api, errorMessage } from "./api";
import type { User } from "../types";
export const roles = [
  "super_admin",
  "admin",
  "seo_manager",
  "seo_executive",
  "client_viewer",
] as const;
export function roleOf(user: User | null) {
  return user?.is_super_admin ? "super_admin" : user?.role || "seo_executive";
}
export function isAdmin(user: User | null) {
  return ["super_admin", "admin"].includes(roleOf(user));
}
export function label(value: string) {
  if(value==='needs_review')return 'Ready for Review';
  if(value==='admin')return 'SEO Admin';
  return value.replaceAll("_", " ").replace(/\b\w/g, (x) => x.toUpperCase());
}
export function logoUrl(id: string) {
  return (
    (api.defaults?.baseURL ||
      import.meta.env.VITE_API_URL ||
      window.location.origin) +
    "/api/clients/" +
    id +
    "/logo"
  );
}
export function useOptions<T>(path: string | null) {
  const [data, setData] = useState<T[]>([]),
    [error, setError] = useState(""),
    [loading, setLoading] = useState(!!path);
  useEffect(() => {
    let stopped = false;
    setData([]);
    setError("");
    if (!path) {
      setLoading(false);
      return;
    }
    setLoading(true);
    (async () => {
      let page = 1,
        last = 1;
      const all: T[] = [];
      do {
        const res = await api.get(
          path + (path.includes("?") ? "&" : "?") + "page=" + page,
        );
        all.push(...(Array.isArray(res.data.data) ? res.data.data : []));
        last = res.data.last_page || 1;
        page++;
      } while (page <= last && !stopped);
      if (!stopped) setData(all);
    })()
      .catch((e) => {
        if (!stopped) setError(errorMessage(e));
      })
      .finally(() => {
        if (!stopped) setLoading(false);
      });
    return () => {
      stopped = true;
    };
  }, [path]);
  return { data, error, loading };
}
