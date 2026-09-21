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
const adminTwoFactorModal = document.getElementById("admin-2fa-modal");
const adminTwoFactorCode = document.getElementById("admin-2fa-code");
const adminTwoFactorSubmit = document.getElementById("admin-2fa-submit");
const adminTwoFactorCancel = document.getElementById("admin-2fa-cancel");
const adminTwoFactorStatus = document.getElementById("admin-2fa-status");
const adminTwoFactorHint = document.getElementById("admin-2fa-hint");

// What to hand focus back to when the dialog closes.
let twoFactorReturnFocus = null;

// True while a sign-in request is on the wire.
let loginInFlight = false;

document.addEventListener("DOMContentLoaded", () => {
  const session = loadStaffSession();

  if (session?.role === "admin") {
    window.location.replace(ADMIN_DASHBOARD_ROUTE);
    return;
  }

  adminLoginForm?.addEventListener("submit", handleAdminLogin);
  adminTwoFactorSubmit?.addEventListener("click", handleTwoFactorSubmit);
  adminTwoFactorCancel?.addEventListener("click", () => cancelTwoFactor());

  // Clicking the blurred area behind the dialog is the other way out.
  adminTwoFactorModal?.querySelector("[data-2fa-dismiss]")
    ?.addEventListener("click", () => cancelTwoFactor());

  adminTwoFactorCode?.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      handleTwoFactorSubmit();
    }
  });

  // Digits only, so a pasted "123 456" still submits.
  adminTwoFactorCode?.addEventListener("input", () => {
    adminTwoFactorCode.value = adminTwoFactorCode.value.replace(/\D+/g, "").slice(0, 6);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !isTwoFactorOpen()) {
      return;
    }

    if (event.key === "Escape") {
      event.preventDefault();
      cancelTwoFactor();
      return;
    }

    // A modal that can be tabbed out of is not modal. Only two controls and
    // one field live in here, so the cycle is kept by hand rather than by
    // pulling in a focus-trap library.
    if (event.key === "Tab" && isTwoFactorOpen()) {
      trapTwoFactorFocus(event);
    }
  });
});

function isTwoFactorOpen() {
  return Boolean(adminTwoFactorModal) && !adminTwoFactorModal.hidden;
}

function trapTwoFactorFocus(event) {
  const focusable = [adminTwoFactorCancel, adminTwoFactorCode, adminTwoFactorSubmit]
    .filter((node) => node && !node.disabled);

  if (focusable.length === 0) {
    return;
  }

  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  const active = document.activeElement;

  if (event.shiftKey && (active === first || !focusable.includes(active))) {
    event.preventDefault();
    last.focus();
    return;
  }

  if (!event.shiftKey && active === last) {
    event.preventDefault();
    first.focus();
  }
}

async function handleAdminLogin(event) {
  event.preventDefault();

  // A sign-in takes a moment, and the button stayed live the whole time. Every
  // impatient click fired another request, so a handful of clicks became a
  // wall of 401s in the console and a burst of password checks on the server.
  // One attempt is allowed to be in flight at a time.
  if (loginInFlight) {
    return;
  }

  const formData = new FormData(adminLoginForm);
  const email = String(formData.get("email") || "").trim().toLowerCase();
  const password = String(formData.get("password") || "");

  if (!email || !password) {
    setStatus("Please enter your admin email and password.", true);
    return;
  }

  setLoginBusy(true);

  try {
    // Forwarded so a headless browser that fills every input on the page trips
    // the same wire a raw form post does. A real browser submits it untouched,
    // which is an empty string and means nothing happened.
    const result = await loginAdminViaApi(email, password, String(formData.get("admin_notes_hp") || ""));

    // The password was right but the account also wants the emailed code.
    if (result.requiresTwoFactor) {
      showTwoFactorStep(result.message || "Enter the code we emailed you.");
      return;
    }

    completeAdminSignIn(result);
  } catch (error) {
    setStatus(error.message || "Invalid admin account credentials.", true);
  } finally {
    setLoginBusy(false);
  }
}

