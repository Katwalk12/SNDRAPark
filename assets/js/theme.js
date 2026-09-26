/* Theme switching for SNDRA Park.
 *
 * The stylesheets do the actual work: design-system.css themes anyone whose OS
 * is dark through a media query, and themes an explicit choice through
 * :root[data-theme]. This file only decides which of those applies and gives
 * the user something to click.
 *
 * Three states, not two. "light" and "dark" are explicit choices that override
 * the OS; absent means follow the OS, and the attribute is removed entirely so
 * the media query governs again. Storing "light" when the OS is light is not
 * the same thing -- it pins the page light even if the OS later goes dark.
 *
 * The no-flash snippet inlined in each page's <head> is what stops a dark-mode
 * visitor seeing a white page for one frame; it runs the same storage read
 * before the first paint. This file must agree with it on the storage key.
 */
(function () {
  "use strict";

  var KEY = "sndrapark-theme";
  var root = document.documentElement;
  var media = window.matchMedia ? window.matchMedia("(prefers-color-scheme: dark)") : null;

  /* Every storage access is wrapped: a browser in private mode, or with site
     data blocked, throws on read as readily as on write. The theme is a
     convenience, so losing it must never take the page down with it. */
  function stored() {
    try {
      var v = localStorage.getItem(KEY);
      return v === "dark" || v === "light" ? v : null;
    } catch (e) {
      return null;
    }
  }

  function store(value) {
    try {
      if (value) {
        localStorage.setItem(KEY, value);
      } else {
        localStorage.removeItem(KEY);
      }
    } catch (e) {
      /* ignore: the choice still applies to this page, it just will not persist */
    }
  }

  function systemTheme() {
    return media && media.matches ? "dark" : "light";
  }

  function effective() {
    return stored() || systemTheme();
  }

  function apply(choice) {
    if (choice) {
      root.setAttribute("data-theme", choice);
    } else {
      root.removeAttribute("data-theme");
    }
    render();
  }

  var button = null;

  function render() {
    if (!button) { return; }
    var isDark = effective() === "dark";
    button.setAttribute("aria-pressed", isDark ? "true" : "false");
    var label = isDark ? "Switch to light theme" : "Switch to dark theme";
    button.setAttribute("aria-label", label);
    button.setAttribute("title", label + (stored() ? "" : " (currently following your system)"));
    button.innerHTML = isDark ? SUN : MOON;
  }

  var MOON =
    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"' +
    ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';

  var SUN =
    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"' +
    ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41' +
    'M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';

  function toggle() {
    /* Flip away from what is on screen now, whether that came from a stored
       choice or from the OS. */
    apply(effective() === "dark" ? "light" : "dark");
    store(root.getAttribute("data-theme"));
  }

  function mount() {
    /* A page can host the control itself by putting [data-theme-toggle] in its
       markup; otherwise it gets the floating one. */
    button = document.querySelector("[data-theme-toggle]");
    if (!button) {
      button = document.createElement("button");
      button.type = "button";
      button.className = "ds-theme-toggle";
      button.setAttribute("data-theme-toggle", "");
      document.body.appendChild(button);
    }
    button.addEventListener("click", toggle);
    render();
  }

  /* Following the OS means reacting when the OS changes mid-session, but only
     while the user has not overridden it. */
  if (media) {
    var onChange = function () { if (!stored()) { render(); } };
    if (media.addEventListener) { media.addEventListener("change", onChange); }
    else if (media.addListener) { media.addListener(onChange); }
  }

  /* Keep other tabs of the app in step. */
  window.addEventListener("storage", function (e) {
    if (e.key === KEY) { apply(stored()); }
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mount);
  } else {
    mount();
  }
})();
