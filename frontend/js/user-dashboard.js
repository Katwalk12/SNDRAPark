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

const dashboardAuthApi = getBackendUrl("/backend/api/v1");
const USER_API_BASE = getBackendUrl("/backend/user");
const USER_PROFILE_API = `${dashboardAuthApi}/users`;
const USER_PROFILE_KEY = "userProfile";
const PARKING_RESERVATIONS_KEY = "parkingReservations";
const FEEDBACK_MESSAGES_KEY = "feedbackMessages";
const PARKING_BACKEND_ORIGIN = typeof window.getSndraProjectBasePath === "function"
  ? `${window.location.origin}${window.getSndraProjectBasePath()}`
  : `${window.location.origin}${getProjectRoot()}`;
const SYSTEM_SETTINGS_API = `${PARKING_BACKEND_ORIGIN}/backend/config/get-system-settings.php`;
const USER_FLOORS_API = `${USER_API_BASE}/get_floors.php`;
const USER_SLOTS_API = `${USER_API_BASE}/get_slots_by_floor.php`;
const USER_RESERVATION_API = `${USER_API_BASE}/submit_reservation.php`;
const USER_RESERVATIONS_API = `${USER_API_BASE}/get_reservations.php`;
const USER_CANCEL_RESERVATION_API = `${USER_API_BASE}/cancel_reservation.php`;
const FEEDBACK_SUBMIT_API = `${PARKING_BACKEND_ORIGIN}/backend/feedback/submit.php`;
const LOGIN_ROUTE = getRoutePath("login", "./login.html");
const RESERVATION_LOG_STATUS_TIMEOUT = 2800;
const FLOOR_REFRESH_INTERVAL = 5 * 60 * 1000;
const SLOT_REFRESH_INTERVAL = 3000;
const PROFILE_REFRESH_INTERVAL = 30000;
const RESERVATION_REFRESH_INTERVAL = 5000;
const DEFAULT_SYSTEM_SETTINGS = {
  system_name: "SNDRA Park",
  contact_number: "+63 917 555 0142",
  gmail_address: "sndraparkemulator@gmail.com",
  parking_base_rate: 20,
  extra_hourly_rate: 10
};

const floorData = {};
let floorList = [];
let dashboardSystemSettings = getSystemSettingsSnapshot();

const statusLabelMap = {
  available: "Available",
  reserved: "Reserved",
  occupied: "Occupied",
  inactive: "Inactive"
};

let selectedFloor = "";
let selectedFloorId = null;
let latestReservation = null;
let currentUser = null;
let currentProfile = null;
let currentSlotPage = 0;
let monitorClockTimer = null;
let reservationLogStatusTimer = null;
let floorRefreshTimer = null;
let slotRefreshTimer = null;
let profileRefreshTimer = null;
let reservationRefreshTimer = null;
let sidebarNavigationBound = false;
let logoutBound = false;
let pendingReservationCancellation = null;

const PARKING_SLOTS_PER_PAGE = 10;
const PARKING_ROW_SIZE = 5;

const floorGrid = document.getElementById("floor-grid");
const slotsGrid = document.getElementById("slots-grid");
const selectedFloorLabel = document.getElementById("selected-floor-label");
const monitorFloorFeedLabel = document.getElementById("monitor-floor-feed-label");
const monitorScreenMeta = document.getElementById("monitor-screen-meta");
const availableCountDisplay = document.getElementById("monitor-available-count");
const reservedCountDisplay = document.getElementById("monitor-reserved-count");
const occupiedCountDisplay = document.getElementById("monitor-occupied-count");
const monitorChipDisplay = document.getElementById("monitor-chip-status");
const monitorDigitalClock = document.getElementById("monitor-digital-clock");
const monitorDigitalDate = document.getElementById("monitor-digital-date");
const monitorPrevPageButton = document.getElementById("monitor-prev-page");
const monitorNextPageButton = document.getElementById("monitor-next-page");
const monitorPageIndicator = document.getElementById("monitor-page-indicator");
const reservationModal = document.getElementById("reservation-modal");
const summaryModal = document.getElementById("summary-modal");
const reservationForm = document.getElementById("reservation-form");
const reservationFormStatus = document.getElementById("reservation-form-status");
const totalPaymentInput = document.getElementById("reservation-total");
const barcodeCanvas = document.getElementById("barcode-canvas");
const logoutButton = document.getElementById("logout-btn");
const userNameDisplay = document.getElementById("dashboard-user-name");
const userMetaDisplay = document.getElementById("dashboard-user-meta");
const reservationRecordsGrid = document.getElementById("reservation-records");
const reservedTotalCount = document.getElementById("reserved-total-count");
const heroReservationCount = document.getElementById("hero-reservation-count");
const heroSelectedFloor = document.getElementById("hero-selected-floor");
const heroAvailableCount = document.getElementById("hero-available-count");
const accountWarningBanner = document.getElementById("account-warning-banner");
const accountWarningKicker = document.getElementById("account-warning-kicker");
const accountWarningTitle = document.getElementById("account-warning-title");
const accountWarningMessage = document.getElementById("account-warning-message");
const clearReservationLogButton = document.getElementById("clear-reservation-log-btn");
const reservationLogStatus = document.getElementById("reservation-log-status");
const clearLogModal = document.getElementById("clear-log-modal");
const confirmClearLogButton = document.getElementById("confirm-clear-log-btn");
const cancelReservationModal = document.getElementById("cancel-reservation-modal");
const cancelReservationMessage = document.getElementById("cancel-reservation-message");
const cancelReservationHint = document.getElementById("cancel-reservation-hint");
const confirmCancelReservationButton = document.getElementById("confirm-cancel-reservation-btn");
const sidebarLinks = Array.from(document.querySelectorAll(".sidebar-link"));
const dashboardPanels = Array.from(document.querySelectorAll(".dashboard-panel"));
const dashboardPage = document.querySelector(".dashboard-page");
const pageUtilityBar = document.querySelector(".page-utility-bar");

const profileForm = document.getElementById("profile-form");
const profileStatus = document.getElementById("profile-form-status");
const profilePreviewName = document.getElementById("profile-preview-name");
const profilePreviewRole = document.getElementById("profile-preview-role");
const profilePreviewEmail = document.getElementById("profile-preview-email");
const profilePreviewBirthday = document.getElementById("profile-preview-birthday");
const profilePreviewVehicle = document.getElementById("profile-preview-vehicle");
const profilePreviewPlate = document.getElementById("profile-preview-plate");
const feedbackForm = document.getElementById("feedback-form");
const feedbackFormStatus = document.getElementById("feedback-form-status");
const feedbackEmailInput = document.getElementById("feedback-email");
const feedbackMessageInput = document.getElementById("feedback-message");

const profileFieldRefs = {
  fullName: document.getElementById("profile-full-name"),
  email: document.getElementById("profile-email"),
  birthday: document.getElementById("profile-birthday"),
  phone: document.getElementById("profile-phone"),
  vehicleType: document.getElementById("profile-vehicle"),
  plateNumber: document.getElementById("profile-plate"),
  address: document.getElementById("profile-address"),
  password: document.getElementById("profile-password")
};

const fieldRefs = {
  floor: document.getElementById("reservation-floor"),
  slot: document.getElementById("reservation-slot"),
  fullName: document.getElementById("reservation-name"),
  email: document.getElementById("reservation-email"),
  reservationDate: document.getElementById("reservation-date"),
  timeIn: document.getElementById("reservation-time-in"),
  timeOut: document.getElementById("reservation-time-out"),
  totalPayment: document.getElementById("reservation-total")
};

function updateDashboardFloatingFieldState(field) {
  const fieldGroup = field?.closest(".floating-field");

  if (!fieldGroup) {
    return;
  }

  const hasValue = field.value.trim() !== "";
  fieldGroup.classList.toggle("has-value", hasValue);
}

function syncDashboardFloatingFieldStates(scope = document) {
  scope.querySelectorAll(".floating-field input, .floating-field textarea").forEach((field) => {
    updateDashboardFloatingFieldState(field);
  });
}

function initializeDashboardFloatingFields() {
  document.querySelectorAll(".floating-field input, .floating-field textarea").forEach((field) => {
    updateDashboardFloatingFieldState(field);

    field.addEventListener("input", () => {
      updateDashboardFloatingFieldState(field);
    });

    field.addEventListener("focus", () => {
      field.closest(".floating-field")?.classList.add("is-focused");
    });

    field.addEventListener("blur", () => {
      field.closest(".floating-field")?.classList.remove("is-focused");
      updateDashboardFloatingFieldState(field);
    });
  });
}

document.addEventListener("DOMContentLoaded", () => {
  bindSidebarNavigation();
  bindLogout();

  initializeUserDashboard().catch((error) => {
    console.error("Dashboard initialization failed:", error);

    try {
      renderEmptySlotsState(error?.message || "Unable to load parking floors right now.");
    } catch (renderError) {
      console.error("Dashboard fallback render failed:", renderError);
    }
  });
});

async function initializeUserDashboard() {
  await ensureSystemSettingsLoaded();
  const session = await ensureAuthenticatedSession();

  if (!session) {
    return;
  }

  currentUser = session.user || {};
  clearLegacySharedUserStorage();
  currentProfile = await loadUserProfile(buildDefaultProfile(currentUser));
  hydrateProfileUI();
  initializeDateField();
  initializeMonitorClock();
  renderEmptySlotsState();

  // Keep the parking monitor as an early critical step so floors and slots
  // still render even if a non-critical dashboard panel fails later.
  await initializeParkingMonitor();

  await refreshUserReservationsState().catch(() => {
    // Keep the dashboard usable if reservation sync is temporarily unavailable.
  });

  renderReservationRecords();
  bindModalControls();
  bindReservationFormEvents();
  bindMonitorNavigation();
  bindProfileFormEvents();
  bindFeedbackFormEvents();
  initializeDashboardFloatingFields();
  updateDashboardHighlights();
  hydrateFeedbackForm();
  startProfileRefresh();
  startReservationRefresh();
}

