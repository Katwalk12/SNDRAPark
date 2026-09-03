(function () {
  const DEFAULT_SETTINGS = {
    system_name: "SNDRA Park",
    contact_number: "+63 917 555 0142",
    gmail_address: "sndraparksupport@gmail.com",
    parking_base_rate: 20,
    extra_hourly_rate: 10
  };

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

  function normalizeSettings(settings) {
    const source = settings && typeof settings === "object" ? settings : {};

    return {
      system_name: String(source.system_name || DEFAULT_SETTINGS.system_name).trim() || DEFAULT_SETTINGS.system_name,
      contact_number: String(source.contact_number || DEFAULT_SETTINGS.contact_number).trim() || DEFAULT_SETTINGS.contact_number,
      gmail_address: String(source.gmail_address || DEFAULT_SETTINGS.gmail_address).trim() || DEFAULT_SETTINGS.gmail_address,
      parking_base_rate: normalizeNumber(source.parking_base_rate, DEFAULT_SETTINGS.parking_base_rate),
      extra_hourly_rate: normalizeNumber(source.extra_hourly_rate, DEFAULT_SETTINGS.extra_hourly_rate)
    };
  }

  function normalizeNumber(value, fallback) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
  }

  function formatCurrency(value) {
    return `PHP ${normalizeNumber(value, 0).toFixed(2)}`;
  }

  function normalizePhoneHref(value) {
    return String(value || "").replace(/[^\d+]/g, "");
  }

  function isBrandSegment(value, settings) {
    const normalized = String(value || "").trim().toLowerCase();

    return (
      !normalized ||
      normalized === "sndra park" ||
      normalized === "sndrapark" ||
      normalized === String(settings.system_name || "").trim().toLowerCase()
    );
  }

  function getTitleSuffix(settings) {
    const bodySuffix = document.body?.dataset.pageTitleSuffix?.trim();

    if (bodySuffix) {
      return bodySuffix;
    }

    // Pages use both "Brand | Page" and "Page | Brand", so drop whichever
    // segment is the brand instead of assuming it comes first.
    return String(document.title || "")
      .split("|")
      .map((part) => part.trim())
      .filter((part) => !isBrandSegment(part, settings))
      .join(" | ");
  }

  function replaceLegacyText(settings) {
    const brandReplacements = [
      [/SNDRAPark/g, settings.system_name],
      [/SNDRA PARK/g, settings.system_name.toUpperCase()],
      [/SNDRA Park/g, settings.system_name]
    ];

    const contactReplacements = [
      [/sndrapark\.emulator@gmail\.com/g, settings.gmail_address],
      [/support@sndrapark\.com/g, settings.gmail_address],
      [/\+63 917 555 0142/g, settings.contact_number]
    ];

    function rewrite(value) {
      let nextValue = value;

      // "SNDRA Park" is a prefix of the configured name, so swapping it into
      // text that already carries that name appends the suffix a second time.
      const alreadyBranded =
        nextValue.includes(settings.system_name) ||
        nextValue.includes(settings.system_name.toUpperCase());

      if (!alreadyBranded) {
        brandReplacements.forEach(([pattern, replacement]) => {
          nextValue = nextValue.replace(pattern, replacement);
        });
      }

      contactReplacements.forEach(([pattern, replacement]) => {
        nextValue = nextValue.replace(pattern, replacement);
      });

      return nextValue;
    }

    const walker = document.createTreeWalker(document.body || document.documentElement, NodeFilter.SHOW_TEXT, null);
    const textNodes = [];

    while (walker.nextNode()) {
      textNodes.push(walker.currentNode);
    }

    textNodes.forEach((node) => {
      const parent = node.parentElement;
      const parentTag = parent?.tagName;

      if (!node.nodeValue || parentTag === "SCRIPT" || parentTag === "STYLE") {
        return;
      }

      // applyConfiguredElements already wrote these nodes from the settings.
      if (parent?.closest("[data-system-setting]")) {
        return;
      }

      const nextValue = rewrite(node.nodeValue);

      if (nextValue !== node.nodeValue) {
        node.nodeValue = nextValue;
      }
    });

    document.querySelectorAll("[aria-label], [title], [alt]").forEach((element) => {
      if (element.closest("[data-system-setting]")) {
        return;
      }

      ["aria-label", "title", "alt"].forEach((attributeName) => {
        const currentValue = element.getAttribute(attributeName);

        if (!currentValue) {
          return;
        }

        const nextValue = rewrite(currentValue);

        if (nextValue !== currentValue) {
          element.setAttribute(attributeName, nextValue);
        }
      });
    });
  }

  function applyConfiguredElements(settings) {
    document.querySelectorAll("[data-system-setting]").forEach((element) => {
      const settingKey = element.dataset.systemSetting;
      const format = element.dataset.systemSettingFormat || "";
      const target = element.dataset.systemSettingTarget || "text";
      const attributeName = element.dataset.systemSettingAttr || "";
      const linkType = element.dataset.systemSettingLink || "";
      const rawValue = settings[settingKey];
      const formattedValue = format === "currency" ? formatCurrency(rawValue) : String(rawValue ?? "");

      if (target === "value" && "value" in element) {
        element.value = formattedValue;
      } else if (target !== "attribute") {
        element.textContent = formattedValue;
      }

      if (attributeName) {
        let attributeValue = formattedValue;

        if (linkType === "mailto") {
          attributeValue = `mailto:${settings[settingKey] || ""}`;
        } else if (linkType === "tel") {
          attributeValue = `tel:${normalizePhoneHref(settings[settingKey] || "")}`;
        } else if (linkType === "raw") {
          attributeValue = String(rawValue ?? "");
        }

        element.setAttribute(attributeName, attributeValue);
      }
    });
  }

  function updateKnownContactLinks(settings) {
    document.querySelectorAll('a[href="mailto:sndraparksupport@gmail.com"], a[href="mailto:support@sndrapark.com"]').forEach((link) => {
      link.setAttribute("href", `mailto:${settings.gmail_address}`);
    });

    document.querySelectorAll('a[href="tel:+639175550142"]').forEach((link) => {
      link.setAttribute("href", `tel:${normalizePhoneHref(settings.contact_number)}`);
    });
  }

  function applySettings(settings) {
    const normalized = normalizeSettings(settings);
    const suffix = getTitleSuffix(normalized);

    window.SNDRA_SYSTEM_SETTINGS = normalized;
    document.title = suffix ? `${normalized.system_name} | ${suffix}` : normalized.system_name;

    applyConfiguredElements(normalized);
    updateKnownContactLinks(normalized);
    replaceLegacyText(normalized);

    window.dispatchEvent(new CustomEvent("sndra:system-settings-updated", {
      detail: {
        settings: normalized
      }
    }));

    return normalized;
  }

  function buildInvalidJsonError(response, rawText) {
    const preview = String(rawText || "").trim().slice(0, 180);
    const looksLikeHtml = preview.startsWith("<") || /<!DOCTYPE|<html/i.test(preview);

    console.error("Invalid system settings response:", {
      url: response?.url || "unknown endpoint",
      status: response?.status,
      preview
    });

    return new Error(
      looksLikeHtml
        ? "System settings endpoint returned HTML instead of JSON."
        : "System settings endpoint returned invalid JSON."
    );
  }

  async function parseJsonResponse(response) {
    const rawText = await response.text();
    const trimmed = rawText.trim();

    if (!trimmed) {
      return {};
    }

    try {
      return JSON.parse(trimmed);
    } catch (error) {
      throw buildInvalidJsonError(response, rawText);
    }
  }

  async function loadSettings() {
    const endpoint = getBackendUrl("/backend/config/get-system-settings.php");

    try {
      const response = await fetch(endpoint, {
        method: "GET",
        headers: {
          Accept: "application/json"
        },
        credentials: "same-origin",
        cache: "no-store"
      });
      const payload = await parseJsonResponse(response);

      if (!response.ok || payload?.success === false) {
        throw new Error(payload?.message || "Failed to load system settings.");
      }

      return applySettings(payload?.data?.settings || payload?.settings || {});
    } catch (error) {
      console.error("System settings fetch error:", error);
      return applySettings(DEFAULT_SETTINGS);
    }
  }

  window.getSndraSystemSettings = function getSndraSystemSettings() {
    return normalizeSettings(window.SNDRA_SYSTEM_SETTINGS || DEFAULT_SETTINGS);
  };

  window.setSndraSystemSettings = function setSndraSystemSettings(settings) {
    return applySettings(settings);
  };

  window.ensureSndraSystemSettingsLoaded = function ensureSndraSystemSettingsLoaded() {
    return window.SNDRA_SYSTEM_SETTINGS_READY;
  };

  window.SNDRA_SYSTEM_SETTINGS = normalizeSettings(DEFAULT_SETTINGS);
  window.SNDRA_SYSTEM_SETTINGS_READY = loadSettings();
})();
