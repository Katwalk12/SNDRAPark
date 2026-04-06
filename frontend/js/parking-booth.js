const POLLING_INTERVAL_MS = 3000;
const SETTINGS_REFRESH_INTERVAL_MS = 30000;
const DEFAULT_SYSTEM_SETTINGS = {
  parking_base_rate: 20,
  extra_hourly_rate: 10
};

function getProjectBasePath() {
  const pathSegments = window.location.pathname.split("/").filter(Boolean);
  const frontendIndex = pathSegments.indexOf("frontend");
  const backendIndex = pathSegments.indexOf("backend");
  const projectIndex = frontendIndex >= 0 ? frontendIndex : backendIndex >= 0 ? backendIndex : pathSegments.length;

  return projectIndex > 0 ? `/${pathSegments.slice(0, projectIndex).join("/")}` : "";
}

const PROJECT_BASE_PATH = window.SNDRA_PROJECT_BASE_PATH || getProjectBasePath();
const PHP_BOOTH_API_BASE = window.SNDRA_PARKING_BOOTH_API_BASE || `${window.location.origin}${PROJECT_BASE_PATH}/backend/parking-booth`;
const SYSTEM_SETTINGS_API = `${window.location.origin}${PROJECT_BASE_PATH}/backend/config/get-system-settings.php`;
const BOOTH_LOGIN_URL = `${window.location.origin}${PROJECT_BASE_PATH}/frontend/pages/booth-login.html`;
const BOOTH_LOGOUT_URL = `${PHP_BOOTH_API_BASE}/logout.php`;
const BOOTH_API_ENDPOINTS = {
  session: `${PHP_BOOTH_API_BASE}/session.php`,
  monitor: `${PHP_BOOTH_API_BASE}/realtime-monitor.php`,
  recent: `${PHP_BOOTH_API_BASE}/recent.php`,
  payment: `${PHP_BOOTH_API_BASE}/payment.php`,
  logs: `${PHP_BOOTH_API_BASE}/logs.php`
};

let currentTransaction = null;
let pollingTimer = null;
let settingsRefreshTimer = null;
let latestLogGroups = [];
const collapsedLogDates = new Set();
let boothSystemSettings = normalizeSystemSettings(DEFAULT_SYSTEM_SETTINGS);

const liveDateTime = document.getElementById("booth-live-datetime");
const boothSessionName = document.getElementById("booth-session-name");
const boothLogoutButton = document.getElementById("booth-logout-btn");
const navButtons = Array.from(document.querySelectorAll(".booth-nav-button[data-view-target]"));
const boothViews = Array.from(document.querySelectorAll(".booth-view"));

const monitorReservationsBody = document.getElementById("monitor-reservations-body");
const monitorTotalReservedToday = document.getElementById("monitor-total-reserved-today");
const monitorTotalActiveParked = document.getElementById("monitor-total-active-parked");
const monitorTotalUnpaid = document.getElementById("monitor-total-unpaid");
const monitorTotalPaidToday = document.getElementById("monitor-total-paid-today");

const scanForm = document.getElementById("scan-form");
const barcodeInput = document.getElementById("barcode-input");
const scanButton = document.getElementById("scan-button");
const scannerHint = document.getElementById("scanner-hint");
const statusChip = document.getElementById("status-chip");
const statusCopy = document.getElementById("status-copy");
const latestBarcode = document.getElementById("latest-barcode");
const latestUpdated = document.getElementById("latest-updated");
const markPaidButton = document.getElementById("mark-paid-btn");
const clearTransactionButton = document.getElementById("clear-transaction-btn");
const printReceiptButton = document.getElementById("print-receipt-btn");
const actionNote = document.getElementById("action-note");
const recentActivityBody = document.getElementById("recent-activity-body");
let scannerSubmitTimer = null;

const logsOverallTotal = document.getElementById("logs-overall-total");
const logsGroupCount = document.getElementById("logs-group-count");
const logGroupsContainer = document.getElementById("log-groups");

const detailRefs = {
  barcode: document.getElementById("detail-barcode"),
  fullName: document.getElementById("detail-full-name"),
  email: document.getElementById("detail-email"),
  floor: document.getElementById("detail-floor"),
  slot: document.getElementById("detail-slot"),
  reservationDate: document.getElementById("detail-reservation-date"),
  reservedTimeIn: document.getElementById("detail-reserved-time-in"),
  reservedTimeOut: document.getElementById("detail-reserved-time-out"),
  reservedDuration: document.getElementById("detail-reserved-duration"),
  actualTimeIn: document.getElementById("detail-actual-time-in"),
  actualTimeOut: document.getElementById("detail-actual-time-out"),
  actualDuration: document.getElementById("detail-actual-duration"),
  totalHours: document.getElementById("detail-total-hours"),
  reservationFee: document.getElementById("detail-reservation-fee"),
  overtimeFee: document.getElementById("detail-overtime-fee"),
  totalPayment: document.getElementById("detail-total-payment"),
  paymentStatus: document.getElementById("detail-payment-status"),
  boothStatus: document.getElementById("detail-booth-status")
};