window.addEventListener("sndra:system-settings-updated", (event) => {
  dashboardSystemSettings = normalizeSystemSettings(event.detail?.settings || {});
  updatePaymentDisplay();
});

async function ensureAuthenticatedSession() {
  try {
    const response = await fetch(`${dashboardAuthApi}/session`, {
      method: "GET",
      headers: {
        "Content-Type": "application/json"
      },
      credentials: "same-origin"
    });
    const result = await parseJsonResponse(response, "session");

    if (!result || !response.ok || result.success === false) {
      throw new Error(result?.message || "No active session found.");
    }

    const normalizedSession = result?.data && typeof result.data === "object"
      ? result.data
      : {
        user: result?.user || {},
        session: result?.session || {},
        role: result?.role || "user",
        redirect: result?.redirect || "user-dashboard.html"
      };

    if (!normalizedSession?.user || !normalizedSession?.session) {
      throw new Error("Invalid session payload.");
    }

    return normalizedSession;
  } catch (error) {
    window.location.replace(LOGIN_ROUTE);
    return null;
  }
}

function buildDefaultProfile(user) {
  return {
    fullName: buildDisplayName(user),
    email: user?.email || "",
    birthday: "",
    phone: "",
    vehicleType: "",
    plateNumber: "",
    address: "",
    password: "",
    role: "Member",
    warningCount: 0,
    firstWarningAt: "",
    accountStatus: "active",
    accountLockedUntil: ""
  };
}

function buildDisplayName(user) {
  const firstName = typeof user?.first_name === "string" ? user.first_name.trim() : "";
  const lastName = typeof user?.last_name === "string" ? user.last_name.trim() : "";
  const fullName = typeof user?.full_name === "string" ? user.full_name.trim() : "";

  if (firstName || lastName) {
    return `${firstName} ${lastName}`.trim();
  }

  if (fullName) {
    return fullName;
  }

  return user?.email || `${getConfiguredSystemName()} User`;
}

function normalizeSystemSettings(settings) {
  const source = settings && typeof settings === "object" ? settings : {};

  return {
    system_name: String(source.system_name || DEFAULT_SYSTEM_SETTINGS.system_name).trim() || DEFAULT_SYSTEM_SETTINGS.system_name,
    contact_number: String(source.contact_number || DEFAULT_SYSTEM_SETTINGS.contact_number).trim() || DEFAULT_SYSTEM_SETTINGS.contact_number,
    gmail_address: String(source.gmail_address || DEFAULT_SYSTEM_SETTINGS.gmail_address).trim() || DEFAULT_SYSTEM_SETTINGS.gmail_address,
    parking_base_rate: Number.isFinite(Number(source.parking_base_rate))
      ? Number(source.parking_base_rate)
      : DEFAULT_SYSTEM_SETTINGS.parking_base_rate,
    extra_hourly_rate: Number.isFinite(Number(source.extra_hourly_rate))
      ? Number(source.extra_hourly_rate)
      : DEFAULT_SYSTEM_SETTINGS.extra_hourly_rate
  };
}

function getSystemSettingsSnapshot() {
  if (typeof window.getSndraSystemSettings === "function") {
    return normalizeSystemSettings(window.getSndraSystemSettings());
  }

  return normalizeSystemSettings(DEFAULT_SYSTEM_SETTINGS);
}

async function ensureSystemSettingsLoaded() {
  if (typeof window.ensureSndraSystemSettingsLoaded === "function") {
    dashboardSystemSettings = normalizeSystemSettings(await window.ensureSndraSystemSettingsLoaded());
  } else {
    dashboardSystemSettings = await fetchSystemSettingsFromBackend();
  }

  updatePaymentDisplay();
  return dashboardSystemSettings;
}

async function fetchSystemSettingsFromBackend() {
  try {
    const response = await fetch(SYSTEM_SETTINGS_API, {
      method: "GET",
      headers: {
        Accept: "application/json"
      },
      credentials: "same-origin",
      cache: "no-store"
    });
    const result = await parseJsonResponse(response);

    if (!response.ok || result?.success === false) {
      throw new Error(result?.message || "Failed to load system settings.");
    }

    const settings = normalizeSystemSettings(result?.data?.settings || {});
    window.dispatchEvent(new CustomEvent("sndra:system-settings-updated", {
      detail: { settings }
    }));
    return settings;
  } catch (error) {
    return getSystemSettingsSnapshot();
  }
}

function getReservationBaseRate() {
  return Number(dashboardSystemSettings?.parking_base_rate || DEFAULT_SYSTEM_SETTINGS.parking_base_rate);
}

function getConfiguredSystemName() {
  return dashboardSystemSettings?.system_name || DEFAULT_SYSTEM_SETTINGS.system_name;
}

function getScopedStorageKey(baseKey) {
  const identitySource = currentUser?.id
    ? `user-${currentUser.id}`
    : currentUser?.email
      ? String(currentUser.email).toLowerCase()
      : "guest";

  const normalizedIdentity = identitySource.replace(/[^a-z0-9@._-]+/gi, "-");
  return `${baseKey}:${normalizedIdentity}`;
}

function readScopedStorage(baseKey, fallbackValue) {
  const scopedKey = getScopedStorageKey(baseKey);
  const scopedValue = localStorage.getItem(scopedKey);

  if (scopedValue !== null) {
    return scopedValue;
  }

  return fallbackValue;
}

function writeScopedStorage(baseKey, value) {
  localStorage.removeItem(baseKey);
  localStorage.setItem(getScopedStorageKey(baseKey), value);
}

function removeScopedStorage(baseKey) {
  localStorage.removeItem(baseKey);
  localStorage.removeItem(getScopedStorageKey(baseKey));
}

function clearLegacySharedUserStorage() {
  [USER_PROFILE_KEY, PARKING_RESERVATIONS_KEY, FEEDBACK_MESSAGES_KEY].forEach((storageKey) => {
    localStorage.removeItem(storageKey);
  });
}

async function loadUserProfile(defaultProfile) {
  const storedProfile = loadStoredUserProfile();

  try {
    const backendProfile = await fetchUserProfileFromBackend();
    const nextProfile = {
      ...defaultProfile,
      ...storedProfile,
      ...backendProfile
    };

    currentProfile = sanitizeStoredProfile(nextProfile);
    saveUserProfile();
    return currentProfile;
  } catch (error) {
    return {
      ...defaultProfile,
      ...storedProfile
    };
  }
}

function loadStoredUserProfile() {
  try {
    const stored = JSON.parse(readScopedStorage(USER_PROFILE_KEY, "null"));
    return sanitizeStoredProfile(stored);
  } catch (error) {
    return {};
  }
}

function sanitizeStoredProfile(profile) {
  if (!profile || typeof profile !== "object" || Array.isArray(profile)) {
    return {};
  }

  const nextProfile = { ...profile };
  delete nextProfile.profileImage;
  return nextProfile;
}

function saveUserProfile() {
  const sanitizedProfile = sanitizeStoredProfile(currentProfile);
  currentProfile = sanitizedProfile;
  writeScopedStorage(USER_PROFILE_KEY, JSON.stringify(sanitizedProfile));
}

async function fetchUserProfileFromBackend() {
  const response = await fetch(USER_PROFILE_API, {
    method: "GET",
    headers: {
      Accept: "application/json"
    },
    credentials: "same-origin"
  });
  const result = await parseJsonResponse(response);

  if (!response.ok || result?.success === false) {
    throw new Error(result?.message || "Failed to load user profile.");
  }

  return normalizeBackendProfile(result?.data || {});
}

async function saveUserProfileToBackend(profile) {
  const payload = {
    full_name: profile.fullName,
    email: profile.email,
    birth_date: profile.birthday || "",
    password: profile.password || ""
  };

  const response = await fetch(`${USER_PROFILE_API}/update`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json"
    },
    credentials: "same-origin",
    body: JSON.stringify(payload)
  });
  const result = await parseJsonResponse(response);

  if (!response.ok || result?.success === false) {
    throw new Error(result?.message || "Failed to save user profile.");
  }

  return normalizeBackendProfile(result?.data?.user || {});
}

function normalizeBackendProfile(profile) {
  const accountStatus = String(profile?.account_status || "active").toLowerCase();

  return {
    fullName: profile?.full_name || buildDisplayName(profile) || "",
    email: profile?.email || "",
    birthday: profile?.birth_date || "",
    phone: profile?.phone || "",
    vehicleType: profile?.vehicle_type || "",
    plateNumber: profile?.plate_number || "",
    address: profile?.address || "",
    password: "",
    role: accountStatus === "locked"
      ? "Temporarily Locked Member"
      : profile?.status === "Disabled"
        ? "Disabled Member"
        : "Member",
    warningCount: Number(profile?.warning_count || 0),
    firstWarningAt: profile?.first_warning_at || "",
    accountStatus,
    accountLockedUntil: profile?.account_locked_until || ""
  };
}

function getStoredFeedbackMessages() {
  try {
    const messages = JSON.parse(readScopedStorage(FEEDBACK_MESSAGES_KEY, "[]"));
    return Array.isArray(messages) ? messages : [];
  } catch (error) {
    return [];
  }
}

function saveFeedbackMessages(messages) {
  writeScopedStorage(FEEDBACK_MESSAGES_KEY, JSON.stringify(messages));
}

function getStoredReservations() {
  try {
    const reservations = JSON.parse(readScopedStorage(PARKING_RESERVATIONS_KEY, "[]"));
    return Array.isArray(reservations)
      ? reservations.map((reservation, index) => normalizeStoredReservation(reservation, index))
      : [];
  } catch (error) {
    return [];
  }
}

