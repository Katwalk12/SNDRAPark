function getProjectRoot() {
  const pathSegments = window.location.pathname.split("/").filter(Boolean);
  const frontendIndex = pathSegments.indexOf("frontend");
  const backendIndex = pathSegments.indexOf("backend");
  const projectIndex = frontendIndex >= 0 ? frontendIndex : backendIndex >= 0 ? backendIndex : pathSegments.length;

  return projectIndex > 0 ? `/${pathSegments.slice(0, projectIndex).join("/")}` : "";
}

function getBackendUrl(pathName) {
  if (typeof window.getSndraBackendUrl === "function") {
    return window.getSndraBackendUrl(pathName);
  }

  return `${window.location.origin}${getProjectRoot()}${pathName}`;
}

function getRoutePath(pageName, fallbackPath) {
  if (typeof window.getSndraRoutePath === "function") {
    return window.getSndraRoutePath(pageName);
  }

  return fallbackPath;
}

function normalizeRouteTarget(target) {
  const routeMap = {
    "./index.html": getRoutePath("home", "./index.html"),
    "index.html": getRoutePath("home", "./index.html"),
    "./login.html": getRoutePath("login", "./login.html"),
    "login.html": getRoutePath("login", "./login.html"),
    "./signup.html": getRoutePath("signup", "./signup.html"),
    "signup.html": getRoutePath("signup", "./signup.html"),
    "./user-dashboard.html": getRoutePath("dashboard", "./user-dashboard.html"),
    "user-dashboard.html": getRoutePath("dashboard", "./user-dashboard.html")
  };

  return routeMap[String(target || "").trim()] || target;
}

const authApiBase = getBackendUrl("/backend/api/v1/auth.php");
const userDashboardRoute = getRoutePath("dashboard", "./user-dashboard.html");
const managedSelector = "form[data-auth-form], #login-form, #register-form, #signup-form";

function buildAuthApiUrl(action) {
  const url = new URL(authApiBase, window.location.origin);
  url.searchParams.set("action", action);
  return url.toString();
}

function getManagedForms() {
  return Array.from(document.querySelectorAll(managedSelector));
}

function getErrorElement(form, fieldName) {
  return form.querySelector(`[data-error-for="${fieldName}"]`);
}

function getStatusElement(form) {
  return form.querySelector("[data-form-status]");
}

function updateFloatingFieldState(field) {
  const fieldGroup = field.closest(".floating-field");

  if (!fieldGroup) {
    return;
  }

  const hasValue = field.type === "checkbox" ? field.checked : field.value.trim() !== "";
  fieldGroup.classList.toggle("has-value", hasValue);
}

function setFieldError(form, field, message) {
  const fieldGroup = field.closest(".field-group");
  const checkboxRow = field.closest(".checkbox-row");
  const errorElement = getErrorElement(form, field.name);

  field.setAttribute("aria-invalid", "true");

  if (fieldGroup) {
    fieldGroup.classList.add("is-invalid");
  }

  if (checkboxRow) {
    checkboxRow.classList.add("is-invalid");
  }

  if (errorElement) {
    errorElement.textContent = message;
  }
}

function clearFieldError(form, field) {
  const fieldGroup = field.closest(".field-group");
  const checkboxRow = field.closest(".checkbox-row");
  const errorElement = getErrorElement(form, field.name);

  field.removeAttribute("aria-invalid");

  if (fieldGroup) {
    fieldGroup.classList.remove("is-invalid");
  }

  if (checkboxRow) {
    checkboxRow.classList.remove("is-invalid");
  }

  if (errorElement) {
    errorElement.textContent = "";
  }
}

function setFormStatus(form, message, type = "") {
  const statusElement = getStatusElement(form);

  if (!statusElement) {
    return;
  }

  statusElement.textContent = message;
  statusElement.classList.remove("is-error", "is-success");

  if (type) {
    statusElement.classList.add(`is-${type}`);
  }

  revealAppealPanelIfLocked(message);
}

/**
 * The appeal form only exists for the one failure it answers: an account
 * locked for repeated no-shows. Any other login error leaves it hidden.
 */
function revealAppealPanelIfLocked(message) {
  const panel = document.getElementById("appeal-panel");

  if (!panel) {
    return;
  }

  const text = String(message || "").toLowerCase();
  const isLocked = text.includes("locked") || text.includes("letter of appeal");

  if (isLocked) {
    panel.hidden = false;
  }
}