document.addEventListener("DOMContentLoaded", async () => {
  await ensureSystemSettingsLoaded();
  const session = await ensureBoothSession(BOOTH_LOGIN_URL);

  if (!session) {
    return;
  }

  if (boothSessionName) {
    boothSessionName.textContent = session.fullName || session.email || "Booth Teller";
  }

  startLiveClock();
  bindNavigation();
  bindEvents();
  resetTransactionPanel();
  syncViewFromLocation();
  await pollRealtimeData();
  startPolling();
  startSettingsRefresh();
  barcodeInput?.focus();
});

function normalizeSystemSettings(settings) {
  const source = settings && typeof settings === "object" ? settings : {};

  return {
    parking_base_rate: Number.isFinite(Number(source.parking_base_rate))
      ? Number(source.parking_base_rate)
      : DEFAULT_SYSTEM_SETTINGS.parking_base_rate,
    extra_hourly_rate: Number.isFinite(Number(source.extra_hourly_rate))
      ? Number(source.extra_hourly_rate)
      : DEFAULT_SYSTEM_SETTINGS.extra_hourly_rate
  };
}

async function ensureSystemSettingsLoaded() {
  if (typeof window.ensureSndraSystemSettingsLoaded === "function") {
    boothSystemSettings = normalizeSystemSettings(await window.ensureSndraSystemSettingsLoaded());
    return;
  }

  boothSystemSettings = await fetchSystemSettingsFromBackend();
}

function getSystemSettingsSnapshot() {
  if (typeof window.getSndraSystemSettings === "function") {
    return normalizeSystemSettings(window.getSndraSystemSettings());
  }

  return boothSystemSettings;
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

    return normalizeSystemSettings(result?.data?.settings || {});
  } catch (error) {
    return normalizeSystemSettings(DEFAULT_SYSTEM_SETTINGS);
  }
}

function startSettingsRefresh() {
  if (settingsRefreshTimer) {
    window.clearInterval(settingsRefreshTimer);
  }

  settingsRefreshTimer = window.setInterval(async () => {
    boothSystemSettings = await fetchSystemSettingsFromBackend();
  }, SETTINGS_REFRESH_INTERVAL_MS);
}

function bindNavigation() {
  navButtons.forEach((button) => {
    button.addEventListener("click", () => {
      const targetId = button.dataset.viewTarget;

      activateView(targetId);
    });
  });
}

function syncViewFromLocation() {
  const hashTarget = window.location.hash.replace("#", "").trim();

  if (hashTarget) {
    activateView(hashTarget);
  }
}

function activateView(targetId) {
  if (!targetId) {
    return;
  }

  const matchedButton = navButtons.find((button) => button.dataset.viewTarget === targetId);
  const hasMatchingView = boothViews.some((view) => view.id === targetId);

  if (!matchedButton || !hasMatchingView) {
    return;
  }

  navButtons.forEach((item) => item.classList.toggle("active", item === matchedButton));
  boothViews.forEach((view) => view.classList.toggle("active", view.id === targetId));
  window.location.hash = targetId;

  if (targetId === "payment-view") {
    barcodeInput?.focus();
  }
}

function bindEvents() {
  scanForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    await handleBarcodeScan();
  });

  barcodeInput?.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      scanForm?.requestSubmit();
    }
  });

  barcodeInput?.addEventListener("input", (event) => {
    const rawValue = String(barcodeInput.value || "");
    const normalizedValue = normalizeBarcodeValue(rawValue);
    const scannerTerminated = /[\r\n\t]/.test(rawValue) || event.inputType === "insertFromPaste";

    barcodeInput.value = normalizedValue;

    if (scannerTerminated && normalizedValue) {
      scheduleScannerSubmit();
    }
  });

  barcodeInput?.addEventListener("paste", (event) => {
    const pastedText = event.clipboardData?.getData("text") || "";

    if (!pastedText) {
      return;
    }

    event.preventDefault();
    barcodeInput.value = normalizeBarcodeValue(pastedText);
    scheduleScannerSubmit();
  });

  markPaidButton?.addEventListener("click", handleMarkAsPaid);
  clearTransactionButton?.addEventListener("click", clearCurrentTransaction);
  printReceiptButton?.addEventListener("click", handlePrintReceipt);
  boothLogoutButton?.addEventListener("click", () => {
    logoutTo(BOOTH_LOGIN_URL);
  });

  logGroupsContainer?.addEventListener("click", (event) => {
    const toggleButton = event.target.closest("[data-log-date]");

    if (!toggleButton) {
      return;
    }

    const dateKey = toggleButton.dataset.logDate;

    if (!dateKey) {
      return;
    }

    if (collapsedLogDates.has(dateKey)) {
      collapsedLogDates.delete(dateKey);
    } else {
      collapsedLogDates.add(dateKey);
    }

    renderLogGroups(latestLogGroups);
  });
}

