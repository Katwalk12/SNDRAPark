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

const dashboardAuthApi = getBackendUrl("/backend/api/v1/auth.php");
const USER_RESOURCE_API = getBackendUrl("/backend/api/v1/users.php");
const USER_API_BASE = getBackendUrl("/backend/user");
const USER_PROFILE_API = buildApiActionUrl(USER_RESOURCE_API);
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
const USER_VEHICLES_API = buildApiActionUrl(USER_RESOURCE_API, "vehicles");
const FEEDBACK_SUBMIT_API = `${PARKING_BACKEND_ORIGIN}/backend/feedback/submit.php`;
const LOGIN_ROUTE = getRoutePath("login", "./login.html");
const RESERVATION_LOG_STATUS_TIMEOUT = 2800;
const FLOOR_REFRESH_INTERVAL = 5 * 60 * 1000;
const SLOT_REFRESH_INTERVAL = 3000;
const PROFILE_REFRESH_INTERVAL = 30000;
const RESERVATION_REFRESH_INTERVAL = 5000;

// When the garage is open. The server refuses anything outside this window
// (PARKING_OPENING_TIME / PARKING_CLOSING_TIME in backend/parking/common.php)
// and must be changed together with these.
const PARKING_HOURS = { opening: "08:00", closing: "22:00" };
// Same-day booking closes an hour before the garage does. Mirrors
// PARKING_SAME_DAY_CUTOFF in backend/parking/common.php.
const PARKING_SAME_DAY_CUTOFF = "21:00";
const DEFAULT_SYSTEM_SETTINGS = {
  system_name: "SNDRA Park",
  contact_number: "+63 917 555 0142",
  gmail_address: "sndraparksupport@gmail.com",
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
let currentVehicles = [];
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

// Mirrors RESERVATION_SECURITY_WARNING_ALLOWANCE in
// backend/common/reservation-security.php — keep the two in step.
const NO_SHOW_WARNING_ALLOWANCE = 3;

function getSupportEmail() {
  return window.SNDRA_SYSTEM_SETTINGS?.gmail_address || "sndraparksupport@gmail.com";
}

const PARKING_SLOTS_PER_PAGE = 14;
const PARKING_ROW_SIZE = 7;

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
const activeHoldModal = document.getElementById("active-hold-modal");
const activeHoldMessage = document.getElementById("active-hold-message");
const activeHoldHint = document.getElementById("active-hold-hint");
const activeHoldViewButton = document.getElementById("active-hold-view-btn");
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
const vehicleModal = document.getElementById("vehicle-modal");
const vehicleForm = document.getElementById("vehicle-form");
const addVehicleButton = document.getElementById("add-vehicle-btn");
const vehicleCardGrid = document.getElementById("vehicle-card-grid");
const vehicleListStatus = document.getElementById("vehicle-list-status");
const vehicleFormStatus = document.getElementById("vehicle-form-status");
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

function buildApiActionUrl(scriptUrl, action = "") {
  const url = new URL(scriptUrl, window.location.origin);

  if (action) {
    url.searchParams.set("action", action);
  }

  return url.toString();
}

const profileFieldRefs = {
  fullName: document.getElementById("profile-full-name"),
  email: document.getElementById("profile-email"),
  birthday: document.getElementById("profile-birthday"),
  phone: document.getElementById("profile-phone"),
  vehicleType: document.getElementById("profile-vehicle"),
  plateNumber: document.getElementById("profile-plate"),
  address: document.getElementById("profile-address"),
  vehicleBrand: document.getElementById("profile-brand"),
  vehicleColor: document.getElementById("profile-color"),
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

const reservationFieldRefs = {
  ...fieldRefs,
  vehicle: document.getElementById("reservation-vehicle")
};

fieldRefs.vehicleId = reservationFieldRefs.vehicle;

function updateDashboardFloatingFieldState(field) {
  const fieldGroup = field?.closest(".floating-field");

  if (!fieldGroup) {
    return;
  }

  const hasValue = field.value.trim() !== "";
  fieldGroup.classList.toggle("has-value", hasValue);
}

function syncDashboardFloatingFieldStates(scope = document) {
  scope.querySelectorAll(".floating-field input, .floating-field textarea, .floating-field select").forEach((field) => {
    updateDashboardFloatingFieldState(field);
  });
}

function initializeDashboardFloatingFields() {
  document.querySelectorAll(".floating-field input, .floating-field textarea, .floating-field select").forEach((field) => {
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
  await refreshVehiclesState({ silent: true }).catch(() => {
    currentVehicles = buildLegacyVehicleListFromProfile(currentProfile);
  });
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
  bindVehicleFormEvents();
  bindFeedbackFormEvents();
  initializeDashboardFloatingFields();
  updateDashboardHighlights();
  hydrateFeedbackForm();
  startProfileRefresh();
  startReservationRefresh();
  handleGoogleWelcome();
}

// A Google sign in skips the vehicle step that the signup form collects, so a
// brand-new Google account lands here with nothing to reserve with. The OAuth
// callback flags that account with ?welcome=google; finish the registration
// here rather than leaving the user on a dashboard that quietly refuses every
// reservation.
function handleGoogleWelcome() {
  const params = new URLSearchParams(window.location.search);

  // session-guard.js shows the welcome banner and strips ?welcome=google before
  // this runs, so its flag is the primary signal; the query string is only a
  // fallback for a direct hit that somehow skipped the guard.
  const isGoogleWelcome = window.__SNDRA_GOOGLE_WELCOME === true || params.get("welcome") === "google";

  if (!isGoogleWelcome) {
    return;
  }

  delete window.__SNDRA_GOOGLE_WELCOME;

  const needsVehicle = !currentVehicles.length;

  // Drop the flag so a refresh does not reopen the modal, and land on the panel
  // that holds the vehicle form.
  params.delete("welcome");
  const query = params.toString();
  const hash = needsVehicle ? "#profile-section" : window.location.hash;
  history.replaceState(null, "", `${window.location.pathname}${query ? `?${query}` : ""}${hash}`);

  if (!needsVehicle) {
    return;
  }

  syncSidebarSectionState("profile-section");

  if (vehicleListStatus) {
    vehicleListStatus.textContent = "Welcome! Add your vehicle to finish setting up your account — every reservation needs one.";
    vehicleListStatus.className = "section-inline-status";
  }

  openVehicleModal();
}

window.addEventListener("sndra:system-settings-updated", (event) => {
  dashboardSystemSettings = normalizeSystemSettings(event.detail?.settings || {});
  updatePaymentDisplay();
});

async function ensureAuthenticatedSession() {
  try {
    const response = await fetch(buildApiActionUrl(dashboardAuthApi, "session"), {
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
    password: profile.password || "",
    vehicle_type: profile.vehicleType || "",
    plate_number: profile.plateNumber || "",
    vehicle_brand: profile.vehicleBrand || "",
    vehicle_model: profile.vehicleModel || "",
    vehicle_color: profile.vehicleColor || ""
  };

  const response = await fetch(buildApiActionUrl(USER_RESOURCE_API, "update"), {
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
    vehicleBrand: profile?.vehicle_brand || "",
    vehicleModel: profile?.vehicle_model || "",
    vehicleColor: profile?.vehicle_color || "",
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

function normalizeVehicle(vehicle) {
  const vehicleId = Number(vehicle?.vehicle_id || vehicle?.vehicleId || 0);
  const vehicleType = String(vehicle?.vehicle_type || vehicle?.vehicleType || "").trim();
  const plateNumber = String(vehicle?.plate_number || vehicle?.plateNumber || "").trim().toUpperCase();
  const brand = String(vehicle?.brand || vehicle?.vehicleBrand || vehicle?.vehicle_brand || "").trim();
  const model = String(vehicle?.model || vehicle?.vehicleModel || vehicle?.vehicle_model || "").trim();
  const color = String(vehicle?.color || vehicle?.vehicleColor || vehicle?.vehicle_color || "").trim();

  return {
    vehicleId,
    vehicle_id: vehicleId,
    vehicleType,
    vehicle_type: vehicleType,
    plateNumber,
    plate_number: plateNumber,
    brand,
    model,
    color,
    createdAt: vehicle?.created_at || vehicle?.createdAt || ""
  };
}

function buildLegacyVehicleListFromProfile(profile) {
  if (!profile?.plateNumber) {
    return [];
  }

  return [normalizeVehicle({
    vehicleId: 0,
    vehicleType: profile.vehicleType || "Car",
    plateNumber: profile.plateNumber,
    brand: profile.vehicleBrand || "",
    model: profile.vehicleModel || "",
    color: profile.vehicleColor || ""
  })];
}

async function fetchUserVehiclesFromBackend() {
  const response = await fetch(USER_VEHICLES_API, {
    method: "GET",
    headers: {
      Accept: "application/json"
    },
    credentials: "same-origin",
    cache: "no-store"
  });
  const result = await parseJsonResponse(response, "vehicle list");

  if (!response.ok || result?.success === false) {
    throw new Error(result?.message || "Failed to load vehicles.");
  }

  return Array.isArray(result?.data?.vehicles)
    ? result.data.vehicles.map(normalizeVehicle)
    : [];
}

async function refreshVehiclesState(options = {}) {
  try {
    const backendVehicles = await fetchUserVehiclesFromBackend();
    currentVehicles = backendVehicles.length ? backendVehicles : buildLegacyVehicleListFromProfile(currentProfile);
    renderVehicleCards();
    populateReservationVehicleDropdown();
    return currentVehicles;
  } catch (error) {
    if (!options.silent && vehicleListStatus) {
      vehicleListStatus.textContent = error.message || "Unable to load vehicles.";
      vehicleListStatus.className = "section-inline-status is-error";
    }
    throw error;
  }
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
    profilePreviewVehicle.textContent = currentVehicles[0]
      ? currentVehicles[0].vehicleType
      : currentProfile?.vehicleType?.trim() || "Not set";
  }

  if (profilePreviewPlate) {
    profilePreviewPlate.textContent = currentVehicles[0]
      ? currentVehicles[0].plateNumber
      : currentProfile?.plateNumber?.trim() || "Not set";
  }

  updateAccountWarningBanner();

  Object.entries(profileFieldRefs).forEach(([key, field]) => {
    if (field) {
      field.value = currentProfile?.[key] || "";
    }
  });

  syncDashboardFloatingFieldStates(profileForm || document);
  renderVehicleCards();
}

const ACCOUNT_NOTICE_DISMISSED_KEY = "sndrapark_account_notice_dismissed";

/**
 * What the banner should say right now, or null when there is nothing to say.
 *
 * The signature identifies this exact notice. Dismissing stores it, so the
 * banner stays gone for that notice but returns the moment the situation
 * changes - a new warning, a different hold, a lock.
 */
function buildAccountNotice() {
  const warningCount = Number(currentProfile?.warningCount || 0);
  const accountStatus = String(currentProfile?.accountStatus || "active").toLowerCase();
  const lockedUntil = currentProfile?.accountLockedUntil || "";

  if (accountStatus === "locked") {
    // A lock with no release date is lifted only by an approved appeal.
    return {
      signature: `locked:${lockedUntil || "appeal"}`,
      variant: "is-danger",
      dismissible: false,
      kicker: "Account Lock",
      title: lockedUntil
        ? "Your account is temporarily locked"
        : "Your account is locked pending an appeal",
      message: lockedUntil
        ? `You cannot create a new reservation until ${formatDateTime(lockedUntil)} because of repeated expired reservations.`
        : `Too many reservations expired without you arriving at the parking lot. To have your account reactivated, file a letter of appeal to ${getSupportEmail()} explaining the missed reservations.`
    };
  }

  const activeHold = findActiveReservationHold();
  const remaining = Math.max(0, NO_SHOW_WARNING_ALLOWANCE - warningCount);

  if (activeHold) {
    return {
      signature: `hold:${activeHold.reservationId || activeHold.barcode || ""}:${warningCount}`,
      variant: "",
      dismissible: true,
      kicker: "Active Reservation",
      title: "One reservation at a time",
      message: warningCount >= 1
        ? `${buildActiveHoldMessage(activeHold)} You also have ${warningCount} of ${NO_SHOW_WARNING_ALLOWANCE} warnings, with ${remaining} chance${remaining === 1 ? "" : "s"} left.`
        : buildActiveHoldMessage(activeHold)
    };
  }

  if (warningCount >= 1) {
    return {
      signature: `warn:${warningCount}`,
      variant: "is-warning",
      dismissible: true,
      kicker: `Reservation Warning ${warningCount} of ${NO_SHOW_WARNING_ALLOWANCE}`,
      title: remaining === 0
        ? "Another expired reservation will lock your account"
        : `You have ${remaining} chance${remaining === 1 ? "" : "s"} left`,
      message: remaining === 0
        ? "One more reservation that expires because you did not arrive on time will lock your account until a letter of appeal is approved."
        : `A reservation expired because you did not arrive on time, and the slot was released. After ${NO_SHOW_WARNING_ALLOWANCE} warnings, the next one locks your account until a letter of appeal is approved.`
    };
  }

  return null;
}

function updateAccountWarningBanner() {
  if (!accountWarningBanner || !accountWarningKicker || !accountWarningTitle || !accountWarningMessage) {
    return;
  }

  const notice = buildAccountNotice();
  const dismissButton = document.getElementById("account-warning-dismiss");

  if (!notice) {
    accountWarningBanner.hidden = true;
    accountWarningBanner.className = "account-warning-banner";
    return;
  }

  if (notice.dismissible && readScopedStorage(ACCOUNT_NOTICE_DISMISSED_KEY, "") === notice.signature) {
    accountWarningBanner.hidden = true;
    return;
  }

  accountWarningBanner.hidden = false;
  accountWarningBanner.className = `account-warning-banner${notice.variant ? ` ${notice.variant}` : ""}`;
  accountWarningBanner.dataset.noticeSignature = notice.signature;
  accountWarningKicker.textContent = notice.kicker;
  accountWarningTitle.textContent = notice.title;
  accountWarningMessage.textContent = notice.message;

  if (dismissButton) {
    // A lock is the standing reason nothing can be booked, so it stays put.
    dismissButton.hidden = !notice.dismissible;
  }
}

function dismissAccountNotice() {
  const signature = accountWarningBanner?.dataset.noticeSignature || "";

  if (signature) {
    writeScopedStorage(ACCOUNT_NOTICE_DISMISSED_KEY, signature);
  }

  if (accountWarningBanner) {
    accountWarningBanner.hidden = true;
  }
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
      await fetch(buildApiActionUrl(dashboardAuthApi, "logout"), {
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
  // The banner now reports an outstanding hold, so it depends on this state.
  updateAccountWarningBanner();
}

// toISOString() returns the UTC date. East of UTC that is yesterday for the
// first hours of every local day, so the field was pre-filled with a date that
// isPastReservationDate() — which compares in LOCAL time — then rejected. In
// UTC+8 that made reservations impossible from 00:00 to 08:00 daily.
function localIsoDate(date = new Date()) {
  const pad = (n) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function isSameDayBookingClosed() {
  const now = new Date();
  const nowMinutes = now.getHours() * 60 + now.getMinutes();

  return nowMinutes > timeToMinutes(PARKING_SAME_DAY_CUTOFF);
}

function getEarliestReservationDate() {
  const earliest = new Date();

  if (isSameDayBookingClosed()) {
    earliest.setDate(earliest.getDate() + 1);
  }

  return localIsoDate(earliest);
}

function initializeDateField() {
  const earliest = getEarliestReservationDate();
  fieldRefs.reservationDate.min = earliest;

  if (!fieldRefs.reservationDate.value || fieldRefs.reservationDate.value < earliest) {
    fieldRefs.reservationDate.value = earliest;
  }

  syncReservationTimeBounds();
}

/**
 * On today the earliest usable arrival is the next minute, not opening time.
 * Any later date opens the full window again.
 */
function syncReservationTimeBounds() {
  const isToday = fieldRefs.reservationDate.value === localIsoDate();
  const now = new Date();
  const nowMinutes = now.getHours() * 60 + now.getMinutes();
  const opening = timeToMinutes(PARKING_HOURS.opening);
  const lowerBound = isToday ? Math.max(opening, nowMinutes + 1) : opening;

  fieldRefs.timeIn.min = minutesToTimeValue(lowerBound);
  fieldRefs.timeIn.max = PARKING_HOURS.closing;
}

function minutesToTimeValue(minutes) {
  const clamped = Math.max(0, Math.min(24 * 60 - 1, Math.round(minutes)));

  return `${String(Math.floor(clamped / 60)).padStart(2, "0")}:${String(clamped % 60).padStart(2, "0")}`;
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
      disabled: Boolean(slot.disabled),
      unavailableScope: String(slot.unavailable_scope || ""),
      unavailableReason: String(slot.unavailable_reason || "")
    })).sort((left, right) => compareSlotCodes(left.code, right.code))
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

function getSlotSortParts(slotCode) {
  const code = String(slotCode || "").trim().toUpperCase();
  const standardMatch = code.match(/^F(\d+)-S(\d+)$/);
  if (standardMatch) {
    return [Number(standardMatch[1]), Number(standardMatch[2]), code];
  }

  const legacyMatch = code.match(/^([A-Z]+)(\d+)$/);
  if (legacyMatch) {
    return [0, Number(legacyMatch[2]), code];
  }

  const numberMatch = code.match(/(\d+)(?!.*\d)/);
  return [999999, numberMatch ? Number(numberMatch[1]) : 999999, code];
}

function compareSlotCodes(leftCode, rightCode) {
  const left = getSlotSortParts(leftCode);
  const right = getSlotSortParts(rightCode);

  for (let index = 0; index < left.length; index += 1) {
    if (left[index] < right[index]) return -1;
    if (left[index] > right[index]) return 1;
  }

  return 0;
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
    const isClosed = Number(floor.is_active) === 0;
    button.className = `floor-card${isClosed ? " is-closed" : ""}`;
    button.dataset.floorName = floor.floor_name;
    button.dataset.floorId = String(floor.id || "");
    // "0 reserved" on every pill was the widest thing in the floor row and
    // said nothing; show the hold count only when a floor actually has one.
    const reservedCount = Number(floor.reserved_count || 0);
    button.innerHTML = `
      <span class="floor-card-icon" aria-hidden="true"><i class="bi bi-building-fill"></i></span>
      <strong>${floor.floor_label || floor.floor_name}</strong>
      <span>${isClosed ? "Closed" : `${Number(floor.available_count || 0)} open`}</span>
      ${isClosed ? `<small>Tap to see why</small>` : (reservedCount > 0 ? `<small>${reservedCount} reserved</small>` : "")}
    `;

    if (isClosed) {
      // A closed floor has nothing to browse, so tapping it explains itself.
      button.addEventListener("click", () => openSlotUnavailableModal({
        code: floor.floor_label || floor.floor_name,
        unavailableScope: "floor",
        unavailableReason: floor.unavailable_reason || ""
      }));
      floorGrid.appendChild(button);
      return;
    }

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
  updateMonitorPagination(0, 0, 0);

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
  updateMonitorPagination(currentSlotPage, totalPages, slots.length);
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

function updateMonitorPagination(pageIndex, totalPages, totalSlots = 0) {
  const monitorNav = document.querySelector(".monitor-nav");

  if (monitorNav) {
    monitorNav.hidden = totalPages <= 1;
  }

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
  const handlePrevPage = () => {
    if (!selectedFloor || currentSlotPage <= 0) return;
    currentSlotPage -= 1;
    renderSlots(selectedFloor);
  };

  const handleNextPage = () => {
    if (!selectedFloor) return;
    const slots = floorData[selectedFloor] || [];
    const totalPages = Math.ceil(slots.length / PARKING_SLOTS_PER_PAGE);
    if (currentSlotPage >= totalPages - 1) return;
    currentSlotPage += 1;
    renderSlots(selectedFloor);
  };

  // Paging lives in the header controls only. A second pair of arrows used to
  // float over the slot grid calling these same handlers, which read as two
  // different controls for one action.
  monitorPrevPageButton?.addEventListener("click", handlePrevPage);
  monitorNextPageButton?.addEventListener("click", handleNextPage);
}

const SLOT_UNAVAILABLE_TITLES = {
  reserved: "Someone is holding this slot",
  occupied: "A vehicle is parked here",
  floor: "This floor is closed",
  slot: "This slot is out of service"
};

function openSlotUnavailableModal(slot) {
  const modal = document.getElementById("slot-unavailable-modal");
  const kicker = document.getElementById("slot-unavailable-kicker");
  const title = document.getElementById("slot-unavailable-title");
  const reason = document.getElementById("slot-unavailable-reason");
  const hint = document.getElementById("slot-unavailable-hint");

  if (!modal) {
    return;
  }

  const scope = String(slot?.unavailableScope || "");
  const isFloor = scope === "floor";

  if (kicker) {
    kicker.textContent = isFloor
      ? `${slot?.code || "Floor"} Closed`
      : `${slot?.code || "Slot"} Unavailable`;
  }

  if (title) {
    title.textContent = SLOT_UNAVAILABLE_TITLES[scope] || "This slot cannot be booked";
  }

  // The admin wrote this text; render it as text, never as markup.
  if (reason) {
    reason.textContent = slot?.unavailableReason
      || (isFloor
        ? "This floor is temporarily closed. No reason was recorded."
        : "No reason was recorded for this slot.");
  }

  if (hint) {
    hint.textContent = scope === "floor"
      ? "Try another floor - the floor selector shows how many slots each one has open."
      : "Pick any green slot to reserve instead.";
  }

  modal.classList.add("is-open");
  modal.setAttribute("aria-hidden", "false");
  syncBodyModalState();
}

function closeSlotUnavailableModal() {
  const modal = document.getElementById("slot-unavailable-modal");
  modal?.classList.remove("is-open");
  modal?.setAttribute("aria-hidden", "true");
  syncBodyModalState();
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
    button.className = `slot-card ${slot.status} is-clickable`;
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
      // Still clickable, but only to explain why it cannot be booked.
      button.addEventListener("click", () => openSlotUnavailableModal(slot));
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

  document.getElementById("account-warning-dismiss")?.addEventListener("click", dismissAccountNotice);

  document.querySelectorAll("[data-close-slot-unavailable]").forEach((button) => {
    button.addEventListener("click", closeSlotUnavailableModal);
  });

  document.getElementById("slot-unavailable-modal")?.addEventListener("click", (event) => {
    if (event.target === document.getElementById("slot-unavailable-modal")) {
      closeSlotUnavailableModal();
    }
  });

  document.querySelectorAll("[data-close-active-hold]").forEach((button) => {
    button.addEventListener("click", closeActiveHoldModal);
  });

  activeHoldViewButton?.addEventListener("click", () => {
    closeActiveHoldModal();
    syncSidebarSectionState("park-reserved");
  });

  activeHoldModal?.addEventListener("click", (event) => {
    if (event.target === activeHoldModal) {
      closeActiveHoldModal();
    }
  });

  document.querySelectorAll("[data-close-vehicle-modal]").forEach((button) => {
    button.addEventListener("click", closeVehicleModal);
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

  vehicleModal?.addEventListener("click", (event) => {
    if (event.target === vehicleModal) {
      closeVehicleModal();
    }
  });

  document.getElementById("download-pdf-btn")?.addEventListener("click", downloadReservationPdf);
  clearReservationLogButton?.addEventListener("click", openClearLogModal);
  confirmClearLogButton?.addEventListener("click", clearReservationLog);
  confirmCancelReservationButton?.addEventListener("click", confirmReservationCancellation);
  addVehicleButton?.addEventListener("click", openVehicleModal);
}

function bindReservationFormEvents() {
  fieldRefs.timeIn.min = PARKING_HOURS.opening;
  fieldRefs.timeIn.max = PARKING_HOURS.closing;
  fieldRefs.timeIn.addEventListener("input", () => {
    updatePaymentDisplay();
    validateReservationTimeField();
  });
  fieldRefs.reservationDate.addEventListener("input", () => {
    // Switching off today reopens the full window; switching back to it
    // clamps the earliest arrival to the current time again.
    syncReservationTimeBounds();
    validateReservationDateField();
    validateReservationTimeField();
  });

  reservationForm.addEventListener("submit", handleReservationSubmit);
}

function bindProfileFormEvents() {
  profileForm?.addEventListener("submit", handleProfileSubmit);
}

function bindVehicleFormEvents() {
  vehicleForm?.addEventListener("submit", handleVehicleSubmit);
}

function bindFeedbackFormEvents() {
  feedbackForm?.addEventListener("submit", handleFeedbackSubmit);
}

function syncBodyModalState() {
  const hasOpenModal = [reservationModal, summaryModal, clearLogModal, cancelReservationModal, vehicleModal].some((modal) => {
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
  const activeHold = findActiveReservationHold();

  if (activeHold) {
    updateAccountWarningBanner();
    openActiveHoldModal(activeHold);
    return;
  }

  clearReservationErrors();
  reservationForm.reset();
  reservationFormStatus.textContent = "";
  reservationFormStatus.className = "form-status";
  fieldRefs.floor.value = floorName;
  fieldRefs.slot.value = slot.code;
  fieldRefs.fullName.value = currentProfile?.fullName || buildDisplayName(currentUser);
  fieldRefs.email.value = currentProfile?.email || currentUser?.email || "";
  initializeDateField();
  populateReservationVehicleDropdown();
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

function openActiveHoldModal(hold) {
  if (activeHoldMessage) {
    const where = [hold?.floor, hold?.slot].filter(Boolean).join(" ");
    activeHoldMessage.textContent = where
      ? `You are already holding ${where}${hold?.reservationCode ? ` under ${hold.reservationCode}` : ""}.`
      : "You already have a parking slot reserved.";
  }

  if (activeHoldHint) {
    activeHoldHint.textContent = "Only one reservation can be held at a time. Present its barcode at the parking booth, or cancel it from History, before reserving another slot.";
  }

  activeHoldModal?.classList.add("is-open");
  activeHoldModal?.setAttribute("aria-hidden", "false");
  syncBodyModalState();
}

function closeActiveHoldModal() {
  activeHoldModal?.classList.remove("is-open");
  activeHoldModal?.setAttribute("aria-hidden", "true");
  syncBodyModalState();
}

function closeCancelReservationModal() {
  pendingReservationCancellation = null;
  cancelReservationModal?.classList.remove("is-open");
  cancelReservationModal?.setAttribute("aria-hidden", "true");
  syncBodyModalState();
}

function openVehicleModal() {
  clearVehicleFormErrors();
  vehicleForm?.reset();
  if (vehicleFormStatus) {
    vehicleFormStatus.textContent = "";
    vehicleFormStatus.className = "form-status";
  }
  syncDashboardFloatingFieldStates(vehicleForm || document);
  vehicleModal?.classList.add("is-open");
  vehicleModal?.setAttribute("aria-hidden", "false");
  syncBodyModalState();
}

function closeVehicleModal() {
  vehicleModal?.classList.remove("is-open");
  vehicleModal?.setAttribute("aria-hidden", "true");
  syncDashboardFloatingFieldStates(vehicleForm || document);
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

function timeToMinutes(timeValue) {
  const match = /^(\d{2}):(\d{2})/.exec(String(timeValue || ""));

  return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

function formatHourLabel(timeValue) {
  const minutes = timeToMinutes(timeValue);

  if (minutes === null) {
    return timeValue;
  }

  const hour24 = Math.floor(minutes / 60);
  const hour12 = hour24 % 12 === 0 ? 12 : hour24 % 12;

  return `${hour12}:${String(minutes % 60).padStart(2, "0")} ${hour24 < 12 ? "AM" : "PM"}`;
}

function validateReservationTimeField() {
  const value = fieldRefs.timeIn.value;

  if (!value) {
    return true;
  }

  const minutes = timeToMinutes(value);
  const opening = timeToMinutes(PARKING_HOURS.opening);
  const closing = timeToMinutes(PARKING_HOURS.closing);

  // A time input still accepts out-of-range values typed by hand, so min/max
  // on the element is a convenience and this is the actual check.
  if (minutes === null || minutes < opening || minutes > closing) {
    setFieldError(
      "timeIn",
      `The parking is open ${formatHourLabel(PARKING_HOURS.opening)} to ${formatHourLabel(PARKING_HOURS.closing)}.`
    );
    return false;
  }

  if (fieldRefs.reservationDate.value === localIsoDate()) {
    const now = new Date();
    const nowMinutes = now.getHours() * 60 + now.getMinutes();

    // A time already gone expires as a no-show within 30 minutes, which would
    // cost a warning the driver had no way to avoid.
    if (minutes <= nowMinutes) {
      setFieldError("timeIn", "That time has already passed. Please pick a later time.");
      return false;
    }
  }

  clearFieldError("timeIn");
  return true;
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
    timeIn: fieldRefs.timeIn.value,
    vehicleId: reservationFieldRefs.vehicle.value
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

  if (values.timeIn && !validateReservationTimeField()) {
    isValid = false;
  }

  if (!values.vehicleId) {
    setFieldError("vehicleId", "Please add and select a registered vehicle first.");
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
    // The server mints the barcode and returns it; nothing here may choose one.
    barcode: "",
    ...values,
    timeOut: null,
    totalPayment: getReservationBaseRate(),
    paymentStatus: "Reserved",
    boothStatus: "Reserved",
    status: "Reserved",
    userId: currentUser?.id || null
  };
  const selectedVehicle = currentVehicles.find((vehicle) => String(vehicle.vehicleId) === String(values.vehicleId));
  if (selectedVehicle) {
    latestReservation.vehicleId = selectedVehicle.vehicleId;
    latestReservation.vehicleType = selectedVehicle.vehicleType;
    latestReservation.plateNumber = selectedVehicle.plateNumber;
    latestReservation.vehicleBrand = selectedVehicle.brand;
    latestReservation.vehicleModel = selectedVehicle.model;
    latestReservation.vehicleColor = selectedVehicle.color;
  }
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

function populateReservationVehicleDropdown() {
  const vehicleSelect = reservationFieldRefs.vehicle;
  if (!vehicleSelect) return;

  vehicleSelect.innerHTML = "";

  if (!currentVehicles.length) {
    const opt = document.createElement("option");
    opt.value = "";
    opt.textContent = "Please add a vehicle first.";
    opt.disabled = true;
    opt.selected = true;
    vehicleSelect.appendChild(opt);
    return;
  }

  currentVehicles.forEach((vehicle, index) => {
    const opt = document.createElement("option");
    opt.value = String(vehicle.vehicleId || "");
    opt.textContent = formatVehicleOption(vehicle);
    opt.selected = index === 0;
    vehicleSelect.appendChild(opt);
  });
}

function formatVehicleOption(vehicle) {
  const brandModel = [vehicle.brand, vehicle.model].filter(Boolean).join(" ");
  return `${vehicle.plateNumber} - ${vehicle.vehicleType}${brandModel ? ` - ${brandModel}` : ""}`;
}

function renderVehicleCards() {
  if (!vehicleCardGrid) {
    return;
  }

  vehicleCardGrid.innerHTML = "";

  if (!currentVehicles.length) {
    vehicleCardGrid.innerHTML = `
      <div class="empty-state vehicle-empty-state">
        <div>
          <span class="empty-state-icon"><i class="bi bi-car-front-fill" aria-hidden="true"></i></span>
          <h3>No vehicles registered yet.</h3>
          <p>Please add a vehicle first before making a reservation.</p>
        </div>
      </div>
    `;
    return;
  }

  currentVehicles.forEach((vehicle) => {
    const card = document.createElement("article");
    card.className = "vehicle-card";
    card.innerHTML = `
      <div class="vehicle-card-top">
        <span class="vehicle-type-pill"><i class="bi bi-car-front-fill" aria-hidden="true"></i>${vehicle.vehicleType || "Vehicle"}</span>
        <strong>${vehicle.plateNumber || "--"}</strong>
      </div>
      <div class="vehicle-detail-grid">
        <div><span>Brand</span><strong>${vehicle.brand || "Not set"}</strong></div>
        <div><span>Model</span><strong>${vehicle.model || "Not set"}</strong></div>
        <div><span>Color</span><strong>${vehicle.color || "Not set"}</strong></div>
      </div>
    `;
    vehicleCardGrid.appendChild(card);
  });
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
      vehicleId: reservation.vehicleId,
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
        <span><i class="bi bi-car-front-fill" aria-hidden="true"></i> ${reservation.plateNumber || "Vehicle"}</span>
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

/**
 * Mirrors parking_assert_single_active_reservation in
 * backend/parking/common.php. A hold stands until the booth scans the barcode,
 * the driver cancels, or it expires — all three land the record on a display
 * status other than "Reserved".
 */
function findActiveReservationHold() {
  return getStoredReservations().find(
    (reservation) => getReservationDisplayStatus(reservation) === "Reserved"
  ) || null;
}

function buildActiveHoldMessage(hold) {
  const where = [hold?.floor, hold?.slot].filter(Boolean).join(" ");

  return `You are holding ${where || "a parking slot"}. Present its barcode at the parking booth, or cancel it from History, before reserving another slot.`;
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

function setVehicleError(fieldName, message) {
  const field = vehicleForm?.elements?.[fieldName];
  const group = field?.closest(".field-group");
  const errorElement = vehicleForm?.querySelector(`[data-vehicle-error-for="${fieldName}"]`);

  group?.classList.add("has-error");
  if (errorElement) {
    errorElement.textContent = message;
  }
}

function clearVehicleFormErrors() {
  vehicleForm?.querySelectorAll(".field-group").forEach((group) => {
    group.classList.remove("has-error");
  });
  vehicleForm?.querySelectorAll("[data-vehicle-error-for]").forEach((errorElement) => {
    errorElement.textContent = "";
  });
}

function isValidPlateNumber(value) {
  return /^[A-Z0-9-]{2,20}$/.test(String(value || "").trim().toUpperCase());
}

async function handleVehicleSubmit(event) {
  event.preventDefault();

  if (!vehicleForm || !vehicleFormStatus) {
    return;
  }

  clearVehicleFormErrors();
  const formData = new FormData(vehicleForm);
  const payload = {
    vehicleType: String(formData.get("vehicleType") || "").trim(),
    plateNumber: String(formData.get("plateNumber") || "").trim().toUpperCase(),
    brand: String(formData.get("brand") || "").trim(),
    model: String(formData.get("model") || "").trim(),
    color: String(formData.get("color") || "").trim()
  };

  let isValid = true;
  if (!payload.vehicleType) {
    setVehicleError("vehicleType", "Vehicle type is required.");
    isValid = false;
  }
  if (!payload.plateNumber) {
    setVehicleError("plateNumber", "Plate number is required.");
    isValid = false;
  } else if (!isValidPlateNumber(payload.plateNumber)) {
    setVehicleError("plateNumber", "Use 2-20 letters/numbers. Hyphens are allowed.");
    isValid = false;
  }

  if (!isValid) {
    vehicleFormStatus.textContent = "Please review the highlighted fields and try again.";
    vehicleFormStatus.className = "form-status is-error";
    return;
  }

  try {
    vehicleFormStatus.textContent = "Saving vehicle...";
    vehicleFormStatus.className = "form-status";

    const response = await fetch(USER_VEHICLES_API, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json"
      },
      credentials: "same-origin",
      body: JSON.stringify(payload)
    });
    const result = await parseJsonResponse(response, "vehicle save");

    if (!response.ok || result?.success === false) {
      throw new Error(result?.message || "Failed to save vehicle.");
    }

    await refreshVehiclesState();
    hydrateProfileUI();
    vehicleFormStatus.textContent = result?.message || "Vehicle saved successfully.";
    vehicleFormStatus.className = "form-status is-success";
    if (vehicleListStatus) {
      vehicleListStatus.textContent = "Vehicle saved successfully.";
      vehicleListStatus.className = "section-inline-status is-success";
    }
    window.setTimeout(closeVehicleModal, 450);
  } catch (error) {
    vehicleFormStatus.textContent = error.message || "Failed to save vehicle.";
    vehicleFormStatus.className = "form-status is-error";
  }
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
    vehicleBrand: profileFieldRefs.vehicleBrand.value.trim(),
    vehicleColor: profileFieldRefs.vehicleColor.value.trim(),
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
  drawBarcode(barcodeCanvas, reservation.short_code || reservation.barcode || reservation.reservationCode);
  summaryModal.classList.add("is-open");
  summaryModal.setAttribute("aria-hidden", "false");
  syncBodyModalState();
}

function fillSummaryDetails(reservation) {
  const shortCode = reservation.short_code || reservation.barcode || reservation.reservationCode;
  const fullReservationId = reservation.id || reservation.reservationId || '';
  document.getElementById("summary-code-text").textContent = fullReservationId ? ('Reservation #' + fullReservationId) : '';
  document.getElementById("barcode-caption").textContent = shortCode;
  document.getElementById("summary-full-name").textContent = reservation.fullName;
  document.getElementById("summary-email").textContent = reservation.email;
  document.getElementById("summary-floor").textContent = reservation.floor;
  document.getElementById("summary-slot").textContent = reservation.slot;
  document.getElementById("summary-vehicle").textContent = getReservationVehicleLabel(reservation);
  document.getElementById("summary-date").textContent = formatDate(reservation.reservationDate);
  document.getElementById("summary-time-in").textContent = formatTime(reservation.timeIn);
  document.getElementById("summary-time-out").textContent = reservation.timeOut ? formatTime(reservation.timeOut) : "Will be recorded at the parking booth";
  document.getElementById("summary-status").textContent = getReservationDisplayStatus(reservation);
  document.getElementById("summary-payment").textContent = `${formatCurrency(reservation.totalPayment)} base rate`;
}

function getReservationVehicleLabel(reservation) {
  const plate = reservation?.plateNumber || reservation?.plate_number || "";
  const type = reservation?.vehicleType || reservation?.vehicle_type || "";
  const brandModel = [reservation?.vehicleBrand, reservation?.vehicleModel].filter(Boolean).join(" ");

  if (!plate && !type && !brandModel) {
    return "Not specified";
  }

  return [plate, type, brandModel].filter(Boolean).join(" - ");
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
  const svg = document.getElementById('barcode-svg');

  const jsBarcodeOptions = {
    format: 'CODE128',
    displayValue: true,
    font: 'Montserrat, Arial, sans-serif',
    fontSize: 18,
    textMargin: 8,
    margin: 30,
    height: 150,
    background: '#ffffff',
    lineColor: '#000000',
    width: 4,
    flat: true
  };

  if (window.JsBarcode && svg) {
    try {
      // Adjust SVG intrinsic width to occupy ~78% of the container (no CSS scaling)
      try {
        const container = svg.parentElement;
        if (container && typeof container.clientWidth === 'number') {
          const desired = Math.max(200, Math.round(container.clientWidth * 0.78));
          svg.setAttribute('width', String(desired));
          if (canvas) {
            canvas.width = desired;
          }
        }
      } catch (e) {
        // ignore measurement errors
      }

      // Render SVG (primary for scanner compatibility)
      window.JsBarcode(svg, barcodeValue, jsBarcodeOptions);

      // Also render into the hidden canvas for PDF export
      if (canvas) {
        window.JsBarcode(canvas, barcodeValue, Object.assign({}, jsBarcodeOptions, { format: 'CODE128' }));
      }
      return;
    } catch (e) {
      // Fall through to canvas drawing fallback
      console.error('JsBarcode SVG render failed, falling back to canvas:', e);
    }
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
    vehicleId: record.vehicleId || fallbackReservation.vehicleId,
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
    reservationStatus: record.reservationStatus || record.reservation_status || record.status || fallbackReservation.reservationStatus || fallbackReservation.status,
    vehicleType: record.vehicleType || fallbackReservation.vehicleType,
    plateNumber: record.plateNumber || fallbackReservation.plateNumber,
    vehicleBrand: record.vehicleBrand || fallbackReservation.vehicleBrand,
    vehicleModel: record.vehicleModel || fallbackReservation.vehicleModel,
    vehicleColor: record.vehicleColor || fallbackReservation.vehicleColor
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

