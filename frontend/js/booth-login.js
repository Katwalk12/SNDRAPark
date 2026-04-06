const BOOTH_AUTH_API = typeof window.getSndraBackendUrl === "function"
  ? window.getSndraBackendUrl("/backend/parking-booth/login.php")
  : `${window.location.origin}/backend/parking-booth/login.php`;
const BOOTH_SESSION_API = typeof window.getSndraBackendUrl === "function"
  ? window.getSndraBackendUrl("/backend/parking-booth/session.php")
  : `${window.location.origin}/backend/parking-booth/session.php`;
const BOOTH_DASHBOARD_ROUTE = typeof window.getSndraRoutePath === "function"
  ? window.getSndraRoutePath("boothDashboard")
  : "./parking-booth.html";

const boothLoginForm = document.getElementById("booth-login-form");
const boothLoginStatus = document.getElementById("booth-login-status");

document.addEventListener("DOMContentLoaded", async () => {
  const activeSession = await fetchBoothSession();

  if (activeSession?.role === "booth") {
    window.location.replace(BOOTH_DASHBOARD_ROUTE);
    return;
  }

  boothLoginForm?.addEventListener("submit", handleBoothLogin);
});

async function handleBoothLogin(event) {
  event.preventDefault();

  const formData = new FormData(boothLoginForm);
  const email = String(formData.get("email") || "").trim().toLowerCase();
  const password = String(formData.get("password") || "");

  if (!email || !password) {
    setStatus("Please enter your booth email and password.", true);
    return;
  }

  try {
    const result = await loginBoothViaApi(email, password);
    setStatus("Login successful. Redirecting to parking booth dashboard...", false);
    const redirectTarget = String(result.redirect || BOOTH_DASHBOARD_ROUTE);
    const normalizedTarget = /parking-booth\.html$/i.test(redirectTarget)
      ? BOOTH_DASHBOARD_ROUTE
      : redirectTarget;
    window.setTimeout(() => window.location.replace(normalizedTarget), 400);
  } catch (error) {
    setStatus(error.message || "Invalid booth teller credentials.", true);
  }
}

async function loginBoothViaApi(email, password) {
  const response = await fetch(BOOTH_AUTH_API, {
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
    throw new Error(result.message || "Booth login failed.");
  }

  return result;
}

function setStatus(message, isError) {
  boothLoginStatus.textContent = message;
  boothLoginStatus.className = `form-status ${isError ? "is-error" : "is-success"}`;
}

async function parseJsonResponse(response) {
  const rawText = await response.text();

  try {
    return rawText.trim() ? JSON.parse(rawText) : {};
  } catch (error) {
    console.error("Booth login raw response:", rawText);
    throw new Error("Backend did not return valid JSON.");
  }
}

async function fetchBoothSession() {
  try {
    const response = await fetch(BOOTH_SESSION_API, {
      method: "GET",
      credentials: "same-origin",
      headers: {
        Accept: "application/json"
      }
    });
    const result = await parseJsonResponse(response);

    if (!response.ok || result?.success === false) {
      return null;
    }

    return result?.data || null;
  } catch (error) {
    return null;
  }
}