function startLiveClock() {
  updateLiveClock();
  window.setInterval(updateLiveClock, 1000);
}

function updateLiveClock() {
  if (!liveDateTime) {
    return;
  }

  liveDateTime.textContent = new Date().toLocaleString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit"
  });
}

function startPolling() {
  if (pollingTimer) {
    window.clearInterval(pollingTimer);
  }

  pollingTimer = window.setInterval(() => {
    pollRealtimeData().catch((error) => {
      console.error("Fetch error:", error);
    });
  }, POLLING_INTERVAL_MS);
}

async function pollRealtimeData() {
  console.log("Fetching realtime data...", BOOTH_API_ENDPOINTS);
  await Promise.allSettled([
    loadMonitorData(),
    loadRecentActivity(),
    loadLogData()
  ]);
}

async function loadMonitorData() {
  try {
    const result = await apiRequest(BOOTH_API_ENDPOINTS.monitor, {
      method: "GET"
    });
    console.log("Realtime monitor response:", result);

    const records = Array.isArray(result?.data?.records)
      ? result.data.records.map((record) => normalizeReservationRecord(record)).filter(Boolean)
      : [];

    renderMonitorSummary(result?.data?.summary || {});
    renderMonitorTable(records);
  } catch (error) {
    console.error("Fetch error:", error);
    renderMonitorSummary({});
    renderMonitorTable([]);
  }
}

function renderMonitorSummary(summary) {
  if (monitorTotalReservedToday) {
    monitorTotalReservedToday.textContent = String(summary.reserved_today || 0);
  }

  if (monitorTotalActiveParked) {
    monitorTotalActiveParked.textContent = String(summary.active_parked || 0);
  }

  if (monitorTotalUnpaid) {
    monitorTotalUnpaid.textContent = String(summary.unpaid || 0);
  }

  if (monitorTotalPaidToday) {
    monitorTotalPaidToday.textContent = String(summary.paid_today || 0);
  }
}

function renderMonitorTable(records) {
  if (!monitorReservationsBody) {
    return;
  }

  if (!records.length) {
    monitorReservationsBody.innerHTML = `<tr><td colspan="8" class="empty-table">No reserved parking records available.</td></tr>`;
    return;
  }

  monitorReservationsBody.innerHTML = records.map((record) => `
    <tr>
      <td>${escapeHtml(record.barcode)}</td>
      <td>${escapeHtml(record.fullName || "--")}</td>
      <td>${escapeHtml(record.email || "--")}</td>
      <td>${escapeHtml(record.floor || "--")}</td>
      <td>${escapeHtml(record.slot || "--")}</td>
      <td>${escapeHtml(formatDate(record.reservationDate))}</td>
      <td>${escapeHtml(formatReservedWindow(record))}</td>
      <td><span class="table-status ${mapToneFromStatuses(record.paymentStatus, record.boothStatus)}">${escapeHtml(getDisplayStatus(record))}</span></td>
    </tr>
  `).join("");
}

async function handleBarcodeScan() {
  const barcode = normalizeBarcodeValue(barcodeInput?.value || "");

  if (!barcode) {
    setBoothStatus("danger", "Invalid Barcode", "Please scan or enter a barcode value first.");
    barcodeInput?.focus();
    return;
  }

  setScannerBusy(true);

  try {
    const result = await apiRequest(BOOTH_API_ENDPOINTS.payment, {
      method: "POST",
      body: {
        action: "scan",
        barcode
      }
    });

    currentTransaction = normalizeReservationRecord(result.data);
    renderTransactionDetails(currentTransaction);
    setBoothStatus(
      mapToneFromStatuses(currentTransaction.paymentStatus, currentTransaction.boothStatus),
      currentTransaction.statusLabel || "Scan Success",
      result.message || "Barcode processed successfully."
    );
    await pollRealtimeData();
  } catch (error) {
    const failedTransaction = normalizeReservationRecord(error?.payload?.data?.transaction || error?.payload?.data);

    if (failedTransaction) {
      currentTransaction = failedTransaction;
      renderTransactionDetails(failedTransaction);
    }

    setBoothStatus(
      failedTransaction ? mapToneFromStatuses(failedTransaction.paymentStatus, failedTransaction.boothStatus) : "danger",
      failedTransaction?.statusLabel || "Scan Error",
      error.message || "Unable to process the barcode right now."
    );
  } finally {
    setScannerBusy(false);

    if (barcodeInput) {
      barcodeInput.value = "";
      barcodeInput.focus();
    }
  }
}