function saveReservations(reservations) {
  writeScopedStorage(PARKING_RESERVATIONS_KEY, JSON.stringify(reservations));
}

function resetFloorDataToEmpty() {
  Object.keys(floorData).forEach((floorName) => {
    delete floorData[floorName];
  });
}

function getFloorRecordByName(floorName) {
  return floorList.find((floor) => floor.floor_name === floorName) || null;
}

function getFloorRecordById(floorId) {
  return floorList.find((floor) => Number(floor.id || 0) === Number(floorId || 0)) || null;
}

function hydrateProfileUI() {
  const displayName = currentProfile?.fullName?.trim() || buildDisplayName(currentUser);
  const email = currentProfile?.email?.trim() || currentUser?.email || `${getConfiguredSystemName()} Account`;

  if (userNameDisplay) {
    userNameDisplay.textContent = displayName;
  }

  if (userMetaDisplay) {
    userMetaDisplay.textContent = email;
  }

  if (profilePreviewName) {
    profilePreviewName.textContent = displayName;
  }

  if (profilePreviewRole) {
    profilePreviewRole.textContent = currentProfile?.role || "Member";
  }

  if (profilePreviewEmail) {
    profilePreviewEmail.textContent = email;
  }

  if (profilePreviewBirthday) {
    profilePreviewBirthday.textContent = currentProfile?.birthday ? formatDate(currentProfile.birthday) : "Not set";
  }

  if (profilePreviewVehicle) {
    profilePreviewVehicle.textContent = currentProfile?.vehicleType?.trim() || "Not set";
  }

  if (profilePreviewPlate) {
    profilePreviewPlate.textContent = currentProfile?.plateNumber?.trim() || "Not set";
  }

  updateAccountWarningBanner();

  Object.entries(profileFieldRefs).forEach(([key, field]) => {
    if (field) {
      field.value = currentProfile?.[key] || "";
    }
  });

  syncDashboardFloatingFieldStates(profileForm || document);
}

function updateAccountWarningBanner() {
  if (!accountWarningBanner || !accountWarningKicker || !accountWarningTitle || !accountWarningMessage) {
    return;
  }

  const warningCount = Number(currentProfile?.warningCount || 0);
  const accountStatus = String(currentProfile?.accountStatus || "active").toLowerCase();
  const lockedUntil = currentProfile?.accountLockedUntil || "";

  if (accountStatus === "locked") {
    accountWarningBanner.hidden = false;
    accountWarningBanner.className = "account-warning-banner is-danger";
    accountWarningKicker.textContent = "Account Lock";
    accountWarningTitle.textContent = "Your account is temporarily locked";
    accountWarningMessage.textContent = lockedUntil
      ? `You cannot create a new reservation until ${formatDateTime(lockedUntil)} because of repeated expired reservation barcodes.`
      : "You cannot create a new reservation right now because of repeated expired reservation barcodes.";
    return;
  }

  if (warningCount >= 1) {
    accountWarningBanner.hidden = false;
    accountWarningBanner.className = "account-warning-banner is-warning";
    accountWarningKicker.textContent = "Reservation Warning";
    accountWarningTitle.textContent = "Another expired reservation will trigger a lock";
    accountWarningMessage.textContent = "Warning: Another expired reservation barcode within 24 hours will lock your account for 6 days.";
    return;
  }

  accountWarningBanner.hidden = true;
  accountWarningBanner.className = "account-warning-banner";
}

function bindSidebarNavigation() {
  if (sidebarNavigationBound) {
    syncSidebarSectionState(getSidebarTargetFromLocation() || "dashboard-control");
    return;
  }

  sidebarNavigationBound = true;

  sidebarLinks.forEach((link) => {
    link.addEventListener("click", (event) => {
      event.preventDefault();
      const targetId = link.dataset.target;

      if (!targetId || !getDashboardPanelById(targetId)) {
        return;
      }

      syncSidebarSectionState(targetId);

      if (window.location.hash !== `#${targetId}`) {
        history.replaceState(null, "", `#${targetId}`);
      }
    });
  });

  window.addEventListener("hashchange", () => {
    syncSidebarSectionState(getSidebarTargetFromLocation() || "dashboard-control");
  });

  syncSidebarSectionState(getSidebarTargetFromLocation() || "dashboard-control");
}

function getDashboardPanelById(targetId) {
  return dashboardPanels.find((panel) => panel.id === targetId) || null;
}