/**
 * Hold the sign-in button while an attempt is running.
 *
 * The flag is what actually prevents a second request -- a disabled button
 * still leaves the Enter key -- and the disabled state is what tells the
 * person why nothing is happening.
 */
function setLoginBusy(isBusy) {
  loginInFlight = isBusy;

  const submitButton = adminLoginForm?.querySelector("button[type=\"submit\"]");

  if (!submitButton) {
    return;
  }

  submitButton.disabled = isBusy;

  if (isBusy) {
    submitButton.dataset.idleLabel = submitButton.dataset.idleLabel || submitButton.textContent;
    submitButton.textContent = "Signing in...";
    setStatus("Checking your credentials...", false);
    return;
  }

  if (submitButton.dataset.idleLabel) {
    submitButton.textContent = submitButton.dataset.idleLabel;
  }
}

function showTwoFactorStep(message) {
  if (!adminTwoFactorModal) {
    return;
  }

  twoFactorReturnFocus = document.activeElement;

  if (adminTwoFactorHint && message) {
    adminTwoFactorHint.textContent = message;
  }

  setTwoFactorStatus("", false);
  adminTwoFactorModal.hidden = false;
  document.body.classList.add("has-modal-open");

  // The password step is done; saying so on the form underneath means the
  // page still explains itself once the dialog is dismissed.
  setStatus("Waiting for your emailed sign-in code.", false);

  if (adminTwoFactorCode) {
    adminTwoFactorCode.value = "";
  }

  adminTwoFactorCode?.focus();
}

/**
 * Abandon the half-finished sign-in.
 *
 * Closing the dialog is not enough on its own: the server is holding a pending
 * challenge for this session, so it is told to drop it. That request is
 * deliberately not awaited -- the dialog closes at once either way, and a
 * challenge that outlives the cancel still expires on its own in five minutes.
 */
function cancelTwoFactor(message = "Sign-in cancelled. Enter your details to start again.") {
  if (!isTwoFactorOpen()) {
    return;
  }

  fetch(ADMIN_2FA_API, {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ action: "cancel" })
  }).catch(() => {
    // Nothing to recover: the challenge expires by itself.
  });

  closeTwoFactorModal();
  setStatus(message, false);

  const passwordField = document.getElementById("admin-password");

  if (passwordField) {
    passwordField.value = "";
  }

  if (twoFactorReturnFocus && document.contains(twoFactorReturnFocus)) {
    twoFactorReturnFocus.focus();
  } else {
    document.getElementById("admin-email")?.focus();
  }

  twoFactorReturnFocus = null;
}

function closeTwoFactorModal() {
  if (!adminTwoFactorModal) {
    return;
  }

  adminTwoFactorModal.hidden = true;
  document.body.classList.remove("has-modal-open");

  if (adminTwoFactorCode) {
    adminTwoFactorCode.value = "";
  }
}

function setTwoFactorStatus(message, isError) {
  if (!adminTwoFactorStatus) {
    return;
  }

  adminTwoFactorStatus.textContent = message || "";
  adminTwoFactorStatus.className = `form-status ${isError ? "is-error" : "is-success"}`;
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
    setTwoFactorStatus("Enter the 6-digit code from your email.", true);
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

    closeTwoFactorModal();
    completeAdminSignIn(result);
  } catch (error) {
    setTwoFactorStatus(error.message || "That code is not correct.", true);
    adminTwoFactorCode?.select();
  } finally {
    if (adminTwoFactorSubmit) {
      adminTwoFactorSubmit.disabled = false;
    }
  }
}

async function loginAdminViaApi(email, password, trapValue = "") {
  const response = await fetch(ADMIN_AUTH_API, {
    method: "POST",
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json"
    },
    body: JSON.stringify({ email, password, admin_notes_hp: trapValue })
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
