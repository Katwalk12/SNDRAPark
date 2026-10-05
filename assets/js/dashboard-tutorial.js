/* First-run tutorial for the driver dashboard.
 *
 * Opens once, for an account that has never finished it, and from the Replay
 * button under Support. Whether someone has seen it is a property of the
 * account, not of the browser: users.tutorial_completed_at, read off the
 * session payload and written back through POST users.php?action=tutorial.
 * localStorage would have re-run the whole thing on their phone.
 *
 * Skipping counts as finishing. Someone who closes it has made a decision,
 * and asking again at every sign-in is how a welcome mat becomes nagging --
 * the Replay button is there for anyone who wants it back.
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

  /* Written against what the dashboard actually does. The warning rule in the
     last step is the one drivers get caught by, so it is stated in full
     rather than softened. */
  var STEPS = [
    {
      kicker: "Getting started",
      title: "Welcome to SNDRA Park",
      icon: "bi-hand-thumbs-up",
      copy: "Reserve a parking bay before you drive over, then show your pass at the booth. This takes about a minute."
    },
    {
      kicker: "Step 1 of 5",
      title: "Pick a floor",
      icon: "bi-building",
      copy: "The floor tabs show how many bays are open on each level right now. The count updates by itself every few seconds, so you are never choosing from a stale list."
    },
    {
      kicker: "Step 2 of 5",
      title: "Choose a bay",
      icon: "bi-grid-3x3-gap",
      copy: "Green bays are free to book. Amber is already reserved, red is occupied, and grey is out of service. Tap any green bay to open the booking form."
    },
    {
      kicker: "Step 3 of 5",
      title: "Set your arrival time",
      icon: "bi-clock",
      copy: "Tell us when you expect to arrive and confirm. The bay is then held for you, and your booking appears under History."
    },
    {
      kicker: "Step 4 of 5",
      title: "Show your pass at the booth",
      icon: "bi-upc-scan",
      copy: "Every booking comes with a barcode pass. The teller scans it on the way in and again on the way out, and settles the fee at the booth."
    },
    {
      kicker: "Step 5 of 5",
      title: "Arrive on time",
      icon: "bi-exclamation-triangle",
      copy: "If you do not arrive in time the bay is released and you get a warning. After 3 warnings the next one locks your account until a letter of appeal is approved."
    }
  ];

  var modal = null;
  var stepIndex = 0;
  var lastFocused = null;
  var els = {};

  function byId(id) { return document.getElementById(id); }

  function cacheElements() {
    modal = byId("tutorial-modal");
    if (!modal) { return false; }
    els = {
      kicker: byId("tutorial-kicker"),
      title: byId("tutorial-title"),
      copy: byId("tutorial-copy"),
      figure: byId("tutorial-figure"),
      steps: byId("tutorial-steps"),
      back: byId("tutorial-back"),
      next: byId("tutorial-next"),
      nextLabel: byId("tutorial-next-label"),
      card: modal.querySelector(".tutorial-card")
    };
    return true;
  }

  function buildStepPips() {
    if (!els.steps) { return; }
    els.steps.innerHTML = "";
    STEPS.forEach(function (step, i) {
      var li = document.createElement("li");
      li.setAttribute("aria-label", "Step " + (i + 1) + " of " + STEPS.length);
      els.steps.appendChild(li);
    });
  }

  function render() {
    var step = STEPS[stepIndex];
    if (!step) { return; }

    if (els.kicker) { els.kicker.textContent = step.kicker; }
    if (els.title) { els.title.textContent = step.title; }
    if (els.copy) { els.copy.textContent = step.copy; }
    if (els.figure) { els.figure.innerHTML = '<i class="bi ' + step.icon + '"></i>'; }

    var isLast = stepIndex === STEPS.length - 1;
    if (els.nextLabel) { els.nextLabel.textContent = isLast ? "Got it" : "Next"; }
    if (els.back) {
      els.back.disabled = stepIndex === 0;
      /* Hidden from the tab order rather than only greyed out, so a keyboard
         user is not stopped on a control that does nothing. */
      els.back.setAttribute("aria-disabled", stepIndex === 0 ? "true" : "false");
    }

    if (els.steps) {
      Array.prototype.forEach.call(els.steps.children, function (li, i) {
        li.classList.toggle("is-done", i < stepIndex);
        if (i === stepIndex) { li.setAttribute("aria-current", "step"); }
        else { li.removeAttribute("aria-current"); }
      });
    }
  }

  function focusables() {
    if (!els.card) { return []; }
    var found = els.card.querySelectorAll(
      'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );
    return Array.prototype.slice.call(found);
  }

  function onKeydown(event) {
    if (event.key === "Escape") {
      event.preventDefault();
      close({ markComplete: true });
      return;
    }
    if (event.key !== "Tab") { return; }

    /* The dialog is modal, so Tab must not walk out of it into the dashboard
       behind. */
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

  function open(startIndex) {
    if (!modal) { return; }
    lastFocused = document.activeElement;
    stepIndex = typeof startIndex === "number" ? startIndex : 0;
    render();
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("modal-open");
    document.addEventListener("keydown", onKeydown, true);
    if (els.next) { els.next.focus(); }
  }

  function close(options) {
    if (!modal) { return; }
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("modal-open");
    document.removeEventListener("keydown", onKeydown, true);

    if (options && options.markComplete) { markComplete(); }

    /* Put focus back where it came from, so a replay does not dump a keyboard
       user at the top of the page. */
    if (lastFocused && typeof lastFocused.focus === "function") {
      lastFocused.focus();
    }
    lastFocused = null;
  }

  var marked = false;

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
      /* A failed save is not worth interrupting anyone over. The tutorial
         simply opens again next time, which is the safe direction to fail. */
      marked = false;
    });
  }

  function bind() {
    Array.prototype.forEach.call(
      modal.querySelectorAll("[data-tutorial-skip]"),
      function (btn) {
        btn.addEventListener("click", function () { close({ markComplete: true }); });
      }
    );

    if (els.back) {
      els.back.addEventListener("click", function () {
        if (stepIndex > 0) { stepIndex -= 1; render(); }
      });
    }

    if (els.next) {
      els.next.addEventListener("click", function () {
        if (stepIndex < STEPS.length - 1) { stepIndex += 1; render(); }
        else { close({ markComplete: true }); }
      });
    }

    /* Clicking the backdrop closes, clicking the card does not. */
    modal.addEventListener("click", function (event) {
      if (event.target === modal) { close({ markComplete: true }); }
    });

    var replay = byId("replay-tutorial-btn");
    if (replay) {
      replay.addEventListener("click", function () { open(0); });
    }
  }

  function start(user) {
    if (!cacheElements()) { return; }
    buildStepPips();
    bind();

    var done = user && user.tutorial_completed_at;
    if (!done) { open(0); }
  }

  window.addEventListener("sndra:session-ready", function (event) {
    start(event && event.detail ? event.detail.user : null);
  }, { once: true });
})();