function getSidebarTargetFromLocation() {
  const hashTarget = window.location.hash.replace(/^#/, "").trim();
  return getDashboardPanelById(hashTarget) ? hashTarget : "";
}

function syncSidebarSectionState(targetId) {
  const resolvedTargetId = getDashboardPanelById(targetId) ? targetId : "dashboard-control";
  const activeLink = sidebarLinks.find((link) => link.dataset.target === resolvedTargetId) || sidebarLinks[0] || null;

  if (activeLink) {
    setActiveSidebarLink(activeLink);
  }

  showDashboardSection(resolvedTargetId);
}

function setActiveSidebarLink(activeLink) {
  sidebarLinks.forEach((link) => {
    const isActive = link === activeLink;
    link.classList.toggle("active", isActive);
    link.classList.toggle("is-active", isActive);
    if (isActive) {
      link.setAttribute("aria-current", "page");
    } else {
      link.removeAttribute("aria-current");
    }
  });
}

function showDashboardSection(targetId) {
  dashboardPanels.forEach((panel) => {
    const isActive = panel.id === targetId;
    panel.classList.toggle("active", isActive);
    panel.hidden = !isActive;
    panel.setAttribute("aria-hidden", isActive ? "false" : "true");
  });

  if (pageUtilityBar) {
    const showUtilityBar = targetId === "dashboard-control";
    pageUtilityBar.hidden = !showUtilityBar;
    pageUtilityBar.setAttribute("aria-hidden", showUtilityBar ? "false" : "true");
  }

  if (dashboardPage) {
    dashboardPage.scrollTo({
      top: 0,
      behavior: "smooth"
    });
  }

  window.scrollTo({
    top: 0,
    behavior: "smooth"
  });
}

function bindLogout() {
  if (logoutBound) {
    return;
  }

  logoutBound = true;

  logoutButton?.addEventListener("click", async () => {
    if (profileRefreshTimer) {
      window.clearInterval(profileRefreshTimer);
      profileRefreshTimer = null;
    }

    if (reservationRefreshTimer) {
      window.clearInterval(reservationRefreshTimer);
      reservationRefreshTimer = null;
    }

    try {
      await fetch(`${dashboardAuthApi}/logout`, {
        method: "GET",
        headers: {
          "Content-Type": "application/json"
        },
        credentials: "same-origin"
      });
    } finally {
      window.location.replace(LOGIN_ROUTE);
    }
  });
}

function startProfileRefresh() {
  if (profileRefreshTimer) {
    window.clearInterval(profileRefreshTimer);
  }

  profileRefreshTimer = window.setInterval(() => {
    refreshUserProfileState().catch((error) => {
      console.error("Fetch error:", error);
    });
  }, PROFILE_REFRESH_INTERVAL);
}

function startReservationRefresh() {
  if (reservationRefreshTimer) {
    window.clearInterval(reservationRefreshTimer);
  }

  reservationRefreshTimer = window.setInterval(() => {
    refreshUserReservationsState().catch((error) => {
      console.error("Reservation sync error:", error);
    });
  }, RESERVATION_REFRESH_INTERVAL);
}

async function refreshUserProfileState() {
  dashboardSystemSettings = await fetchSystemSettingsFromBackend();
  const backendProfile = await fetchUserProfileFromBackend();
  currentProfile = sanitizeStoredProfile({
    ...currentProfile,
    ...backendProfile,
    phone: currentProfile?.phone || "",
    vehicleType: currentProfile?.vehicleType || "",
    plateNumber: currentProfile?.plateNumber || "",
    address: currentProfile?.address || "",
    password: ""
  });
  saveUserProfile();
  hydrateProfileUI();
}

async function fetchUserReservationsFromBackend() {
  const response = await fetch(USER_RESERVATIONS_API, {
    method: "GET",
    headers: {
      Accept: "application/json"
    },
    credentials: "same-origin",
    cache: "no-store"
  });
  const result = await parseJsonResponse(response);

  if (!response.ok || result?.success === false) {
    throw new Error(result?.message || "Failed to load reservation history.");
  }

  return Array.isArray(result?.data?.reservations) ? result.data.reservations : [];
}

async function refreshUserReservationsState() {
  const storedReservations = getStoredReservations();
  const reservationByKey = new Map();

  storedReservations.forEach((reservation) => {
    const key = String(reservation.reservationId || reservation.barcode || "");
    if (key) {
      reservationByKey.set(key, reservation);
    }
  });

  const backendReservations = await fetchUserReservationsFromBackend();
  const syncedReservations = backendReservations.map((record, index) => {
    const key = String(record.reservationId || record.barcode || index);
    const fallbackReservation = reservationByKey.get(key) || {};
    return mapBackendReservation(record, fallbackReservation);
  });

  saveReservations(syncedReservations);

  if (latestReservation?.reservationId || latestReservation?.barcode) {
    const latestKey = String(latestReservation.reservationId || latestReservation.barcode || "");
    const nextLatest = syncedReservations.find((reservation) => {
      return String(reservation.reservationId || reservation.barcode || "") === latestKey;
    });

    if (nextLatest) {
      latestReservation = nextLatest;
    }
  }

  renderReservationRecords();
  updateDashboardHighlights();
}

function initializeDateField() {
  const today = new Date().toISOString().split("T")[0];
  fieldRefs.reservationDate.min = today;

  if (!fieldRefs.reservationDate.value || isPastReservationDate(fieldRefs.reservationDate.value)) {
    fieldRefs.reservationDate.value = today;
  }
}

async function initializeParkingMonitor() {
  try {
    await refreshFloors({
      preserveSelection: false,
      resetPage: true
    });
    startParkingPolling();
  } catch (error) {
    console.error("Fetch error:", error);
    renderEmptySlotsState(error.message || "Unable to load parking floors right now.");
  }
}

function startParkingPolling() {
  if (floorRefreshTimer) {
    window.clearInterval(floorRefreshTimer);
  }

  if (slotRefreshTimer) {
    window.clearInterval(slotRefreshTimer);
  }

  floorRefreshTimer = window.setInterval(() => {
    refreshFloors({
      preserveSelection: true,
      resetPage: false
    }).catch((error) => {
      console.error("Fetch error:", error);
    });
  }, FLOOR_REFRESH_INTERVAL);

  slotRefreshTimer = window.setInterval(() => {
    if (!selectedFloor) {
      return;
    }

    refreshFloorSlots(selectedFloor, {
      keepPage: true
    }).catch((error) => {
      console.error("Fetch error:", error);
    });
  }, SLOT_REFRESH_INTERVAL);
}

async function refreshFloors({ preserveSelection = true, resetPage = false } = {}) {
  if (!floorGrid) {
    return;
  }

  console.log("Fetching realtime data...", {
    endpoint: USER_FLOORS_API
  });
  const response = await fetch(USER_FLOORS_API, {
    method: "GET",
    headers: {
      Accept: "application/json"
    },
    credentials: "same-origin",
    cache: "no-store"
  });
  const result = await parseJsonResponse(response);
  console.log("Floor response:", result);

  if (!response.ok || result?.success === false) {
    throw new Error(result?.message || "Unable to load parking floors.");
  }

  floorList = Array.isArray(result?.data?.floors) ? result.data.floors : [];
  const nextFloorNames = floorList.map((floor) => floor.floor_name).filter(Boolean);

  resetFloorDataToEmpty();
  nextFloorNames.forEach((floorName) => {
    floorData[floorName] = Array.isArray(floorData[floorName]) ? floorData[floorName] : [];
  });

  renderFloorButtons();

  if (!nextFloorNames.length) {
    selectedFloor = "";
    selectedFloorId = null;
    renderEmptySlotsState("No active floors available yet.");
    return;
  }

  const shouldKeepSelection = preserveSelection && floorList.some((floor) => Number(floor.id || 0) === Number(selectedFloorId || 0));
  const activeFloor = shouldKeepSelection
    ? getFloorRecordById(selectedFloorId)
    : floorList[0] || null;

  selectedFloor = activeFloor?.floor_name || nextFloorNames[0];
  selectedFloorId = Number(activeFloor?.id || 0) || null;

  if (resetPage || !shouldKeepSelection) {
    currentSlotPage = 0;
  }

  updateSelectedFloorButtonState();
  await refreshFloorSlots(selectedFloor, {
    keepPage: !resetPage && shouldKeepSelection,
    floorId: selectedFloorId
  });
}

async function refreshFloorSlots(floorName, { keepPage = true, floorId = null } = {}) {
  if (!slotsGrid) {
    return;
  }

  if (!floorName) {
    renderEmptySlotsState();
    return;
  }

  console.log("Fetching realtime data...", {
    endpoint: USER_SLOTS_API,
    floor: floorName
  });
  const resolvedFloorId = Number(floorId || getFloorRecordByName(floorName)?.id || 0) || null;
  const query = new URLSearchParams();

  if (resolvedFloorId) {
    query.set("floor_id", String(resolvedFloorId));
  } else {
    query.set("floor_name", floorName);
  }
  const response = await fetch(`${USER_SLOTS_API}?${query.toString()}`, {
    method: "GET",
    headers: {
      Accept: "application/json"
    },
    credentials: "same-origin",
    cache: "no-store"
  });
  const result = await parseJsonResponse(response);
  console.log("Slot response:", result);

  if (!response.ok || result?.success === false) {
    throw new Error(result?.message || "Unable to load parking slots.");
  }

  const slots = Array.isArray(result?.data?.slots)
    ? result.data.slots.map((slot) => ({
      id: slot.id,
      code: slot.slot_code,
      rowLabel: slot.row_label || "ROW",
      status: String(slot.status_key || slot.status || "available").toLowerCase(),
      disabled: Boolean(slot.disabled)
    }))
    : [];

  floorData[floorName] = slots;

  if (!keepPage) {
    currentSlotPage = 0;
  }

  if (selectedFloor === floorName || (resolvedFloorId !== null && Number(selectedFloorId || 0) === resolvedFloorId)) {
    renderSlots(floorName);
    syncReservationFormAvailability();
  }
}

function renderFloorButtons() {
  if (!floorGrid) {
    return;
  }

  floorGrid.innerHTML = "";

  if (!floorList.length) {
    floorGrid.innerHTML = `<div class="empty-state"><div><h3>No floors yet</h3><p>The admin has not published any active parking floor yet.</p></div></div>`;
    return;
  }

  floorList.forEach((floor) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "floor-card";
    button.dataset.floorName = floor.floor_name;
    button.dataset.floorId = String(floor.id || "");
    button.innerHTML = `
      <span class="floor-card-icon" aria-hidden="true"><i class="bi bi-building-fill"></i></span>
      <strong>${floor.floor_label || floor.floor_name}</strong>
      <span>${Number(floor.available_count || 0)} open</span>
      <small>${Number(floor.reserved_count || 0)} reserved</small>
    `;
    button.addEventListener("click", async () => {
      try {
        if (Number(selectedFloorId || 0) === Number(floor.id || 0)) {
          await refreshFloorSlots(floor.floor_name, { keepPage: true, floorId: floor.id });
          return;
        }

        selectedFloor = floor.floor_name;
        selectedFloorId = Number(floor.id || 0) || null;
        currentSlotPage = 0;
        updateSelectedFloorButtonState();
        await refreshFloorSlots(floor.floor_name, { keepPage: false, floorId: floor.id });
      } catch (error) {
        console.error("Fetch error:", error);
        renderEmptySlotsState(error.message || "Unable to load this floor right now.");
      }
    });
    floorGrid.appendChild(button);
  });

  updateSelectedFloorButtonState();
}

function updateSelectedFloorButtonState() {
  floorGrid.querySelectorAll(".floor-card").forEach((card) => {
    const cardFloorId = Number(card.dataset.floorId || 0);
    const isSelected = cardFloorId > 0
      ? cardFloorId === Number(selectedFloorId || 0)
      : card.dataset.floorName === selectedFloor;
    card.classList.toggle("is-selected", isSelected);
  });
}

function renderEmptySlotsState(message = "Choose a parking floor to view available slots and start a reservation.") {
  if (selectedFloorLabel) {
    selectedFloorLabel.textContent = "No Floor Selected";
  }

  updateMonitorStats([]);
  updateMonitorStatusChip("");
  updateMonitorMeta("");
  updateMonitorPagination(0, 0);

  if (!slotsGrid) {
    return;
  }

  slotsGrid.innerHTML = `
    <div class="empty-state parking-empty-state">
      <div>
        <span class="empty-state-icon"><i class="bi bi-cone-striped" aria-hidden="true"></i></span>
        <h3>No floor selected yet</h3>
        <p>${message}</p>
      </div>
    </div>
  `;
}

function renderSlots(floorName) {
  if (!slotsGrid) {
    return;
  }

  const slots = floorData[floorName] || [];
  const selectedFloorMeta = getFloorRecordById(selectedFloorId) || getFloorRecordByName(floorName);

  if (selectedFloorLabel) {
    selectedFloorLabel.textContent = selectedFloorMeta?.floor_label || floorName;
  }

  updateMonitorStats(slots);
  updateMonitorStatusChip(floorName);
  updateMonitorMeta(floorName);
  const totalPages = Math.max(1, Math.ceil(slots.length / PARKING_SLOTS_PER_PAGE));
  currentSlotPage = Math.min(currentSlotPage, totalPages - 1);
  updateMonitorPagination(currentSlotPage, totalPages);
  slotsGrid.innerHTML = "";

  if (!slots.length) {
    slotsGrid.innerHTML = `
      <div class="empty-state">
        <div>
          <h3>No slots found on this floor</h3>
          <p>This floor exists, but no active parking slots have been added yet.</p>
        </div>
      </div>
    `;
    updateDashboardHighlights();
    return;
  }

  const startIndex = currentSlotPage * PARKING_SLOTS_PER_PAGE;
  const visibleSlots = slots.slice(startIndex, startIndex + PARKING_SLOTS_PER_PAGE);
  const leftRowSlots = visibleSlots.slice(0, PARKING_ROW_SIZE);
  const rightRowSlots = visibleSlots.slice(PARKING_ROW_SIZE, PARKING_SLOTS_PER_PAGE);
  const firstRowLabel = leftRowSlots.length ? `Row ${leftRowSlots[0].rowLabel}` : "Row A";
  const secondRowLabel = rightRowSlots.length ? `Row ${rightRowSlots[0].rowLabel}` : "Row B";

  renderParkingRow(firstRowLabel, leftRowSlots, floorName, 0);
  renderParkingRow(secondRowLabel, rightRowSlots, floorName, 1);
  updateDashboardHighlights();
}

function updateMonitorStats(slots) {
  const counts = slots.reduce((summary, slot) => {
    summary[slot.status] = (summary[slot.status] || 0) + 1;
    return summary;
  }, {
    available: 0,
    reserved: 0,
    occupied: 0,
    inactive: 0
  });

  if (availableCountDisplay) {
    availableCountDisplay.textContent = String(counts.available);
  }

  if (reservedCountDisplay) {
    reservedCountDisplay.textContent = String(counts.reserved);
  }

  if (occupiedCountDisplay) {
    occupiedCountDisplay.textContent = String(counts.occupied);
  }

  if (heroAvailableCount) {
    heroAvailableCount.textContent = String(counts.available);
  }
}

