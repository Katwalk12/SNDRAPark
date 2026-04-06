(function () {
  const POLL_INTERVAL_MS = 5000;
  const MODAL_ID = "notification-detail-modal";

  function getProjectRoot() {
    const pathSegments = window.location.pathname.split("/").filter(Boolean);
    const frontendIndex = pathSegments.indexOf("frontend");
    const backendIndex = pathSegments.indexOf("backend");
    const projectIndex = frontendIndex >= 0 ? frontendIndex : backendIndex >= 0 ? backendIndex : pathSegments.length;

    return projectIndex > 0 ? `/${pathSegments.slice(0, projectIndex).join("/")}` : "";
  }

  function getProjectBasePath() {
    if (typeof window.getSndraProjectBasePath === "function") {
      return window.getSndraProjectBasePath();
    }

    return getProjectRoot();
  }

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function formatTimestamp(value) {
    if (!value) {
      return "Just now";
    }

    const normalized = String(value).includes("T") ? String(value) : String(value).replace(" ", "T");
    const date = new Date(normalized);

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

  function setBadgeCount(badge, count) {
    if (!badge) {
      return;
    }

    if (count > 0) {
      badge.textContent = count > 99 ? "99+" : String(count);
      badge.classList.remove("is-hidden");
      return;
    }

    badge.textContent = "0";
    badge.classList.add("is-hidden");
  }

  function buildInvalidJsonError(response, rawText, contextLabel) {
    const preview = String(rawText || "").trim().slice(0, 180);
    const looksLikeHtml = preview.startsWith("<") || /<!DOCTYPE|<html/i.test(preview);

    console.error(`Invalid JSON response during ${contextLabel}:`, {
      url: response?.url || "unknown endpoint",
      status: response?.status,
      preview
    });

    return new Error(
      looksLikeHtml
        ? `${contextLabel} failed because the backend returned HTML instead of JSON.`
        : `${contextLabel} failed because the backend returned invalid JSON.`
    );
  }

  function parseJsonResponse(response, contextLabel = "notification request") {
    return response.text().then((rawText) => {
      const trimmed = rawText.trim();

      if (!trimmed) {
        return {};
      }

      try {
        return JSON.parse(trimmed);
      } catch (error) {
        throw buildInvalidJsonError(response, rawText, contextLabel);
      }
    });
  }

  function getOrCreateModal() {
    let modal = document.getElementById(MODAL_ID);

    if (modal) {
      return modal;
    }

    modal = document.createElement("div");
    modal.id = MODAL_ID;
    modal.className = "notification-modal";
    modal.hidden = true;
    modal.innerHTML = `
      <div class="notification-modal-card" role="dialog" aria-modal="true" aria-labelledby="notification-modal-title">
        <div class="notification-modal-header">
          <div>
            <p class="notification-modal-kicker">Notification Details</p>
            <h3 class="notification-modal-title" id="notification-modal-title">Notification</h3>
          </div>
          <button class="notification-modal-close" type="button" data-notification-modal-close aria-label="Close notification details">&times;</button>
        </div>
        <div class="notification-modal-body" data-notification-modal-body>
          <div class="notification-detail-grid">
            <article class="notification-detail-card">
              <span class="notification-detail-label">Loading</span>
              <p>Preparing notification details...</p>
            </article>
          </div>
        </div>
        <div class="notification-modal-footer">
          <button class="notification-modal-action" type="button" data-notification-modal-close>Close</button>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    modal.addEventListener("click", (event) => {
      if (event.target === modal || event.target.closest("[data-notification-modal-close]")) {
        closeModal(modal);
      }
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !modal.hidden) {
        closeModal(modal);
      }
    });

    return modal;
  }

  function openModal() {
    const modal = getOrCreateModal();
    modal.hidden = false;
    document.body.classList.add("modal-open");
    return modal;
  }

  function closeModal(modal = getOrCreateModal()) {
    modal.hidden = true;
    document.body.classList.remove("modal-open");
  }

  function renderModalContent(notification) {
    const modal = openModal();
    const title = modal.querySelector("#notification-modal-title");
    const body = modal.querySelector("[data-notification-modal-body]");
    const statusLabel = Number(notification?.is_read || 0) === 0 ? "Unread" : "Read";

    if (title) {
      title.textContent = notification?.title || "Notification";
    }

    if (body) {
      body.innerHTML = `
        <div class="notification-detail-grid">
          <article class="notification-detail-card">
            <span class="notification-detail-label">Title</span>
            <strong>${escapeHtml(notification?.title || "Notification")}</strong>
          </article>
          <article class="notification-detail-card">
            <span class="notification-detail-label">Message</span>
            <p>${escapeHtml(notification?.message || "")}</p>
          </article>
          <article class="notification-detail-card">
            <span class="notification-detail-label">Date</span>
            <strong>${escapeHtml(formatTimestamp(notification?.created_at || notification?.notification_date || ""))}</strong>
          </article>
          <article class="notification-detail-card">
            <span class="notification-detail-label">Status</span>
            <strong>${escapeHtml(statusLabel)}</strong>
          </article>
        </div>
      `;
    }
  }

  function renderModalLoading() {
    const modal = openModal();
    const title = modal.querySelector("#notification-modal-title");
    const body = modal.querySelector("[data-notification-modal-body]");

    if (title) {
      title.textContent = "Loading Notification";
    }

    if (body) {
      body.innerHTML = `
        <div class="notification-detail-grid">
          <article class="notification-detail-card">
            <span class="notification-detail-label">Loading</span>
            <p>Preparing notification details...</p>
          </article>
        </div>
      `;
    }
  }

  function renderModalError(message) {
    const modal = openModal();
    const title = modal.querySelector("#notification-modal-title");
    const body = modal.querySelector("[data-notification-modal-body]");

    if (title) {
      title.textContent = "Notification Unavailable";
    }

    if (body) {
      body.innerHTML = `
        <div class="notification-detail-grid">
          <article class="notification-detail-card">
            <span class="notification-detail-label">Error</span>
            <p>${escapeHtml(message || "Unable to load notification details.")}</p>
          </article>
        </div>
      `;
    }
  }

  function renderNotifications(listElement, notifications) {
    if (!listElement) {
      return;
    }

    if (!notifications.length) {
      listElement.innerHTML = '<div class="notification-empty">No notifications right now.</div>';
      return;
    }

    listElement.innerHTML = notifications.map((notification) => {
      const unreadClass = Number(notification.is_read || 0) === 0 ? " is-unread" : "";

      return `
        <button class="notification-item${unreadClass}" type="button" data-notification-id="${escapeHtml(notification.id)}">
          <div class="notification-item-title-row">
            <h4 class="notification-item-title">${escapeHtml(notification.title || "Notification")}</h4>
            <span class="notification-item-time">${escapeHtml(formatTimestamp(notification.created_at))}</span>
          </div>
          <p class="notification-item-message notification-item-preview">${escapeHtml(notification.message || "")}</p>
          <div class="notification-item-footer">
            <span class="notification-item-time">${Number(notification.is_read || 0) === 0 ? "Unread" : "Read"}</span>
            <span class="notification-item-open">Open Details</span>
          </div>
        </button>
      `;
    }).join("");
  }

  function initNotificationCenter(root) {
    const projectRoot = `${window.location.origin}${getProjectBasePath()}`;
    const userType = root.dataset.userType || "user";
    const toggle = root.querySelector("[data-notification-toggle]");
    const panel = root.querySelector("[data-notification-panel]");
    const badge = root.querySelector("[data-notification-count]");
    const meta = root.querySelector("[data-notification-meta]");
    const list = root.querySelector("[data-notification-list]");
    const getEndpoint = root.dataset.notificationEndpoint
      ? new URL(root.dataset.notificationEndpoint, window.location.href).toString()
      : `${projectRoot}/backend/notifications/get-notifications.php`;
    const getSingleEndpoint = `${projectRoot}/backend/notifications/get-single.php`;
    const markReadEndpoint = `${projectRoot}/backend/notifications/mark-read.php`;

    let isOpen = false;
    let unreadCount = 0;
    let notifications = [];

    function closePanel() {
      isOpen = false;

      if (toggle) {
        toggle.setAttribute("aria-expanded", "false");
      }

      if (panel) {
        panel.hidden = true;
      }
    }

    function updateMeta() {
      if (!meta) {
        return;
      }

      meta.textContent = unreadCount > 0 ? `${unreadCount} unread` : "All caught up";
    }

    function updateNotificationReadState(notificationId) {
      const nextId = Number(notificationId || 0);
      notifications = notifications.map((notification) => {
        if (Number(notification.id || 0) !== nextId) {
          return notification;
        }

        return {
          ...notification,
          is_read: 1
        };
      });

      unreadCount = notifications.filter((notification) => Number(notification.is_read || 0) === 0).length;
      setBadgeCount(badge, unreadCount);
      updateMeta();
      renderNotifications(list, notifications);
    }

    async function markAsRead(notificationId) {
      const response = await fetch(markReadEndpoint, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json"
        },
        credentials: "same-origin",
        body: JSON.stringify({
          user_type: userType,
          id: notificationId
        })
      });
      const result = await parseJsonResponse(response, "mark notification as read");

      if (!response.ok || result?.success === false) {
        throw new Error(result?.message || "Failed to mark notification as read.");
      }

      unreadCount = Number(result.count ?? unreadCount);
      setBadgeCount(badge, unreadCount);
      updateNotificationReadState(notificationId);
    }

    async function fetchNotificationDetails(notificationId) {
      const url = new URL(getSingleEndpoint, window.location.href);
      url.searchParams.set("id", String(notificationId));
      url.searchParams.set("user_type", userType);

      const response = await fetch(url.toString(), {
        method: "GET",
        headers: {
          Accept: "application/json"
        },
        credentials: "same-origin",
        cache: "no-store"
      });
      const result = await parseJsonResponse(response, "load notification details");

      if (!response.ok || result?.success === false) {
        throw new Error(result?.message || "Failed to load notification details.");
      }

      return result.notification || null;
    }

    async function openNotificationDetails(notificationId) {
      const nextId = Number(notificationId || 0);

      if (nextId <= 0) {
        return;
      }

      renderModalLoading();

      try {
        const notification = await fetchNotificationDetails(nextId);
        renderModalContent(notification);

        if (Number(notification?.is_read || 0) === 0) {
          try {
            await markAsRead(nextId);
            notification.is_read = 1;
            renderModalContent(notification);
          } catch (error) {
            console.error("Notification mark-read error:", error);
          }
        }
      } catch (error) {
        console.error("Notification details error:", error);
        renderModalError(error.message || "Unable to load notification details.");
      }
    }

    async function loadNotifications() {
      try {
        const url = new URL(getEndpoint, window.location.href);
        url.searchParams.set("user_type", userType);
        const response = await fetch(url.toString(), {
          method: "GET",
          headers: {
            Accept: "application/json"
          },
          credentials: "same-origin",
          cache: "no-store"
        });
        const result = await parseJsonResponse(response, "load notifications");

        if (!response.ok || result?.success === false) {
          throw new Error(result?.message || "Failed to load notifications.");
        }

        notifications = Array.isArray(result.notifications) ? result.notifications : [];
        unreadCount = Number(result.count || 0);

        renderNotifications(list, notifications);
        setBadgeCount(badge, unreadCount);
        updateMeta();
      } catch (error) {
        console.error("Notification fetch error:", error);
        notifications = [];
        unreadCount = 0;
        renderNotifications(list, []);

        if (meta) {
          meta.textContent = "Unavailable";
        }
      }
    }

    toggle?.addEventListener("click", () => {
      isOpen = !isOpen;
      toggle.setAttribute("aria-expanded", String(isOpen));

      if (panel) {
        panel.hidden = !isOpen;
      }

      if (isOpen) {
        loadNotifications();
      }
    });

    list?.addEventListener("click", (event) => {
      const item = event.target.closest("[data-notification-id]");

      if (!item) {
        return;
      }

      event.preventDefault();
      const notificationId = Number(item.dataset.notificationId || 0);
      openNotificationDetails(notificationId);
    });

    document.addEventListener("click", (event) => {
      if (!root.contains(event.target)) {
        closePanel();
      }
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        closePanel();
      }
    });

    closePanel();
    getOrCreateModal();
    loadNotifications();
    window.setInterval(loadNotifications, POLL_INTERVAL_MS);
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("[data-notification-root]").forEach((root) => {
      initNotificationCenter(root);
    });
  });
})();
