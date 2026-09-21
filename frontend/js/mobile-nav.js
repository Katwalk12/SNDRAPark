/**
 * mobile-nav.js — off-canvas sidebar for the console pages.
 *
 * Markup contract (see responsive.css):
 *   <button data-mobile-nav-toggle data-mobile-nav-breakpoint="1080" aria-controls="ID">
 *   <aside id="ID" data-mobile-nav-drawer> … <button data-mobile-nav-close> … </aside>
 *   <div class="mobile-nav-backdrop" data-mobile-nav-close></div>
 *
 * The drawer opens by toggling `nav-open` on <body>. It closes on the close
 * button, the backdrop, Escape, any nav link/button inside the drawer, and
 * automatically when the viewport grows past the breakpoint.
 */
(() => {
  const toggle = document.querySelector("[data-mobile-nav-toggle]");
  const drawer = document.querySelector("[data-mobile-nav-drawer]");
  if (!toggle || !drawer) {
    return;
  }

  const body = document.body;
  const breakpoint = Number(toggle.dataset.mobileNavBreakpoint) || 1080;
  const collapsed = window.matchMedia(`(max-width: ${breakpoint}px)`);
  const closeButton = drawer.querySelector("[data-mobile-nav-close]");

  const isOpen = () => body.classList.contains("nav-open");

  const setOpen = (open) => {
    if (open === isOpen()) {
      return;
    }
    body.classList.toggle("nav-open", open);
    toggle.setAttribute("aria-expanded", String(open));
    toggle.setAttribute("aria-label", open ? "Close menu" : "Open menu");

    const icon = toggle.querySelector("i");
    if (icon) {
      icon.classList.toggle("fa-bars", !open);
      icon.classList.toggle("fa-xmark", open);
    }

    if (open) {
      // Wait for the slide-in so the focus ring lands on a visible element.
      window.setTimeout(() => (closeButton || drawer).focus(), 300);
    } else if (collapsed.matches) {
      toggle.focus();
    }
  };

  toggle.addEventListener("click", () => setOpen(!isOpen()));

  document.querySelectorAll("[data-mobile-nav-close]").forEach((el) => {
    el.addEventListener("click", () => setOpen(false));
  });

  // Picking a destination closes the drawer; the page's own handlers still
  // run because we do not stop propagation.
  drawer.addEventListener("click", (event) => {
    if (!collapsed.matches) {
      return;
    }
    const control = event.target.closest("a, button");
    if (control && !control.hasAttribute("data-mobile-nav-close")) {
      setOpen(false);
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && isOpen()) {
      setOpen(false);
    }
  });

  const onBreakpointChange = (event) => {
    if (!event.matches) {
      setOpen(false);
    }
  };
  if (typeof collapsed.addEventListener === "function") {
    collapsed.addEventListener("change", onBreakpointChange);
  } else {
    collapsed.addListener(onBreakpointChange);
  }
})();
