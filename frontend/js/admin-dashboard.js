const STAFF_SESSION_KEY = "sndraStaffSession";
const ADMIN_API_BASE = typeof window.getSndraBackendUrl === "function"
  ? window.getSndraBackendUrl("/backend/admin")
  : `${window.location.origin}/backend/admin`;
const ADMIN_LOGIN_ROUTE = typeof window.getSndraRoutePath === "function"
  ? window.getSndraRoutePath("adminLogin")
  : "./admin-login.html";
const ADMIN_ENDPOINTS = {
  dashboard: `${ADMIN_API_BASE}/get_dashboard_summary.php`,
  floors: `${ADMIN_API_BASE}/get_floors.php`,
  addFloor: `${ADMIN_API_BASE}/add_floor.php`,
  deleteFloor: `${ADMIN_API_BASE}/delete_floor.php`,
  slots: `${ADMIN_API_BASE}/manage_slots.php`,
  deleteSlot: `${ADMIN_API_BASE}/delete_slot.php`,
  reservations: `${ADMIN_API_BASE}/get_reservations.php`,
  liveReservations: `${ADMIN_API_BASE}/get_live_reservations.php`,
  users: `${ADMIN_API_BASE}/get_users.php`,
  staff: `${ADMIN_API_BASE}/manage_booth_staff.php`,
  payments: `${ADMIN_API_BASE}/get_payments.php`,
  logs: `${ADMIN_API_BASE}/get_logs.php`,
  feedback: `${ADMIN_API_BASE}/get_feedback.php`,
  replyFeedback: `${ADMIN_API_BASE}/reply_feedback.php`,
  notifications: `${ADMIN_API_BASE}/manage_notifications.php`,
  userViolations: `${ADMIN_API_BASE}/get-user-violations.php`,
  settings: `${ADMIN_API_BASE}/save_settings.php`,
  logout: `${ADMIN_API_BASE}/logout.php`
};

const SECTION_META = {
  dashboard: {
    kicker: "Administrator View",
    title: "Dashboard",
    description: "Operational summary and live activity across the configured parking system."
  },
  slots: {
    kicker: "Parking Control",
    title: "Manage Floors & Slots",
    description: "Create floors, add slot codes, and adjust slot availability for the parking layout."
  },
  reservations: {
    kicker: "Reservation Center",
    title: "Reservations",
    description: "Search reservation records, review status flow, and inspect transaction details."
  },
  users: {
    kicker: "Account Management",
    title: "Users",
    description: "Review, update, disable, or remove registered user accounts."
  },
  staff: {
    kicker: "Staff Access",
    title: "Booth Staff",
    description: "Manage booth staff and administrator accounts stored in MySQL."
  },
  payments: {
    kicker: "Cashier Overview",
    title: "Payments",
    description: "Monitor paid and unpaid transaction records and total income."
  },
  logs: {
    kicker: "Audit Trail",
    title: "Admin Audit Logs",
    description: "Review immutable admin login and action records grouped by date and refreshed automatically."
  },
  feedback: {
    kicker: "Inbox",
    title: "Feedback / Concerns",
    description: "Review submitted concerns, read full messages, and send admin replies."
  },
  notifications: {
    kicker: "Announcements",
    title: "Notifications",
    description: "Create notices for users and review previously sent announcements."
  },
  settings: {
    kicker: "Configuration",
    title: "Settings",
    description: "Update the system name, contact details, and parking rate settings."
  }
};

const state = {
  activeSection: "dashboard",
  slotsData: { floors: [], slots: [] },
  selectedFloor: "",
  selectedFloorId: null,
  selectedSlotId: null,
  liveReservations: [],
  reservations: [],
  users: [],
  staff: [],
  notifications: [],
  feedback: [],
  selectedFeedbackId: null,
  violationsHistory: null,
  entityEditor: null
};

const refs = {
  sectionKicker: document.getElementById("admin-section-kicker"),
  sectionTitle: document.getElementById("admin-section-title"),
  sectionDescription: document.getElementById("admin-section-description"),
  sessionName: document.getElementById("admin-session-name"),
  liveDatetime: document.getElementById("admin-live-datetime"),
  globalStatus: document.getElementById("admin-global-status"),
  logoutButton: document.getElementById("admin-logout-btn"),
  sidebarLinks: Array.from(document.querySelectorAll(".sidebar-link")),
  sections: Array.from(document.querySelectorAll(".admin-section")),
  floorForm: document.getElementById("floor-form"),
  slotForm: document.getElementById("slot-form"),
  reservationFilterForm: document.getElementById("reservation-filter-form"),
  staffForm: document.getElementById("staff-form"),
  notificationForm: document.getElementById("notification-form"),
  settingsForm: document.getElementById("settings-form"),
  notificationDate: document.getElementById("notification-date"),
  slotFloorSelect: document.getElementById("slot-floor"),
  slotFormFloorCopy: document.getElementById("slot-form-floor-copy"),
  floorCardGrid: document.getElementById("floor-card-grid"),
  slotCardGrid: document.getElementById("slot-card-grid"),
  selectedFloorTitle: document.getElementById("selected-floor-title"),
  selectedFloorCaption: document.getElementById("selected-floor-caption"),
  slotEditorForm: document.getElementById("slot-editor-form"),
  slotEditorEmpty: document.getElementById("slot-editor-empty"),
  slotEditorEmptyTitle: document.getElementById("slot-editor-empty-title"),
  slotEditorEmptyCopy: document.getElementById("slot-editor-empty-copy"),
  slotEditorId: document.getElementById("slot-editor-id"),
  slotEditorFloor: document.getElementById("slot-editor-floor"),
  slotEditorCode: document.getElementById("slot-editor-code"),
  slotEditorLiveStatus: document.getElementById("slot-editor-live-status"),
  slotEditorManualStatus: document.getElementById("slot-editor-manual-status"),
  slotEditorActive: document.getElementById("slot-editor-active"),
  slotEditorSubcopy: document.getElementById("slot-editor-subcopy"),
  slotEditorClearButton: document.getElementById("slot-editor-clear-btn"),
  liveReservationsBody: document.getElementById("live-reservations-body"),
  liveReservationsUpdatedAt: document.getElementById("live-reservations-updated-at"),
  reservationTableBody: document.getElementById("reservation-table-body"),
  usersTableBody: document.getElementById("users-table-body"),
  staffTableBody: document.getElementById("staff-table-body"),
  paymentsTableBody: document.getElementById("payments-table-body"),
  feedbackTableBody: document.getElementById("feedback-table-body"),
  feedbackModal: document.getElementById("feedback-detail-modal"),
  feedbackModalClose: document.getElementById("feedback-detail-close"),
  feedbackModalStatus: document.getElementById("feedback-modal-status"),
  feedbackModalEmail: document.getElementById("feedback-modal-email"),
  feedbackModalDate: document.getElementById("feedback-modal-date"),
  feedbackModalStatusPill: document.getElementById("feedback-modal-status-pill"),
  feedbackModalRepliedAt: document.getElementById("feedback-modal-replied-at"),
  feedbackModalMessage: document.getElementById("feedback-modal-message"),
  feedbackExistingReplyCard: document.getElementById("feedback-existing-reply-card"),
  feedbackModalExistingReply: document.getElementById("feedback-modal-existing-reply"),
  feedbackReplyForm: document.getElementById("feedback-reply-form"),
  feedbackReplyId: document.getElementById("feedback-reply-id"),
  feedbackReplyStatus: document.getElementById("feedback-reply-status"),
  feedbackReplyMessage: document.getElementById("feedback-reply-message"),
  feedbackModalResolveButton: document.getElementById("feedback-modal-resolve-btn"),
  notificationList: document.getElementById("notification-list"),
  logsContainer: document.getElementById("logs-groups-container"),
  dashboardActivityBody: document.getElementById("dashboard-activity-body"),
  reservationModal: document.getElementById("reservation-detail-modal"),
  reservationModalClose: document.getElementById("reservation-detail-close"),
  reservationModalContent: document.getElementById("reservation-detail-content"),
  entityEditorModal: document.getElementById("entity-editor-modal"),
  entityEditorClose: document.getElementById("entity-editor-close"),
  entityEditorKicker: document.getElementById("entity-editor-kicker"),
  entityEditorTitle: document.getElementById("entity-editor-title"),
  entityEditorForm: document.getElementById("entity-editor-form"),
  violationsHistoryModal: document.getElementById("violations-history-modal"),
  violationsHistoryClose: document.getElementById("violations-history-close"),
  violationsHistoryTitle: document.getElementById("violations-history-title"),
  violationsHistoryContent: document.getElementById("violations-history-content")
};

let dashboardPollTimer = null;
let logsPollTimer = null;

document.addEventListener("DOMContentLoaded", () => {
  const session = requireAdminSession();

  if (!session) {
    return;
  }

  refs.sessionName.textContent = session.fullName || session.email || "Administrator";
  syncAdminCsrfFields();
  refs.notificationDate.value = toDateInputValue(new Date());
  updateLiveClock();
  window.setInterval(updateLiveClock, 1000);

  bindEvents();
  loadSlots().catch(() => {
    // Keep the dashboard boot stable even if the slot workspace is temporarily unavailable.
  });
  activateSection("dashboard");
});

