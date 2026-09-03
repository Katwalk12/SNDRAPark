const STAFF_SESSION_KEY = "sndraStaffSession";
const ADMIN_AUTH_API = typeof window.getSndraBackendUrl === "function"
  ? window.getSndraBackendUrl("/backend/admin/login.php")
  : `${window.location.origin}/backend/admin/login.php`;
const ADMIN_2FA_API = typeof window.getSndraBackendUrl === "function"
  ? window.getSndraBackendUrl("/backend/admin/verify-2fa.php")
  : `${window.location.origin}/backend/admin/verify-2fa.php`;
const ADMIN_DASHBOARD_ROUTE = typeof window.getSndraRoutePath === "function"
  ? window.getSndraRoutePath("adminDashboard")
  : "./admin-dashboard.html";

const adminLoginForm = document.getElementById("admin-login-form");
const adminLoginStatus = document.getElementById("admin-login-status");
const adminTwoFactorStep = document.getElementById("admin-2fa-step");
const adminTwoFactorCode = document.getElementById("admin-2fa-code");
const adminTwoFactorSubmit = document.getElementById("admin-2fa-submit");

document.addEventListener("DOMContentLoaded", () => {
  const session = loadStaffSession();

  if (session?.role === "admin") {
    window.location.replace(ADMIN_DASHBOARD_ROUTE);
    return;
  }

  adminLoginForm?.addEventListener("submit", handleAdminLogin);
  adminTwoFactorSubmit?.addEventListener("click", handleTwoFactorSubmit);
  adminTwoFactorCode?.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      handleTwoFactorSubmit();
    }
  });
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

    // The password was right but the account also wants the emailed code.
    if (result.requiresTwoFactor) {
      showTwoFactorStep(result.message || "Enter the code we emailed you.");
      return;
    }

    completeAdminSignIn(result);
  } catch (error) {
    setStatus(error.message || "Invalid admin account credentials.", true);
  }
}

function showTwoFactorStep(message) {
  if (!adminTwoFactorStep) {
    return;
  }

  adminTwoFactorStep.hidden = false;
  setStatus(message, false);
  adminTwoFactorCode?.focus();
}

function completeAdminSignIn(result) {
  saveStaffSession(result.data || {});
  setStatus("Login successful. Redirecting to admin dashboard...", false);

  const redirectTarget = String(result.redirect || ADMIN_DASHBOARD_ROUTE);
  const normalizedTarget = /admin-dashboard\.html$/i.test(redirectTarget)
    ? ADMIN_DASHBOARD_ROUTE
    : redirectTarget;

  window.setTimeout(() => window.location.replace(normalizedTarget), 400);
}

async function handleTwoFactorSubmit() {
  const code = String(adminTwoFactorCode?.value || "").replace(/\D+/g, "");

  if (code.length !== 6) {
    setStatus("Enter the 6-digit code from your email.", true);
    adminTwoFactorCode?.focus();
    return;
  }

  if (adminTwoFactorSubmit) {
    adminTwoFactorSubmit.disabled = true;
  }

  try {
    const response = await fetch(ADMIN_2FA_API, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ code })
    });

    const result = await parseJsonResponse(response);

    if (!response.ok || result.success === false) {
      throw new Error(result.message || "That code is not correct.");
    }

    completeAdminSignIn(result);
  } catch (error) {
    setStatus(error.message || "That code is not correct.", true);
  } finally {
    if (adminTwoFactorSubmit) {
      adminTwoFactorSubmit.disabled = false;
    }
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