async function handleMarkAsPaid() {
  if (!currentTransaction?.reservationId) {
    setBoothStatus("danger", "No Transaction", "Scan a reservation barcode before processing payment.");
    return;
  }

  if (!currentTransaction.actualTimeOut) {
    setBoothStatus("warning", "Time Out Required", "Record Time Out first before marking payment as paid.");
    return;
  }

  markPaidButton.disabled = true;

  try {
    const result = await apiRequest(BOOTH_API_ENDPOINTS.payment, {
      method: "POST",
      body: {
        action: "mark_paid",
        reservationId: currentTransaction.reservationId
      }
    });

    currentTransaction = normalizeReservationRecord(result.data);
    renderTransactionDetails(currentTransaction);
    setBoothStatus("success", "Paid", result.message || "Payment completed successfully.");
    await pollRealtimeData();
  } catch (error) {
    const failedTransaction = normalizeReservationRecord(error?.payload?.data?.transaction || error?.payload?.data);

    if (failedTransaction) {
      currentTransaction = failedTransaction;
      renderTransactionDetails(failedTransaction);
    }

    setBoothStatus(
      failedTransaction ? mapToneFromStatuses(failedTransaction.paymentStatus, failedTransaction.boothStatus) : "danger",
      failedTransaction?.statusLabel || "Payment Error",
      error.message || "Unable to complete the payment right now."
    );
  } finally {
    markPaidButton.disabled = false;
  }
}

async function loadRecentActivity() {
  try {
    const result = await apiRequest(BOOTH_API_ENDPOINTS.recent, {
      method: "GET"
    });
    console.log("Recent activity response:", result);

    const records = Array.isArray(result?.data?.records)
      ? result.data.records.map((record) => normalizeActivityRecord(record)).filter(Boolean)
      : [];

    renderRecentActivity(records);
  } catch (error) {
    console.error("Fetch error:", error);
    renderRecentActivity([]);
  }
}

function renderRecentActivity(records) {
  if (!recentActivityBody) {
    return;
  }

  if (!records.length) {
    recentActivityBody.innerHTML = `<tr><td colspan="7" class="empty-table">No scanned activity yet.</td></tr>`;
    return;
  }

  recentActivityBody.innerHTML = records.map((record) => `
    <tr>
      <td>${escapeHtml(record.barcode)}</td>
      <td>${escapeHtml(record.fullName || "Walk-in Customer")}</td>
      <td>${escapeHtml(`${record.floor || "--"} / ${record.slot || "--"}`)}</td>
      <td>${formatDateTime(record.actualTimeIn)}</td>
      <td>${formatDateTime(record.actualTimeOut)}</td>
      <td>${formatCurrency(record.totalPayment || record.reservationFee || 0)}</td>
      <td><span class="table-status ${mapToneFromStatuses(record.paymentStatus, record.statusLabel)}">${escapeHtml(record.statusLabel || getDisplayStatus(record))}</span></td>
    </tr>
  `).join("");
}

async function loadLogData() {
  try {
    const result = await apiRequest(BOOTH_API_ENDPOINTS.logs, {
      method: "GET"
    });
    console.log("Logs response:", result);

    const groups = Array.isArray(result?.data?.groups)
      ? result.data.groups.map((group) => ({
        ...group,
        logs: Array.isArray(group.logs)
          ? group.logs.map((record) => normalizeLogRecord(record)).filter(Boolean)
          : []
      }))
      : [];

    latestLogGroups = groups;

    if (logsOverallTotal) {
      logsOverallTotal.textContent = formatCurrency(result?.data?.overallTotalPayment || 0);
    }

    if (logsGroupCount) {
      logsGroupCount.textContent = String(groups.length);
    }

    renderLogGroups(groups);
  } catch (error) {
    console.error("Fetch error:", error);
    latestLogGroups = [];

    if (logsOverallTotal) {
      logsOverallTotal.textContent = formatCurrency(0);
    }

    if (logsGroupCount) {
      logsGroupCount.textContent = "0";
    }

    renderLogGroups([]);
  }
}

