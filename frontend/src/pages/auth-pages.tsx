import { useState, type FormEvent, type ReactNode } from "react";
import { Link, Navigate, useLocation, useSearchParams } from "react-router-dom";
import {
  ArrowLeft,
  ArrowRight,
  ShieldCheck,
  Layers3,
  ChartNoAxesCombined,
} from "lucide-react";
import { useAuth } from "../auth";
import { api, csrf, errorMessage } from "../lib/api";
import { Button } from "../components/ui/button";
import { Input } from "../components/ui/input";
import { Brand } from "../components/layout";
import { Alert } from "../components/common";
function AuthFrame({ children, client=false }: { children: ReactNode; client?:boolean }) {
  return (
    <div className="auth-shell">
      <section className="auth-story">
        <Brand />
        <div className="auth-story-content">
          <div className="eyebrow">CLARITY. CONFIDENCE. GROWTH.</div>
          <h1>Great reporting starts with a clearer view.</h1>
          <p>
            {client?'Your monthly SEO results, insights and reporting history, prepared by Pentagon.':'Your clients. Your insights. One connected workspace for the Pentagon SEO team.'}
          </p>
          <div className="story-lines">
            <div>
              <Layers3 />
              {client?'Your monthly reports, together':'Every client, organized'}
            </div>
            <div>
              <ChartNoAxesCombined />A foundation for meaningful insights
            </div>
            <div>
              <ShieldCheck />{client?'Private access to your reports':'A secure team workspace'}
            </div>
          </div>
        </div>
        <small>Pentagon Information Technology LLC</small>
      </section>
      <section className="auth-form-side">
        <div className="auth-card">{children}</div>
        <div className="auth-copyright">
          Pentagon Insight Flow · {client?'Client access':'Team access'}
        </div>
      </section>
    </div>
  );
}
export function Login({client=false}:{client?:boolean}) {
  const { login, user } = useAuth(),
    location = useLocation(),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  if (user) return <Navigate to={location.state?.from || (user.role==='client_viewer'?'/portal':'/')} replace />;
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setBusy(true);
    setError("");
    const f = new FormData(e.currentTarget);
    try {
      await login(String(f.get("email")), String(f.get("password")));
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <AuthFrame client={client}>
      <div className="eyebrow">WELCOME BACK</div>
      <h2>{client?'Sign in to your client reports':'Sign in to your workspace'}</h2>
      <p className="muted">A clearer picture of every client starts here.</p>
      {location.state?.passwordChanged && (
        <Alert success>Password updated. Sign in with your new password.</Alert>
      )}
      {error && <Alert>{error}</Alert>}
      <form onSubmit={submit}>
        <label>
          Email address
          <Input
            name="email"
            type="email"
            autoComplete="username"
            defaultValue=""
            required
            maxLength={254}
            placeholder="you@company.com"
          />
        </label>
        <label>
          Password
          <Input
            name="password"
            type="password"
            autoComplete="current-password"
            defaultValue=""
            maxLength={1024}
            required
          />
        </label>
        <Link className="text-link forgot-link" to="/forgot-password">
          Forgot password?
        </Link>
        <Button className="w-full" disabled={busy}>
          {busy ? "Signing in…" : "Sign in"}
          <ArrowRight size={17} />
        </Button>
      </form>
      <div className="auth-help">
        Need access? Ask your workspace administrator to create your account.
      </div>
    </AuthFrame>
  );
}
export function ForgotPassword() {
  const [error, setError] = useState(""),
    [message, setMessage] = useState(""),
    [busy, setBusy] = useState(false);
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setBusy(true);
    setError("");
    setMessage("");
    const email = new FormData(e.currentTarget).get("email");
    try {
      await csrf();
      setMessage((await api.post("/forgot-password", { email })).data.message);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <AuthFrame>
      <Link to="/login" className="back-link">
        <ArrowLeft size={15} />
        Back to sign in
      </Link>
      <h2>Reset your password</h2>
      <p className="muted">
        Enter your account email and we’ll send you a reset link.
      </p>
      {error && <Alert>{error}</Alert>}
      {message && <Alert success>{message}</Alert>}
      <form onSubmit={submit}>
        <label>
          Email address
          <Input
            type="email"
            name="email"
            autoComplete="email"
            maxLength={254}
            required
          />
        </label>
        <Button disabled={busy}>{busy ? "Sending…" : "Send reset link"}</Button>
      </form>
    </AuthFrame>
  );
}
export function ResetPassword() {
  const [params] = useSearchParams(),
    [error, setError] = useState(""),
    [message, setMessage] = useState(""),
    [busy, setBusy] = useState(false);
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setBusy(true);
    setError("");
    const f = Object.fromEntries(new FormData(e.currentTarget));
    try {
      await csrf();
      setMessage(
        (
          await api.post("/reset-password", {
            ...f,
            token: params.get("token"),
          })
        ).data.message,
      );
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <AuthFrame>
      <Link to="/login" className="back-link">
        <ArrowLeft size={15} />
        Back to sign in
      </Link>
      <h2>Choose a new password</h2>
      {error && <Alert>{error}</Alert>}
      {message ? (
        <Alert success>
          {message}{" "}
          <Link to="/login" className="text-link">
            Sign in
          </Link>
        </Alert>
      ) : !params.get("token") ? (
        <Alert>
          This reset link is incomplete.{" "}
          <Link to="/forgot-password" className="text-link">
            Request a new link.
          </Link>
        </Alert>
      ) : (
        <form onSubmit={submit}>
          <label>
            Email address
            <Input
              type="email"
              name="email"
              defaultValue={params.get("email") || ""}
              autoComplete="email"
              required
            />
          </label>
          <PasswordFields />
          <Button disabled={busy}>
            {busy ? "Resetting…" : "Reset password"}
          </Button>
        </form>
      )}
    </AuthFrame>
  );
}
export function PasswordFields() {
  return (
    <>
      <label>
        New password
        <Input
          type="password"
          name="password"
          autoComplete="new-password"
          minLength={12}
          required
          aria-describedby="password-help"
        />
      </label>
      <small id="password-help" className="muted">
        Use at least 12 characters, with uppercase, lowercase, a number and a
        symbol.
      </small>
      <label>
        Confirm new password
        <Input
          type="password"
          name="password_confirmation"
          autoComplete="new-password"
          minLength={12}
          required
        />
      </label>
    </>
  );
}
