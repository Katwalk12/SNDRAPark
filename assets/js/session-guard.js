/**
 * SNDRA Park - idle session guard.
 *
 * Users should log out when they finish, but unattended sessions must not stay
 * open forever. This watches for activity and signs the user out automatically
 * after the configured idle period (15-30 minutes), warning shortly before.
 *
 * Load after assets/js/runtime-config.js on any authenticated page.
 */
(function () {
  "use strict";

  const DEFAULT_IDLE_SECONDS = 30 * 60;
  const MIN_IDLE_SECONDS = 5 * 60;
  const WARNING_SECONDS = 60;
  const KEEP_ALIVE_INTERVAL_MS = 5 * 60 * 1000;
  const ACTIVITY_EVENTS = ["mousedown", "keydown", "wheel", "touchstart", "scroll", "pointerdown"];

  function backendUrl(pathName) {
    if (typeof window.getSndraBackendUrl === "function") {
      return window.getSndraBackendUrl(pathName);
    }

    return `${window.location.origin}${pathName}`;
  }

  function loginRoute() {
    if (typeof window.getSndraRoutePath === "function") {
      return window.getSndraRoutePath("login");
    }

    return "/frontend/pages/login.html";
  }

  function authActionUrl(action) {
    const url = new URL(backendUrl("/backend/api/v1/auth.php"), window.location.origin);
    url.searchParams.set("action", action);
    return url.toString();
  }

  const guard = {
    idleSeconds: DEFAULT_IDLE_SECONDS,
    lastActivityAt: Date.now(),
    lastKeepAliveAt: 0,
    tickTimer: null,
    warningVisible: false,
    signingOut: false
  };

  function buildWarningDialog() {
    const overlay = document.createElement("div");
    overlay.className = "session-guard-overlay";
    overlay.setAttribute("role", "alertdialog");
    overlay.setAttribute("aria-modal", "true");
    overlay.setAttribute("aria-labelledby", "session-guard-title");
    overlay.hidden = true;
    overlay.innerHTML = `
      <div class="session-guard-dialog">
        <h2 id="session-guard-title">Still there?</h2>
        <p class="session-guard-copy">
          You have been inactive for a while. For your security you will be logged out in
          <strong data-session-countdown>${WARNING_SECONDS}</strong> seconds.
        </p>
        <div class="session-guard-actions">
          <button type="button" class="session-guard-btn primary" data-session-stay>Stay signed in</button>
          <button type="button" class="session-guard-btn" data-session-logout>Log out now</button>
        </div>
      </div>
    `;

    const style = document.createElement("style");
    style.textContent = `
      .session-guard-overlay {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(5, 5, 5, 0.72);
        backdrop-filter: blur(4px);
      }
      .session-guard-overlay[hidden] { display: none; }
      .session-guard-dialog {
        width: min(420px, 100%);
        padding: 28px;
        border-radius: 18px;
        background: #141414;
        border: 1px solid #2c2c2c;
        color: #f2f2f2;
        font-family: "Montserrat", system-ui, sans-serif;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.55);
        text-align: center;
      }
      .session-guard-dialog h2 { margin: 0 0 10px; font-size: 22px; }
      .session-guard-copy { margin: 0 0 22px; font-size: 14px; line-height: 1.6; color: #cfcfcf; }
      .session-guard-copy strong { color: #f4c542; }
      .session-guard-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
      .session-guard-btn {
        padding: 11px 18px;
        border-radius: 999px;
        border: 1px solid #3a3a3a;
        background: transparent;
        color: #e8e8e8;
        font: inherit;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
      }
      .session-guard-btn.primary { background: #f4c542; border-color: #f4c542; color: #191919; }
      .session-guard-btn:hover { opacity: 0.88; }
    `;

    document.head.appendChild(style);
    document.body.appendChild(overlay);

    overlay.querySelector("[data-session-stay]").addEventListener("click", () => {
      hideWarning();
      registerActivity(true);
    });

    overlay.querySelector("[data-session-logout]").addEventListener("click", () => {
      signOut(false);
    });

    return overlay;
  }

  let warningOverlay = null;

  function showWarning(secondsLeft) {
    if (!warningOverlay) {
      warningOverlay = buildWarningDialog();
    }

    warningOverlay.hidden = false;
    guard.warningVisible = true;
    warningOverlay.querySelector("[data-session-countdown]").textContent = String(Math.max(0, secondsLeft));
  }

  function hideWarning() {
    if (warningOverlay) {
      warningOverlay.hidden = true;
    }

    guard.warningVisible = false;
  }

  async function signOut(expired) {
    if (guard.signingOut) {
      return;
    }

    guard.signingOut = true;
    hideWarning();

    try {
      // The auth router exposes logout over GET (see backend/routes/api.php).
      await fetch(authActionUrl("logout"), {
        method: "GET",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin"
      });
    } catch (error) {
      // Even if the call fails the local session is abandoned below.
    }

    const target = new URL(loginRoute(), window.location.origin);

    if (expired) {
      target.searchParams.set("reason", "timeout");
    }

    window.location.replace(target.toString());
  }

  async function keepServerSessionAlive() {
    if (Date.now() - guard.lastKeepAliveAt < KEEP_ALIVE_INTERVAL_MS) {
      return;
    }

    guard.lastKeepAliveAt = Date.now();

    try {
      const response = await fetch(authActionUrl("session"), {
        method: "GET",
        credentials: "same-origin"
      });
      const result = await response.json();

      if (result && result.authenticated === false) {
        signOut(true);
        return;
      }

      const serverIdleSeconds = Number(result?.idle_timeout_seconds || 0);

      if (serverIdleSeconds >= MIN_IDLE_SECONDS) {
        guard.idleSeconds = serverIdleSeconds;
      }
    } catch (error) {
      // Network hiccup - keep using the last known timeout.
    }
  }

  function registerActivity(force) {
    guard.lastActivityAt = Date.now();

    if (guard.warningVisible && !force) {
      return;
    }

    if (guard.warningVisible) {
      hideWarning();
    }

    keepServerSessionAlive();
  }

  function tick() {
    const idleSeconds = Math.floor((Date.now() - guard.lastActivityAt) / 1000);
    const secondsLeft = guard.idleSeconds - idleSeconds;

    if (secondsLeft <= 0) {
      signOut(true);
      return;
    }

    if (secondsLeft <= WARNING_SECONDS) {
      showWarning(secondsLeft);
      return;
    }

    if (guard.warningVisible) {
      hideWarning();
    }
  }

  /**
   * One-off banner for things the user needs to know right after signing in:
   * a password that is due for its periodic change, or a brand new Google
   * account that still has no vehicle registered.
   */
  function showPostLoginNotice() {
    const messages = [];
    const params = new URLSearchParams(window.location.search);

    if (params.get("welcome") === "google") {
      messages.push("Welcome! Your account was created with Google. Add your vehicle to start reserving a slot.");

      // The query string is stripped immediately below, so leave a flag behind:
      // a page that owns a vehicle form (the user dashboard) reads this to send
      // the user straight to it instead of just naming it in a banner.
      window.__SNDRA_GOOGLE_WELCOME = true;

      if (window.history.replaceState) {
        params.delete("welcome");
        const query = params.toString();
        window.history.replaceState({}, "", window.location.pathname + (query ? `?${query}` : ""));
      }
    }

    try {
      const passwordNotice = window.sessionStorage.getItem("sndraPasswordNotice");

      if (passwordNotice) {
        messages.push(passwordNotice);
        window.sessionStorage.removeItem("sndraPasswordNotice");
      }
    } catch (error) {
      // Storage unavailable - nothing to show.
    }

    if (!messages.length) {
      return;
    }

    const banner = document.createElement("div");
    banner.className = "session-guard-notice";
    banner.setAttribute("role", "status");
    banner.innerHTML = `
      <div class="session-guard-notice-body">${messages.map((text) => `<p>${text}</p>`).join("")}</div>
      <button type="button" class="session-guard-notice-close" aria-label="Dismiss">&times;</button>
    `;

    const style = document.createElement("style");
    style.textContent = `
      .session-guard-notice {
        position: fixed;
        top: 16px;
        right: 16px;
        z-index: 9998;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        max-width: min(420px, calc(100vw - 32px));
        padding: 14px 16px;
        border-radius: 14px;
        border: 1px solid #f4c542;
        background: #1b1a14;
        color: #f6f2e6;
        font-family: "Montserrat", system-ui, sans-serif;
        font-size: 13px;
        line-height: 1.55;
        box-shadow: 0 16px 40px rgba(0, 0, 0, 0.4);
      }
      .session-guard-notice p { margin: 0 0 6px; }
      .session-guard-notice p:last-child { margin-bottom: 0; }
      .session-guard-notice-close {
        flex: 0 0 auto;
        border: 0;
        background: transparent;
        color: #f4c542;
        font-size: 20px;
        line-height: 1;
        cursor: pointer;
      }
    `;

    document.head.appendChild(style);
    document.body.appendChild(banner);

    banner.querySelector(".session-guard-notice-close").addEventListener("click", () => {
      banner.remove();
    });

    window.setTimeout(() => banner.remove(), 15000);
  }

  function start(options) {
    const configuredSeconds = Number(options?.idleSeconds || 0);

    if (configuredSeconds >= MIN_IDLE_SECONDS) {
      guard.idleSeconds = configuredSeconds;
    }

    ACTIVITY_EVENTS.forEach((eventName) => {
      window.addEventListener(eventName, () => registerActivity(false), { passive: true });
    });

    document.addEventListener("visibilitychange", () => {
      if (!document.hidden) {
        tick();
      }
    });

    guard.lastActivityAt = Date.now();
    keepServerSessionAlive();
    showPostLoginNotice();

    if (guard.tickTimer) {
      window.clearInterval(guard.tickTimer);
    }

    guard.tickTimer = window.setInterval(tick, 1000);
  }

  window.SndraSessionGuard = {
    start,
    signOut,
    getIdleSeconds: () => guard.idleSeconds
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => start({}));
  } else {
    start({});
  }
})();
