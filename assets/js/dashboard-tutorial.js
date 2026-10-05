/* First-run guided tour for the driver dashboard.
 *
 * An introduction, then a step for each thing a new driver has to find, with
 * the page itself as the illustration: each step dims the dashboard and
 * spotlights the real control it is talking about.
 *
 * Opens once, for an account that has never finished it, and from the Replay
 * button under Support. Whether someone has seen it is a property of the
 * account, not of the browser: users.tutorial_completed_at, read off the
 * session payload and written back through POST users.php?action=tutorial.
 * localStorage would have re-run the whole thing on their phone.
 *
 * Skipping counts as finishing. Someone who closes it has made a decision,
 * and asking again at every sign-in is how a welcome mat becomes nagging.
 *
 * Listens for sndra:session-ready rather than importing anything from
 * user-dashboard.js, so neither file has to know how the other works.
 */
(function () {
  "use strict";

  var ENDPOINT = "/backend/api/v1/users.php";
  var ACTION = "?action=tutorial";

  /* The app is served from a sub-path, so a root-relative URL would miss.
     runtime-config.js resolves it; the fallback mirrors what
     user-dashboard.js does when that script has not loaded. */
  function backendUrl(path) {
    if (typeof window.getSndraBackendUrl === "function") {
      return window.getSndraBackendUrl(path);
    }
    var root = window.location.pathname.split("/frontend/")[0] || "";
    return window.location.origin + root + path;
  }

  /* A step with no `target` is a plain centred card -- the introduction and
     the closing rule are about the service, not about a control on screen.
     `optional` steps are dropped when their element is not on the page: the
     bay step has nothing to point at on a full floor, and pointing at empty
     space would be worse than skipping it. */
  var STEPS = [
    {
      kicker: "Getting started",
      title: "Welcome to SNDRA Park",
      copy: "Reserve a parking bay before you drive over, then show your pass at the booth. Here is the whole thing in about a minute."
    },
    {
      target: "#floor-grid",
      kicker: "Step 1",
      title: "Pick a floor",
      copy: "Each floor shows how many bays are open on it right now. The counts refresh by themselves, so you are never choosing from a stale list."
    },
    {
      target: ".monitor-stats",
      kicker: "Step 2",
      title: "Read the live counts",
      copy: "Open, held, and already parked for the floor you picked. Saved bookings is your own count, so you can see at a glance whether you already hold a bay."
    },
    {
      target: "#slots-grid .slot-card.available",
      optional: true,
      kicker: "Step 3",
      title: "Choose a bay",
      copy: "A green bay is free to book. Tap one and the booking form opens with that bay already filled in."
    },
    {
      target: ".monitor-legend",
      kicker: "Step 4",
      title: "What the colours mean",
      copy: "Green is free, amber is already reserved, red has a car in it, and grey is out of service. Only green bays can be booked."
    },
    {
      target: '.sidebar-link[data-target="park-reserved"]',
      kicker: "Step 5",
      title: "Find your pass",
      copy: "Every booking lands in History with a barcode pass. The booth teller scans it on the way in and again on the way out, and the fee is settled there."
    },
    {
      kicker: "One rule to know",
      title: "Arrive on time",
      copy: "If you do not arrive in time the bay is released and you get a warning. After 3 warnings the next one locks your account until a letter of appeal is approved."
    }
  ];

  var GUTTER = 16;      /* keeps the card off the viewport edge */
  var PAD = 8;          /* breathing room around the spotlit element */

  var root = null;
  var spotlight = null;
  var pop = null;
  var els = {};
  var order = [];       /* indexes into STEPS, after dropping missing optionals */
  var cursor = 0;
  var lastFocused = null;
  var tickTimer = null;
  var marked = false;

  function byId(id) { return document.getElementById(id); }
  function reduceMotion() {
    return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }

  function cache() {
    root = byId("tutorial-tour");
    if (!root) { return false; }
    spotlight = byId("tour-spotlight");
    pop = byId("tour-pop");
    els = {
      kicker: byId("tour-kicker"),
      title: byId("tour-title"),
      copy: byId("tour-copy"),
      steps: byId("tour-steps"),
      back: byId("tour-back"),
      next: byId("tour-next"),
      nextLabel: byId("tour-next-label")
    };
    return true;
  }

  function step() { return STEPS[order[cursor]]; }

  /* Resolved fresh every time rather than cached: the slot grid is re-rendered
     by the dashboard's three-second poll, so a node captured when the step
     opened is detached a moment later and its rect reads as zero. */
  function targetEl() {
    var s = step();
    if (!s || !s.target) { return null; }
    return document.querySelector(s.target);
  }

  function buildOrder() {
    order = [];
    STEPS.forEach(function (s, i) {
      if (s.optional && !document.querySelector(s.target)) { return; }
      order.push(i);
    });
  }

  function buildPips() {
    if (!els.steps) { return; }
    els.steps.innerHTML = "";
    order.forEach(function (_, i) {
      var li = document.createElement("li");
      li.setAttribute("aria-label", "Step " + (i + 1) + " of " + order.length);
      els.steps.appendChild(li);
    });
  }

  function place() {
    if (!root || root.hidden) { return; }
    var el = targetEl();
    var vw = window.innerWidth;
    var vh = window.innerHeight;

    if (!el) {
      /* No target: collapse the spotlight so its ring shadow simply fills the
         screen, and centre the card. */
      spotlight.style.width = "0px";
      spotlight.style.height = "0px";
      spotlight.style.left = vw / 2 + "px";
      spotlight.style.top = vh / 2 + "px";
      spotlight.style.borderRadius = "0";
      pop.style.left = Math.round((vw - pop.offsetWidth) / 2) + "px";
      pop.style.top = Math.round((vh - pop.offsetHeight) / 2) + "px";
      return;
    }

    var r = el.getBoundingClientRect();
    spotlight.style.left = Math.round(r.left - PAD) + "px";
    spotlight.style.top = Math.round(r.top - PAD) + "px";
    spotlight.style.width = Math.round(r.width + PAD * 2) + "px";
    spotlight.style.height = Math.round(r.height + PAD * 2) + "px";
    spotlight.style.borderRadius = "12px";

    var pw = pop.offsetWidth;
    var ph = pop.offsetHeight;
    var below = r.bottom + PAD + 12;
    var above = r.top - PAD - 12 - ph;

    /* Prefer underneath, flip above when there is no room, and if neither
       fits (a tall target on a short screen) pin it to the bottom gutter
       rather than letting it run off. */
    var top;
    if (below + ph <= vh - GUTTER) { top = below; }
    else if (above >= GUTTER) { top = above; }
    else { top = Math.max(GUTTER, vh - ph - GUTTER); }

    var left = r.left + r.width / 2 - pw / 2;
    left = Math.max(GUTTER, Math.min(left, vw - pw - GUTTER));

    pop.style.left = Math.round(left) + "px";
    pop.style.top = Math.round(top) + "px";
  }

  function render() {
    var s = step();
    if (!s) { return; }

    if (els.kicker) { els.kicker.textContent = s.kicker; }
    if (els.title) { els.title.textContent = s.title; }
    if (els.copy) { els.copy.textContent = s.copy; }

    var isLast = cursor === order.length - 1;
    if (els.nextLabel) { els.nextLabel.textContent = isLast ? "Got it" : "Next"; }
    if (els.back) {
      els.back.disabled = cursor === 0;
      els.back.setAttribute("aria-disabled", cursor === 0 ? "true" : "false");
    }
    if (els.steps) {
      Array.prototype.forEach.call(els.steps.children, function (li, i) {
        li.classList.toggle("is-done", i < cursor);
        if (i === cursor) { li.setAttribute("aria-current", "step"); }
        else { li.removeAttribute("aria-current"); }
      });
    }

    var el = targetEl();
    if (el && typeof el.scrollIntoView === "function") {
      el.scrollIntoView({
        block: "center",
        inline: "nearest",
        behavior: reduceMotion() ? "auto" : "smooth"
      });
    }

    /* Two passes: once now so nothing flashes in the wrong place, once after
       the smooth scroll has settled. */
    place();
    window.setTimeout(place, reduceMotion() ? 0 : 320);
  }

  function focusables() {
    if (!pop) { return []; }
    return Array.prototype.slice.call(pop.querySelectorAll(
      'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    ));
  }

  function onKeydown(event) {
    if (event.key === "Escape") {
      event.preventDefault();
      close({ markComplete: true });
      return;
    }
    if (event.key === "ArrowRight") { event.preventDefault(); go(1); return; }
    if (event.key === "ArrowLeft") { event.preventDefault(); go(-1); return; }
    if (event.key !== "Tab") { return; }

    /* The tour is modal, so Tab must not walk out of it into the dashboard
       underneath. */
    var list = focusables();
    if (!list.length) { return; }
    var first = list[0];
    var last = list[list.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function go(delta) {
    var next = cursor + delta;
    if (next < 0) { return; }
    if (next >= order.length) { close({ markComplete: true }); return; }
    cursor = next;
    render();
  }

  function open(startAt) {
    if (!root) { return; }
    lastFocused = document.activeElement;
    buildOrder();
    buildPips();
    cursor = typeof startAt === "number" ? startAt : 0;
    root.hidden = false;
    document.body.classList.add("modal-open");
    render();
    document.addEventListener("keydown", onKeydown, true);
    window.addEventListener("resize", place);
    window.addEventListener("scroll", place, true);
    /* The dashboard re-renders on a poll, which can move a spotlit element
       without firing scroll or resize. */
    tickTimer = window.setInterval(place, 500);
    if (els.next) { els.next.focus(); }
  }

  function close(options) {
    if (!root) { return; }
    root.hidden = true;
    document.body.classList.remove("modal-open");
    document.removeEventListener("keydown", onKeydown, true);
    window.removeEventListener("resize", place);
    window.removeEventListener("scroll", place, true);
    if (tickTimer) { window.clearInterval(tickTimer); tickTimer = null; }

    if (options && options.markComplete) { markComplete(); }

    if (lastFocused && typeof lastFocused.focus === "function") {
      lastFocused.focus();
    }
    lastFocused = null;
  }

  function markComplete() {
    /* Once per page. The server guards on IS NULL anyway, so a second call
       cannot overwrite the original timestamp -- this just avoids the
       pointless request. */
    if (marked) { return; }
    marked = true;

    fetch(backendUrl(ENDPOINT) + ACTION, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: "{}"
    }).catch(function () {
      /* A failed save is not worth interrupting anyone over. The tour simply
         opens again next time, which is the safe direction to fail. */
      marked = false;
    });
  }

  function bind() {
    Array.prototype.forEach.call(
      root.querySelectorAll("[data-tour-skip]"),
      function (btn) {
        btn.addEventListener("click", function () { close({ markComplete: true }); });
      }
    );
    if (els.back) { els.back.addEventListener("click", function () { go(-1); }); }
    if (els.next) { els.next.addEventListener("click", function () { go(1); }); }

    /* Clicking the dimmed page does nothing on purpose. Mid-tour it is far
       more likely to be a misjudged click than a decision to leave, and Skip
       and Escape are both right there. */
    root.addEventListener("click", function (event) { event.stopPropagation(); });

    var replay = byId("replay-tutorial-btn");
    if (replay) { replay.addEventListener("click", function () { open(0); }); }
  }

  /* sndra:session-ready fires as soon as the session is known, which is well
     before the floors and slots have been fetched and drawn. Opening then
     would spotlight an empty grid and drop the bay step as a missing optional,
     so wait for the first slot to exist. The timeout is the backstop: if the
     grid never fills -- a floor with no bays, a failed request -- the tour
     still runs, just without the step that has nothing to point at. */
  function whenPopulated(selector, timeoutMs, done) {
    var started = Date.now();
    (function poll() {
      if (document.querySelector(selector) || Date.now() - started > timeoutMs) {
        done();
        return;
      }
      window.setTimeout(poll, 150);
    })();
  }

  function start(user) {
    if (!cache()) { return; }
    bind();
    if (user && user.tutorial_completed_at) { return; }
    whenPopulated("#slots-grid .slot-card", 6000, function () { open(0); });
  }

  window.addEventListener("sndra:session-ready", function (event) {
    start(event && event.detail ? event.detail.user : null);
  }, { once: true });
})();
