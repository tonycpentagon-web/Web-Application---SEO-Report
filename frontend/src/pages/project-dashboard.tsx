import { Navigate, useParams } from "react-router-dom";

// Keep existing bookmarks pointed at the current monthly reporting workspace.
export function ProjectDashboard() {
  const { id } = useParams();
  return <Navigate to={"/projects/" + id + "/reports"} replace />;
}