function bindEvents() {
  refs.sidebarLinks.forEach((button) => {
    button.addEventListener("click", () => activateSection(button.dataset.section || "dashboard"));
  });

  refs.logoutButton?.addEventListener("click", handleLogout);
  refs.floorForm?.addEventListener("submit", handleAddFloor);
  refs.slotForm?.addEventListener("submit", handleAddSlot);
  refs.slotFloorSelect?.addEventListener("change", (event) => {
    const nextFloorId = Number(event.target.value || 0);
    if (nextFloorId > 0) {
      setSelectedFloor(nextFloorId);
      return;
    }

    clearSelectedFloor();
  });
  refs.reservationFilterForm?.addEventListener("submit", (event) => {
    event.preventDefault();
    loadReservations();
  });
  refs.staffForm?.addEventListener("submit", handleCreateStaff);
  refs.notificationForm?.addEventListener("submit", handleCreateNotification);
  refs.settingsForm?.addEventListener("submit", handleSaveSettings);
  refs.slotEditorForm?.addEventListener("submit", handleSlotEditorSubmit);
  refs.slotEditorClearButton?.addEventListener("click", clearSelectedSlot);
  refs.reservationModalClose?.addEventListener("click", closeReservationModal);
  refs.feedbackModalClose?.addEventListener("click", closeFeedbackModal);
  refs.entityEditorClose?.addEventListener("click", closeEntityEditor);
  refs.violationsHistoryClose?.addEventListener("click", closeViolationsHistoryModal);
  refs.entityEditorForm?.addEventListener("submit", handleEntityEditorSubmit);
  refs.feedbackReplyForm?.addEventListener("submit", submitFeedbackReply);
  refs.feedbackModalResolveButton?.addEventListener("click", handleResolveFeedbackFromModal);

  document.addEventListener("click", handleDocumentClick);

  [refs.reservationModal, refs.feedbackModal, refs.entityEditorModal, refs.violationsHistoryModal].forEach((modal) => {
    modal?.addEventListener("click", (event) => {
      if (event.target === modal) {
        if (modal === refs.feedbackModal) {
          closeFeedbackModal();
          return;
        }

        modal.hidden = true;
      }
    });
  });
}

function activateSection(sectionName) {
  state.activeSection = sectionName;
  const sectionMeta = SECTION_META[sectionName] || SECTION_META.dashboard;

  refs.sectionKicker.textContent = sectionMeta.kicker;
  refs.sectionTitle.textContent = sectionMeta.title;
  refs.sectionDescription.textContent = sectionMeta.description;

  refs.sidebarLinks.forEach((button) => {
    button.classList.toggle("is-active", button.dataset.section === sectionName);
  });

  refs.sections.forEach((section) => {
    section.classList.toggle("is-active", section.id === `section-${sectionName}`);
  });

  updateGlobalStatusVisibility();

  if (sectionName === "dashboard") {
    startDashboardPolling();
  } else {
    stopDashboardPolling();
  }

  if (sectionName === "logs") {
    startLogsPolling();
  } else {
    stopLogsPolling();
  }

  const loaderMap = {
    dashboard: loadDashboard,
    slots: loadSlots,
    reservations: loadReservations,
    users: loadUsers,
    staff: loadStaff,
    payments: loadPayments,
    logs: loadLogs,
    feedback: loadFeedback,
    notifications: loadNotifications,
    settings: loadSettings
  };

  const loader = loaderMap[sectionName];
  if (typeof loader === "function") {
    loader();
  }
}

function updateGlobalStatusVisibility() {
  if (!refs.globalStatus) {
    return;
  }

  const shouldShow = ["slots", "feedback"].includes(state.activeSection);
  refs.globalStatus.hidden = !shouldShow;

  if (!shouldShow) {
    refs.globalStatus.textContent = "";
    refs.globalStatus.className = "admin-status";
  }
}

async function loadDashboard() {
  try {
    const result = await fetchJson(ADMIN_ENDPOINTS.dashboard);
    const summary = result?.data?.summary || {};
    const activity = Array.isArray(result?.data?.recentActivity) ? result.data.recentActivity : [];

    setText("admin-total-users", formatCount(summary.totalUsers));
    setText("admin-total-reservations", formatCount(summary.totalReservations));
    setText("admin-total-available-slots", formatCount(summary.totalAvailableSlots));
    setText("admin-total-occupied-slots", formatCount(summary.totalOccupiedSlots));
    setText("admin-total-reserved-slots", formatCount(summary.totalReservedSlots));
    setText("admin-total-paid-today", formatCurrency(summary.totalPaidToday));
    setText("admin-total-unpaid", formatCount(summary.totalUnpaid));

    await loadLiveReservations();

    if (!activity.length) {
      refs.dashboardActivityBody.innerHTML = `<tr><td colspan="7" class="empty-table">No recent activity available yet.</td></tr>`;
      return;
    }

    refs.dashboardActivityBody.innerHTML = activity.map((record) => `
      <tr>
        <td>${escapeHtml(record.barcode_value || "--")}</td>
        <td>${escapeHtml(record.full_name || "--")}</td>
        <td>${escapeHtml(record.parking_floor || "--")}</td>
        <td>${escapeHtml(record.parking_slot || "--")}</td>
        <td>${renderStatusPill(record.booth_status || record.reservation_status || "Reserved")}</td>
        <td>${renderStatusPill(record.payment_status || "Reserved")}</td>
        <td>${escapeHtml(formatDateTime(record.updated_at))}</td>
      </tr>
    `).join("");
  } catch (error) {
    showStatus(error.message || "Failed to load dashboard summary.", true);
  }
}

function startDashboardPolling() {
  if (dashboardPollTimer) {
    window.clearInterval(dashboardPollTimer);
  }

  dashboardPollTimer = window.setInterval(() => {
    if (state.activeSection !== "dashboard") {
      return;
    }

    loadDashboard().catch(() => {
      // Keep the current dashboard visible if a background refresh fails.
    });
  }, 4000);
}

function stopDashboardPolling() {
  if (!dashboardPollTimer) {
    return;
  }

  window.clearInterval(dashboardPollTimer);
  dashboardPollTimer = null;
}

function startLogsPolling() {
  if (logsPollTimer) {
    window.clearInterval(logsPollTimer);
  }

  logsPollTimer = window.setInterval(() => {
    if (state.activeSection !== "logs") {
      return;
    }

    loadLogs().catch(() => {
      // Keep the current logs visible if a background refresh fails.
    });
  }, 4000);
}

function stopLogsPolling() {
  if (!logsPollTimer) {
    return;
  }

  window.clearInterval(logsPollTimer);
  logsPollTimer = null;
}

async function loadLiveReservations() {
  try {
    const result = await fetchJson(`${ADMIN_ENDPOINTS.liveReservations}?limit=20`);
    state.liveReservations = Array.isArray(result?.data?.reservations) ? result.data.reservations : [];

    if (!state.liveReservations.length) {
      refs.liveReservationsBody.innerHTML = `<tr><td colspan="8" class="empty-table">No live reservations available yet.</td></tr>`;
      if (refs.liveReservationsUpdatedAt) {
        refs.liveReservationsUpdatedAt.textContent = "Waiting for live updates...";
      }
      return;
    }

    refs.liveReservationsBody.innerHTML = state.liveReservations.map((record) => `
      <tr>
        <td>${escapeHtml(record.full_name || "--")}</td>
        <td>${escapeHtml(record.email || "--")}</td>
        <td>${escapeHtml(record.barcode_value || record.barcode || "--")}</td>
        <td>${escapeHtml(record.parking_floor || record.floor || "--")}</td>
        <td>${escapeHtml(record.parking_slot || record.slot || "--")}</td>
        <td>${escapeHtml(formatDate(record.reservation_date))}</td>
        <td>${escapeHtml(formatTime(record.reserved_time_in))}</td>
        <td>${renderStatusPill(record.status || "Reserved")}</td>
      </tr>
    `).join("");

    if (refs.liveReservationsUpdatedAt) {
      refs.liveReservationsUpdatedAt.textContent = `Last refresh: ${formatDateTime(new Date().toISOString())}`;
    }
  } catch (error) {
    if (refs.liveReservationsUpdatedAt) {
      refs.liveReservationsUpdatedAt.textContent = error.message || "Live refresh failed.";
    }
  }
}

async function loadSlots() {
  try {
    const result = await fetchJson(ADMIN_ENDPOINTS.slots);
    state.slotsData = {
      floors: Array.isArray(result?.data?.floors) ? result.data.floors : [],
      slots: Array.isArray(result?.data?.slots) ? result.data.slots : []
    };

    syncSlotSectionSelection();
    renderSlotManagementWorkspace();

    if (state.activeSection === "slots") {
      showStatus("", false);
    }
  } catch (error) {
    showStatus(error.message || "Failed to load floors and slots.", true);
  }
}

async function loadReservations() {
  try {
    const search = document.getElementById("reservation-search")?.value?.trim() || "";
    const status = document.getElementById("reservation-status-filter")?.value || "";
    const paymentStatus = document.getElementById("reservation-payment-filter")?.value || "";
    const query = new URLSearchParams();

    if (search) query.set("search", search);
    if (status) query.set("status", status);
    if (paymentStatus) query.set("payment_status", paymentStatus);

    const url = query.toString() ? `${ADMIN_ENDPOINTS.reservations}?${query.toString()}` : ADMIN_ENDPOINTS.reservations;
    const result = await fetchJson(url);
    state.reservations = Array.isArray(result?.data?.reservations) ? result.data.reservations : [];

    if (!state.reservations.length) {
      refs.reservationTableBody.innerHTML = `<tr><td colspan="10" class="empty-table">No reservation records found.</td></tr>`;
      return;
    }

    refs.reservationTableBody.innerHTML = state.reservations.map((record) => `
      <tr>
        <td>${escapeHtml(record.barcode_value || "--")}</td>
        <td>${escapeHtml(record.full_name || "--")}</td>
        <td>${escapeHtml(record.email || "--")}</td>
        <td>${escapeHtml(record.parking_floor || "--")}</td>
        <td>${escapeHtml(record.parking_slot || "--")}</td>
        <td>${escapeHtml(formatDate(record.reservation_date))}</td>
        <td>${escapeHtml(formatTime(record.reserved_time_in))}</td>
        <td>${renderStatusPill(record.booth_status || record.reservation_status || "Reserved")}</td>
        <td>${renderStatusPill(record.payment_status || "Reserved")}</td>
        <td>
          <div class="table-inline-actions">
            <button class="table-action-btn" type="button" data-admin-action="reservation-view" data-id="${record.id}">View</button>
          </div>
        </td>
      </tr>
    `).join("");
  } catch (error) {
    showStatus(error.message || "Failed to load reservations.", true);
  }
}