async function submitAccountAppeal() {
  const panel = document.getElementById("appeal-panel");
  const messageField = document.getElementById("appeal-message");
  const statusNode = document.getElementById("appeal-status");
  const button = document.getElementById("appeal-submit-btn");
  const emailField = document.getElementById("login-email");

  if (!panel || !messageField || !statusNode) {
    return;
  }

  const setStatus = (text, type) => {
    statusNode.textContent = text;
    statusNode.classList.remove("is-error", "is-success");

    if (type) {
      statusNode.classList.add(`is-${type}`);
    }
  };

  const email = String(emailField?.value || "").trim();
  const message = String(messageField.value || "").trim();

  if (!email) {
    setStatus("Enter the email address of the locked account above first.", "error");
    emailField?.focus();
    return;
  }

  if (message.length < 20) {
    setStatus("Explain what happened in at least 20 characters.", "error");
    messageField.focus();
    return;
  }

  if (button) {
    button.disabled = true;
  }

  try {
    const endpoint = typeof window.getSndraBackendUrl === "function"
      ? window.getSndraBackendUrl("/backend/user/submit-appeal.php")
      : `${window.location.origin}/backend/user/submit-appeal.php`;

    const response = await fetch(endpoint, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ email, message })
    });

    const rawText = await response.text();
    let result = {};

    try {
      result = rawText.trim() ? JSON.parse(rawText) : {};
    } catch (parseError) {
      throw new Error("The server did not return a valid response.");
    }

    if (!response.ok || result.success === false) {
      throw new Error(result.message || "Failed to submit the appeal.");
    }

    setStatus(result.message || "Your appeal has been submitted.", "success");
    messageField.value = "";
  } catch (error) {
    setStatus(error.message || "Failed to submit the appeal.", "error");
  } finally {
    if (button) {
      button.disabled = false;
    }
  }
}

document.addEventListener("DOMContentLoaded", () => {
  document.getElementById("appeal-submit-btn")?.addEventListener("click", submitAccountAppeal);
});

function validateEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function getPasswordPolicy() {
  return window.SndraPasswordPolicy || null;
}

// Details the password is not allowed to contain (name, email, birth date).
function getPasswordContext(form) {
  if (!form) {
    return {};
  }

  const readField = (name) => String(form.querySelector(`[name="${name}"]`)?.value || "").trim();

  return {
    firstName: readField("firstName"),
    lastName: readField("lastName"),
    email: readField("email"),
    birthDate: readField("birthDate")
  };
}

function evaluatePassword(value, context) {
  const policy = getPasswordPolicy();

  if (policy) {
    return policy.evaluate(value, context);
  }

  // Fallback when password-policy.js is not loaded on the page.
  const valid = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d])\S{8,}$/.test(value);

  return {
    valid,
    errors: valid ? [] : ["Use 8+ characters with uppercase, lowercase, a number and a special character."],
    checks: {},
    score: valid ? 4 : 0
  };
}

function validatePlateNumber(value) {
  return /^[A-Z0-9-]{2,20}$/.test(String(value || "").trim().toUpperCase());
}

function getPasswordStrength(value, context) {
  return evaluatePassword(value, context).score;
}

function getAdultCutoffDate() {
  const cutoffDate = new Date();
  cutoffDate.setHours(0, 0, 0, 0);
  cutoffDate.setFullYear(cutoffDate.getFullYear() - 18);
  return cutoffDate;
}