function renderLogGroups(groups) {
  if (!logGroupsContainer) {
    return;
  }

  if (!groups.length) {
    logGroupsContainer.innerHTML = `
      <section class="booth-card log-empty-state">
        <p class="card-copy">No booth logs available yet.</p>
      </section>
    `;
    return;
  }

  logGroupsContainer.innerHTML = groups.map((group) => {
    const dateKey = group.date || group.displayDate || "unknown";
    const isCollapsed = collapsedLogDates.has(dateKey);

    return `
      <section class="booth-card log-group">
        <div class="log-group-header">
          <div class="log-group-copy">
            <p class="section-kicker">Log Date</p>
            <h3>${escapeHtml(group.dateLabel || dateKey)}</h3>
            <p>${escapeHtml(`${group.logs.length} log entr${group.logs.length === 1 ? "y" : "ies"}`)}</p>
          </div>
          <div class="log-group-actions">
            <div class="log-group-total">
              Daily Total
              <strong>${formatCurrency(group.dailyTotal || 0)}</strong>
            </div>
            <button class="log-toggle-btn" type="button" data-log-date="${escapeHtml(dateKey)}" aria-label="Toggle log group">
              <span class="log-toggle-icon">${isCollapsed ? "+" : "-"}</span>
            </button>
          </div>
        </div>
        <div class="log-group-body ${isCollapsed ? "is-collapsed" : ""}">
          <div class="table-shell">
              <table class="booth-table">
                <thead>
                  <tr>
                    <th>Time</th>
                    <th>Role</th>
                    <th>Actor</th>
                    <th>Action</th>
                    <th>Description</th>
                    <th>Barcode</th>
                    <th>Floor</th>
                    <th>Slot</th>
                    <th>Amount</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  ${group.logs.map((record) => `
                    <tr>
                      <td>${escapeHtml(formatDateTime(record.logTime))}</td>
                      <td><span class="table-status ready">${escapeHtml(record.actorRole || "--")}</span></td>
                      <td>${escapeHtml(record.actorName || "--")}</td>
                      <td>${escapeHtml(record.logType || "--")}</td>
                      <td>${escapeHtml(record.description || "--")}</td>
                      <td>${escapeHtml(record.barcode)}</td>
                      <td>${escapeHtml(record.floor || "--")}</td>
                      <td>${escapeHtml(record.slot || "--")}</td>
                      <td>${formatCurrency(record.totalPayment || 0)}</td>
                      <td><span class="table-status ${mapToneFromStatuses(record.statusLabel, record.statusLabel)}">${escapeHtml(record.statusLabel || "--")}</span></td>
                    </tr>
                  `).join("")}
                </tbody>
              </table>
          </div>
        </div>
      </section>
    `;
  }).join("");
}

function renderTransactionDetails(transaction) {
  if (!transaction) {
    resetTransactionPanel();
    return;
  }

  const reservedDurationHours = calculateReservedHours(transaction);
  const actualDurationHours = calculateActualHours(transaction.actualTimeIn, transaction.actualTimeOut);

  detailRefs.barcode.textContent = transaction.barcode || "--";
  detailRefs.fullName.textContent = transaction.fullName || "--";
  detailRefs.email.textContent = transaction.email || "--";
  detailRefs.floor.textContent = transaction.floor || "--";
  detailRefs.slot.textContent = transaction.slot || "--";
  detailRefs.reservationDate.textContent = formatDate(transaction.reservationDate);
  detailRefs.reservedTimeIn.textContent = formatTimeValue(transaction.reservedTimeIn);
  detailRefs.reservedTimeOut.textContent = formatTimeValue(transaction.reservedTimeOut);
  detailRefs.reservedDuration.textContent = formatHoursLabel(reservedDurationHours);
  detailRefs.actualTimeIn.textContent = formatDateTime(transaction.actualTimeIn);
  detailRefs.actualTimeOut.textContent = formatDateTime(transaction.actualTimeOut);
  detailRefs.actualDuration.textContent = formatHoursLabel(actualDurationHours);
  detailRefs.totalHours.textContent = formatHoursLabel(transaction.totalHours || actualDurationHours);
  detailRefs.reservationFee.textContent = formatCurrency(transaction.reservationFee || 0);
  detailRefs.overtimeFee.textContent = formatCurrency(transaction.overtimeFee || 0);
  detailRefs.totalPayment.textContent = formatCurrency(transaction.totalPayment || 0);
  detailRefs.paymentStatus.textContent = transaction.paymentStatus || "--";
  detailRefs.boothStatus.textContent = transaction.boothStatus || "--";

  latestBarcode.textContent = transaction.barcode || "No barcode yet";
  latestUpdated.textContent = formatDateTime(transaction.lastUpdatedAt || transaction.paidAt || transaction.actualTimeOut || transaction.actualTimeIn);
  actionNote.textContent = transaction.paymentStatus === "Unpaid"
    ? "Transaction is waiting for payment confirmation. Click Mark as Paid after collecting the payment."
    : "Scan a barcode to record Time In or Time Out.";

  const canMarkPaid = Boolean(transaction.actualTimeOut) && transaction.paymentStatus !== "Paid" && transaction.boothStatus !== "Completed";
  markPaidButton.disabled = !canMarkPaid;
  printReceiptButton.disabled = !transaction.actualTimeOut;
}