async function loadUsers() {
  try {
    const result = await fetchJson(ADMIN_ENDPOINTS.users);
    state.users = Array.isArray(result?.data?.users) ? result.data.users : [];

    if (!state.users.length) {
      refs.usersTableBody.innerHTML = `<tr><td colspan="8" class="empty-table">No registered users yet.</td></tr>`;
      return;
    }

    refs.usersTableBody.innerHTML = state.users.map((user) => `
      <tr>
        <td class="user-cell-id">${escapeHtml(String(user.id || "--"))}</td>
        <td class="user-cell-name"><strong>${escapeHtml(user.full_name || "--")}</strong></td>
        <td class="user-cell-email">${escapeHtml(user.email || "--")}</td>
        <td class="user-cell-birthday">${escapeHtml(formatDate(user.birth_date))}</td>
        <td class="user-cell-created">${escapeHtml(formatDateTime(user.created_at))}</td>
        <td class="user-cell-status">${renderUserStatusCell(user)}</td>
        <td class="user-cell-reservations">${escapeHtml(String(user.reservation_count || 0))}</td>
        <td class="user-cell-actions">
          <div class="table-inline-actions user-actions">
            <button class="table-action-btn" type="button" data-admin-action="user-history" data-id="${user.id}">View History</button>
            <button class="table-action-btn" type="button" data-admin-action="user-edit" data-id="${user.id}">Edit</button>
            ${(user.account_status || "active") === "locked"
              ? `<button class="table-action-btn" type="button" data-admin-action="user-unlock" data-id="${user.id}">Unlock</button>`
              : ""}
            <button class="table-action-btn" type="button" data-admin-action="${(user.status || "Active") === "Disabled" ? "user-activate" : "user-disable"}" data-id="${user.id}">${(user.status || "Active") === "Disabled" ? "Activate" : "Disable"}</button>
            <button class="table-action-btn" type="button" data-admin-action="user-delete" data-id="${user.id}">Delete</button>
          </div>
        </td>
      </tr>
    `).join("");
  } catch (error) {
    showStatus(error.message || "Failed to load users.", true);
  }
}

async function loadStaff() {
  try {
    const result = await fetchJson(ADMIN_ENDPOINTS.staff);
    state.staff = Array.isArray(result?.data?.staff) ? result.data.staff : [];

    if (!state.staff.length) {
      refs.staffTableBody.innerHTML = `<tr><td colspan="6" class="empty-table">No staff accounts found.</td></tr>`;
      return;
    }

    refs.staffTableBody.innerHTML = state.staff.map((staff) => `
      <tr>
        <td><strong>${escapeHtml(staff.full_name || "--")}</strong></td>
        <td>${escapeHtml(staff.username || "--")}</td>
        <td>${escapeHtml(staff.email || "--")}</td>
        <td>${renderStatusPill((staff.role || "booth").toUpperCase())}</td>
        <td>${renderStatusPill(Number(staff.is_active) === 1 ? "Active" : "Disabled")}</td>
        <td>
          <div class="table-inline-actions">
            <button class="table-action-btn" type="button" data-admin-action="staff-edit" data-id="${staff.id}">Edit</button>
            <button class="table-action-btn" type="button" data-admin-action="staff-delete" data-id="${staff.id}">Delete</button>
          </div>
        </td>
      </tr>
    `).join("");
  } catch (error) {
    showStatus(error.message || "Failed to load staff accounts.", true);
  }
}

async function loadPayments() {
  try {
    const result = await fetchJson(ADMIN_ENDPOINTS.payments);
    const summary = result?.data?.summary || {};
    const payments = Array.isArray(result?.data?.payments) ? result.data.payments : [];

    setText("payments-total-income", formatCurrency(summary.totalIncome));
    setText("payments-paid-count", formatCount(summary.paidCount));
    setText("payments-unpaid-count", formatCount(summary.unpaidCount));

    if (!payments.length) {
      refs.paymentsTableBody.innerHTML = `<tr><td colspan="6" class="empty-table">No payment records available.</td></tr>`;
      return;
    }

    refs.paymentsTableBody.innerHTML = payments.map((record) => `
      <tr>
        <td>${escapeHtml(record.full_name || "--")}</td>
        <td>${escapeHtml(record.barcode_value || "--")}</td>
        <td>${escapeHtml(formatCurrency(record.total_payment))}</td>
        <td>${renderStatusPill(record.payment_status || "Unpaid")}</td>
        <td>${escapeHtml(formatDateTime(record.paid_at))}</td>
        <td>${renderStatusPill(record.booth_status || "Reserved")}</td>
      </tr>
    `).join("");
  } catch (error) {
    showStatus(error.message || "Failed to load payments.", true);
  }
}

async function loadLogs() {
  try {
    const result = await fetchJson(ADMIN_ENDPOINTS.logs);
    const groups = Array.isArray(result?.data?.groups) ? result.data.groups : [];
    setText("logs-overall-total", formatCount(result?.data?.totalEntries || 0));

    if (!groups.length) {
      refs.logsContainer.innerHTML = `<section class="panel-card"><p class="empty-table">No admin audit logs available yet.</p></section>`;
      return;
    }

    refs.logsContainer.innerHTML = groups.map((group) => `
      <section class="report-group" data-group-date="${escapeHtml(group.date)}">
        <header class="report-group-header">
          <div>
            <p class="section-kicker">Audit Log Date Group</p>
            <h4>${escapeHtml(group.dateLabel || group.date)}</h4>
            <p class="report-group-summary">${escapeHtml(`Entries: ${formatCount(group.count || 0)}`)}</p>
          </div>
          <button class="toggle-btn" type="button" data-admin-action="toggle-log-group" data-group-date="${escapeHtml(group.date)}">-</button>
        </header>
        <div class="report-group-body">
          <div class="table-shell">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th>Time</th>
                    <th>Admin</th>
                    <th>Email</th>
                    <th>Action</th>
                    <th>Description</th>
                    <th>Target</th>
                    <th>Target ID</th>
                    <th>IP Address</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  ${group.logs.map((log) => `
                    <tr>
                      <td>${escapeHtml(formatDateTime(log.log_time))}</td>
                      <td>${escapeHtml(log.admin_name || "--")}</td>
                      <td>${escapeHtml(log.admin_email || "--")}</td>
                      <td>${escapeHtml(log.action_label || log.action_type || "--")}</td>
                      <td>${escapeHtml(log.description || "--")}</td>
                      <td>${escapeHtml(log.target_type || "--")}</td>
                      <td>${escapeHtml(log.target_id || "--")}</td>
                      <td>${escapeHtml(log.ip_address || "--")}</td>
                      <td>${renderStatusPill(log.status_label || "--")}</td>
                    </tr>
                  `).join("")}
                </tbody>
              </table>
          </div>
        </div>
      </section>
    `).join("");
  } catch (error) {
    showStatus(error.message || "Failed to load logs and reports.", true);
  }
}

async function loadFeedbackInbox() {
  try {
    const result = await fetchJson(ADMIN_ENDPOINTS.feedback);
    state.feedback = Array.isArray(result?.data?.feedback) ? result.data.feedback : [];
    renderFeedbackTable();

    if (state.selectedFeedbackId) {
      const activeRecord = getFeedbackRecordById(state.selectedFeedbackId);

      if (activeRecord) {
        renderFeedbackModal(activeRecord);
      } else {
        closeFeedbackModal();
      }
    }
  } catch (error) {
    showStatus(error.message || "Failed to load feedback messages.", true);
  }
}

function loadFeedback() {
  return loadFeedbackInbox();
}

function renderFeedbackTable() {
  if (!refs.feedbackTableBody) {
    return;
  }

  if (!state.feedback.length) {
    refs.feedbackTableBody.innerHTML = `<tr><td colspan="5" class="empty-table">No feedback messages submitted yet.</td></tr>`;
    return;
  }

  refs.feedbackTableBody.innerHTML = state.feedback.map((item) => `
    <tr>
      <td class="feedback-cell-email"><strong>${escapeHtml(item.email || "--")}</strong></td>
      <td class="feedback-cell-message">
        <span class="feedback-message-preview" title="${escapeHtml(item.concern_message || item.message || "--")}">${escapeHtml(getFeedbackPreview(item.concern_message || item.message || "--"))}</span>
      </td>
      <td class="feedback-cell-date">${escapeHtml(formatDateTime(item.date_submitted || item.submitted_at || item.created_at))}</td>
      <td>${renderStatusPill(item.status || "Pending")}</td>
      <td>
        <div class="table-inline-actions">
          <button class="table-action-btn" type="button" data-admin-action="feedback-read" data-id="${item.id}">Read</button>
          <button class="table-action-btn" type="button" data-admin-action="feedback-resolve" data-id="${item.id}">Resolve</button>
          <button class="table-action-btn" type="button" data-admin-action="feedback-delete" data-id="${item.id}">Delete</button>
        </div>
      </td>
    </tr>
  `).join("");
}

function getFeedbackRecordById(feedbackId) {
  return state.feedback.find((item) => Number(item.id) === Number(feedbackId)) || null;
}

function getFeedbackPreview(message) {
  const normalized = String(message || "").replace(/\s+/g, " ").trim();

  if (normalized.length <= 92) {
    return normalized || "--";
  }

  return `${normalized.slice(0, 89)}...`;
}

function openFeedbackModal(feedbackId) {
  const record = getFeedbackRecordById(feedbackId);

  if (!record || !refs.feedbackModal) {
    showStatus("Feedback record not found.", true);
    return;
  }

  state.selectedFeedbackId = Number(record.id || 0) || null;
  renderFeedbackModal(record);
  refs.feedbackModal.hidden = false;
}

function closeFeedbackModal() {
  state.selectedFeedbackId = null;

  if (refs.feedbackReplyForm) {
    refs.feedbackReplyForm.reset();
  }

  setFeedbackModalStatus("");

  if (refs.feedbackModal) {
    refs.feedbackModal.hidden = true;
  }
}

