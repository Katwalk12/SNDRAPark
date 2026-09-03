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
// Booth PINs are a fixed 4 digits. The server enforces the same length
// (backend/parking-booth/login.php) and the admin staff form issues them.
const BOOTH_PIN_LENGTH = 4;

const boothPinInput = document.getElementById("booth-pin");
const boothPinDisplay = document.getElementById("booth-pin-display");
const keypadButtons = Array.from(document.querySelectorAll("[data-pin-key], [data-pin-action]"));

document.addEventListener("DOMContentLoaded", async () => {
  const activeSession = await fetchBoothSession();

  if (activeSession?.role === "booth") {
    window.location.replace(BOOTH_DASHBOARD_ROUTE);
    return;
  }

  boothLoginForm?.addEventListener("submit", handleBoothLogin);
  boothPinInput?.addEventListener("input", () => {
    boothPinInput.value = sanitizePin(boothPinInput.value);
    renderPinDisplay();
  });
  keypadButtons.forEach((button) => button.addEventListener("click", handleKeypadPress));
  renderPinDisplay();
});

async function handleBoothLogin(event) {
  event.preventDefault();

  const pin = sanitizePin(boothPinInput?.value || "");

  if (!pin) {
    setStatus("Please enter your booth PIN.", true);
    shakeForm();
    return;
  }

  if (pin.length !== BOOTH_PIN_LENGTH) {
    setStatus(`PIN must be exactly ${BOOTH_PIN_LENGTH} digits.`, true);
    shakeForm();
    return;
  }

  setSubmitting(true);

  try {
    const result = await loginBoothViaApi(pin);
    setStatus("Access granted. Opening booth console...", false);
    const redirectTarget = String(result.redirect || BOOTH_DASHBOARD_ROUTE);
    const normalizedTarget = /parking-booth\.html$/i.test(redirectTarget)
      ? BOOTH_DASHBOARD_ROUTE
      : redirectTarget;
    window.setTimeout(() => window.location.replace(normalizedTarget), 400);
  } catch (error) {
    setStatus(error.message || "Incorrect PIN code.", true);
    clearPin();
    shakeForm();
  } finally {
    setSubmitting(false);
  }
}

function handleKeypadPress(event) {
  const button = event.currentTarget;
  const key = button.dataset.pinKey;
  const action = button.dataset.pinAction;

  if (!boothPinInput) {
    return;
  }

  if (key && boothPinInput.value.length < BOOTH_PIN_LENGTH) {
    boothPinInput.value = sanitizePin(`${boothPinInput.value}${key}`);
  }

  if (action === "backspace") {
    boothPinInput.value = boothPinInput.value.slice(0, -1);
  }

  if (action === "clear") {
    clearPin();
    return;
  }

  setStatus("", false);
  renderPinDisplay();
}

async function loginBoothViaApi(pin) {
  const response = await fetch(BOOTH_AUTH_API, {
    method: "POST",
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json"
    },
    body: JSON.stringify({ pin })
  });

  const result = await parseJsonResponse(response);

  if (!response.ok || result.success === false) {
    throw new Error(result.message || "Booth login failed.");
  }

  return result;
}

function renderPinDisplay() {
  const pin = sanitizePin(boothPinInput?.value || "");
  const slots = Array.from(boothPinDisplay?.querySelectorAll("span") || []);

  slots.forEach((slot, index) => {
    const isFilled = index < Math.min(pin.length, slots.length);
    slot.textContent = isFilled ? "•" : "";
    slot.classList.toggle("is-filled", isFilled);
  });
}

function clearPin() {
  if (boothPinInput) {
    boothPinInput.value = "";
  }
  renderPinDisplay();
}

function sanitizePin(value) {
  return String(value || "").replace(/\D+/g, "").slice(0, 8);
}

function setStatus(message, isError) {
  boothLoginStatus.textContent = message;
  boothLoginStatus.className = `form-status ${message ? (isError ? "is-error" : "is-success") : ""}`.trim();
}

function setSubmitting(isSubmitting) {
  const submitButton = boothLoginForm?.querySelector('button[type="submit"]');
  if (submitButton) {
    submitButton.disabled = isSubmitting;
    submitButton.textContent = isSubmitting ? "Checking PIN..." : "Unlock Booth Console";
  }
}

function shakeForm() {
  if (!boothLoginForm) {
    return;
  }

  boothLoginForm.classList.remove("is-shaking");
  window.requestAnimationFrame(() => {
    boothLoginForm.classList.add("is-shaking");
    window.setTimeout(() => boothLoginForm.classList.remove("is-shaking"), 240);
  });
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
