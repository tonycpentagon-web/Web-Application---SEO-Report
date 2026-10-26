import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { AuthProvider } from "../auth";
import { AppRoutes } from "../App";
import type { Client, Project, ReportPeriod, User, TeamUser } from "../types";
const mock = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  patch: vi.fn(),
  csrf: vi.fn(),
}));
vi.mock("../lib/api", async (original) => ({
  ...(await original<typeof import("../lib/api")>()),
  api: { get: mock.get, post: mock.post, put: mock.put, patch: mock.patch },
  csrf: mock.csrf,
}));
const account: User = {
  id: 1,
  name: "Test Analyst",
  email: "analyst@example.test",
  is_super_admin: true,
  role: "super_admin",
};
const client: Client = {
  id: "client-a",
  name: "Test Client",
  company_name: null,
  website: "https://example.test",
  contact_email: null,
  contact_phone: null,
  country: null,
  brand_color: "#087e98",
  notes: null,
  status: "active",
  has_logo: false,
  projects_count: 1,
  reporting_periods_count: 1,
  created_at: "2026-10-01T00:00:00Z",
  updated_at: "2026-10-01T00:00:00Z",
  permissions: { manage: true, assign_admins: true },
};
const project: Project = {
  id: "project-a",
  client_id: client.id,
  client,
  client_name: client.name,
  name: "Test SEO",
  website: "https://example.test",
  description: null,
  status: "active",
  created_at: "2026-10-01T00:00:00Z",
  updated_at: "2026-10-01T00:00:00Z",
  reporting_frequency: "monthly",
  permissions: { manage: true, work_reports: true, view_internal: true },
  members: [],
  reporting_periods_count: 1,
  recent_activity: [],
};
const report: ReportPeriod = {
  id: "report-a",
  project_id: project.id,
  title: "August SEO report",
  kind: "monthly",
  reporting_month: "2026-08",
  start_date: "2026-08-01",
  end_date: "2026-08-31",
  status: "draft",
  version: 1,
  created_at: project.created_at,
  updated_at: project.updated_at,
  allowed_transitions: ["needs_review", "archived"],
  can_edit: true,
  can_share: true,
  has_data: false,
  history: [],
  shared_user_ids: [],
};
const manager: TeamUser = {
  id: 2,
  name: "SEO Manager",
  email: "manager@example.test",
  role: "seo_manager",
  is_active: true,
};
const executive: TeamUser = {
  id: 3,
  name: "SEO Executive",
  email: "executive@example.test",
  role: "seo_executive",
  is_active: true,
};
const overview = {
  total_projects: 1,
  active_projects: 1,
  archived_projects: 0,
  recent_projects: [project],
  reporting_status: "periods_enabled",
};
const page = <T,>(data: T[]) => ({
  data,
  total: data.length,
  current_page: 1,
  last_page: 1,
});
function respond(url: string) {
  if (url === "/api/user") return { data: account };
  if (url === "/api/dashboard") return { data: overview };
  if (url.startsWith("/api/clients?")) return { data: page([client]) };
  if (url === "/api/clients/client-a")
    return {
      data: {
        data: { ...client, projects: [project], assigned_admin_ids: [] },
      },
    };
  if (url.startsWith("/api/projects?")) return { data: page([project]) };
  if (url === "/api/projects/project-a") return { data: { data: project } };
  if (url.startsWith("/api/projects/project-a/reports?"))
    return { data: page([report]) };
  if (url === "/api/projects/project-a/reports/report-a")
    return { data: { data: report } };
  if (url.startsWith("/api/team/options"))
    return { data: page([manager, executive]) };
  if (url.startsWith("/api/team?")) return { data: page([executive]) };
  if (url === "/api/team/3")
    return { data: { data: { ...executive, projects: [project] } } };
  return { data: page([]) };
}
function mount(path = "/") {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </MemoryRouter>,
  );
}
beforeEach(() => {
  vi.clearAllMocks();
  mock.csrf.mockResolvedValue(undefined);
  mock.get.mockImplementation((url: string) => Promise.resolve(respond(url)));
  mock.post.mockResolvedValue({
    data: { data: project, message: "Saved successfully." },
  });
  mock.put.mockResolvedValue({
    data: { data: project, message: "Updated successfully." },
  });
  mock.patch.mockResolvedValue({
    data: { data: report, message: "Report status updated successfully." },
  });
});
describe("authentication regression", () => {
  it("redirects guests to login and sends entered credentials after CSRF", async () => {
    mock.get.mockRejectedValue({ response: { status: 401 } });
    mock.post.mockResolvedValue({ data: { user: account } });
    mount("/projects");
    await screen.findByRole("heading", { name: "Sign in to your workspace" });
    const u = userEvent.setup();
    await u.type(screen.getByLabelText("Email address"), account.email);
    await u.type(screen.getByLabelText("Password"), "Example!Password123");
    mock.get.mockImplementation((url: string) => Promise.resolve(respond(url)));
    await u.click(screen.getByRole("button", { name: "Sign in" }));
    expect(mock.csrf).toHaveBeenCalledOnce();
    expect(mock.post).toHaveBeenCalledWith("/login", {
      email: account.email,
      password: "Example!Password123",
    });
    await screen.findByRole("heading", { name: "Client projects" });
  });
  it("opens with empty credentials without loading URL or storage suggestions", async () => {
    mock.get.mockRejectedValue({ response: { status: 401 } });
    localStorage.setItem("email", "saved@example.test");
    localStorage.setItem("password", "ignored");
    try {
      mount("/login?email=url@example.test&password=ignored");
      await screen.findByRole("heading", { name: "Sign in to your workspace" });
      expect(screen.getByLabelText("Email address")).toHaveValue("");
      expect(screen.getByLabelText("Password")).toHaveValue("");
      expect(screen.getByLabelText("Email address")).toHaveAttribute(
        "autocomplete",
        "username",
      );
      expect(screen.getByLabelText("Password")).toHaveAttribute(
        "autocomplete",
        "current-password",
      );
    } finally {
      localStorage.clear();
    }
  });
  it("clears credentials when the login page is opened again", async () => {
    mock.get.mockRejectedValue({ response: { status: 401 } });
    mount("/login");
    const u = userEvent.setup();
    await u.type(screen.getByLabelText("Email address"), account.email);
    await u.type(screen.getByLabelText("Password"), "Example!Password123");
    await u.click(screen.getByRole("link", { name: "Forgot password?" }));
    await u.click(await screen.findByRole("link", { name: "Back to sign in" }));
    expect(screen.getByLabelText("Email address")).toHaveValue("");
    expect(screen.getByLabelText("Password")).toHaveValue("");
  });
  it("displays incorrect credentials without losing the form", async () => {
    mock.get.mockRejectedValue({ response: { status: 401 } });
    mock.post.mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: { errors: { email: ["The email or password is incorrect."] } },
      },
    });
    mount("/login");
    const u = userEvent.setup();
    await u.type(screen.getByLabelText("Email address"), account.email);
    await u.type(screen.getByLabelText("Password"), "bad");
    await u.click(screen.getByRole("button", { name: "Sign in" }));
    expect(await screen.findByRole("alert")).toHaveTextContent(
      "The email or password is incorrect.",
    );
  });
  it("recovers from API connection errors", async () => {
    mock.get.mockRejectedValue({ isAxiosError: true });
    mount("/");
    await screen.findByRole("heading", { name: /load this view/ });
    mock.get.mockImplementation((url: string) => Promise.resolve(respond(url)));
    await userEvent.click(screen.getByRole("button", { name: "Try again" }));
    await screen.findByRole("heading", {
      name: "Your monthly reporting workspace",
    });
  });
  it("logs out through the backend and returns to empty login fields", async () => {
    mount("/");
    await screen.findByRole("heading", {
      name: "Your monthly reporting workspace",
    });
    await userEvent.click(screen.getByRole("button", { name: "Sign out" }));
    expect(mock.post).toHaveBeenCalledWith("/logout");
    await screen.findByRole("heading", { name: "Sign in to your workspace" });
    expect(screen.getByLabelText("Password")).toHaveValue("");
  });
  it("submits recovery email after CSRF without displaying tokens", async () => {
    mock.post.mockResolvedValue({
      data: {
        message: "If an account exists, a password reset link has been sent.",
      },
    });
    mount("/forgot-password");
    const u = userEvent.setup();
    await u.type(screen.getByLabelText("Email address"), account.email);
    await u.click(screen.getByRole("button", { name: "Send reset link" }));
    expect(mock.csrf).toHaveBeenCalledOnce();
    expect(mock.post).toHaveBeenCalledWith("/forgot-password", {
      email: account.email,
    });
    expect(await screen.findByRole("status")).toHaveTextContent(
      "If an account exists",
    );
  });
});
describe("client and project workspace", () => {
  it("lists clients and links to their profile", async () => {
    mount("/clients");
    expect(
      await screen.findByRole("link", { name: /Test Client/ }),
    ).toHaveAttribute("href", "/clients/client-a");
  });
  it("creates a client with validated form data", async () => {
    mock.post.mockResolvedValue({
      data: { data: client, message: "Client created successfully." },
    });
    mount("/clients/new");
    const u = userEvent.setup();
    await screen.findByLabelText("Client name");
    await u.type(screen.getByLabelText("Client name"), "New Client");
    await u.click(screen.getByRole("button", { name: "Create client" }));
    expect(mock.post).toHaveBeenCalledWith(
      "/api/clients",
      expect.objectContaining({ name: "New Client", brand_color: "#087e98" }),
    );
    await screen.findByRole("heading", { name: "Clients" });
  });
  it("shows API-driven project data and functional links", async () => {
    mount("/projects");
    expect(
      await screen.findByRole("link", { name: "Open Test SEO" }),
    ).toHaveAttribute("href", "/projects/project-a");
  });
  it("searches, filters and sorts through API requests", async () => {
    mount("/projects");
    await screen.findByText("Test SEO");
    const u = userEvent.setup();
    await u.type(
      screen.getByRole("textbox", { name: "Search records" }),
      "Acme",
    );
    await u.click(screen.getByRole("button", { name: "Search" }));
    await u.selectOptions(screen.getByLabelText("Filter status"), "archived");
    await u.selectOptions(screen.getByLabelText("Filter client"), client.id);
    await u.selectOptions(screen.getByLabelText("Sort projects"), "name");
    await waitFor(() =>
      expect(mock.get).toHaveBeenCalledWith(
        "/api/projects?search=Acme&status=archived&client_id=client-a&sort=name",
        expect.anything(),
      ),
    );
  });
  it("renders the empty project state with a create link for admins", async () => {
    mock.get.mockImplementation((url: string) =>
      Promise.resolve(
        url.startsWith("/api/projects?") ? { data: page([]) } : respond(url),
      ),
    );
    mount("/projects");
    await screen.findByRole("heading", { name: "A new home for your clients" });
    expect(
      screen.getByRole("link", { name: "Create a project" }),
    ).toHaveAttribute("href", "/projects/new");
  });
  it("creates a project tied to a selected existing client", async () => {
    mount("/projects/new");
    await screen.findByLabelText("Project name");
    const u = userEvent.setup();
    await u.type(screen.getByLabelText("Project name"), "New SEO");
    await u.selectOptions(screen.getByLabelText("Client"), client.id);
    await u.type(screen.getByLabelText("Website"), "https://new.example");
    await u.click(screen.getByRole("button", { name: "Create project" }));
    expect(mock.post).toHaveBeenCalledWith(
      "/api/projects",
      expect.objectContaining({
        name: "New SEO",
        client_id: client.id,
        website: "https://new.example",
        reporting_frequency: "monthly",
      }),
    );
    await screen.findByRole("heading", { name: "Client projects" });
  });
  it("retains project form values on backend validation errors", async () => {
    mock.post.mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: { errors: { website: ["The website must be a valid URL."] } },
      },
    });
    mount("/projects/new");
    await screen.findByLabelText("Project name");
    const u = userEvent.setup();
    await u.type(screen.getByLabelText("Project name"), "New SEO");
    await u.selectOptions(screen.getByLabelText("Client"), client.id);
    await u.type(screen.getByLabelText("Website"), "ftp://example.test");
    await u.click(screen.getByRole("button", { name: "Create project" }));
    expect(await screen.findByRole("alert")).toHaveTextContent(
      "The website must be a valid URL.",
    );
    expect(screen.getByLabelText("Project name")).toHaveValue("New SEO");
  });
  it("routes the five project tabs and shows the project document index", async () => {
    mount("/projects/project-a");
    await screen.findByRole("heading", { name: "Test SEO" });
    const nav = screen.getByRole("navigation", { name: "Project tabs" });
    expect(within(nav).getAllByRole("link")).toHaveLength(5);
    await userEvent.click(within(nav).getByRole("link", { name: "Files" }));
    await screen.findByRole("heading", {
      name: "Project documents",
    });
    expect(
      screen.getByText(
        /Open a reporting period to upload documents/,
      ),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /upload/i }),
    ).not.toBeInTheDocument();
  });
  it("assigns a manager and executive through the project Team tab", async () => {
    mount("/projects/project-a/team");
    await screen.findByLabelText("Assigned SEO Manager");
    const u = userEvent.setup();
    await u.selectOptions(
      screen.getByLabelText("Assigned SEO Manager"),
      String(manager.id),
    );
    await u.click(screen.getByRole("checkbox", { name: /SEO Executive/ }));
    await u.click(screen.getByRole("button", { name: "Save project team" }));
    expect(mock.put).toHaveBeenCalledWith("/api/projects/project-a/team", {
      manager_id: 2,
      member_ids: [3],
    });
  });
  it("redirects viewers away from internal project pages into My Reports", async () => {
    mock.get.mockImplementation((url: string) =>
      Promise.resolve(
        url === "/api/user"
          ? {
              data: {
                ...account,
                is_super_admin: false,
                role: "client_viewer",
              },
            }
          : url === "/api/projects/project-a"
            ? {
                data: {
                  data: {
                    ...project,
                    permissions: {
                      manage: false,
                      work_reports: false,
                      view_internal: false,
                    },
                  },
                },
              }
            : respond(url),
      ),
    );
    mount("/projects/project-a");
    await screen.findByRole("heading", { name: "My Reports" });
    expect(screen.queryByRole("navigation", { name: "Project tabs" })).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Team Management" }),
    ).not.toBeInTheDocument();
  });
});
describe("reports and team management", () => {
  it("creates an August 2026 monthly reporting period without fake data", async () => {
    mock.post.mockResolvedValue({
      data: { data: report, message: "Reporting period created successfully." },
    });
    mount("/projects/project-a/reports/new");
    await screen.findByLabelText("Report title");
    const u = userEvent.setup();
    await u.type(screen.getByLabelText("Report title"), "August SEO report");
    await u.type(screen.getByLabelText("Reporting month"), "2026-08");
    await u.click(
      screen.getByRole("button", { name: "Create reporting period" }),
    );
    expect(mock.post).toHaveBeenCalledWith("/api/projects/project-a/reports", {
      title: "August SEO report",
      reporting_month: "2026-08",
      kind: "monthly",
    });
    await screen.findByRole("heading", { name: "Dashboard data is not yet available" });
  });
  it("shows only permitted status transitions and confirms before mutation", async () => {
    mount("/projects/project-a/reports/report-a");
    await screen.findByLabelText("Next report status");
    const u = userEvent.setup();
    await u.selectOptions(
      screen.getByLabelText("Next report status"),
      "needs_review",
    );
    await u.click(screen.getByRole("button", { name: "Move to Ready for Review" }));
    expect(mock.patch).not.toHaveBeenCalled();
    await u.click(screen.getByRole("button", { name: "Confirm" }));
    expect(mock.patch).toHaveBeenCalledWith(
      "/api/projects/project-a/reports/report-a/status",
      { status: "needs_review", version: 1 },
    );
  });
  it("creates team accounts via password setup links, without a password field", async () => {
    mock.post.mockResolvedValue({
      data: {
        data: executive,
        message: "Team member created. A secure password setup link was sent.",
      },
    });
    mount("/team/new");
    await screen.findByLabelText("Full name");
    expect(screen.queryByLabelText("Password")).not.toBeInTheDocument();
    const u = userEvent.setup();
    await u.type(screen.getByLabelText("Full name"), executive.name);
    await u.type(screen.getByLabelText("Email address"), executive.email);
    await u.click(screen.getByRole("button", { name: "Create team member" }));
    expect(mock.post).toHaveBeenCalledWith("/api/team", {
      name: executive.name,
      email: executive.email,
      role: "seo_executive",
    });
  });
  it("denies the team module to executives independently of hidden navigation", async () => {
    mock.get.mockImplementation((url: string) =>
      Promise.resolve(
        url === "/api/user"
          ? {
              data: {
                ...account,
                is_super_admin: false,
                role: "seo_executive",
              },
            }
          : respond(url),
      ),
    );
    mount("/team");
    await screen.findByRole("heading", { name: "Your monthly reporting workspace" });
    expect(screen.queryByRole("link", {name:"Team Management"})).not.toBeInTheDocument();
    expect(mock.get).not.toHaveBeenCalledWith(
      expect.stringMatching(/^\/api\/team\?/),
      expect.anything(),
    );
  });
});