function setFeedbackModalStatus(message, isError = false) {
  if (!refs.feedbackModalStatus) {
    return;
  }

  refs.feedbackModalStatus.hidden = !message;
  refs.feedbackModalStatus.textContent = message || "";
  refs.feedbackModalStatus.className = `admin-status feedback-modal-status ${message ? (isError ? "is-error" : "is-success") : ""}`.trim();
}

function renderFeedbackModal(record) {
  if (!record) {
    return;
  }

  const concernMessage = record.concern_message || record.message || "";
  const submittedAt = record.date_submitted || record.submitted_at || record.created_at || "";
  const replyText = record.admin_reply || "";
  const repliedAt = record.replied_at || record.resolved_at || "";
  const status = record.status || "Pending";

  if (refs.feedbackModalEmail) {
    refs.feedbackModalEmail.textContent = record.email || "--";
  }
  if (refs.feedbackModalDate) {
    refs.feedbackModalDate.textContent = formatDateTime(submittedAt);
  }
  if (refs.feedbackModalStatusPill) {
    refs.feedbackModalStatusPill.innerHTML = renderStatusPill(status);
  }
  if (refs.feedbackModalRepliedAt) {
    refs.feedbackModalRepliedAt.textContent = repliedAt ? formatDateTime(repliedAt) : "Not yet replied";
  }
  if (refs.feedbackModalMessage) {
    refs.feedbackModalMessage.textContent = concernMessage || "No concern message available.";
  }
  if (refs.feedbackExistingReplyCard) {
    refs.feedbackExistingReplyCard.hidden = !replyText;
  }
  if (refs.feedbackModalExistingReply) {
    refs.feedbackModalExistingReply.textContent = replyText || "No reply saved yet.";
  }
  if (refs.feedbackReplyId) {
    refs.feedbackReplyId.value = String(record.id || "");
  }
  if (refs.feedbackReplyStatus) {
    refs.feedbackReplyStatus.value = ["Pending", "Replied", "Resolved"].includes(status) ? status : "Replied";
  }
  if (refs.feedbackReplyMessage) {
    refs.feedbackReplyMessage.value = replyText || "";
  }

  setFeedbackModalStatus("");
}

async function submitFeedbackReply(event) {
  event.preventDefault();

  const feedbackId = Number(refs.feedbackReplyId?.value || state.selectedFeedbackId || 0);
  const replyMessage = String(refs.feedbackReplyMessage?.value || "").trim();
  const status = String(refs.feedbackReplyStatus?.value || "Replied").trim();

  if (feedbackId <= 0) {
    setFeedbackModalStatus("Select a feedback message first.", true);
    return;
  }

  if (!replyMessage) {
    setFeedbackModalStatus("Reply message is required.", true);
    return;
  }

  try {
    const result = await postJson(ADMIN_ENDPOINTS.replyFeedback, {
      feedback_id: feedbackId,
      admin_reply: replyMessage,
      status
    });

    state.feedback = Array.isArray(result?.data?.feedback) ? result.data.feedback : state.feedback;
    renderFeedbackTable();
    openFeedbackModal(feedbackId);
    setFeedbackModalStatus(result?.message || "Reply sent successfully.");
    showStatus("Feedback reply saved successfully.");
  } catch (error) {
    setFeedbackModalStatus(error.message || "Failed to send feedback reply.", true);
    showStatus(error.message || "Failed to send feedback reply.", true);
  }
}

async function handleResolveFeedbackFromModal() {
  const feedbackId = Number(refs.feedbackReplyId?.value || state.selectedFeedbackId || 0);

  if (feedbackId <= 0) {
    setFeedbackModalStatus("Select a feedback message first.", true);
    return;
  }

  try {
    const result = await postJson(ADMIN_ENDPOINTS.feedback, { action: "resolve", message_id: feedbackId });
    state.feedback = Array.isArray(result?.data?.feedback) ? result.data.feedback : state.feedback;
    renderFeedbackTable();
    openFeedbackModal(feedbackId);
    setFeedbackModalStatus(result?.message || "Feedback marked as resolved.");
    showStatus("Feedback marked as resolved.");
  } catch (error) {
    setFeedbackModalStatus(error.message || "Failed to update feedback status.", true);
    showStatus(error.message || "Failed to update feedback status.", true);
  }
}

async function loadNotifications() {
  try {
    const result = await fetchJson(ADMIN_ENDPOINTS.notifications);
    state.notifications = Array.isArray(result?.data?.notifications) ? result.data.notifications : [];

    if (!state.notifications.length) {
      refs.notificationList.innerHTML = `<article class="stack-card"><strong>No notifications sent yet.</strong><p>New announcements will appear here after they are created.</p></article>`;
      return;
    }

    refs.notificationList.innerHTML = state.notifications.map((item) => `
      <article class="stack-card">
        <div class="stack-card-header">
          <div>
            <p class="section-kicker">${escapeHtml(item.audience || "Users")}</p>
            <h4>${escapeHtml(item.title || "Notice")}</h4>
          </div>
          <button class="table-action-btn" type="button" data-admin-action="notification-delete" data-id="${item.id}">Delete</button>
        </div>
        <p>${escapeHtml(item.message || "")}</p>
        <p>${escapeHtml(formatDate(item.notification_date))}</p>
      </article>
    `).join("");
  } catch (error) {
    showStatus(error.message || "Failed to load notifications.", true);
  }
}

async function loadSettings() {
  try {
    const result = await fetchJson(ADMIN_ENDPOINTS.settings);
    const settings = result?.data?.settings || {};
    setFormValue(refs.settingsForm, "system_name", settings.system_name || "");
    setFormValue(refs.settingsForm, "contact_number", settings.contact_number || "");
    setFormValue(refs.settingsForm, "gmail_address", settings.gmail_address || "");
    setFormValue(refs.settingsForm, "parking_base_rate", settings.parking_base_rate || "20");
    setFormValue(refs.settingsForm, "extra_hourly_rate", settings.extra_hourly_rate || "10");
  } catch (error) {
    showStatus(error.message || "Failed to load settings.", true);
  }
}

function renderFloorOptions() {
  const options = state.slotsData.floors.map((floor) => `
    <option value="${escapeHtml(String(floor.id || ""))}">${escapeHtml(floor.floor_label || floor.floor_name)}</option>
  `).join("");

  const placeholder = state.slotsData.floors.length
    ? `<option value="">Choose a floor</option>`
    : `<option value="">No floor available</option>`;

  refs.slotFloorSelect.innerHTML = `${placeholder}${options}`;
  refs.slotFloorSelect.disabled = state.slotsData.floors.length === 0;

  if (state.selectedFloorId) {
    refs.slotFloorSelect.value = String(state.selectedFloorId);
  } else {
    refs.slotFloorSelect.value = "";
  }

  if (refs.slotFormFloorCopy) {
    const selectedFloorLabel = getSelectedFloorLabel();
    refs.slotFormFloorCopy.textContent = selectedFloorLabel
      ? `New slots will be added to ${selectedFloorLabel}.`
      : state.slotsData.floors.length
        ? "Select a floor from the collection below or use the dropdown."
        : "Add a floor first to enable slot creation.";
  }
}

function getFloorRecordById(floorId) {
  return state.slotsData.floors.find((floor) => Number(floor.id) === Number(floorId)) || null;
}

function getFloorRecordByName(floorName) {
  return state.slotsData.floors.find((floor) => floor.floor_name === floorName) || null;
}

function getSelectedFloorRecord() {
  return getFloorRecordById(state.selectedFloorId) || getFloorRecordByName(state.selectedFloor);
}

function getSelectedFloorLabel(fallback = "") {
  const selectedFloorRecord = getSelectedFloorRecord();
  return selectedFloorRecord?.floor_label || selectedFloorRecord?.floor_name || state.selectedFloor || fallback;
}

function syncSlotSectionSelection() {
  const selectedFloorRecord = getSelectedFloorRecord();

  if (!selectedFloorRecord) {
    state.selectedFloorId = null;
    state.selectedFloor = "";
    state.selectedSlotId = null;
    return;
  }

  state.selectedFloorId = Number(selectedFloorRecord.id || 0) || null;
  state.selectedFloor = selectedFloorRecord.floor_name || "";

  const slotsForFloor = getSlotsForSelectedFloor();
  const selectedSlotExists = slotsForFloor.some((slot) => Number(slot.id) === Number(state.selectedSlotId));

  if (!selectedSlotExists) {
    state.selectedSlotId = null;
  }
}

function renderSlotManagementWorkspace() {
  renderFloorOptions();
  renderSlotWorkspaceSummary();
  renderFloorCards();
  renderSlotCards();
  renderSlotEditor();
}

function renderSlotWorkspaceSummary() {
  const selectedFloorRecord = getSelectedFloorRecord();
  const selectedFloorLabel = getSelectedFloorLabel();
  const selectedFloorSlots = selectedFloorRecord ? getSlotsForSelectedFloor() : [];
  const floorCount = state.slotsData.floors.length;
  const slotCount = state.slotsData.slots.length;

  setText("slot-summary-floor-count", formatCount(floorCount));
  setText("slot-summary-slot-count", formatCount(slotCount));
  setText("slot-summary-selected-floor", selectedFloorLabel || "None");
  setText("floor-collection-count", `${formatCount(floorCount)} ${floorCount === 1 ? "floor" : "floors"}`);
  setText("slot-gallery-count", `${formatCount(selectedFloorSlots.length)} ${selectedFloorSlots.length === 1 ? "slot" : "slots"}`);
}

function getSlotsForSelectedFloor() {
  if (state.selectedFloorId) {
    return state.slotsData.slots.filter((slot) => Number(slot.floor_id || 0) === Number(state.selectedFloorId));
  }

  return state.slotsData.slots.filter((slot) => slot.floor_name === state.selectedFloor);
}