function resetTransactionPanel() {
  currentTransaction = null;
  const currencyFieldIds = new Set([
    "detail-reservation-fee",
    "detail-overtime-fee",
    "detail-total-payment"
  ]);

  Object.values(detailRefs).forEach((element) => {
    if (!element) {
      return;
    }

    element.textContent = currencyFieldIds.has(element.id) ? "PHP 0.00" : "--";
  });

  latestBarcode.textContent = "No barcode yet";
  latestUpdated.textContent = "Not available";
  markPaidButton.disabled = true;
  printReceiptButton.disabled = true;
  actionNote.textContent = "Scan a valid reservation barcode to begin Time In or Time Out processing.";
  setBoothStatus("ready", "Ready to Scan", "Waiting for a barcode scan from the teller booth.");
}

function clearCurrentTransaction() {
  resetTransactionPanel();
  barcodeInput?.focus();
}

function handlePrintReceipt() {
  if (!currentTransaction?.actualTimeOut) {
    setBoothStatus("warning", "Receipt Not Ready", "Complete Time Out first before printing a receipt.");
    return;
  }

  setBoothStatus("ready", "Receipt Placeholder", "Receipt printing can be connected to your booth printer next.");
}

function scheduleScannerSubmit(delayMs = 80) {
  if (!scanForm || !barcodeInput) {
    return;
  }

  if (scannerSubmitTimer) {
    window.clearTimeout(scannerSubmitTimer);
  }

  scannerSubmitTimer = window.setTimeout(() => {
    scannerSubmitTimer = null;

    if (!normalizeBarcodeValue(barcodeInput.value || "")) {
      return;
    }

    scanForm.requestSubmit();
  }, delayMs);
}

function setBoothStatus(tone, label, message) {
  if (statusChip) {
    statusChip.className = `status-chip ${tone}`;
    statusChip.textContent = label;
  }

  if (statusCopy) {
    statusCopy.textContent = message;
  }

  if (scannerHint) {
    scannerHint.textContent = message;
  }
}

function setScannerBusy(isBusy) {
  if (scanButton) {
    scanButton.disabled = isBusy;
    scanButton.textContent = isBusy ? "Processing Scan..." : "Scan Barcode";
  }

  if (barcodeInput) {
    barcodeInput.disabled = isBusy;
  }
}

async function apiRequest(url, options = {}) {
  const fetchOptions = {
    method: options.method || "GET",
    credentials: "same-origin",
    headers: {
      ...(options.body ? { "Content-Type": "application/json" } : {}),
      ...(options.headers || {})
    }
  };

  if (options.body) {
    fetchOptions.body = JSON.stringify(options.body);
  }

  let response;

  try {
    response = await fetch(url, fetchOptions);
  } catch (networkError) {
    console.error("Fetch error:", networkError);
    throw buildClientError("Unable to reach the PHP parking booth backend. Check Apache/XAMPP and the endpoint URL.", 0, null);
  }

  const payload = await parseJsonResponse(response);
  console.log("API payload:", {
    url,
    status: response.status,
    ok: response.ok,
    payload
  });

  if (!response.ok || payload?.success === false) {
    if (response.status === 401) {
      window.location.replace(BOOTH_LOGIN_URL);
    }
    throw buildClientError(payload?.message || `Request failed with status ${response.status}.`, response.status, payload);
  }

  return payload;
}

function buildClientError(message, status, payload) {
  const error = new Error(message);
  error.status = status;
  error.payload = payload || null;
  return error;
}

async function parseJsonResponse(response) {
  const rawText = await response.text();

  if (!rawText.trim()) {
    return {};
  }

  try {
    return JSON.parse(rawText);
  } catch (error) {
    throw buildClientError("The backend returned an invalid JSON response.", response.status, {
      rawText
    });
  }
}

