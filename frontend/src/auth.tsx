import {
  createContext,
  useContext,
  useEffect,
  useState,
  useRef,
  type ReactNode,
} from "react";
import { api, csrf, errorMessage } from "./lib/api";
import type { User } from "./types";
type Auth = {
  user: User | null;
  loading: boolean;
  error: string;
  refresh: () => Promise<void>;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
};
const Context = createContext<Auth | null>(null);
export function AuthProvider({ children, anonymous = false }: { children: ReactNode; anonymous?: boolean }) {
  const [user, setUser] = useState<User | null>(null),
    [loading, setLoading] = useState(true),
    [error, setError] = useState("");
  const initialRefresh = useRef<Promise<void> | null>(null);
  const refresh = async () => {
    setLoading(true);
    setError("");
    try {
      setUser((await api.get<User>("/api/user")).data);
    } catch (e) {
      if ((e as { response?: { status: number } }).response?.status !== 401)
        setError(errorMessage(e));
      setUser(null);
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {
    // Reuse the initial request through StrictMode effect replay.
    if (anonymous) setLoading(false);
    else initialRefresh.current ??= refresh();
    const expire = () => setUser(null);
    window.addEventListener("auth:expired", expire);
    return () => window.removeEventListener("auth:expired", expire);
  }, [anonymous]);
  const login = async (email: string, password: string) => {
    // Finish the initial session check before CSRF creates the login session.
    await initialRefresh.current;
    await csrf();
    const r = await api.post<{ user: User }>("/login", { email, password });
    setError("");
    setUser(r.data.user);
  };
  const logout = async () => {
    await api.post("/logout");
    setUser(null);
  };
  return (
    <Context.Provider value={{ user, loading, error, refresh, login, logout }}>
      {children}
    </Context.Provider>
  );
}
export function useAuth() {
  const ctx = useContext(Context);
  if (!ctx) throw new Error("AuthProvider required");
  return ctx;
}