function getSelectedSlotRecord() {
  return state.slotsData.slots.find((slot) => Number(slot.id) === Number(state.selectedSlotId)) || null;
}

function createAdminEmptyState(title, message) {
  const article = document.createElement("article");
  article.className = "master-empty-state";

  const strong = document.createElement("strong");
  strong.textContent = title;

  const paragraph = document.createElement("p");
  paragraph.textContent = message;

  article.append(strong, paragraph);
  return article;
}

function renderFloorCards() {
  if (!refs.floorCardGrid) {
    return;
  }

  refs.floorCardGrid.innerHTML = "";

  if (!state.slotsData.floors.length) {
    refs.floorCardGrid.appendChild(createAdminEmptyState(
      "No floors created yet.",
      "Add your first parking floor above to begin building the layout."
    ));
    return;
  }

  state.slotsData.floors.forEach((floor) => {
    const article = document.createElement("article");
    article.className = `floor-browser-card ${Number(floor.id) === Number(state.selectedFloorId) ? "is-selected" : ""}`.trim();

    const deleteButton = document.createElement("button");
    deleteButton.className = "delete-floor-btn";
    deleteButton.type = "button";
    deleteButton.dataset.adminAction = "floor-delete";
    deleteButton.dataset.id = String(floor.id || "");
    deleteButton.setAttribute("aria-label", `Delete parking floor ${floor.floor_name || "--"}`);
    deleteButton.title = "Delete floor";
    deleteButton.innerHTML = "&times;";

    const cardButton = document.createElement("button");
    cardButton.className = "browser-card-button";
    cardButton.type = "button";
    cardButton.dataset.adminAction = "floor-select";
    cardButton.dataset.floorId = String(floor.id || "");
    cardButton.setAttribute("aria-pressed", Number(floor.id) === Number(state.selectedFloorId) ? "true" : "false");

    const kicker = document.createElement("span");
    kicker.className = "browser-card-kicker";
    kicker.textContent = floor.floor_label || floor.floor_name || "--";

    const name = document.createElement("strong");
    name.textContent = floor.floor_name || "--";

    const meta = document.createElement("div");
    meta.className = "browser-card-meta";
    const slotCount = document.createElement("span");
    slotCount.textContent = `${Number(floor.slot_count || 0)} Slots`;
    const availableCount = document.createElement("span");
    availableCount.textContent = `${Number(floor.available_count || 0)} Available`;
    const occupiedCount = document.createElement("span");
    occupiedCount.textContent = `${Number(floor.occupied_count || 0)} Occupied`;
    meta.append(slotCount, availableCount, occupiedCount);

    const footer = document.createElement("div");
    footer.className = "browser-card-footer";
    footer.innerHTML = renderStatusPill(Number(floor.is_active) === 1 ? "Active" : "Inactive");

    cardButton.append(kicker, name, meta, footer);

    const toggleButton = document.createElement("button");
    toggleButton.className = "table-action-btn browser-card-action";
    toggleButton.type = "button";
    toggleButton.dataset.adminAction = "floor-toggle";
    toggleButton.dataset.id = String(floor.id || "");
    toggleButton.dataset.active = Number(floor.is_active) === 1 ? "0" : "1";
    toggleButton.textContent = Number(floor.is_active) === 1 ? "Set Inactive" : "Set Active";

    article.append(deleteButton, cardButton, toggleButton);
    refs.floorCardGrid.appendChild(article);
  });
}

function renderSlotCards() {
  if (!refs.slotCardGrid) {
    return;
  }

  refs.slotCardGrid.innerHTML = "";

  const selectedFloorRecord = getSelectedFloorRecord();
  const selectedFloorLabel = getSelectedFloorLabel("No floor selected");

  if (!selectedFloorRecord) {
    refs.slotCardGrid.appendChild(createAdminEmptyState(
      "No floor selected.",
      "Choose a floor card above to reveal its parking slots."
    ));
    if (refs.selectedFloorTitle) {
      refs.selectedFloorTitle.textContent = "No floor selected";
    }
    if (refs.selectedFloorCaption) {
      refs.selectedFloorCaption.textContent = "Choose a floor card to display its parking slots.";
    }
    return;
  }

  const slots = getSlotsForSelectedFloor();

  if (refs.selectedFloorTitle) {
    refs.selectedFloorTitle.textContent = selectedFloorLabel;
  }
  if (refs.selectedFloorCaption) {
    refs.selectedFloorCaption.textContent = slots.length
      ? `${slots.length} parking slots loaded for this floor. Select one to edit it.`
      : "This floor has no slots yet. Use the add slot form above to create one.";
  }

  if (!slots.length) {
    refs.slotCardGrid.appendChild(createAdminEmptyState(
      "No slots added yet.",
      `Create a new slot for ${selectedFloorLabel || state.selectedFloor} using the form above.`
    ));
    return;
  }

  slots.forEach((slot) => {
    const article = document.createElement("article");
    article.className = `slot-browser-card ${Number(slot.id) === Number(state.selectedSlotId) ? "is-selected" : ""}`.trim();

    const deleteButton = document.createElement("button");
    deleteButton.className = "delete-slot-btn";
    deleteButton.type = "button";
    deleteButton.dataset.adminAction = "slot-delete";
    deleteButton.dataset.id = String(slot.id || "");
    deleteButton.setAttribute("aria-label", `Delete parking slot ${slot.slot_code || "--"}`);
    deleteButton.title = "Delete slot";
    deleteButton.innerHTML = "&times;";

    const cardButton = document.createElement("button");
    cardButton.className = "slot-browser-card-main";
    cardButton.type = "button";
    cardButton.dataset.adminAction = "slot-select";
    cardButton.dataset.id = String(slot.id || "");

    const kicker = document.createElement("span");
    kicker.className = "browser-card-kicker";
    kicker.textContent = slot.floor_name || "--";

    const code = document.createElement("strong");
    code.textContent = slot.slot_code || "--";

    const liveStack = document.createElement("div");
    liveStack.className = "browser-card-stack";
    const liveLabel = document.createElement("span");
    liveLabel.textContent = "Live Status";
    const liveValue = document.createElement("div");
    liveValue.innerHTML = renderStatusPill(slot.live_status || "Available");
    liveStack.append(liveLabel, liveValue);

    const manualStack = document.createElement("div");
    manualStack.className = "browser-card-stack";
    const manualLabel = document.createElement("span");
    manualLabel.textContent = "Manual";
    const manualValue = document.createElement("strong");
    manualValue.textContent = slot.manual_status || "Auto";
    manualStack.append(manualLabel, manualValue);

    const activeStack = document.createElement("div");
    activeStack.className = "browser-card-stack";
    const activeLabel = document.createElement("span");
    activeLabel.textContent = "Active";
    const activeValue = document.createElement("strong");
    activeValue.textContent = Number(slot.is_active) === 1 ? "Yes" : "No";
    activeStack.append(activeLabel, activeValue);

    cardButton.append(kicker, code, liveStack, manualStack, activeStack);
    article.append(deleteButton, cardButton);
    refs.slotCardGrid.appendChild(article);
  });
}

function renderSlotEditor() {
  const selectedFloorRecord = getSelectedFloorRecord();
  const selectedFloorLabel = getSelectedFloorLabel("No floor selected");
  const selectedSlot = getSelectedSlotRecord();

  if (!selectedSlot) {
    refs.slotEditorForm.hidden = true;
    refs.slotEditorEmpty.hidden = false;
    refs.slotEditorForm.reset();
    refs.slotEditorId.value = "";
    refs.slotEditorFloor.value = selectedFloorLabel;

    if (refs.slotEditorSubcopy) {
      refs.slotEditorSubcopy.textContent = selectedFloorRecord
        ? `Editing controls are ready for ${selectedFloorLabel}. Select a slot card to load its details.`
        : "Choose a floor and slot to edit its status and availability controls.";
    }
    if (refs.slotEditorEmptyTitle) {
      refs.slotEditorEmptyTitle.textContent = selectedFloorRecord ? "No slot selected" : "No floor selected";
    }
    if (refs.slotEditorEmptyCopy) {
      refs.slotEditorEmptyCopy.textContent = selectedFloorRecord
        ? "Select a slot card to edit its slot code, status controls, and activation state."
        : "Select a floor card first, then choose a slot to open the editor.";
    }
    return;
  }

  refs.slotEditorEmpty.hidden = true;
  refs.slotEditorForm.hidden = false;
  refs.slotEditorId.value = String(selectedSlot.id || "");
  refs.slotEditorFloor.value = selectedFloorLabel || selectedSlot.floor_name || "No floor selected";
  refs.slotEditorCode.value = selectedSlot.slot_code || "";
  refs.slotEditorLiveStatus.value = selectedSlot.live_status || "Available";
  refs.slotEditorManualStatus.value = selectedSlot.manual_status || "Auto";
  refs.slotEditorActive.value = Number(selectedSlot.is_active) === 1 ? "1" : "0";

  if (refs.slotEditorSubcopy) {
    refs.slotEditorSubcopy.textContent = `Updating ${selectedSlot.slot_code || "selected slot"} on ${selectedFloorLabel}.`;
  }
}

function setSelectedFloor(floorId) {
  const selectedFloorRecord = getFloorRecordById(floorId);
  state.selectedFloorId = selectedFloorRecord ? Number(selectedFloorRecord.id || 0) : null;
  state.selectedFloor = selectedFloorRecord?.floor_name || "";
  state.selectedSlotId = null;
  renderSlotManagementWorkspace();
}

function clearSelectedFloor() {
  state.selectedFloorId = null;
  state.selectedFloor = "";
  state.selectedSlotId = null;
  renderSlotManagementWorkspace();
}

function setSelectedSlot(slotId) {
  state.selectedSlotId = slotId;
  renderSlotManagementWorkspace();
}

function clearSelectedSlot() {
  state.selectedSlotId = null;
  renderSlotManagementWorkspace();
}

