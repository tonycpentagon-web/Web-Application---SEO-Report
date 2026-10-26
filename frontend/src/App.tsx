import {TrashPage} from './pages/trash';
import {AdministrationPage} from './pages/administration';
import {isAdmin} from './lib/workspace';
import {ReportSourceAudit} from './pages/report-source-audit';
import {ReportComparison} from './pages/report-comparison';
import {MonthlyReports,MonthlyReportCreate,MonthlyReportGenerate} from './pages/monthly-reports';
import {ClientPortal,ClientPortalLayout} from './pages/client-portal';
import { ReportDeliveryPage } from './pages/report-delivery';
import {ReportSharePage} from './pages/report-share';

import {lazy,Suspense} from 'react';
const ClientReportPage=lazy(()=>import('./pages/client-report').then(m=>({default:m.ClientReportPage})));
const ReportDraftPreview=lazy(()=>import('./pages/report-draft-preview').then(m=>({default:m.ReportDraftPreview})));
const SystemHealthPage=lazy(()=>import('./pages/system-health').then(m=>({default:m.SystemHealthPage})));

const ReportDashboardPreview=lazy(()=>import('./pages/report-dashboard').then(module=>({default:module.ReportDashboardPreview}))); 
import { ReportDataReview } from "./pages/report-data";
import { DocumentDetails } from "./pages/document-details";
import {
  BrowserRouter,
  Routes,
  Route,
  Navigate,
  Outlet,
  useLocation,
  Link,
} from "react-router-dom";
import { AuthProvider, useAuth } from "./auth";
import { Layout } from "./components/layout";
import { ErrorState, Loading, Empty } from "./components/common";
import { Button } from "./components/ui/button";
import { Login, ForgotPassword, ResetPassword } from "./pages/auth-pages";
import { OverviewPage } from "./pages/overview";
import { ProjectsPage } from "./pages/projects";
import { EditProjectPage, ProjectForm } from "./pages/project-form";
import {
  ClientsPage,
  ClientForm,
  ClientEditPage,
  ClientDetails,
} from "./pages/clients";
import { TeamPage, TeamForm, TeamEditPage, TeamDetails } from "./pages/team";
import { ProjectDetails } from "./pages/project-details";
import {
  ReportCreatePage,
  ReportEditPage,
  ReportDetails,
} from "./pages/reports";
import { ProjectDashboard } from "./pages/project-dashboard";
import { SettingsPage } from "./pages/settings";
function Gate() {
  const { user, loading, error, refresh } = useAuth(),
    location = useLocation();
  if (loading) return <Loading />;
  if (error) return <ErrorState message={error} retry={() => void refresh()} />;
  return user ? (
    <Outlet />
  ) : (
    <Navigate
      to={location.pathname.startsWith('/portal')||location.pathname==='/shared-report'||location.pathname.endsWith('/client')?'/client-login':'/login'}
      replace
      state={{ from: location.pathname + location.search + location.hash }}
    />
  );
}
function InternalGate(){const {user}=useAuth();return user?.role==='client_viewer'?<Navigate to="/portal" replace/>:<Outlet/>;}
function PortalGate(){const {user}=useAuth();return user?.role==='client_viewer'?<Outlet/>:<Navigate to="/" replace/>;}
function AdminGate({superOnly=false}:{superOnly?:boolean}){const {user}=useAuth();return (superOnly?user?.is_super_admin:isAdmin(user))?<Outlet/>:<Navigate to="/" replace/>;}
export function AppRoutes() {
  return (
    <Routes>
      
      <Route path="/login" element={<Login />} /><Route path="/client-login" element={<Login client/>}/>
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/reset-password" element={<ResetPassword />} />
      <Route path="/public-report" element={<Suspense fallback={<Loading/>}><ClientReportPage shared publicLink/></Suspense>}/>
      <Route element={<Gate />}>
        <Route element={<ClientPortalLayout/>}>
          <Route path="/shared-report" element={<Suspense fallback={<Loading/>}><ClientReportPage shared/></Suspense>}/>
          <Route path="/projects/:id/reports/:reportId/client" element={<Suspense fallback={<Loading/>}><ClientReportPage/></Suspense>}/>
          <Route element={<PortalGate/>}><Route path="/portal" element={<ClientPortal/>}/><Route path="/portal/history" element={<ClientPortal/>}/><Route path="/portal/settings" element={<SettingsPage/>}/></Route>
        </Route>
        <Route element={<InternalGate/>}>
        <Route path="/projects/:id/reports/:reportId/preview" element={<Suspense fallback={<Loading/>}><ReportDraftPreview/></Suspense>}/>
        <Route path="/projects/:id/reports/:reportId/publications/:publicationId" element={<Suspense fallback={<Loading/>}><ClientReportPage history/></Suspense>}/>
        <Route element={<Layout />}>
          <Route index element={<OverviewPage />} />
          <Route element={<AdminGate/>}><Route path="/administration" element={<AdministrationPage/>}/><Route path="/trash" element={<TrashPage/>}/></Route>
          <Route path="/projects/:id/reports/:reportId/source-audit" element={<ReportSourceAudit/>}/><Route path="/projects/:id/reports/:reportId/compare" element={<ReportComparison/>}/><Route path="/monthly-reports" element={<MonthlyReports/>}/>
          <Route path="/monthly-reports/new" element={<MonthlyReportCreate/>}/>
          <Route path="/projects/:id/reports/:reportId/generate" element={<MonthlyReportGenerate/>}/>
          <Route path="/projects" element={<ProjectsPage />} />
          <Route path="/projects/new" element={<ProjectForm />} />
          <Route path="/projects/:id" element={<ProjectDetails />} />
          <Route
            path="/projects/:id/dashboard"
            element={<ProjectDashboard />}
          />
          <Route
            path="/projects/:id/reports/new"
            element={<ReportCreatePage />}
          />
          <Route
            path="/projects/:id/reports/:reportId"
            element={<ReportDetails />}
          />
          <Route
            path="/projects/:id/reports/:reportId/edit"
            element={<ReportEditPage />}
          />
          <Route path="/projects/:id/reports/:reportId/dashboard" element={<Suspense fallback={<Loading/>}><ReportDashboardPreview/></Suspense>} />
          <Route path="/projects/:id/reports/:reportId/delivery" element={<ReportSharePage/>}/>
          <Route path="/projects/:id/reports/:reportId/advanced-delivery" element={<ReportDeliveryPage/>}/>
          <Route path="/projects/:id/reports/:reportId/data" element={<ReportDataReview />} />
          <Route path="/projects/:id/reports/:reportId/documents/:documentId" element={<DocumentDetails />} />
          <Route path="/projects/:id/:tab" element={<ProjectDetails />} />
          <Route path="/projects/:id/edit" element={<EditProjectPage />} />
          <Route path="/clients" element={<ClientsPage />} />
          <Route path="/clients/new" element={<ClientForm />} />
          <Route path="/clients/:id" element={<ClientDetails />} />
          <Route path="/clients/:id/edit" element={<ClientEditPage />} />
          <Route element={<AdminGate/>}><Route path="/team" element={<TeamPage />} />
          <Route path="/team/new" element={<TeamForm />} />
          <Route path="/team/:id" element={<TeamDetails />} />
          <Route path="/team/:id/edit" element={<TeamEditPage />} /></Route>
          <Route path="/settings" element={<SettingsPage />} />
          <Route element={<AdminGate superOnly/>}><Route path="/system-health" element={<Suspense fallback={<Loading/>}><SystemHealthPage/></Suspense>}/></Route>
          <Route
            path="*"
            element={
              <Empty
                title="Page not found"
                action={
                  <Button asChild>
                    <Link to="/">Go to overview</Link>
                  </Button>
                }
              >
                This page does not exist in your workspace.
              </Empty>
            }
          />
        </Route>
        </Route>
      </Route>
    </Routes>
  );
}
function AppSession(){
 const location=useLocation();
 return <AuthProvider anonymous={location.pathname==='/public-report'}><a className="skip-link" href="#main-content" onClick={e=>{e.preventDefault();const main=document.getElementById('main-content');if(main){main.setAttribute('tabindex','-1');main.focus();main.scrollIntoView();}}}>Skip to content</a><AppRoutes/></AuthProvider>;
}
export default function App(){return <BrowserRouter><AppSession/></BrowserRouter>;}
