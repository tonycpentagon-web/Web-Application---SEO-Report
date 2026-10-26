export type User = {
  id: number;
  name: string;
  email: string;
  role?: Role;
  is_super_admin?: boolean;
  is_active?: boolean;
};
export type Project = {
  client_id?: string;
  client?: Client;
  reporting_frequency?: string;
  permissions?: {
    manage: boolean;
    work_reports: boolean;
    view_internal: boolean;
  };
  members?: (TeamUser & { assignment_role: string })[];
  latest_report?: ReportPeriod | null;
  recent_activity?: Activity[];
  reporting_periods_count?: number;
  id: string;
  name: string;
  client_name: string;
  website: string;
  description: string | null;
  status: "active" | "archived";
  created_at: string;
  updated_at: string;
};
export type Page<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
};
export type Overview = {
  total_clients?:number;generated_this_month?:number;recent_activity?:{id:string;action:string;created_at:string}[];
  active_reports?:number;
  recent_published_reports?:{id:string;project_id:string;title:string;status:string;project_name:string;client_name:string;can_manage:boolean;publication_id:string;published_at:string}[];
  active_clients?:number;
  reports_this_month?:number;
  draft_reports?:number;
  pending_manager_review?:number;
  published_reports?:number;
  recently_shared_reports?:number;
  recent_reports?:{id:string;project_id:string;title:string;status:string;project_name:string;client_name:string;can_manage:boolean}[];
  total_projects: number;
  active_projects: number;
  archived_projects: number;
  recent_projects: Project[];
  reporting_status: string;
};
export type Dashboard = {
  project: Project;
  reporting_status: string;
  sections: { id: string; name: string; status: string }[];
};

export type Role =
  "super_admin" | "admin" | "seo_manager" | "seo_executive" | "client_viewer";
export type Client = {
  id: string;
  name: string;
  company_name: string | null;
  website: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  country: string | null;
  brand_color: string;
  status: "active" | "archived";
  notes: string | null;
  has_logo: boolean;
  projects_count: number;
  reporting_periods_count: number;
  created_at: string;
  updated_at: string;
  permissions: { manage: boolean; assign_admins: boolean };
  projects?: Project[];
  assigned_admin_ids?: number[];
};
export type TeamUser = User & {
  role: Role;
  is_active: boolean;
  projects?: { id: string; name: string }[];
};
export type ReportPeriod = {
  workspace_archived_at?:string|null;
  id: string;
  project_id: string;
  title: string;
  reporting_month: string;
  kind: "monthly" | "custom";
  start_date: string;
  end_date: string;
  status: string;
  version: number;
  created_at: string;
  updated_at: string;
  created_by?: number;
  updated_by?: number;
  allowed_transitions: string[];
  can_edit: boolean;
  can_share: boolean;
  can_manage_lifecycle?:boolean;
  shared_user_ids?: number[];
  has_data: boolean;
  publication?: {allowed:boolean;blocking_warning_count:number;reasons:string[]};
  view_documents?: boolean;
  can_upload_documents?: boolean;
  documents_count?: number;
  history?: Activity[];
};
export type Activity = {
  id: string;
  action: string;
  created_at: string;
  actor?: { id: number; name: string } | null;
  properties?: Record<string, unknown>;
};