async function handleAddFloor(event) {
  event.preventDefault();
  const formData = new FormData(refs.floorForm);
  const floorName = String(formData.get("floor_name") || "").trim();

  try {
    await postJson(ADMIN_ENDPOINTS.addFloor, {
      floor_name: floorName,
      floor_label: String(formData.get("floor_label") || "").trim()
    });
    refs.floorForm.reset();
    await loadSlots();
    if (floorName) {
      const addedFloor = getFloorRecordByName(floorName);
      if (addedFloor) {
        setSelectedFloor(addedFloor.id);
      }
    }
    showStatus("Parking floor added successfully.");
    if (state.activeSection === "dashboard") {
      await loadDashboard();
    }
  } catch (error) {
    showStatus(error.message || "Failed to add floor.", true);
  }
}

async function handleAddSlot(event) {
  event.preventDefault();
  const formData = new FormData(refs.slotForm);
  const targetFloorId = Number(formData.get("floor_id") || state.selectedFloorId || 0);
  const targetFloor = getFloorRecordById(targetFloorId);

  if (!targetFloorId || !targetFloor) {
    showStatus("Select a floor card before adding a slot.", true);
    return;
  }

  try {
    await postJson(ADMIN_ENDPOINTS.slots, {
      action: "add_slot",
      floor_id: targetFloorId,
      slot_code: String(formData.get("slot_code") || "").trim().toUpperCase()
    });
    refs.slotForm.reset();
    await loadSlots();
    setSelectedFloor(targetFloorId);
    showStatus("Parking slot added successfully.");
    await loadDashboard();
  } catch (error) {
    showStatus(error.message || "Failed to add slot.", true);
  }
}

async function handleCreateStaff(event) {
  event.preventDefault();
  const formData = new FormData(refs.staffForm);

  try {
    await postJson(ADMIN_ENDPOINTS.staff, {
      action: "create",
      full_name: String(formData.get("full_name") || "").trim(),
      username: String(formData.get("username") || "").trim(),
      email: String(formData.get("email") || "").trim(),
      password: String(formData.get("password") || "").trim(),
      role: String(formData.get("role") || "booth")
    });
    refs.staffForm.reset();
    showStatus("Staff account created successfully.");
    loadStaff();
  } catch (error) {
    showStatus(error.message || "Failed to create staff account.", true);
  }
}

async function handleCreateNotification(event) {
  event.preventDefault();
  const formData = new FormData(refs.notificationForm);

  try {
    await postJson(ADMIN_ENDPOINTS.notifications, {
      action: "create",
      title: String(formData.get("title") || "").trim(),
      message: String(formData.get("message") || "").trim(),
      notification_date: String(formData.get("notification_date") || "").trim(),
      audience: String(formData.get("audience") || "Users")
    });
    refs.notificationForm.reset();
    refs.notificationDate.value = toDateInputValue(new Date());
    showStatus("Notification created successfully.");
    loadNotifications();
  } catch (error) {
    showStatus(error.message || "Failed to create notification.", true);
  }
}

async function handleSaveSettings(event) {
  event.preventDefault();
  const formData = new FormData(refs.settingsForm);

  try {
    const result = await postJson(ADMIN_ENDPOINTS.settings, Object.fromEntries(formData.entries()));
    if (typeof window.setSndraSystemSettings === "function") {
      window.setSndraSystemSettings(result?.data?.settings || {});
    }
    showStatus("System settings saved successfully.");
    loadSettings();
  } catch (error) {
    showStatus(error.message || "Failed to save settings.", true);
  }
}

async function handleLogout() {
  try {
    await postJson(ADMIN_ENDPOINTS.logout, {});
  } catch (error) {
    // Keep local logout reliable even if the endpoint is unavailable.
  }

  localStorage.removeItem(STAFF_SESSION_KEY);
  window.location.replace(ADMIN_LOGIN_ROUTE);
}

async function handleDocumentClick(event) {
  const button = event.target.closest("[data-admin-action]");
  if (!button) {
    return;
  }

  const action = button.dataset.adminAction;
  const id = Number(button.dataset.id || 0);

  if (action === "reservation-view") {
    const reservation = state.reservations.find((item) => Number(item.id) === id);
    if (reservation) {
      openReservationModal(reservation);
    }
    return;
  }

  if (action === "floor-select") {
    setSelectedFloor(Number(button.dataset.floorId || 0));
    return;
  }

  if (action === "floor-delete") {
    await handleDeleteFloor(id);
    return;
  }

  if (action === "slot-select") {
    setSelectedSlot(id);
    return;
  }

  if (action === "slot-delete") {
    await handleDeleteSlot(id);
    return;
  }

  if (action === "floor-toggle") {
    await postJson(ADMIN_ENDPOINTS.slots, {
      action: "update_floor",
      floor_id: id,
      is_active: button.dataset.active === "1"
    }).then(() => {
      showStatus("Floor status updated successfully.");
      return Promise.all([loadSlots(), loadDashboard()]);
    }).catch((error) => showStatus(error.message || "Failed to update floor status.", true));
    return;
  }

  if (action === "user-edit") {
    const user = state.users.find((item) => Number(item.id) === id);
    if (user) {
      openEntityEditor("user", user);
    }
    return;
  }

  if (action === "user-history") {
    await openViolationsHistoryModal(id);
    return;
  }

  if (action === "user-disable") {
    await confirmAndRun("Disable this user account?", async () => {
      await postJson(ADMIN_ENDPOINTS.users, { action: "disable", user_id: id });
      showStatus("User disabled successfully.");
      loadUsers();
    });
    return;
  }

  if (action === "user-activate") {
    await confirmAndRun("Activate this user account?", async () => {
      await postJson(ADMIN_ENDPOINTS.users, { action: "activate", user_id: id });
      showStatus("User activated successfully.");
      loadUsers();
    });
    return;
  }

  if (action === "user-unlock") {
    await confirmAndRun("Unlock this user account?", async () => {
      await postJson(ADMIN_ENDPOINTS.users, { action: "unlock", user_id: id });
      showStatus("User account unlocked successfully.");
      loadUsers();
    });
    return;
  }

  if (action === "user-delete") {
    await confirmAndRun("Delete this user account?", async () => {
      await postJson(ADMIN_ENDPOINTS.users, { action: "delete", user_id: id });
      showStatus("User deleted successfully.");
      loadUsers();
    });
    return;
  }

  if (action === "staff-edit") {
    const staff = state.staff.find((item) => Number(item.id) === id);
    if (staff) {
      openEntityEditor("staff", staff);
    }
    return;
  }

  if (action === "staff-delete") {
    await confirmAndRun("Delete this staff account?", async () => {
      await postJson(ADMIN_ENDPOINTS.staff, { action: "delete", staff_id: id });
      showStatus("Staff account deleted successfully.");
      loadStaff();
    });
    return;
  }

  if (action === "feedback-resolve") {
    await postJson(ADMIN_ENDPOINTS.feedback, { action: "resolve", message_id: id }).then(() => {
      showStatus("Feedback marked as resolved.");
      return loadFeedbackInbox().then(() => {
        if (Number(state.selectedFeedbackId) === Number(id)) {
          openFeedbackModal(id);
        }
      });
    }).catch((error) => showStatus(error.message || "Failed to update feedback.", true));
    return;
  }

  if (action === "feedback-read") {
    openFeedbackModal(id);
    return;
  }

  if (action === "feedback-delete") {
    await confirmAndRun("Delete this feedback message?", async () => {
      await postJson(ADMIN_ENDPOINTS.feedback, { action: "delete", message_id: id });
      showStatus("Feedback deleted successfully.");
      if (Number(state.selectedFeedbackId) === Number(id)) {
        closeFeedbackModal();
      }
      loadFeedbackInbox();
    });
    return;
  }

  if (action === "notification-delete") {
    await confirmAndRun("Delete this notification?", async () => {
      await postJson(ADMIN_ENDPOINTS.notifications, { action: "delete", notification_id: id });
      showStatus("Notification deleted successfully.");
      loadNotifications();
    });
    return;
  }

  if (action === "toggle-log-group") {
    const group = document.querySelector(`.report-group[data-group-date="${CSS.escape(button.dataset.groupDate || "")}"]`);
    if (group) {
      group.classList.toggle("is-collapsed");
      button.textContent = group.classList.contains("is-collapsed") ? "+" : "-";
    }
  }
}

async function handleSlotEditorSubmit(event) {
  event.preventDefault();
  const slotId = Number(refs.slotEditorId?.value || 0);

  if (slotId <= 0) {
    showStatus("Select a slot card first before saving.", true);
    return;
  }

  try {
    await postJson(ADMIN_ENDPOINTS.slots, {
      action: "update_slot",
      slot_id: slotId,
      slot_code: refs.slotEditorCode?.value?.trim() || "",
      manual_status: refs.slotEditorManualStatus?.value || "Auto",
      is_active: refs.slotEditorActive?.value === "1"
    });
    showStatus("Slot updated successfully.");
    await loadSlots();
    await loadDashboard();
    setSelectedSlot(slotId);
  } catch (error) {
    showStatus(error.message || "Failed to update slot.", true);
  }
}