function updateMonitorStatusChip(floorName) {
  if (!monitorChipDisplay) {
    return;
  }

  monitorChipDisplay.textContent = floorName ? `${floorName} Camera Feed` : "Idle Feed";

  if (monitorFloorFeedLabel) {
    monitorFloorFeedLabel.textContent = floorName
      ? "Live Parking Feed Active"
      : "Floor feed offline";
  }
}

function updateMonitorMeta(floorName) {
  if (!monitorScreenMeta) {
    return;
  }

  monitorScreenMeta.textContent = floorName
    ? "Lane view active. Select an available parking slot to reserve."
    : "Choose a floor to view current slot availability.";
}

function initializeMonitorClock() {
  updateMonitorClock();

  if (monitorClockTimer) {
    window.clearInterval(monitorClockTimer);
  }

  monitorClockTimer = window.setInterval(updateMonitorClock, 1000);
}

function updateMonitorClock() {
  if (!monitorDigitalClock) {
    return;
  }

  const now = new Date();

  monitorDigitalClock.textContent = new Intl.DateTimeFormat("en-PH", {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false
  }).format(now);

  if (monitorDigitalDate) {
    monitorDigitalDate.textContent = new Intl.DateTimeFormat("en-PH", {
      weekday: "short",
      month: "short",
      day: "2-digit",
      year: "numeric"
    }).format(now).toUpperCase();
  }
}

function updateMonitorPagination(pageIndex, totalPages) {
  if (monitorPageIndicator) {
    monitorPageIndicator.textContent = totalPages > 0
      ? `Page ${pageIndex + 1} of ${totalPages}`
      : "Page 0 of 0";
  }

  if (monitorPrevPageButton) {
    monitorPrevPageButton.disabled = totalPages <= 1 || pageIndex <= 0;
  }

  if (monitorNextPageButton) {
    monitorNextPageButton.disabled = totalPages <= 1 || pageIndex >= totalPages - 1;
  }
}

function bindMonitorNavigation() {
  monitorPrevPageButton?.addEventListener("click", () => {
    if (!selectedFloor || currentSlotPage <= 0) {
      return;
    }

    currentSlotPage -= 1;
    renderSlots(selectedFloor);
  });

  monitorNextPageButton?.addEventListener("click", () => {
    if (!selectedFloor) {
      return;
    }

    const slots = floorData[selectedFloor] || [];
    const totalPages = Math.ceil(slots.length / PARKING_SLOTS_PER_PAGE);

    if (currentSlotPage >= totalPages - 1) {
      return;
    }

    currentSlotPage += 1;
    renderSlots(selectedFloor);
  });
}

function renderParkingRow(rowName, rowSlots, floorName, rowIndex = 0) {
  const row = document.createElement("section");
  row.className = "parking-row";

  const label = document.createElement("div");
  label.className = "parking-row-label";
  const cameraLabel = `CAM ${String(rowIndex + 1).padStart(2, "0")}`;
  label.innerHTML = `
    <span>${cameraLabel}</span>
    <strong>${rowName}</strong>
    <span>${rowSlots.length ? `${rowSlots[0].code} - ${rowSlots[rowSlots.length - 1].code}` : "No slots"}</span>
  `;

  const track = document.createElement("div");
  track.className = "parking-row-track";

  rowSlots.forEach((slot) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = `slot-card ${slot.status} ${slot.status === "available" ? "is-clickable" : ""}`;
    const slotMetaLabel = slot.status === "available"
      ? "Tap to reserve"
      : slot.status === "reserved"
        ? "Reserved Slot"
        : slot.status === "occupied"
          ? "Parking Unavailable"
          : "Floor Unavailable";
    button.setAttribute("aria-label", `${slot.code} ${statusLabelMap[slot.status] || "Parking slot"}`);
    button.innerHTML = `
      <span class="slot-bay-number">${slot.code}</span>
      <div class="slot-car-icon" aria-hidden="true">
        <i class="fa-solid fa-car-side"></i>
      </div>
      <div class="slot-copy">
        <div class="slot-code">${slot.code}</div>
        <div class="slot-meta">${slotMetaLabel}</div>
      </div>
      <span class="slot-status">
        <span class="slot-status-dot ${slot.status}"></span>
        ${statusLabelMap[slot.status]}
      </span>
    `;

    if (slot.status === "available") {
      button.addEventListener("click", () => openReservationModal(floorName, slot));
    } else {
      button.disabled = true;
    }

    track.appendChild(button);
  });

  row.appendChild(label);
  row.appendChild(track);
  slotsGrid.appendChild(row);
}

function bindModalControls() {
  document.querySelectorAll("[data-close-modal]").forEach((button) => {
    button.addEventListener("click", closeReservationModal);
  });

  document.querySelectorAll("[data-close-summary]").forEach((button) => {
    button.addEventListener("click", closeSummaryModal);
  });

  document.querySelectorAll("[data-close-clear-log]").forEach((button) => {
    button.addEventListener("click", closeClearLogModal);
  });

  document.querySelectorAll("[data-close-cancel-reservation]").forEach((button) => {
    button.addEventListener("click", closeCancelReservationModal);
  });

  reservationModal.addEventListener("click", (event) => {
    if (event.target === reservationModal) {
      closeReservationModal();
    }
  });

  summaryModal.addEventListener("click", (event) => {
    if (event.target === summaryModal) {
      closeSummaryModal();
    }
  });

  clearLogModal?.addEventListener("click", (event) => {
    if (event.target === clearLogModal) {
      closeClearLogModal();
    }
  });

  cancelReservationModal?.addEventListener("click", (event) => {
    if (event.target === cancelReservationModal) {
      closeCancelReservationModal();
    }
  });

  document.getElementById("download-pdf-btn")?.addEventListener("click", downloadReservationPdf);
  clearReservationLogButton?.addEventListener("click", openClearLogModal);
  confirmClearLogButton?.addEventListener("click", clearReservationLog);
  confirmCancelReservationButton?.addEventListener("click", confirmReservationCancellation);
}

function bindReservationFormEvents() {
  fieldRefs.timeIn.addEventListener("input", updatePaymentDisplay);
  fieldRefs.reservationDate.addEventListener("input", validateReservationDateField);

  reservationForm.addEventListener("submit", handleReservationSubmit);
}

function bindProfileFormEvents() {
  profileForm?.addEventListener("submit", handleProfileSubmit);
}

function bindFeedbackFormEvents() {
  feedbackForm?.addEventListener("submit", handleFeedbackSubmit);
}

function syncBodyModalState() {
  const hasOpenModal = [reservationModal, summaryModal, clearLogModal, cancelReservationModal].some((modal) => {
    return modal?.classList.contains("is-open");
  });

  document.body.classList.toggle("modal-open", hasOpenModal);
}

function hydrateFeedbackForm() {
  if (!feedbackEmailInput) {
    return;
  }

  if (!feedbackEmailInput.value) {
    feedbackEmailInput.value = currentProfile?.email || currentUser?.email || "";
  }

  syncDashboardFloatingFieldStates(feedbackForm || document);
}

function openReservationModal(floorName, slot) {
  clearReservationErrors();
  reservationForm.reset();
  reservationFormStatus.textContent = "";
  reservationFormStatus.className = "form-status";
  fieldRefs.floor.value = floorName;
  fieldRefs.slot.value = slot.code;
  fieldRefs.fullName.value = currentProfile?.fullName || buildDisplayName(currentUser);
  fieldRefs.email.value = currentProfile?.email || currentUser?.email || "";
  initializeDateField();
  fieldRefs.timeOut.value = "Will be recorded at the parking booth";
  updatePaymentDisplay();
  syncReservationFormAvailability();
  syncDashboardFloatingFieldStates(reservationForm || document);
  reservationModal.classList.add("is-open");
  reservationModal.setAttribute("aria-hidden", "false");
  syncBodyModalState();
}

function syncReservationFormAvailability() {
  if (!reservationModal?.classList.contains("is-open")) {
    return;
  }

  const floorName = fieldRefs.floor.value.trim();
  const slotCode = fieldRefs.slot.value.trim();
  const slotRecord = getSlotRecord(floorName, slotCode);

  if (!slotRecord || slotRecord.status !== "available") {
    setFieldError("slot", "This parking slot is no longer available.");
    reservationFormStatus.textContent = "This parking slot is no longer available.";
    reservationFormStatus.className = "form-status is-error";
    return;
  }

  clearFieldError("slot");

  if (reservationFormStatus.classList.contains("is-error")) {
    reservationFormStatus.textContent = "";
    reservationFormStatus.className = "form-status";
  }
}

function closeReservationModal() {
  reservationModal.classList.remove("is-open");
  reservationModal.setAttribute("aria-hidden", "true");
  syncDashboardFloatingFieldStates(reservationForm || document);
  syncBodyModalState();
}

function closeSummaryModal() {
  summaryModal.classList.remove("is-open");
  summaryModal.setAttribute("aria-hidden", "true");
  syncBodyModalState();
}

function openClearLogModal() {
  if (!getStoredReservations().length) {
    showReservationLogStatus("No saved reservation records to clear.", "is-error");
    return;
  }

  clearLogModal?.classList.add("is-open");
  clearLogModal?.setAttribute("aria-hidden", "false");
  syncBodyModalState();
}

function closeClearLogModal() {
  clearLogModal?.classList.remove("is-open");
  clearLogModal?.setAttribute("aria-hidden", "true");
  syncBodyModalState();
}

function openCancelReservationModal(reservation) {
  if (!reservation || !isReservationCancellable(reservation)) {
    showReservationLogStatus("This reservation can no longer be cancelled.", "is-error");
    return;
  }

  pendingReservationCancellation = reservation;

  if (cancelReservationMessage) {
    cancelReservationMessage.textContent = `Are you sure you want to cancel reservation ${reservation.reservationCode} for ${reservation.floor} ${reservation.slot}?`;
  }

  if (cancelReservationHint) {
    cancelReservationHint.textContent = "This will release the reserved parking slot immediately and mark this booking as cancelled.";
  }

  cancelReservationModal?.classList.add("is-open");
  cancelReservationModal?.setAttribute("aria-hidden", "false");
  syncBodyModalState();
}

