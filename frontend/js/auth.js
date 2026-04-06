function getProjectRoot() {
  const pathSegments = window.location.pathname.split("/").filter(Boolean);
  const frontendIndex = pathSegments.indexOf("frontend");
  const backendIndex = pathSegments.indexOf("backend");
  const projectIndex = frontendIndex >= 0 ? frontendIndex : backendIndex >= 0 ? backendIndex : pathSegments.length;

  return projectIndex > 0 ? `/${pathSegments.slice(0, projectIndex).join("/")}` : "";
}

const authApiBase = `${window.location.origin}${getProjectRoot()}/backend/api/v1`;
const managedSelector = "form[data-auth-form], #login-form, #register-form, #signup-form";

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
}

function validateEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function validatePasswordStrength(value) {
  return /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/.test(value);
}

function getPasswordStrength(value) {
  let score = 0;

  if (/[A-Z]/.test(value)) {
    score += 1;
  }

  if (/[a-z]/.test(value)) {
    score += 1;
  }

  if (/\d/.test(value)) {
    score += 1;
  }

  if (/[^A-Za-z\d]/.test(value) && value.length >= 8) {
    score += 1;
  }

  return score;
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
  const value = field.type === "checkbox" ? field.checked : field.value.trim();

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

    if (rule === "password" && value && !validatePasswordStrength(value)) {
      setFieldError(form, field, "Use 8+ characters with upper, lower, number, and symbol.");
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

      if (passwordField && value !== passwordField.value.trim()) {
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
      password: payload.password || ""
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

  const response = await fetch(`${authApiBase}/${encodeURIComponent(action)}`, options);
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

function handleDemoAction(trigger) {
  const form = trigger.closest("form");

  if (!form) {
    return;
  }

  if (trigger.dataset.demoAction === "google") {
    setFormStatus(form, "Google sign-in UI is ready. Backend OAuth can be connected next.", "success");
  }

  if (trigger.dataset.demoLink === "forgot-password") {
    setFormStatus(form, "Forgot password flow will be available once backend support is added.", "success");
  }
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

function updatePasswordMeter(input) {
  if (!input || !input.id) {
    return;
  }

  document.querySelectorAll(`[data-password-meter][data-target="${input.id}"]`).forEach((meter) => {
    const strength = input.value ? getPasswordStrength(input.value) : 0;
    meter.dataset.strength = String(strength);
  });
}

async function redirectIfAuthenticated() {
  const isAuthHtmlPage = /\/frontend\/pages\/(login|signup)\.html$/i.test(window.location.pathname);

  if (!isAuthHtmlPage) {
    return;
  }

  try {
    await requestAuth("session", {}, "GET");
    window.location.replace("./user-dashboard.html");
  } catch (error) {
    // No active session, stay on the current page.
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  const birthDateField = document.querySelector('input[name="birthDate"]');

  if (birthDateField) {
    birthDateField.max = formatDateForInput(getAdultCutoffDate());
  }

  for (const form of getManagedForms()) {
    const fields = Array.from(form.querySelectorAll("[data-validate]"));

    fields.forEach((field) => {
      const eventName = field.type === "checkbox" ? "change" : "input";

      updateFloatingFieldState(field);

      field.addEventListener(eventName, () => {
        validateField(form, field);
        setFormStatus(form, "");
        updateFloatingFieldState(field);

        if (field.type === "password") {
          updatePasswordMeter(field);
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
        updatePasswordMeter(field);
      }
    });
  }

  document.querySelectorAll("[data-demo-action], [data-demo-link]").forEach((trigger) => {
    trigger.addEventListener("click", (event) => {
      event.preventDefault();
      handleDemoAction(trigger);
    });
  });

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

  try {
    const action = getAuthAction(form);
    const payload = buildAuthPayload(form);
    const result = await requestAuth(action, payload);
    setFormStatus(form, result.message || "Authentication successful.", "success");

    const redirectTarget = result.redirect || form.dataset.redirect;

    if (redirectTarget) {
      window.setTimeout(() => {
        window.location.href = redirectTarget;
      }, 500);
    }
  } catch (error) {
    console.error("Authentication request failed.", error);
    setFormStatus(form, error.message || "Authentication request failed.", "error");
  }
});
