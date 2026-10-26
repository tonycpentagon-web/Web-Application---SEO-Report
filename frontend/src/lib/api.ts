import axios from "axios";
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || (import.meta.env.DEV ? "http://127.0.0.1:8000" : window.location.origin),
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
});
api.interceptors.response.use(
  (r) => r,
  (error) => {
    if (error.response?.status === 401)
      window.dispatchEvent(new Event("auth:expired"));
    return Promise.reject(error);
  },
);
export async function csrf() {
  await api.get("/sanctum/csrf-cookie");
}
export function errorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    if (error.response?.status === 419)
      return "Your session expired. Refresh this page and try again.";
    if (error.response?.status === 429)
      return "Too many attempts. Please wait a minute and try again.";
    if (error.response?.status === 403)
      return "You do not have permission to perform this action.";
    if (error.response?.status === 404)
      return "This record is unavailable or you do not have access.";
    if (error.response?.data?.errors)
      return Object.values(error.response.data.errors).flat().join(" ");
    if ((error.response?.status || 0) >= 500)
      return "The server could not complete this request. Please try again.";
    if (!error.response)
      return "Unable to connect to the server. Check your connection and try again.";
    return (
      error.response?.data?.message || "The request could not be completed."
    );
  }
  return "Something went wrong. Please try again.";
}

export function notify(message: string) {
  window.dispatchEvent(
    new CustomEvent("workspace:notice", { detail: message }),
  );
}