function closeCancelReservationModal() {
  pendingReservationCancellation = null;
  cancelReservationModal?.classList.remove("is-open");
  cancelReservationModal?.setAttribute("aria-hidden", "true");
  syncBodyModalState();
}

function updatePaymentDisplay() {
  if (!totalPaymentInput) {
    return;
  }

  totalPaymentInput.value = formatCurrency(getReservationBaseRate());
}

function validateReservationDateField() {
  if (!fieldRefs.reservationDate.value) {
    return true;
  }

  if (isPastReservationDate(fieldRefs.reservationDate.value)) {
    setFieldError("reservationDate", "Reservation Date cannot be earlier than today.");
    return false;
  }

  clearFieldError("reservationDate");
  return true;
}

function isPastReservationDate(dateValue) {
  const selectedDate = new Date(`${dateValue}T00:00:00`);
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  return selectedDate.getTime() < today.getTime();
}

function formatCurrency(amount) {
  return `PHP ${Number(amount).toFixed(2)}`;
}

async function handleReservationSubmit(event) {
  event.preventDefault();
  clearReservationErrors();

  const values = {
    floor: fieldRefs.floor.value.trim(),
    slot: fieldRefs.slot.value.trim(),
    fullName: fieldRefs.fullName.value.trim(),
    email: fieldRefs.email.value.trim(),
    reservationDate: fieldRefs.reservationDate.value,
    timeIn: fieldRefs.timeIn.value
  };

  let isValid = true;

  Object.entries(values).forEach(([key, value]) => {
    if (!value) {
      setFieldError(key, "This field is required.");
      isValid = false;
    }
  });

  if (values.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) {
    setFieldError("email", "Enter a valid email address.");
    isValid = false;
  }

  if (values.reservationDate && !validateReservationDateField()) {
    isValid = false;
  }

  const slotRecord = getSlotRecord(values.floor, values.slot);

  if (slotRecord && slotRecord.status !== "available") {
    setFieldError("slot", "This parking slot is no longer available.");
    isValid = false;
  }

  if (!isValid) {
    reservationFormStatus.textContent = "Please fix the highlighted fields and try again.";
    reservationFormStatus.className = "form-status is-error";
    return;
  }

  latestReservation = {
    reservationId: `reservation-${Date.now()}`,
    createdAt: new Date().toISOString(),
    barcode: generateReservationCode(values.floor, values.slot),
    ...values,
    timeOut: null,
    totalPayment: getReservationBaseRate(),
    paymentStatus: "Reserved",
    boothStatus: "Reserved",
    status: "Reserved",
    userId: currentUser?.id || null
  };
  latestReservation.reservationCode = latestReservation.barcode;

  try {
    reservationFormStatus.textContent = "Saving your reservation...";
    reservationFormStatus.className = "form-status";
    latestReservation = await saveReservationToBackend(latestReservation);
  } catch (error) {
    reservationFormStatus.textContent = error.message || "Unable to save the reservation to MySQL.";
    reservationFormStatus.className = "form-status is-error";
    await refreshFloorSlots(values.floor, { keepPage: true }).catch(() => {
      // Preserve the current UI if the background refresh fails.
    });
    return;
  }

  updatePaymentDisplay();
  persistReservation(latestReservation);
  await refreshFloorSlots(values.floor, { keepPage: true }).catch(() => {
    setSlotStatus(values.floor, values.slot, "reserved");
    if (selectedFloor) {
      renderSlots(selectedFloor);
    }
  });

  renderReservationRecords();
  updateDashboardHighlights();
  closeReservationModal();
  openSummaryModal(latestReservation);
}

function persistReservation(reservation) {
  const nextReservation = normalizeStoredReservation(reservation);
  const reservations = getStoredReservations().filter((storedReservation) => {
    return String(storedReservation.reservationId || "") !== String(nextReservation.reservationId || "");
  });

  reservations.unshift(nextReservation);
  saveReservations(reservations);
}

async function saveReservationToBackend(reservation) {
  const response = await fetch(USER_RESERVATION_API, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json"
    },
    credentials: "same-origin",
    body: JSON.stringify({
      userId: reservation.userId,
      barcodeValue: reservation.barcode,
      parkingFloor: reservation.floor,
      parkingSlot: reservation.slot,
      fullName: reservation.fullName,
      email: reservation.email,
      reservationDate: reservation.reservationDate,
      reservedTimeIn: reservation.timeIn,
      reservedTimeOut: null,
      reservationFee: reservation.totalPayment
    })
  });

  const result = await parseJsonResponse(response);
  console.log("Reservation save response:", result);

  if (!response.ok || !result?.success || !result.data) {
    throw new Error(result?.message || "Backend reservation save failed.");
  }

  return mapBackendReservation(result.data, reservation);
}

function renderReservationRecords() {
  const reservations = getStoredReservations();

  if (reservedTotalCount) {
    reservedTotalCount.textContent = String(reservations.length);
  }

  if (heroReservationCount) {
    heroReservationCount.textContent = String(reservations.length);
  }

  if (clearReservationLogButton) {
    clearReservationLogButton.disabled = reservations.length === 0;
  }

  if (!reservationRecordsGrid) {
    return;
  }

  if (!reservations.length) {
    reservationRecordsGrid.innerHTML = `
      <div class="empty-state reservation-empty-state">
        <div>
          <span class="empty-state-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
          <h3>No saved reservation records yet.</h3>
          <p>Reserve an available slot from the live parking monitor to create your first stored booking card.</p>
        </div>
      </div>
    `;
    return;
  }

  reservationRecordsGrid.innerHTML = "";

  reservations.forEach((reservation) => {
    const card = document.createElement("article");
    card.className = "reservation-record-card";
    card.tabIndex = 0;
    card.setAttribute("role", "button");
    const reservationStatus = getReservationDisplayStatus(reservation);
    const canCancel = isReservationCancellable(reservation);
    const statusClassName = getReservationStatusClassName(reservationStatus);
    const floorLabel = getCompactFloorLabel(reservation.floor);
    card.innerHTML = `
      <div class="reservation-record-head">
        <span class="reservation-record-chip"><i class="bi bi-layers-fill" aria-hidden="true"></i>${floorLabel}</span>
        <span class="reservation-status-pill ${statusClassName}">${reservationStatus}</span>
      </div>
      <h3>${formatDate(reservation.reservationDate)}</h3>
      <p>${formatTime(reservation.timeIn)}</p>
      <div class="reservation-record-meta">
        <span><i class="bi bi-p-square-fill" aria-hidden="true"></i> ${reservation.slot}</span>
        <span><i class="bi bi-upc-scan" aria-hidden="true"></i> Details inside</span>
      </div>
      <div class="reservation-record-footer">
        <strong>${reservation.reservationCode}</strong>
        <div class="reservation-record-actions">
          <button class="action-btn action-btn-secondary reservation-record-view-btn" type="button"><i class="bi bi-eye-fill" aria-hidden="true"></i><span>View</span></button>
          ${canCancel ? '<button class="action-btn action-btn-danger reservation-record-cancel-btn" type="button"><i class="bi bi-x-circle-fill" aria-hidden="true"></i><span>Cancel</span></button>' : `<span class="reservation-record-state-copy">${reservationStatus}</span>`}
        </div>
      </div>
    `;

    card.addEventListener("click", () => {
      reopenReservationSummary(reservation);
    });

    card.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        reopenReservationSummary(reservation);
      }
    });

    card.querySelector(".reservation-record-view-btn")?.addEventListener("click", (event) => {
      event.stopPropagation();
      reopenReservationSummary(reservation);
    });

    card.querySelector(".reservation-record-cancel-btn")?.addEventListener("click", (event) => {
      event.stopPropagation();
      openCancelReservationModal(reservation);
    });

    reservationRecordsGrid.appendChild(card);
  });
}

function getCompactFloorLabel(floorValue) {
  const value = String(floorValue || "").trim();

  if (!value) {
    return "Floor";
  }

  const compactMatch = value.match(/\bL\d+\b/i);

  if (compactMatch) {
    return compactMatch[0].toUpperCase();
  }

  const numberMatch = value.match(/\d+/);

  if (numberMatch) {
    return `L${numberMatch[0]}`;
  }

  return value;
}

function getReservationDisplayStatus(reservation) {
  const reservationStatus = String(
    reservation?.status
    || reservation?.reservationStatus
    || reservation?.boothStatus
    || "Reserved"
  ).trim();
  const normalizedReservationStatus = reservationStatus.toLowerCase();
  const barcodeStatus = String(reservation?.barcodeStatus || "").trim().toLowerCase();

  if (normalizedReservationStatus === "cancelled" || barcodeStatus === "cancelled") {
    return "Cancelled";
  }

  if (barcodeStatus === "expired") {
    return "Expired";
  }

  if (reservation?.boothStatus) {
    return String(reservation.boothStatus).trim() || "Reserved";
  }

  if (reservation?.reservationStatus) {
    return String(reservation.reservationStatus).trim() || "Reserved";
  }

  return reservationStatus || "Reserved";
}

function getReservationStatusClassName(status) {
  const normalizedStatus = String(status || "").trim().toLowerCase();

  if (normalizedStatus === "cancelled") {
    return "is-cancelled";
  }

  if (normalizedStatus === "expired") {
    return "is-expired";
  }

  if (normalizedStatus === "paid" || normalizedStatus === "completed") {
    return "is-success";
  }

  if (normalizedStatus === "parked" || normalizedStatus === "occupied" || normalizedStatus === "unpaid") {
    return "is-active";
  }

  return "is-pending";
}

