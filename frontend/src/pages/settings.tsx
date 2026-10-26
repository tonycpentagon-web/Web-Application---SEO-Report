import { useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "../auth";
import { api, errorMessage } from "../lib/api";
import { Button } from "../components/ui/button";
import { Input } from "../components/ui/input";
import { Alert, PageHeading } from "../components/common";
import { PasswordFields } from "./auth-pages";
export function SettingsPage() {
  const { user, refresh } = useAuth(),
    navigate = useNavigate(),
    [busy, setBusy] = useState(false),
    [error, setError] = useState("");
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      await api.put(
        "/password",
        Object.fromEntries(new FormData(e.currentTarget)),
      );
      await refresh();
      navigate("/login", { state: { passwordChanged: true } });
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <>
      <PageHeading
        eyebrow="YOUR ACCOUNT"
        title="Account settings"
        description="Manage your access to the Pentagon workspace."
      />
      <div className="settings-grid">
        <section className="panel form-panel">
          <h2>Profile</h2>
          <dl className="profile-details">
            <dt>Name</dt>
            <dd>{user?.name}</dd>
            <dt>Email address</dt>
            <dd>{user?.email}</dd>
          </dl>
          <p className="footnote">
            Contact your administrator to change your account details.
          </p>
        </section>
        <section className="panel form-panel">
          <h2>Change password</h2>
          <p className="muted">
            Changing your password signs you out of all devices.
          </p>
          {error && <Alert>{error}</Alert>}
          <form onSubmit={submit}>
            <label>
              Current password
              <Input
                type="password"
                name="current_password"
                autoComplete="current-password"
                required
              />
            </label>
            <PasswordFields />
            <Button disabled={busy}>
              {busy ? "Updating…" : "Update password"}
            </Button>
          </form>
        </section>
      </div>
    </>
  );
}