async function handleDeleteSlot(slotId) {
  const slot = state.slotsData.slots.find((item) => Number(item.id) === Number(slotId));

  if (!slot) {
    showStatus("Parking slot not found.", true);
    return;
  }

  if (!window.confirm("Are you sure you want to delete this parking slot?")) {
    return;
  }

  try {
    await postJson(ADMIN_ENDPOINTS.deleteSlot, {
      slot_id: slotId
    });

    state.slotsData.slots = state.slotsData.slots.filter((item) => Number(item.id) !== Number(slotId));
    state.slotsData.floors = state.slotsData.floors.map((floor) => {
      if (Number(floor.id || 0) !== Number(slot.floor_id || 0)) {
        return floor;
      }

      const nextSlotCount = Math.max(0, Number(floor.slot_count || 0) - 1);
      const nextAvailableCount = slot.live_status === "Available"
        ? Math.max(0, Number(floor.available_count || 0) - 1)
        : Number(floor.available_count || 0);
      const nextReservedCount = slot.live_status === "Reserved"
        ? Math.max(0, Number(floor.reserved_count || 0) - 1)
        : Number(floor.reserved_count || 0);
      const nextOccupiedCount = slot.live_status === "Occupied"
        ? Math.max(0, Number(floor.occupied_count || 0) - 1)
        : Number(floor.occupied_count || 0);

      return {
        ...floor,
        slot_count: nextSlotCount,
        available_count: nextAvailableCount,
        reserved_count: nextReservedCount,
        occupied_count: nextOccupiedCount
      };
    });

    if (Number(state.selectedSlotId) === Number(slotId)) {
      state.selectedSlotId = null;
    }

    syncSlotSectionSelection();
    renderSlotManagementWorkspace();
    showStatus("Slot deleted successfully.");
    loadDashboard().catch(() => {
      // Keep the local UI responsive even if the background dashboard refresh fails.
    });
  } catch (error) {
    showStatus(error.message || "Failed to delete slot.", true);
  }
}

async function handleDeleteFloor(floorId) {
  const floor = state.slotsData.floors.find((item) => Number(item.id) === Number(floorId));

  if (!floor) {
    showStatus("Parking floor not found.", true);
    return;
  }

  if (!window.confirm("Are you sure you want to delete this parking floor?")) {
    return;
  }

  try {
    await postJson(ADMIN_ENDPOINTS.deleteFloor, {
      floor_id: floorId
    });

    state.slotsData.floors = state.slotsData.floors.filter((item) => Number(item.id) !== Number(floorId));
    state.slotsData.slots = state.slotsData.slots.filter((item) => Number(item.floor_id || 0) !== Number(floorId));

    if (Number(state.selectedFloorId || 0) === Number(floorId)) {
      state.selectedFloorId = null;
      state.selectedFloor = "";
      state.selectedSlotId = null;
    }

    syncSlotSectionSelection();
    renderSlotManagementWorkspace();
    showStatus("Floor deleted successfully.");
    loadDashboard().catch(() => {
      // Keep the slots workspace responsive even if dashboard refresh fails.
    });
  } catch (error) {
    showStatus(error.message || "Failed to delete floor.", true);
  }
}

function openReservationModal(record) {
  refs.reservationModalContent.innerHTML = `
    <div class="detail-grid">
      ${renderDetailCard("Barcode", record.barcode_value)}
      ${renderDetailCard("Name", record.full_name)}
      ${renderDetailCard("Email", record.email)}
      ${renderDetailCard("Floor", record.parking_floor)}
      ${renderDetailCard("Slot", record.parking_slot)}
      ${renderDetailCard("Reservation Date", formatDate(record.reservation_date))}
      ${renderDetailCard("Reserved Time In", formatTime(record.reserved_time_in))}
      ${renderDetailCard("Actual Time In", formatDateTime(record.actual_time_in))}
      ${renderDetailCard("Actual Time Out", formatDateTime(record.actual_time_out))}
      ${renderDetailCard("Status", record.booth_status || record.reservation_status)}
      ${renderDetailCard("Payment Status", record.payment_status)}
      ${renderDetailCard("Total Payment", formatCurrency(record.total_payment || 0))}
    </div>
  `;
  refs.reservationModal.hidden = false;
}

function closeReservationModal() {
  refs.reservationModal.hidden = true;
}

function openEntityEditor(type, record) {
  state.entityEditor = { type, record };
  refs.entityEditorKicker.textContent = type === "user" ? "Edit User" : "Edit Staff";
  refs.entityEditorTitle.textContent = type === "user" ? "Update user account" : "Update staff account";

  if (type === "user") {
    refs.entityEditorForm.innerHTML = `
      <div class="field-group">
        <label for="editor-full-name">Full Name</label>
        <input id="editor-full-name" name="full_name" type="text" value="${escapeHtml(record.full_name || "")}">
      </div>
      <div class="field-group">
        <label for="editor-email">Email</label>
        <input id="editor-email" name="email" type="email" value="${escapeHtml(record.email || "")}">
      </div>
      <div class="field-group">
        <label for="editor-birth-date">Birthday</label>
        <input id="editor-birth-date" name="birth_date" type="date" value="${escapeHtml(toDateInputValue(record.birth_date))}">
      </div>
      <div class="field-group">
        <label for="editor-status">Status</label>
        <select id="editor-status" name="status">
          <option value="Active" ${record.status === "Active" ? "selected" : ""}>Active</option>
          <option value="Disabled" ${record.status === "Disabled" ? "selected" : ""}>Disabled</option>
        </select>
      </div>
      <div class="editor-actions">
        <button class="secondary-btn" type="button" id="editor-cancel-btn">Cancel</button>
        <button class="primary-btn" type="submit">Save User</button>
      </div>
    `;
  } else {
    refs.entityEditorForm.innerHTML = `
      <div class="field-group">
        <label for="editor-staff-name">Name</label>
        <input id="editor-staff-name" name="full_name" type="text" value="${escapeHtml(record.full_name || "")}">
      </div>
      <div class="field-group">
        <label for="editor-staff-username">Username</label>
        <input id="editor-staff-username" name="username" type="text" value="${escapeHtml(record.username || "")}">
      </div>
      <div class="field-group">
        <label for="editor-staff-email">Email</label>
        <input id="editor-staff-email" name="email" type="email" value="${escapeHtml(record.email || "")}">
      </div>
      <div class="field-group">
        <label for="editor-staff-role">Role</label>
        <select id="editor-staff-role" name="role">
          <option value="booth" ${record.role === "booth" ? "selected" : ""}>Booth</option>
          <option value="admin" ${record.role === "admin" ? "selected" : ""}>Admin</option>
        </select>
      </div>
      <div class="field-group">
        <label for="editor-staff-active">Status</label>
        <select id="editor-staff-active" name="is_active">
          <option value="1" ${Number(record.is_active) === 1 ? "selected" : ""}>Active</option>
          <option value="0" ${Number(record.is_active) === 0 ? "selected" : ""}>Disabled</option>
        </select>
      </div>
      <div class="field-group">
        <label for="editor-staff-password">New Password</label>
        <input id="editor-staff-password" name="password" type="text" placeholder="Leave blank to keep current password">
      </div>
      <div class="editor-actions">
        <button class="secondary-btn" type="button" id="editor-cancel-btn">Cancel</button>
        <button class="primary-btn" type="submit">Save Staff</button>
      </div>
    `;
  }

  refs.entityEditorForm.querySelector("#editor-cancel-btn")?.addEventListener("click", closeEntityEditor);
  refs.entityEditorModal.hidden = false;
}

function closeEntityEditor() {
  refs.entityEditorModal.hidden = true;
  state.entityEditor = null;
  refs.entityEditorForm.innerHTML = "";
}

async function openViolationsHistoryModal(userId, filter = "") {
  const user = state.users.find((item) => Number(item.id) === Number(userId));
  const query = new URLSearchParams({ user_id: String(userId) });

  if (filter) {
    query.set("filter", filter);
  }

  refs.violationsHistoryTitle.textContent = user?.full_name
    ? `${user.full_name} violation history`
    : "User violation history";
  refs.violationsHistoryContent.innerHTML = `
    <section class="violations-loading-state">
      <p class="empty-table">Loading history...</p>
    </section>
  `;
  refs.violationsHistoryModal.hidden = false;

  try {
    const result = await fetchJson(`${ADMIN_ENDPOINTS.userViolations}?${query.toString()}`);
    state.violationsHistory = result?.data || null;
    renderViolationsHistoryModal();
  } catch (error) {
    refs.violationsHistoryContent.innerHTML = `
      <section class="violations-loading-state">
        <p class="empty-table">${escapeHtml(error.message || "Failed to load user violations history.")}</p>
      </section>
    `;
  }
}

function closeViolationsHistoryModal() {
  refs.violationsHistoryModal.hidden = true;
  state.violationsHistory = null;
  refs.violationsHistoryContent.innerHTML = "";
}

function renderViolationsHistoryModal() {
  const payload = state.violationsHistory || {};
  const user = payload.user || {};
  const violations = Array.isArray(payload.violations) ? payload.violations : [];
  const currentFilter = String(payload.filter || "");

  const filterOptions = [
    ["", "All"],
    ["warnings", "Warnings"],
    ["locks", "Lock Events"],
    ["unlocks", "Unlock Events"],
    ["expired", "Barcode Expired"]
  ];

  refs.violationsHistoryTitle.textContent = user.full_name
    ? `${user.full_name} violation history`
    : "User violation history";

  refs.violationsHistoryContent.innerHTML = `
    <section class="violations-summary-grid">
      ${renderDetailCard("User Name", user.full_name || "--")}
      ${renderDetailCard("Email", user.email || "--")}
      ${renderDetailCard("Current Status", resolveUserDisplayStatus(user))}
      ${renderDetailCard("Warning Count", String(user.warning_count || 0))}
      ${renderDetailCard("Lock Until", formatDateTime(user.account_locked_until))}
      ${renderDetailCard("Created", formatDateTime(user.created_at))}
    </section>

    <section class="violations-filter-row">
      <label class="field-group violations-filter-field">
        <span>Filter History</span>
        <select id="violations-filter-select">
          ${filterOptions.map(([value, label]) => `
            <option value="${escapeHtml(value)}" ${value === currentFilter ? "selected" : ""}>${escapeHtml(label)}</option>
          `).join("")}
        </select>
      </label>
    </section>

    <div class="table-shell">
      <table class="admin-table violations-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Violation Type</th>
            <th>Description</th>
            <th>Related Reservation ID</th>
            <th>Created By</th>
          </tr>
        </thead>
        <tbody>
          ${violations.length ? violations.map((entry) => `
            <tr>
              <td class="violations-cell-date">${escapeHtml(formatDateTime(entry.created_at))}</td>
              <td>${renderViolationTypeBadge(entry.violation_type || "--")}</td>
              <td class="violations-cell-description">${escapeHtml(entry.description || "--")}</td>
              <td class="violations-cell-reservation">${escapeHtml(String(entry.related_reservation_id || "--"))}</td>
              <td>${renderStatusPill(formatActorLabel(entry.created_by || "system"))}</td>
            </tr>
          `).join("") : `<tr><td colspan="5" class="empty-table">No violation history found for this filter.</td></tr>`}
        </tbody>
      </table>
    </div>
  `;

  document.getElementById("violations-filter-select")?.addEventListener("change", (event) => {
    openViolationsHistoryModal(Number(user.id || 0), event.target.value || "");
  });
}