function isReservationCancellable(reservation) {
  const displayStatus = getReservationDisplayStatus(reservation).toLowerCase();
  const paymentStatus = String(reservation?.paymentStatus || "").trim().toLowerCase();
  const timeOut = String(reservation?.timeOut || reservation?.reservedTimeOut || "").trim();
  const blockedStatuses = ["cancelled", "parked", "occupied", "exited", "unpaid", "paid", "completed"];

  if (blockedStatuses.includes(displayStatus)) {
    return false;
  }

  if (paymentStatus === "paid") {
    return false;
  }

  if (timeOut !== "" && timeOut !== "00:00:00") {
    return false;
  }

  return true;
}

function reopenReservationSummary(reservation) {
  latestReservation = reservation;
  openSummaryModal(reservation);
}

function handleFeedbackSubmit(event) {
  event.preventDefault();

  if (!feedbackForm || !feedbackEmailInput || !feedbackMessageInput || !feedbackFormStatus) {
    return;
  }

  clearFeedbackErrors();

  const email = feedbackEmailInput.value.trim();
  const message = feedbackMessageInput.value.trim();
  let isValid = true;

  if (!email) {
    setFeedbackError("email", "Email is required.");
    isValid = false;
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    setFeedbackError("email", "Enter a valid email address.");
    isValid = false;
  }

  if (!message) {
    setFeedbackError("message", "Concern message is required.");
    isValid = false;
  }

  if (!isValid) {
    feedbackFormStatus.textContent = "Please review the highlighted fields and try again.";
    feedbackFormStatus.className = "form-status is-error";
    return;
  }

  feedbackFormStatus.textContent = "Submitting your message...";
  feedbackFormStatus.className = "form-status";

  fetch(FEEDBACK_SUBMIT_API, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json"
    },
    body: JSON.stringify({ email, message })
  })
    .then(async (response) => {
      const result = await parseJsonResponse(response, "feedback submit");

      if (!response.ok || result.success === false) {
        throw new Error(result.message || "Failed to submit feedback.");
      }

      const feedbackMessages = getStoredFeedbackMessages();
      feedbackMessages.unshift({
        id: result?.data?.messageId || `feedback-${Date.now()}`,
        email,
        message,
        submittedAt: new Date().toISOString()
      });
      saveFeedbackMessages(feedbackMessages);

      feedbackMessageInput.value = "";
      syncDashboardFloatingFieldStates(feedbackForm || document);
      feedbackFormStatus.textContent = result.message || "Feedback submitted successfully. Thank you for reaching out.";
      feedbackFormStatus.className = "form-status";
    })
    .catch((error) => {
      feedbackFormStatus.textContent = error.message || "Failed to submit feedback.";
      feedbackFormStatus.className = "form-status is-error";
    });
}

function setFeedbackError(fieldName, message) {
  const input = fieldName === "email" ? feedbackEmailInput : feedbackMessageInput;
  const fieldGroup = input?.closest(".field-group");
  const errorElement = feedbackForm?.querySelector(`[data-feedback-error-for="${fieldName}"]`);

  if (fieldGroup) {
    fieldGroup.classList.add("has-error");
  }

  if (errorElement) {
    errorElement.textContent = message;
  }
}

function clearFeedbackErrors() {
  if (!feedbackForm || !feedbackFormStatus) {
    return;
  }

  feedbackForm.querySelectorAll(".field-group").forEach((group) => {
    group.classList.remove("has-error");
  });

  feedbackForm.querySelectorAll("[data-feedback-error-for]").forEach((errorElement) => {
    errorElement.textContent = "";
  });

  feedbackFormStatus.textContent = "";
  feedbackFormStatus.className = "form-status";
}

function clearReservationLog() {
  removeScopedStorage(PARKING_RESERVATIONS_KEY);
  latestReservation = null;
  closeSummaryModal();
  closeClearLogModal();
  renderReservationRecords();

  if (selectedFloor) {
    refreshFloorSlots(selectedFloor, { keepPage: true }).catch(() => {
      renderSlots(selectedFloor);
    });
  } else {
    renderEmptySlotsState();
  }

  updateDashboardHighlights();
  showReservationLogStatus("Local reservation history cleared successfully.", "is-success");
}

async function confirmReservationCancellation() {
  const reservation = pendingReservationCancellation;

  if (!reservation?.reservationId) {
    closeCancelReservationModal();
    showReservationLogStatus("Please choose a valid reservation to cancel.", "is-error");
    return;
  }

  if (confirmCancelReservationButton) {
    confirmCancelReservationButton.disabled = true;
    confirmCancelReservationButton.textContent = "Cancelling...";
  }

  try {
    const response = await fetch(USER_CANCEL_RESERVATION_API, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json"
      },
      credentials: "same-origin",
      body: JSON.stringify({
        reservationId: reservation.reservationId
      })
    });
    const result = await parseJsonResponse(response, "reservation cancel");

    if (!response.ok || result?.success === false) {
      throw new Error(result?.message || "Failed to cancel reservation.");
    }

    closeCancelReservationModal();

    if (summaryModal?.classList.contains("is-open")) {
      const latestKey = String(latestReservation?.reservationId || latestReservation?.barcode || "");
      const cancelledKey = String(reservation.reservationId || reservation.barcode || "");

      if (latestKey !== "" && latestKey === cancelledKey) {
        closeSummaryModal();
      }
    }

    await refreshUserReservationsState();

    if (selectedFloor) {
      await refreshFloorSlots(selectedFloor, {
        keepPage: true,
        floorId: selectedFloorId
      });
    } else {
      await refreshFloors({
        preserveSelection: true,
        resetPage: false
      });
    }

    showReservationLogStatus(result?.message || "Reservation cancelled successfully.", "is-success");
  } catch (error) {
    showReservationLogStatus(error?.message || "Failed to cancel reservation.", "is-error");
  } finally {
    if (confirmCancelReservationButton) {
      confirmCancelReservationButton.disabled = false;
      confirmCancelReservationButton.textContent = "Yes, Cancel";
    }
  }
}

function showReservationLogStatus(message, variant = "") {
  if (!reservationLogStatus) {
    return;
  }

  reservationLogStatus.textContent = message;
  reservationLogStatus.className = "section-inline-status";

  if (variant) {
    reservationLogStatus.classList.add(variant);
  }

  if (reservationLogStatusTimer) {
    window.clearTimeout(reservationLogStatusTimer);
  }

  reservationLogStatusTimer = window.setTimeout(() => {
    reservationLogStatus.textContent = "";
    reservationLogStatus.className = "section-inline-status";
  }, RESERVATION_LOG_STATUS_TIMEOUT);
}

async function handleProfileSubmit(event) {
  event.preventDefault();

  const nextProfile = {
    ...currentProfile,
    fullName: profileFieldRefs.fullName.value.trim(),
    email: profileFieldRefs.email.value.trim(),
    birthday: profileFieldRefs.birthday.value,
    phone: profileFieldRefs.phone.value.trim(),
    vehicleType: profileFieldRefs.vehicleType.value.trim(),
    plateNumber: profileFieldRefs.plateNumber.value.trim(),
    address: profileFieldRefs.address.value.trim(),
    password: profileFieldRefs.password.value
  };

  if (!nextProfile.fullName) {
    profileStatus.textContent = "Full name is required before saving your profile.";
    profileStatus.className = "form-status is-error";
    profileFieldRefs.fullName.focus();
    return;
  }

  if (nextProfile.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(nextProfile.email)) {
    profileStatus.textContent = "Please enter a valid email address.";
    profileStatus.className = "form-status is-error";
    profileFieldRefs.email.focus();
    return;
  }

  try {
    profileStatus.textContent = "Saving your profile...";
    profileStatus.className = "form-status";

    const backendProfile = await saveUserProfileToBackend(nextProfile);
    currentProfile = {
      ...nextProfile,
      ...backendProfile,
      phone: nextProfile.phone,
      vehicleType: nextProfile.vehicleType,
      plateNumber: nextProfile.plateNumber,
      address: nextProfile.address,
      password: ""
    };

    profileFieldRefs.password.value = "";
    saveUserProfile();
    hydrateProfileUI();
    profileStatus.textContent = "Profile saved successfully.";
    profileStatus.className = "form-status";
  } catch (error) {
    profileStatus.textContent = error.message || "Failed to save profile.";
    profileStatus.className = "form-status is-error";
  }
}

function updateDashboardHighlights() {
  if (heroSelectedFloor) {
    const selectedFloorRecord = getFloorRecordById(selectedFloorId) || getFloorRecordByName(selectedFloor);
    heroSelectedFloor.textContent = selectedFloorRecord?.floor_label || selectedFloor || "None yet";
  }

  if (!selectedFloor && heroAvailableCount) {
    heroAvailableCount.textContent = "0";
  }
}

function getSlotRecord(floorName, slotCode) {
  return (floorData[floorName] || []).find((slot) => slot.code === slotCode) || null;
}

function setSlotStatus(floorName, slotCode, nextStatus) {
  const slot = getSlotRecord(floorName, slotCode);

  if (slot) {
    slot.status = nextStatus;
    slot.disabled = nextStatus !== "available";
  }
}

function setFieldError(fieldName, message) {
  const input = fieldRefs[fieldName];
  const fieldGroup = input?.closest(".field-group");
  const errorElement = reservationForm.querySelector(`[data-error-for="${fieldName}"]`);

  if (fieldGroup) {
    fieldGroup.classList.add("has-error");
  }

  if (errorElement) {
    errorElement.textContent = message;
  }
}

function clearFieldError(fieldName) {
  const input = fieldRefs[fieldName];
  const fieldGroup = input?.closest(".field-group");
  const errorElement = reservationForm.querySelector(`[data-error-for="${fieldName}"]`);

  if (fieldGroup) {
    fieldGroup.classList.remove("has-error");
  }

  if (errorElement) {
    errorElement.textContent = "";
  }
}

