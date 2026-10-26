import { useCallback, useEffect, useRef, useState } from "react";
import { api, errorMessage } from "./api";
import axios from "axios";
export function useApi<T>(path: string, keepOnReload = false) {
  const [data, setData] = useState<T | null>(null),
    [error, setError] = useState(""),
    [loading, setLoading] = useState(true),
    [version, setVersion] = useState(0);
  const previousPath = useRef(path);
  const reload = useCallback(() => setVersion(v => v + 1), []);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError("");
    if (!keepOnReload || previousPath.current !== path) setData(null);
    previousPath.current = path;
    api
      .get<T>(path, { signal: controller.signal })
      .then((r) => setData(r.data))
      .catch((e) => {
        if (!axios.isCancel(e)) setError(errorMessage(e));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [path, version, keepOnReload]);
  return { data, error, loading, reload };
}