async function handleEntityEditorSubmit(event) {
  event.preventDefault();

  if (!state.entityEditor) {
    return;
  }

  const formData = new FormData(refs.entityEditorForm);

  try {
    if (state.entityEditor.type === "user") {
      await postJson(ADMIN_ENDPOINTS.users, {
        action: "update",
        user_id: state.entityEditor.record.id,
        full_name: String(formData.get("full_name") || "").trim(),
        email: String(formData.get("email") || "").trim(),
        birth_date: String(formData.get("birth_date") || "").trim(),
        status: String(formData.get("status") || "Active")
      });
      showStatus("User updated successfully.");
      closeEntityEditor();
      loadUsers();
      return;
    }

    await postJson(ADMIN_ENDPOINTS.staff, {
      action: "update",
      staff_id: state.entityEditor.record.id,
      full_name: String(formData.get("full_name") || "").trim(),
      username: String(formData.get("username") || "").trim(),
      email: String(formData.get("email") || "").trim(),
      role: String(formData.get("role") || "booth"),
      is_active: String(formData.get("is_active") || "1") === "1",
      password: String(formData.get("password") || "").trim()
    });
    showStatus("Staff account updated successfully.");
    closeEntityEditor();
    loadStaff();
  } catch (error) {
    showStatus(error.message || "Failed to save changes.", true);
  }
}

function renderDetailCard(label, value) {
  return `
    <article class="detail-card">
      <span>${escapeHtml(label)}</span>
      <strong>${escapeHtml(value || "--")}</strong>
    </article>
  `;
}

function renderViolationTypeBadge(type) {
  const rawType = String(type || "--");
  const label = rawType
    .replaceAll("_", " ")
    .replace(/\b\w/g, (character) => character.toUpperCase());

  return `<span class="status-pill ${mapStatusTone(rawType)}">${escapeHtml(label)}</span>`;
}

function formatActorLabel(value) {
  const actor = String(value || "system").trim().toLowerCase();
  if (actor === "admin") {
    return "Admin";
  }

  if (actor === "system") {
    return "System";
  }

  return actor.replace(/\b\w/g, (character) => character.toUpperCase());
}

async function fetchJson(url) {
  const response = await fetch(url, {
    credentials: "same-origin",
    headers: {
      Accept: "application/json"
    }
  });
  const result = await parseJsonResponse(response);

  if ([401, 403, 419].includes(response.status)) {
    localStorage.removeItem(STAFF_SESSION_KEY);
    window.location.replace(ADMIN_LOGIN_ROUTE);
    throw new Error(result?.message || "Your admin session expired.");
  }

  if (!response.ok || result?.success === false) {
    throw new Error(result?.message || "Request failed.");
  }

  syncAdminSessionFromResponse(result);
  return result;
}

async function postJson(url, payload) {
  const session = loadStaffSession();
  const csrfToken = session?.csrfToken || "";
  const response = await fetch(url, {
    method: "POST",
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(csrfToken ? { "X-CSRF-Token": csrfToken } : {})
    },
    body: JSON.stringify({
      _csrf_token: csrfToken,
      ...payload
    })
  });
  const result = await parseJsonResponse(response);

  if ([401, 403, 419].includes(response.status)) {
    localStorage.removeItem(STAFF_SESSION_KEY);
    window.location.replace(ADMIN_LOGIN_ROUTE);
    throw new Error(result?.message || "Your admin session expired.");
  }

  if (!response.ok || result?.success === false) {
    throw new Error(result?.message || "Request failed.");
  }

  syncAdminSessionFromResponse(result);
  return result;
}

async function parseJsonResponse(response) {
  const raw = (await response.text()).replace(/^\uFEFF/, "");
  return raw.trim() ? JSON.parse(raw) : {};
}

function requireAdminSession() {
  const session = loadStaffSession();

  if (!session || session.role !== "admin") {
    window.location.replace(ADMIN_LOGIN_ROUTE);
    return null;
  }

  return session;
}

function loadStaffSession() {
  try {
    const session = JSON.parse(localStorage.getItem(STAFF_SESSION_KEY) || "null");
    return session && typeof session === "object" ? session : null;
  } catch (error) {
    return null;
  }
}

function syncAdminSessionFromResponse(result) {
  const csrfToken = result?.data?.csrfToken;
  const currentSession = loadStaffSession();

  if (!csrfToken || !currentSession) {
    return;
  }

  localStorage.setItem(STAFF_SESSION_KEY, JSON.stringify({
    ...currentSession,
    csrfToken
  }));
  syncAdminCsrfFields();
}

function syncAdminCsrfFields() {
  const csrfToken = loadStaffSession()?.csrfToken || "";

  document.querySelectorAll("form").forEach((form) => {
    let csrfField = form.querySelector('input[name="_csrf_token"]');

    if (!csrfField) {
      csrfField = document.createElement("input");
      csrfField.type = "hidden";
      csrfField.name = "_csrf_token";
      form.appendChild(csrfField);
    }

    csrfField.value = csrfToken;
  });
}

function showStatus(message, isError = false) {
  if (!refs.globalStatus) {
    return;
  }

  if (state.activeSection !== "slots") {
    refs.globalStatus.hidden = true;
    refs.globalStatus.textContent = "";
    refs.globalStatus.className = "admin-status";
    return;
  }

  refs.globalStatus.hidden = false;
  refs.globalStatus.textContent = message || "";
  refs.globalStatus.className = `admin-status ${message ? (isError ? "is-error" : "is-success") : ""}`;
}

function setText(id, value) {
  const element = document.getElementById(id);
  if (element) {
    element.textContent = value;
  }
}

function setFormValue(form, name, value) {
  const field = form?.elements?.namedItem(name);
  if (field) {
    field.value = value;
  }
}

function updateLiveClock() {
  refs.liveDatetime.textContent = new Date().toLocaleString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit"
  });
}

function renderStatusPill(status) {
  const label = String(status || "--");
  return `<span class="status-pill ${mapStatusTone(label)}">${escapeHtml(label)}</span>`;
}

function resolveUserDisplayStatus(user) {
  const accountStatus = String(user?.account_status || "active").toLowerCase();
  const userStatus = String(user?.status || "Active");

  if (accountStatus === "locked") {
    return "Locked";
  }

  if (userStatus === "Disabled") {
    return "Disabled";
  }

  return "Active";
}

function renderUserStatusCell(user) {
  const displayStatus = resolveUserDisplayStatus(user);
  const warningCount = Number(user?.warning_count || 0);
  const violationCount = Number(user?.violation_count || 0);
  const lockedUntil = user?.account_locked_until ? formatDateTime(user.account_locked_until) : "";
  const warningMeta = warningCount > 0 && displayStatus !== "Locked"
    ? `<span class="user-status-meta">Warnings: ${escapeHtml(String(warningCount))}${user?.first_warning_at ? ` | Since ${escapeHtml(formatDateTime(user.first_warning_at))}` : ""}</span>`
    : "";
  const lockedMeta = displayStatus === "Locked" && lockedUntil
    ? `<span class="user-status-meta">Until ${escapeHtml(lockedUntil)}</span>`
    : "";
  const violationMeta = violationCount > 0
    ? `<span class="user-status-meta">Latest violation: ${escapeHtml(String(user?.latest_violation_type || "recorded").replaceAll("_", " "))}${user?.latest_violation_at ? ` | ${escapeHtml(formatDateTime(user.latest_violation_at))}` : ""}</span>`
    : "";
  const metaParts = [warningMeta, lockedMeta, violationMeta].filter(Boolean).join("");

  return `
    <div class="user-status-stack">
      ${renderStatusPill(displayStatus)}
      ${metaParts}
    </div>
  `;
}

function mapStatusTone(status) {
  const value = String(status || "").toLowerCase();

  if (["paid", "active", "available", "resolved", "completed", "replied"].includes(value)) {
    return "success";
  }

  if (["reserved", "pending", "unpaid", "barcode_expired", "first_warning", "second_warning"].includes(value)) {
    return "warning";
  }

  if (["occupied", "disabled", "cancelled", "deleted", "inactive", "void", "exited", "expired", "locked", "account_locked"].includes(value)) {
    return "danger";
  }

  if (["parked", "admin", "account_unlocked", "admin_unlock", "system"].includes(value)) {
    return "ready";
  }

  if (["warning_reset"].includes(value)) {
    return "neutral";
  }

  return "neutral";
}

function formatCount(value) {
  return String(Number(value || 0));
}

function formatCurrency(value) {
  const amount = Number(value || 0);
  return `PHP ${amount.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(value) {
  if (!value) {
    return "--";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return date.toLocaleDateString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric"
  });
}

function formatTime(value) {
  if (!value) {
    return "--";
  }

  const source = String(value);
  const date = new Date(`1970-01-01T${source}`);
  if (Number.isNaN(date.getTime())) {
    return source;
  }

  return date.toLocaleTimeString("en-PH", {
    hour: "numeric",
    minute: "2-digit"
  });
}

function formatDateTime(value) {
  if (!value) {
    return "--";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return date.toLocaleString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit"
  });
}

function toDateInputValue(value) {
  if (!value) {
    return "";
  }

  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) {
    return "";
  }

  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

async function confirmAndRun(message, callback) {
  if (!window.confirm(message)) {
    return;
  }

  await callback();
}