function clearReservationErrors() {
  reservationForm.querySelectorAll(".field-group").forEach((group) => {
    group.classList.remove("has-error");
  });

  reservationForm.querySelectorAll(".field-error").forEach((error) => {
    error.textContent = "";
  });
}

function generateReservationCode(floor, slot) {
  const compactFloor = String(floor || "").replace(/[^A-Za-z0-9]+/g, "").toUpperCase() || "LG";
  const compactSlot = String(slot || "").replace(/[^A-Za-z0-9]+/g, "").toUpperCase() || "S1";
  const seed = Math.random().toString(36).slice(2, 10).toUpperCase().padEnd(8, "0").slice(0, 8);
  return normalizeBarcodeValue(`SP-${compactFloor}-${compactSlot}-${seed}`);
}

function openSummaryModal(reservation) {
  latestReservation = reservation;
  fillSummaryDetails(reservation);
  drawBarcode(barcodeCanvas, reservation.barcode || reservation.reservationCode);
  summaryModal.classList.add("is-open");
  summaryModal.setAttribute("aria-hidden", "false");
  syncBodyModalState();
}

function fillSummaryDetails(reservation) {
  const barcodeValue = reservation.barcode || reservation.reservationCode;
  document.getElementById("summary-code-text").textContent = barcodeValue;
  document.getElementById("barcode-caption").textContent = barcodeValue;
  document.getElementById("summary-full-name").textContent = reservation.fullName;
  document.getElementById("summary-email").textContent = reservation.email;
  document.getElementById("summary-floor").textContent = reservation.floor;
  document.getElementById("summary-slot").textContent = reservation.slot;
  document.getElementById("summary-date").textContent = formatDate(reservation.reservationDate);
  document.getElementById("summary-time-in").textContent = formatTime(reservation.timeIn);
  document.getElementById("summary-time-out").textContent = reservation.timeOut ? formatTime(reservation.timeOut) : "Will be recorded at the parking booth";
  document.getElementById("summary-status").textContent = getReservationDisplayStatus(reservation);
  document.getElementById("summary-payment").textContent = `${formatCurrency(reservation.totalPayment)} base rate`;
}

function formatDate(dateValue) {
  if (!dateValue) {
    return "Not set";
  }

  const date = new Date(dateValue);
  return date.toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric"
  });
}

function formatTime(timeValue) {
  if (!timeValue) {
    return "--";
  }

  const [hours, minutes] = timeValue.split(":");
  const date = new Date();
  date.setHours(Number(hours), Number(minutes), 0, 0);
  return date.toLocaleTimeString("en-PH", {
    hour: "numeric",
    minute: "2-digit"
  });
}

function drawBarcode(canvas, code) {
  const barcodeValue = normalizeBarcodeValue(code);

  if (window.JsBarcode) {
    window.JsBarcode(canvas, barcodeValue, {
      format: "CODE128",
      displayValue: true,
      font: "Montserrat",
      fontSize: 16,
      textMargin: 8,
      margin: 16,
      height: 88,
      background: "#ffffff",
      lineColor: "#111111",
      width: 2
    });
    return;
  }

  const context = canvas.getContext("2d");
  const width = canvas.width;
  const height = canvas.height;
  const patterns = barcodeValue.split("").map((character) => character.charCodeAt(0).toString(2).padStart(8, "0")).join("");

  context.fillStyle = "#ffffff";
  context.fillRect(0, 0, width, height);

  let x = 20;
  const baseBarWidth = Math.max(1.5, (width - 40) / (patterns.length * 1.6));
  const barHeight = height - 28;

  for (const bit of patterns) {
    const barWidth = bit === "1" ? baseBarWidth * 1.2 : baseBarWidth * 0.75;

    if (bit === "1") {
      context.fillStyle = "#111111";
      context.fillRect(x, 10, barWidth, barHeight);
    }

    x += baseBarWidth + 1.4;
  }

  context.fillStyle = "#111111";
  context.font = "14px Montserrat, sans-serif";
  context.textAlign = "center";
  context.fillText(barcodeValue, width / 2, height - 6);
}

function downloadReservationPdf() {
  if (!latestReservation || !window.jspdf?.jsPDF) {
    return;
  }

  const { jsPDF } = window.jspdf;
  const pdf = new jsPDF({
    orientation: "portrait",
    unit: "mm",
    format: "a4"
  });

  pdf.setFillColor(248, 248, 248);
  pdf.rect(0, 0, 210, 297, "F");
  pdf.setTextColor(20, 20, 20);
  pdf.setFont("helvetica", "bold");
  pdf.setFontSize(20);
  pdf.text(getConfiguredSystemName(), 20, 22);
  pdf.setFontSize(12);
  pdf.setFont("helvetica", "normal");
  pdf.text("Parking Reservation Summary", 20, 30);

  const barcodeImage = barcodeCanvas.toDataURL("image/png");
  pdf.addImage(barcodeImage, "PNG", 20, 38, 170, 36);

  const lines = [
    `Reservation Code: ${latestReservation.barcode || latestReservation.reservationCode}`,
    `Full Name: ${latestReservation.fullName}`,
    `Email: ${latestReservation.email}`,
    `Parking Floor: ${latestReservation.floor}`,
    `Parking Slot: ${latestReservation.slot}`,
    `Date of Reservation: ${formatDate(latestReservation.reservationDate)}`,
    `Time In: ${formatTime(latestReservation.timeIn)}`,
    `Time Out: ${latestReservation.timeOut ? formatTime(latestReservation.timeOut) : 'Recorded at parking booth'}`,
    `Estimated Base Rate: ${formatCurrency(latestReservation.totalPayment)}`
  ];

  let currentY = 88;
  pdf.setFontSize(11);

  lines.forEach((line) => {
    pdf.text(line, 20, currentY);
    currentY += 9;
  });

  pdf.setFont("helvetica", "bold");
  pdf.text("Important Note", 20, currentY + 4);
  pdf.setFont("helvetica", "normal");
  pdf.text("Time out and final payment will be recorded at the parking booth based on actual parking duration.", 20, currentY + 12, {
    maxWidth: 168
  });

  pdf.save(`SNDRA-Park-${latestReservation.barcode || latestReservation.reservationCode}.pdf`);
}

function buildInvalidJsonError(response, rawText, contextLabel = "API request") {
  const url = response?.url || "unknown endpoint";
  const trimmed = String(rawText || "").trim();
  const preview = trimmed.slice(0, 180);
  const looksLikeHtml = preview.startsWith("<") || /<!DOCTYPE|<html/i.test(preview);
  const reason = looksLikeHtml
    ? "The backend returned HTML instead of JSON."
    : "The backend returned invalid JSON.";

  console.error(`Invalid JSON response during ${contextLabel}:`, {
    url,
    status: response?.status,
    preview
  });

  return new Error(`${contextLabel} failed. ${reason}`);
}

async function parseJsonResponse(response, contextLabel = "API request") {
  const rawText = await response.text();
  const trimmed = rawText.trim();

  if (!trimmed) {
    return {};
  }

  try {
    return JSON.parse(trimmed);
  } catch (error) {
    throw buildInvalidJsonError(response, rawText, contextLabel);
  }
}

function normalizeStoredReservation(reservation, fallbackIndex = 0) {
  const barcode = normalizeBarcodeValue(
    reservation?.barcode ||
    reservation?.reservationCode ||
    generateReservationCode(reservation?.floor || "LG", reservation?.slot || `S${fallbackIndex + 1}`)
  );
  const reservationStatus = String(
    reservation?.reservationStatus
    || reservation?.status
    || "Reserved"
  ).trim() || "Reserved";
  const boothStatus = String(
    reservation?.boothStatus
    || (reservationStatus.toLowerCase() === "cancelled" ? "Cancelled" : reservationStatus)
  ).trim() || "Reserved";

  return {
    ...reservation,
    reservationId: reservation?.reservationId || reservation?.id || `reservation-${barcode}-${fallbackIndex}`,
    barcode,
    reservationCode: barcode,
    paymentStatus: reservation?.paymentStatus || "Reserved",
    boothStatus,
    status: reservationStatus,
    reservationStatus
  };
}

function mapBackendReservation(record, fallbackReservation) {
  const barcode = normalizeBarcodeValue(record.barcode || record.barcodeValue || fallbackReservation.barcode);

  return normalizeStoredReservation({
    ...fallbackReservation,
    reservationId: record.reservationId || fallbackReservation.reservationId,
    userId: record.userId || fallbackReservation.userId,
    barcode,
    reservationCode: barcode,
    floor: record.floor || fallbackReservation.floor,
    slot: record.slot || fallbackReservation.slot,
    reservationDate: record.reservationDate || fallbackReservation.reservationDate,
    timeIn: record.reservedTimeIn || fallbackReservation.timeIn,
    timeOut: record.reservedTimeOut || fallbackReservation.timeOut || null,
    totalPayment: Number(record.reservationFee || fallbackReservation.totalPayment || 0),
    paymentStatus: record.paymentStatus || fallbackReservation.paymentStatus,
    boothStatus: record.boothStatus || fallbackReservation.boothStatus,
    status: record.reservationStatus || record.reservation_status || record.status || fallbackReservation.status,
    reservationStatus: record.reservationStatus || record.reservation_status || record.status || fallbackReservation.reservationStatus || fallbackReservation.status
  });
}

function normalizeBarcodeValue(value) {
  return String(value ?? "")
    .trim()
    .replace(/^\](?:C1|c1|E0|e0|d2|D2)/, "")
    .replace(/[\u0000-\u001F\u007F]+/g, "")
    .replace(/[\u200B-\u200D\u2060\uFEFF]+/g, "")
    .replace(/\s+/gu, "")
    .replace(/[\u2013\u2014]/g, "-")
    .toUpperCase();
}