function formatDateForInput(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function calculateAgeFromBirthDate(value) {
  if (!value) {
    return null;
  }

  const birthDate = new Date(`${value}T00:00:00`);

  if (Number.isNaN(birthDate.getTime())) {
    return null;
  }

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  let age = today.getFullYear() - birthDate.getFullYear();
  const hasHadBirthday =
    today.getMonth() > birthDate.getMonth() ||
    (today.getMonth() === birthDate.getMonth() && today.getDate() >= birthDate.getDate());

  if (!hasHadBirthday) {
    age -= 1;
  }

  return age;
}

function getFieldRules(field) {
  return (field.dataset.validate || "")
    .split("|")
    .map((rule) => rule.trim())
    .filter(Boolean);
}

function validateField(form, field) {
  const rules = getFieldRules(field);
  // Passwords are never trimmed so what the user typed is what gets checked and sent.
  const value = field.type === "checkbox"
    ? field.checked
    : field.type === "password"
      ? field.value
      : field.value.trim();

  clearFieldError(form, field);

  for (const rule of rules) {
    if (rule === "required") {
      if ((field.type === "checkbox" && !field.checked) || (field.type !== "checkbox" && !value)) {
        setFieldError(form, field, "This field is required.");
        return false;
      }
    }

    if (rule === "email" && value && !validateEmail(value)) {
      setFieldError(form, field, "Enter a valid email address.");
      return false;
    }

    if (rule === "password" && value) {
      const result = evaluatePassword(value, getPasswordContext(form));

      if (!result.valid) {
        setFieldError(form, field, result.errors[0]);
        return false;
      }
    }

    if (rule === "plate-number" && value && !validatePlateNumber(value)) {
      setFieldError(form, field, "Use 2-20 letters/numbers. Hyphens are allowed.");
      return false;
    }

    if (rule === "adult-age" && value) {
      const age = calculateAgeFromBirthDate(value);

      if (age === null) {
        setFieldError(form, field, "Enter a valid birth date.");
        return false;
      }

      if (age < 18) {
        setFieldError(form, field, "You must be at least 18 years old to sign up.");
        return false;
      }
    }

    if (rule === "confirm-password") {
      const passwordField = form.querySelector('input[name="password"]');

      if (passwordField && value !== passwordField.value) {
        setFieldError(form, field, "Passwords do not match.");
        return false;
      }
    }
  }

  return true;
}

function validateForm(form) {
  const fields = Array.from(form.querySelectorAll("[data-validate]"));
  let isValid = true;

  for (const field of fields) {
    const fieldIsValid = validateField(form, field);

    if (!fieldIsValid && isValid) {
      field.focus();
    }

    isValid = fieldIsValid && isValid;
  }

  return isValid;
}

function getAuthAction(form) {
  if (form.dataset.action) {
    return form.dataset.action;
  }

  if (form.id === "register-form" || form.id === "signup-form") {
    return "register";
  }

  return "login";
}

function buildAuthPayload(form) {
  const formData = new FormData(form);
  const payload = Object.fromEntries(formData.entries());

  if (form.id === "signup-form") {
    return {
      firstName: payload.firstName?.trim() || "",
      lastName: payload.lastName?.trim() || "",
      birthDate: payload.birthDate || "",
      email: payload.email?.trim() || "",
      password: payload.password || "",
      vehicleType: payload.vehicleType || "",
      plateNumber: (payload.plateNumber || "").trim().toUpperCase(),
      vehicleBrand: payload.vehicleBrand?.trim() || "",
      vehicleModel: payload.vehicleModel?.trim() || "",
      vehicleColor: payload.vehicleColor?.trim() || ""
    };
  }

  if (form.id === "register-form") {
    return {
      full_name: payload.full_name?.trim() || "",
      email: payload.email?.trim() || "",
      password: payload.password || ""
    };
  }

  return {
    email: payload.email?.trim() || "",
    password: payload.password || ""
  };
}

async function requestAuth(action, payload, method = "POST") {
  const options = {
    method,
    headers: {
      "Content-Type": "application/json"
    },
    credentials: "same-origin"
  };

  if (method !== "GET") {
    options.body = JSON.stringify(payload);
  }

  const response = await fetch(buildAuthApiUrl(action), options);
  const rawText = await response.text();
  let result = null;

  if (rawText.trim()) {
    try {
      result = JSON.parse(rawText);
    } catch (error) {
      console.error("Authentication raw response:", rawText);
      throw new Error(`Server returned an invalid response (${response.status}).`);
    }
  }

  if (!result) {
    throw new Error(`Server returned an empty response (${response.status}).`);
  }

  if (!response.ok || result.success === false) {
    throw new Error(result.message || "Authentication request failed.");
  }

  return result;
}

function togglePasswordVisibility(toggleButton) {
  const targetId = toggleButton.dataset.target;
  const input = document.getElementById(targetId);

  if (!input) {
    return;
  }

  const willShowPassword = input.type === "password";
  input.type = willShowPassword ? "text" : "password";
  toggleButton.setAttribute("aria-pressed", String(willShowPassword));
  toggleButton.setAttribute("aria-label", willShowPassword ? "Hide password" : "Show password");
}

function openDatePicker(toggleButton) {
  const targetId = toggleButton.dataset.target;
  const input = document.getElementById(targetId);

  if (!(input instanceof HTMLInputElement)) {
    return;
  }

  input.focus({ preventScroll: true });

  if (typeof input.showPicker === "function") {
    input.showPicker();
    return;
  }

  input.click();
}

function renderPasswordRules(container, result) {
  const policy = getPasswordPolicy();

  if (!policy) {
    container.hidden = true;
    return;
  }

  if (!container.dataset.rendered) {
    container.innerHTML = policy.RULES
      .map((rule) => `<li class="password-rule" data-rule="${rule.id}"><span class="rule-mark" aria-hidden="true"></span>${rule.label}</li>`)
      .join("");
    container.dataset.rendered = "true";
  }

  container.querySelectorAll("[data-rule]").forEach((item) => {
    const passed = Boolean(result.checks[item.dataset.rule]);
    item.classList.toggle("is-met", passed);
    item.setAttribute("aria-checked", String(passed));
  });
}

function updatePasswordFeedback(input) {
  if (!input || !input.id) {
    return;
  }

  const form = input.form;
  const result = evaluatePassword(input.value, getPasswordContext(form));

  document.querySelectorAll(`[data-password-meter][data-target="${input.id}"]`).forEach((meter) => {
    meter.dataset.strength = String(input.value ? result.score : 0);
  });

  document.querySelectorAll(`[data-password-rules][data-target="${input.id}"]`).forEach((container) => {
    renderPasswordRules(container, result);
  });
}

// Turns a Google OAuth failure redirect into a message the user can act on.
function getGoogleErrorNotice(params) {
  if (params.get("error") !== "google_oauth_error") {
    return null;
  }

  // Google sends access_denied both when the person closes the consent window
  // and when the OAuth app is still in Testing and their account is not on the
  // tester list. The two are indistinguishable in the callback, so the copy has
  // to name both rather than assert "cancelled" and send them looking in the
  // wrong place.
  const knownCodes = {
    access_denied: "Google did not complete the sign in. Either the Google window was closed, or this Google account is not approved to use the app yet. You can log in with your email and password instead.",
    admin_policy_enforced: "Your Google Workspace administrator has blocked sign in to this app. Contact your administrator, or log in with your email and password.",
    invalid_state: "Your Google sign in session expired. Please click Continue with Google again.",
    email_not_verified: "Your Google email address is not verified. Verify it with Google, then try again.",
    missing_env_credentials: "Google sign in is not configured on this server. Please contact the administrator.",
    oauth_api_error: "Google could not complete the sign in. Please try again, or log in with your email and password.",
    oauth_http_failed: "Could not reach Google. Check your connection and try again, or log in with your email and password.",
    callback_error: "Google sign in failed. Please try again, or log in with your email and password."
  };

  const code = params.get("code") || "";
  const message = knownCodes[code] || params.get("msg") || "Google sign in failed. Please try again.";
  const detail = (params.get("detail") || "").trim();

  // `detail` only ever carries Google's own user-facing explanation; internal
  // failure text stays in the server log (see google_oauth_fail).
  return [detail ? `${message} (Google said: ${detail})` : message, "error"];
}

// Explains why the user landed back on the login page (idle logout, finished reset, ...).
function showEntryNotice() {
  const loginForm = document.getElementById("login-form");

  if (!loginForm) {
    return;
  }

  const params = new URLSearchParams(window.location.search);

  const notices = {
    timeout: ["You were logged out automatically after a period of inactivity. Please log in again.", "error"],
    "session-expired": ["Your session has expired. Please log in again.", "error"],
    "password-reset": ["Your password has been updated. Please log in using your new password.", "success"]
  };

  const notice = getGoogleErrorNotice(params) || notices[params.get("reason")];

  if (notice) {
    setFormStatus(loginForm, notice[0], notice[1]);

    // Keep the error out of the address bar so a refresh does not repeat it.
    if (window.history.replaceState) {
      window.history.replaceState({}, "", window.location.pathname);
    }
  }
}

async function redirectIfAuthenticated() {
  const isAuthHtmlPage = /\/(?:frontend\/pages\/)?(login|signup)(?:\.html)?$/i.test(window.location.pathname);

  if (!isAuthHtmlPage) {
    return;
  }

  try {
    // Binago para tumuro sa tamang action endpoint query param imbes na maging subfolder array URL path
    await requestAuth("session", {}, "GET");
    window.location.replace(userDashboardRoute);
  } catch (error) {
    // Walang aktibong login session, manatili lang sa login window.
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  const birthDateField = document.querySelector('input[name="birthDate"]');

  if (birthDateField) {
    birthDateField.max = formatDateForInput(getAdultCutoffDate());
  }

  for (const form of getManagedForms()) {
    const fields = Array.from(form.querySelectorAll("[data-validate]"));
    const strengthFields = fields.filter((field) => getFieldRules(field).includes("password"));
    const contextFieldNames = ["firstName", "lastName", "email", "birthDate"];

    const refreshPasswordFeedback = () => {
      strengthFields.forEach((passwordField) => {
        updatePasswordFeedback(passwordField);

        // The name/email/birth date are part of the policy, so a password that was
        // already accepted has to be re-checked when any of them changes.
        if (passwordField.value) {
          validateField(form, passwordField);
        }
      });
    };

    fields.forEach((field) => {
      const eventName = field.type === "checkbox" ? "change" : "input";

      updateFloatingFieldState(field);

      field.addEventListener(eventName, () => {
        validateField(form, field);
        setFormStatus(form, "");
        updateFloatingFieldState(field);

        if (field.type === "password") {
          updatePasswordFeedback(field);
        }

        if (contextFieldNames.includes(field.name)) {
          refreshPasswordFeedback();
        }
      });

      field.addEventListener("focus", () => {
        field.closest(".floating-field")?.classList.add("is-focused");
      });

      field.addEventListener("blur", () => {
        field.closest(".floating-field")?.classList.remove("is-focused");
        updateFloatingFieldState(field);
      });

      if (field.type === "password") {
        updatePasswordFeedback(field);
      }
    });
  }

  document.querySelectorAll("[data-password-toggle]").forEach((toggleButton) => {
    toggleButton.addEventListener("click", () => {
      togglePasswordVisibility(toggleButton);
    });
  });

  document.querySelectorAll("[data-date-toggle]").forEach((toggleButton) => {
    toggleButton.addEventListener("click", () => {
      openDatePicker(toggleButton);
    });
  });

  showEntryNotice();

  await redirectIfAuthenticated();
});

document.addEventListener("submit", async (event) => {
  const form = event.target;

  if (!(form instanceof HTMLFormElement) || !form.matches(managedSelector)) {
    return;
  }

  event.preventDefault();
  setFormStatus(form, "");

  const isValid = validateForm(form);

  if (!isValid) {
    setFormStatus(form, "Please fix the highlighted fields and try again.", "error");
    return;
  }

  const submitButton = form.querySelector('button[type="submit"]');

  try {
    if (submitButton) {
      submitButton.disabled = true;
    }

    const action = getAuthAction(form);
    const payload = buildAuthPayload(form);
    const result = await requestAuth(action, payload);

    // Remind the user when the password is due for its periodic change.
    const passwordNotice = result.notice || result.password_status?.message || "";
    setFormStatus(form, passwordNotice || result.message || "Authentication successful.", "success");

    if (passwordNotice) {
      try {
        window.sessionStorage.setItem("sndraPasswordNotice", passwordNotice);
      } catch (storageError) {
        // Storage is unavailable (private mode); the reminder is simply skipped.
      }
    }

    const redirectTarget = result.redirect || form.dataset.redirect;

    if (redirectTarget) {
      window.setTimeout(() => {
        window.location.href = normalizeRouteTarget(redirectTarget);
      }, passwordNotice ? 1600 : 500);
    }
  } catch (error) {
    console.error("Authentication request failed.", error);
    setFormStatus(form, error.message || "Authentication request failed.", "error");

    if (submitButton) {
      submitButton.disabled = false;
    }
  }
});

// Exposed for signup-steps.js. The signup wizard has to decide whether a step
// may be left, and it must reach that verdict with exactly the rules the submit
// handler applies - a second copy would drift.
window.SndraAuthForms = { validateField, validateForm, clearFieldError };
