import { isAdmin } from "../lib/workspace";
import { useState, useEffect } from "react";
import { NavLink, Outlet, useLocation, Link } from "react-router-dom";
import {
  LayoutDashboard,
  FolderKanban,
  Settings,
  LogOut,
  Menu,
  X,
  ChevronRight,
  ShieldCheck,
  Hexagon,
} from "lucide-react";
import { useAuth } from "../auth";
import { Button } from "./ui/button";
import { Alert } from "./common";
import { errorMessage } from "../lib/api";
export function Brand() {
  return (
    <Link to="/" className="brand">
      <span className="brand-mark">
        <Hexagon size={25} />
        <span>P</span>
      </span>
      <span>
        <strong>Pentagon</strong>
        <small>INSIGHT FLOW</small>
      </span>
    </Link>
  );
}
export function Layout() {
  const { user, logout } = useAuth(),
    [open, setOpen] = useState(false),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  const { pathname } = useLocation();
  const [notice, setNotice] = useState("");
  useEffect(() => {
    const receive = (event: Event) =>
      setNotice((event as CustomEvent<string>).detail);
    window.addEventListener("workspace:notice", receive);
    return () => window.removeEventListener("workspace:notice", receive);
  }, []);
  const label = pathname.startsWith("/trash") ? "Trash" : pathname.startsWith("/administration") ? "Administration" : pathname.startsWith("/monthly-reports") ? "Monthly Reports" : pathname.startsWith("/projects")
    ? "Projects"
    : pathname.startsWith("/clients")
      ? "Clients"
      : pathname.startsWith("/team")
        ? "Team management"
        : pathname === "/settings"
          ? "Settings"
          : "Overview";
  async function signOut() {
    setBusy(true);
    try {
      await logout();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="app-shell">
      {open && (
        <button
          className="nav-overlay"
          aria-label="Close navigation"
          onClick={() => setOpen(false)}
        />
      )}
      <aside className={"sidebar " + (open ? "is-open" : "")}>
        <div className="sidebar-brand">
          <Brand />
          <button
            className="mobile-close"
            aria-label="Close navigation"
            onClick={() => setOpen(false)}
          >
            <X />
          </button>
        </div>
        <div className="workspace-label">WORKSPACE</div>
        <nav aria-label="Main navigation">
          {[
            { to: "/", label: "Overview", icon: LayoutDashboard },
            { to: "/clients", label: "Clients", icon: FolderKanban },
            { to: "/projects", label: "SEO Projects", icon: FolderKanban },
            { to: "/monthly-reports", label: "Monthly Reports", icon: FolderKanban },
            ...(isAdmin(user) ? [{ to: "/team", label: "Team Management", icon: ShieldCheck }] : []),
            { to: "/settings", label: "Settings", icon: Settings },
          ].map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              end={to === "/"}
              onClick={() => setOpen(false)}
            >
              <Icon size={19} />
              {label}
            </NavLink>
          ))}
        </nav>
        {isAdmin(user)&&<nav className="administration-nav" aria-label="Administration"><div className="workspace-label">ADMINISTRATION</div><NavLink to="/administration" onClick={()=>setOpen(false)}><ShieldCheck size={19}/>Administration</NavLink><NavLink to="/trash" onClick={()=>setOpen(false)}>Trash</NavLink>{user?.is_super_admin&&<NavLink to="/system-health" onClick={()=>setOpen(false)}>System health</NavLink>}</nav>}
        <div className="sidebar-note">
          <ShieldCheck size={22} />
          <strong>A space for every client.</strong>
          <p>Keep your projects organized and your reporting focused.</p>
          <span>SEO REPORTING WORKSPACE</span>
        </div>
        <div className="sidebar-footer">
          <div className="user-avatar">
            {user?.name.slice(0, 2).toUpperCase()}
          </div>
          <div className="user-detail">
            <strong>{user?.name}</strong>
            <small>SEO workspace</small>
          </div>
          <Button
            aria-label="Sign out"
            variant="ghost"
            size="icon"
            disabled={busy}
            onClick={signOut}
          >
            <LogOut size={17} />
          </Button>
        </div>
      </aside>
      <div className="main-shell">
        <header className="topbar">
          <div className="breadcrumbs">
            <Button
              className="mobile-menu"
              size="icon"
              variant="ghost"
              aria-label="Open navigation"
              aria-expanded={open}
              onClick={() => setOpen(true)}
            >
              <Menu size={20} />
            </Button>
            <span>Workspace</span>
            <ChevronRight size={14} />
            <strong>{label}</strong>
          </div>
          <div className="topbar-right">
            <span className="workspace-dot" />
            Pentagon Information Technology LLC
            <div className="user-avatar small">{user?.name.slice(0, 1)}</div>
          </div>
        </header>
        <main id="main-content">
          {error && <Alert>{error}</Alert>}
          {notice && (
            <Alert success>
              {notice}
              <button
                type="button"
                aria-label="Dismiss notification"
                onClick={() => setNotice("")}
              >
                {" "}
                ×
              </button>
            </Alert>
          )}
          <Outlet />
        </main>
        <footer className="app-footer">
          <span>
            © {new Date().getFullYear()} Pentagon Information Technology LLC
          </span>
          <span>Insight Flow · SEO reporting workspace</span>
        </footer>
      </div>
    </div>
  );
}