function normalizeReservationRecord(record) {
  if (!record || typeof record !== "object") {
    return null;
  }

  const paymentStatus = record.paymentStatus || record.payment_status || "Reserved";
  const boothStatus = record.boothStatus || record.booth_status || "Reserved";
  const reservationStatus = record.reservationStatus || record.reservation_status || record.status || "Reserved";
  const barcodeStatus = String(record.barcodeStatus || record.barcode_status || "active").toLowerCase();
  const effectiveBoothStatus = barcodeStatus === "expired" ? "Expired" : boothStatus;

  return {
    reservationId: Number(record.reservationId || record.reservation_id || record.id || 0) || null,
    userId: Number(record.userId || record.user_id || 0) || null,
    barcode: normalizeBarcodeValue(record.barcode || record.barcodeValue || record.barcode_value || ""),
    fullName: record.fullName || record.full_name || "--",
    email: record.email || "--",
    floor: record.floor || record.parkingFloor || record.parking_floor || "--",
    slot: record.slot || record.parkingSlot || record.parking_slot || "--",
    reservationDate: record.reservationDate || record.reservation_date || "",
    reservedTimeIn: record.reservedTimeIn || record.reserved_time_in || "",
    reservedTimeOut: record.reservedTimeOut || record.reserved_time_out || "",
    reservationFee: toCurrencyNumber(record.reservationFee ?? record.reservation_fee ?? 0),
    actualTimeIn: record.actualTimeIn || record.actual_time_in || null,
    actualTimeOut: record.actualTimeOut || record.actual_time_out || null,
    totalHours: toCurrencyNumber(record.totalHours ?? record.total_hours ?? record.totalHoursStayed ?? record.total_hours_stayed ?? 0),
    overtimeFee: toCurrencyNumber(record.overtimeFee ?? record.overtime_fee ?? record.extraFee ?? record.extra_fee ?? 0),
    totalPayment: toCurrencyNumber(record.totalPayment ?? record.total_payment ?? 0),
    paymentStatus,
    boothStatus: effectiveBoothStatus,
    reservationStatus,
    barcodeStatus,
    paidAt: record.paidAt || record.paid_at || null,
    lastUpdatedAt: record.lastUpdatedAt || record.last_updated_at || record.updated_at || record.paidAt || record.actualTimeOut || record.actualTimeIn || null,
    statusLabel: record.statusLabel || deriveStatusLabel(paymentStatus, effectiveBoothStatus, barcodeStatus, record.actualTimeIn || record.actual_time_in, record.actualTimeOut || record.actual_time_out)
  };
}

function normalizeActivityRecord(record) {
  if (!record || typeof record !== "object") {
    return null;
  }

  return {
    barcode: normalizeBarcodeValue(record.barcode || record.barcodeValue || record.barcode_value || ""),
    fullName: record.fullName || record.full_name || "--",
    email: record.email || "--",
    floor: record.floor || record.parkingFloor || record.parking_floor || "--",
    slot: record.slot || record.parkingSlot || record.parking_slot || "--",
    actualTimeIn: record.actualTimeIn || record.actual_time_in || null,
    actualTimeOut: record.actualTimeOut || record.actual_time_out || null,
    totalPayment: toCurrencyNumber(record.totalPayment ?? record.total_payment ?? 0),
    paymentStatus: record.paymentStatus || record.payment_status || "--",
    statusLabel: record.statusLabel || record.status_label || record.logType || record.log_type || "--",
    logType: record.logType || record.log_type || "--",
    logTime: record.logTime || record.log_time || null
  };
}

function normalizeLogRecord(record) {
  if (!record || typeof record !== "object") {
    return null;
  }

  return {
    logTime: record.logTime || record.log_time || null,
    logType: record.logType || record.log_type || "--",
    actorRole: record.actorRole || record.actor_role || "--",
    actorName: record.actorName || record.actor_name || "--",
    description: record.description || "--",
    barcode: normalizeBarcodeValue(record.barcode || record.barcodeValue || record.barcode_value || ""),
    floor: record.floor || record.parkingFloor || record.parking_floor || "--",
    slot: record.slot || record.parkingSlot || record.parking_slot || "--",
    totalPayment: toCurrencyNumber(record.totalPayment ?? record.total_payment ?? 0),
    statusLabel: record.statusLabel || record.status_label || "--"
  };
}

function deriveStatusLabel(paymentStatus, boothStatus, barcodeStatus, actualTimeIn, actualTimeOut) {
  if (barcodeStatus === "expired") {
    return "Expired Barcode";
  }

  if (paymentStatus === "Paid" || boothStatus === "Completed") {
    return "Paid";
  }

  if (actualTimeOut) {
    return "Time Out Recorded";
  }

  if (actualTimeIn) {
    return "Time In Recorded";
  }

  return "Ready to Scan";
}

function getDisplayStatus(record) {
  const reservationStatus = String(record?.reservationStatus || "").trim();
  const paymentStatus = String(record?.paymentStatus || "").trim();
  const boothStatus = String(record?.boothStatus || "").trim();
  const barcodeStatus = String(record?.barcodeStatus || "").trim().toLowerCase();

  if (barcodeStatus === "expired") {
    return "Expired";
  }

  if (paymentStatus === "Paid" || boothStatus === "Completed" || reservationStatus === "Completed" || reservationStatus === "Paid") {
    return "Paid";
  }

  if (paymentStatus === "Unpaid" || boothStatus === "Exited" || boothStatus === "Unpaid" || reservationStatus === "Unpaid") {
    return "Unpaid";
  }

  if (boothStatus === "Parked" || reservationStatus === "Parked" || (record?.actualTimeIn && !record?.actualTimeOut)) {
    return "Parked";
  }

  return reservationStatus || boothStatus || paymentStatus || "Reserved";
}

