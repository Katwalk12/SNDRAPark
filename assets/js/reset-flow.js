/* ==========================================================================
   SNDRA Park — Password reset helpers

   The three reset screens are plain PHP pages with their own inline fetch
   logic, so they do not load frontend/js/auth.js (which owns the login and
   signup form lifecycle). These are the few behaviours they do share.
   ========================================================================== */

window.ResetFlow = (function () {
  "use strict";

  /**
   * Write a message into a .form-status element. Passing an empty message
   * collapses it again - auth.css hides :empty rather than display:none so
   * aria-live keeps announcing reliably.
   */
  function setStatus(statusElement, message, type) {
    if (!statusElement) {
      return;
    }

    statusElement.textContent = message || "";
    statusElement.className = "form-status" + (type ? " is-" + type : "");
  }

  /**
   * Swap a submit button for a spinner. Disabling alone is not enough - a
   * reset request waits on an SMTP round trip, which is long enough that the
   * page looks stalled without it.
   */
  function setBusy(button, isBusy) {
    if (!button) {
      return;
    }

    button.disabled = Boolean(isBusy);
    button.classList.toggle("is-busy", Boolean(isBusy));
  }

  /**
   * Nudge a field that the server refused, and put the cursor back in it.
   * The class is removed on animationend so a second failure replays it.
   */
  function reject(input) {
    if (!input) {
      return;
    }

    const shell = input.closest(".input-shell");

    if (shell) {
      shell.classList.remove("is-rejected");
      // Reading offsetWidth restarts the animation when the same field is
      // rejected twice in a row.
      void shell.offsetWidth;
      shell.classList.add("is-rejected");
      shell.addEventListener(
        "animationend",
        () => shell.classList.remove("is-rejected"),
        { once: true }
      );
    }

    input.focus();
    input.select();
  }

  function togglePassword(button) {
    const input = document.getElementById(button.dataset.target);

    if (!input) {
      return;
    }

    const willShow = input.type === "password";
    input.type = willShow ? "text" : "password";
    button.setAttribute("aria-pressed", String(willShow));
    button.setAttribute("aria-label", willShow ? "Hide password" : "Show password");
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("[data-password-toggle]").forEach((button) => {
      button.addEventListener("click", () => togglePassword(button));
    });
  });

  return { setStatus, setBusy, reject };
})();
