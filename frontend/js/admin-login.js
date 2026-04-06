const STAFF_SESSION_KEY = "sndraStaffSession";
const ADMIN_AUTH_API = typeof window.getSndraBackendUrl === "function"
  ? window.getSndraBackendUrl("/backend/admin/login.php")
  : `${window.location.origin}/backend/admin/login.php`;
const ADMIN_DASHBOARD_ROUTE = typeof window.getSndraRoutePath === "function"
  ? window.getSndraRoutePath("adminDashboard")
  : "./admin-dashboard.html";

const adminLoginForm = document.getElementById("admin-login-form");
const adminLoginStatus = document.getElementById("admin-login-status");

document.addEventListener("DOMContentLoaded", () => {
  const session = loadStaffSession();

  if (session?.role === "admin") {
    window.location.replace(ADMIN_DASHBOARD_ROUTE);
    return;
  }

  adminLoginForm?.addEventListener("submit", handleAdminLogin);
});

async function handleAdminLogin(event) {
  event.preventDefault();

  const formData = new FormData(adminLoginForm);
  const email = String(formData.get("email") || "").trim().toLowerCase();
  const password = String(formData.get("password") || "");

  if (!email || !password) {
    setStatus("Please enter your admin email and password.", true);
    return;
  }

  try {
    const result = await loginAdminViaApi(email, password);
    const session = result.data || {};
    saveStaffSession(session);
    setStatus("Login successful. Redirecting to admin dashboard...", false);
    const redirectTarget = String(result.redirect || ADMIN_DASHBOARD_ROUTE);
    const normalizedTarget = /admin-dashboard\.html$/i.test(redirectTarget)
      ? ADMIN_DASHBOARD_ROUTE
      : redirectTarget;
    window.setTimeout(() => window.location.replace(normalizedTarget), 400);
  } catch (error) {
    setStatus(error.message || "Invalid admin account credentials.", true);
  }
}

async function loginAdminViaApi(email, password) {
  const response = await fetch(ADMIN_AUTH_API, {
    method: "POST",
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json"
    },
    body: JSON.stringify({ email, password })
  });

  const result = await parseJsonResponse(response);

  if (!response.ok || result.success === false) {
    throw new Error(result.message || "Admin login failed.");
  }

  return result;
}
function saveStaffSession(session) {
  localStorage.setItem(STAFF_SESSION_KEY, JSON.stringify({
    ...session,
    savedAt: new Date().toISOString()
  }));
}

function loadStaffSession() {
  try {
    const session = JSON.parse(localStorage.getItem(STAFF_SESSION_KEY) || "null");
    return session && typeof session === "object" ? session : null;
  } catch (error) {
    return null;
  }
}

function setStatus(message, isError) {
  adminLoginStatus.textContent = message;
  adminLoginStatus.className = `form-status ${isError ? "is-error" : "is-success"}`;
}

async function parseJsonResponse(response) {
  const rawText = await response.text();

  try {
    return rawText.trim() ? JSON.parse(rawText) : {};
  } catch (error) {
    console.error("Admin login raw response:", rawText);
    throw new Error("Backend did not return valid JSON.");
  }
}