function calculatePricingFromReservation(reservationDate, timeIn, timeOut) {
  if (!reservationDate || !timeIn || !timeOut) {
    return { hours: 0, total: 0 };
  }

  return calculatePricingFromDateObjects(
    new Date(`${reservationDate}T${timeIn}`),
    new Date(`${reservationDate}T${timeOut}`)
  );
}

function calculatePricingFromDateTimes(startValue, endValue) {
  if (!startValue || !endValue) {
    return { hours: 0, total: 0 };
  }

  return calculatePricingFromDateObjects(
    new Date(String(startValue).replace(" ", "T")),
    new Date(String(endValue).replace(" ", "T"))
  );
}

function calculatePricingFromDateObjects(start, end) {
  const diffMs = end.getTime() - start.getTime();

  if (!Number.isFinite(diffMs) || diffMs <= 0) {
    return { hours: 0, total: 0 };
  }

  const hours = Math.max(1, Math.ceil(diffMs / (1000 * 60 * 60)));
  const settings = getSystemSettingsSnapshot();
  const total = hours <= 3
    ? settings.parking_base_rate
    : settings.parking_base_rate + ((hours - 3) * settings.extra_hourly_rate);

  return { hours, total };
}

function calculateReservedHours(transaction) {
  return calculatePricingFromReservation(transaction.reservationDate, transaction.reservedTimeIn, transaction.reservedTimeOut).hours;
}

function calculateActualHours(actualTimeIn, actualTimeOut) {
  return calculatePricingFromDateTimes(actualTimeIn, actualTimeOut).hours;
}

function formatDate(value) {
  if (!value) {
    return "--";
  }

  return new Date(value).toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric"
  });
}

function formatDateTime(value) {
  if (!value) {
    return "--";
  }

  return new Date(String(value).replace(" ", "T")).toLocaleString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit"
  });
}

function formatLogEventTime(record) {
  const eventValue = record.paidAt || record.actualTimeOut || record.actualTimeIn || record.lastUpdatedAt;

  if (!eventValue) {
    return "--";
  }

  return new Date(String(eventValue).replace(" ", "T")).toLocaleTimeString("en-PH", {
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit"
  });
}

function formatTimeValue(value) {
  if (!value) {
    return "--";
  }

  const [hours, minutes] = String(value).split(":");
  const date = new Date();
  date.setHours(Number(hours), Number(minutes), 0, 0);
  return date.toLocaleTimeString("en-PH", {
    hour: "numeric",
    minute: "2-digit"
  });
}

function formatReservedWindow(record) {
  const timeIn = formatTimeValue(record.reservedTimeIn);
  const timeOut = formatTimeValue(record.reservedTimeOut);

  if (timeIn !== "--" && timeOut !== "--") {
    return `${timeIn} - ${timeOut}`;
  }

  return timeIn;
}

function formatCurrency(amount) {
  return `PHP ${toCurrencyNumber(amount).toFixed(2)}`;
}

function formatHoursLabel(hours) {
  if (!hours) {
    return "--";
  }

  return `${hours} hour${Number(hours) === 1 ? "" : "s"}`;
}

function mapToneFromStatuses(paymentStatus, boothStatus) {
  if (String(boothStatus || "").toLowerCase() === "expired") {
    return "danger";
  }

  if (paymentStatus === "Paid" || boothStatus === "Completed" || boothStatus === "Paid") {
    return "success";
  }

  if (paymentStatus === "Unpaid" || boothStatus === "Unpaid" || boothStatus === "Exited") {
    return "warning";
  }

  if (boothStatus === "Parked") {
    return "success";
  }

  return "ready";
}

function toCurrencyNumber(value) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
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

async function ensureBoothSession(loginPath) {
  try {
    const result = await apiRequest(BOOTH_API_ENDPOINTS.session, {
      method: "GET"
    });
    return result?.data || null;
  } catch (error) {
    if (error?.status === 401) {
      window.location.replace(loginPath);
      return null;
    }

    throw error;
  }
}

async function logoutTo(loginPath) {
  if (settingsRefreshTimer) {
    window.clearInterval(settingsRefreshTimer);
    settingsRefreshTimer = null;
  }

  try {
    await fetch(BOOTH_LOGOUT_URL, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json"
      }
    });
  } catch (error) {
    console.error("Booth logout request failed:", error);
  }
  window.location.replace(loginPath);
}
