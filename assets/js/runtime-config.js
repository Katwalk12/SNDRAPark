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
})();
