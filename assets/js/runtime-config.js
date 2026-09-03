(function () {
  function normalizeProjectBasePath(value) {
    const normalized = String(value || "")
      .trim()
      .replace(/\/+$/, "");

    return normalized === "/" ? "" : normalized;
  }

  function detectProjectBasePath() {
    const explicitBasePath = normalizeProjectBasePath(window.__SNDRA_PROJECT_BASE_PATH);

    if (explicitBasePath !== "") {
      return explicitBasePath;
    }

    const pathName = String(window.location.pathname || "");
    const frontendIndex = pathName.indexOf("/frontend/");
    const backendIndex = pathName.indexOf("/backend/");
    const splitIndex = frontendIndex >= 0 ? frontendIndex : backendIndex;

    if (splitIndex >= 0) {
      return normalizeProjectBasePath(pathName.slice(0, splitIndex));
    }

    return "";
  }

  function buildProjectPath(projectBasePath, nextPath) {
    const normalizedNextPath = `/${String(nextPath || "").replace(/^\/+/, "")}`;
    return `${projectBasePath}${normalizedNextPath}` || "/";
  }

  function getPagePath(projectBasePath, pageName) {
    const pageDirectory = buildProjectPath(projectBasePath, "/frontend/pages");
    const htmlRoutes = {
      home: `${pageDirectory}/index.html`,
      login: `${pageDirectory}/login.html`,
      signup: `${pageDirectory}/signup.html`,
      dashboard: `${pageDirectory}/user-dashboard.html`,
      adminLogin: `${pageDirectory}/admin-login.html`,
      adminDashboard: `${pageDirectory}/admin-dashboard.html`,
      boothLogin: `${pageDirectory}/booth-login.html`,
      boothDashboard: `${pageDirectory}/parking-booth.html`,
      terms: `${pageDirectory}/terms.html`
    };

    const cleanRoutes = {
      home: buildProjectPath(projectBasePath, "/"),
      login: buildProjectPath(projectBasePath, "/login"),
      signup: buildProjectPath(projectBasePath, "/signup"),
      dashboard: buildProjectPath(projectBasePath, "/dashboard"),
      adminLogin: buildProjectPath(projectBasePath, "/admin"),
      adminDashboard: buildProjectPath(projectBasePath, "/admin/dashboard"),
      boothLogin: buildProjectPath(projectBasePath, "/booth"),
      boothDashboard: buildProjectPath(projectBasePath, "/booth/dashboard"),
      terms: buildProjectPath(projectBasePath, "/terms")
    };

    const isPrettyRouteContext =
      !window.location.pathname.includes("/frontend/") &&
      !window.location.pathname.includes("/backend/") &&
      !/\.html$/i.test(window.location.pathname);

    const activeRouteMap = isPrettyRouteContext ? cleanRoutes : htmlRoutes;
    return activeRouteMap[pageName] || activeRouteMap.home;
  }

  const projectBasePath = detectProjectBasePath();
  const pageBasePath = buildProjectPath(projectBasePath, "/frontend/pages");
  const backendBasePath = buildProjectPath(projectBasePath, "/backend");

  window.SNDRA_RUNTIME_CONFIG = {
    projectBasePath,
    pageBasePath,
    backendBasePath,
    routePath(pageName) {
      return getPagePath(projectBasePath, pageName);
    },
    routeUrl(pageName) {
      return `${window.location.origin}${getPagePath(projectBasePath, pageName)}`;
    },
    backendPath(pathName) {
      return buildProjectPath(projectBasePath, pathName);
    },
    backendUrl(pathName) {
      return `${window.location.origin}${buildProjectPath(projectBasePath, pathName)}`;
    }
  };

  window.getSndraProjectBasePath = function getSndraProjectBasePath() {
    return projectBasePath;
  };

  window.getSndraBackendPath = function getSndraBackendPath(pathName) {
    return window.SNDRA_RUNTIME_CONFIG.backendPath(pathName);
  };

  window.getSndraBackendUrl = function getSndraBackendUrl(pathName) {
    return window.SNDRA_RUNTIME_CONFIG.backendUrl(pathName);
  };

  window.getSndraRoutePath = function getSndraRoutePath(pageName) {
    return window.SNDRA_RUNTIME_CONFIG.routePath(pageName);
  };

  window.getSndraRouteUrl = function getSndraRouteUrl(pageName) {
    return window.SNDRA_RUNTIME_CONFIG.routeUrl(pageName);
  };

  // --- Developer attribution -----------------------------------------------
  // The footer credit is part of this build. This guard lives inside the
  // runtime config on purpose: every page resolves its API URLs through this
  // file, so deleting it to silence the check takes the whole app down with it.
  //
  // The check is client-side and therefore a deterrent, not enforcement -
  // anyone editing the source can defeat it. The licence and the repository
  // history are what actually establish authorship.
  const ATTRIBUTION_PAGES = ["index.html", "user-dashboard.html"];
  const ATTRIBUTION_FINGERPRINT = "8d93dcbf";
  const ATTRIBUTION_NOTICE =
    "This website was made by Programiz. Do not rewrite the original copy or re-code the system.";

  // FNV-1a over the normalised credit line, so spacing and casing may vary but
  // the wording may not.
  function attributionFingerprint(text) {
    const normalized = String(text || "").toLowerCase().replace(/\s+/g, " ").trim();
    let hash = 0x811c9dc5;

    for (let index = 0; index < normalized.length; index += 1) {
      hash ^= normalized.charCodeAt(index);
      hash = Math.imul(hash, 0x01000193) >>> 0;
    }

    return hash.toString(16).padStart(8, "0");
  }

  function showAttributionNotice() {
    if (document.getElementById("attribution-notice")) {
      return;
    }

    const overlay = document.createElement("div");
    overlay.id = "attribution-notice";
    overlay.setAttribute("role", "alertdialog");
    overlay.setAttribute("aria-modal", "true");
    overlay.style.cssText = [
      "position:fixed", "inset:0", "z-index:2147483647",
      "display:flex", "align-items:center", "justify-content:center",
      "padding:24px", "background:#0B0F19", "color:#FFFFFF",
      "font-family:Montserrat,system-ui,sans-serif", "text-align:center"
    ].join(";");

    const box = document.createElement("div");
    box.style.cssText = "max-width:34rem";

    const heading = document.createElement("h1");
    heading.textContent = "Attribution removed";
    heading.style.cssText = "margin:0 0 0.75rem;font-size:1.5rem;letter-spacing:-0.01em";

    const message = document.createElement("p");
    message.textContent = ATTRIBUTION_NOTICE;
    message.style.cssText = "margin:0 0 1rem;font-size:1rem;line-height:1.6;color:#E5E7EB";

    const hint = document.createElement("p");
    hint.textContent = "Restore the \u201cDeveloped by Programiz\u201d credit in the footer to continue.";
    hint.style.cssText = "margin:0;font-size:0.85rem;color:#9CA3AF";

    box.append(heading, message, hint);
    overlay.append(box);
    document.body.append(overlay);
    document.documentElement.style.overflow = "hidden";
  }

  function verifyAttribution() {
    const pathName = String(window.location.pathname || "");
    const guarded = ATTRIBUTION_PAGES.some(function (page) {
      return pathName.endsWith("/" + page);
    });

    if (!guarded) {
      return;
    }

    const credit = document.querySelector('[data-attribution="programiz"]');

    if (!credit || attributionFingerprint(credit.textContent) !== ATTRIBUTION_FINGERPRINT) {
      showAttributionNotice();
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", verifyAttribution);
  } else {
    verifyAttribution();
  }

  // A later removal from the DOM is caught too, not just one at load time.
  if (typeof MutationObserver === "function") {
    document.addEventListener("DOMContentLoaded", function () {
      new MutationObserver(verifyAttribution)
        .observe(document.body, { childList: true, subtree: true, characterData: true });
    });
  }
})();
