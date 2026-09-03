/* ==========================================================================
   SNDRA Park — Sign up wizard

   Registration asks for twelve things, which is more than fits on a screen.
   Rather than scroll, the same single <form> is shown three panels at a time:
   about you / security / vehicle. It stays one form so auth.js keeps owning
   validation and submission - this file only decides what is visible, and
   refuses to advance past a step that has not been filled in correctly.
   ========================================================================== */

(function () {
  "use strict";

  const form = document.getElementById("signup-form");
  const auth = window.SndraAuthForms;

  if (!form || !auth) {
    return;
  }

  const steps = Array.from(form.querySelectorAll("[data-step]"));
  const progressBars = Array.from(document.querySelectorAll("[data-step-bar]"));
  const stepLabel = document.querySelector("[data-step-label]");

  if (!steps.length) {
    return;
  }

  let currentStep = 0;

  /* --- Fields ------------------------------------------------------------ */

  function fieldsIn(step) {
    return Array.from(step.querySelectorAll("[data-validate]"));
  }

  function stepIndexOf(field) {
    return steps.findIndex((step) => step.contains(field));
  }

  /**
   * A field is only allowed to turn red once the user has left it, or once
   * they have tried to move on. Turning green is not held back the same way -
   * confirmation while typing is welcome, correction while typing is not.
   */
  function reflectValidity(field, { allowError }) {
    const group = field.closest(".field-group") || field.closest(".checkbox-row");
    const isValid = auth.validateField(form, field);
    const isEmpty = field.type === "checkbox" ? !field.checked : field.value.trim() === "";

    if (!isValid && !allowError) {
      auth.clearFieldError(form, field);
    }

    if (group) {
      group.classList.toggle("is-valid", isValid && !isEmpty);
    }

    return isValid;
  }

  // auth.js binds its own input handler on DOMContentLoaded, and that handler
  // shows the error as soon as a field stops being valid. Registering after it
  // (this script is parsed later, so this callback is queued later) means these
  // handlers run second and get the final say on what is displayed.
  document.addEventListener("DOMContentLoaded", () => {
    fieldsIn(form).forEach((field) => {
      const eventName = field.tagName === "SELECT" || field.type === "checkbox" || field.type === "date"
        ? "change"
        : "input";

      field.addEventListener(eventName, () => {
        reflectValidity(field, { allowError: field.dataset.touched === "true" });
      });

      field.addEventListener("blur", () => {
        field.dataset.touched = "true";
        reflectValidity(field, { allowError: true });
      });
    });
  });

  /* --- Stepping ---------------------------------------------------------- */

  function paintProgress() {
    progressBars.forEach((bar, index) => {
      bar.classList.toggle("is-done", index < currentStep);
      bar.classList.toggle("is-current", index === currentStep);
    });

    if (stepLabel) {
      stepLabel.textContent = `Step ${currentStep + 1} of ${steps.length}`;
    }
  }

  function showStep(index, direction) {
    currentStep = Math.max(0, Math.min(index, steps.length - 1));

    steps.forEach((step, stepIndex) => {
      const isCurrent = stepIndex === currentStep;

      step.hidden = !isCurrent;
      step.classList.toggle("is-current", isCurrent);

      if (isCurrent && direction) {
        // Restart the animation - re-adding the class alone would not replay it.
        step.classList.remove("enter-forward", "enter-back");
        void step.offsetWidth;
        step.classList.add(direction === "back" ? "enter-back" : "enter-forward");
      }
    });

    paintProgress();

    const firstField = steps[currentStep].querySelector("input, select");

    if (firstField && direction) {
      firstField.focus({ preventScroll: true });
    }
  }

  function leaveStep(index) {
    const fields = fieldsIn(steps[index]);
    let firstInvalid = null;

    fields.forEach((field) => {
      field.dataset.touched = "true";

      if (!reflectValidity(field, { allowError: true }) && !firstInvalid) {
        firstInvalid = field;
      }
    });

    if (firstInvalid) {
      firstInvalid.focus({ preventScroll: true });
      return false;
    }

    return true;
  }

  form.querySelectorAll("[data-step-next]").forEach((button) => {
    button.addEventListener("click", () => {
      if (leaveStep(currentStep)) {
        showStep(currentStep + 1, "forward");
      }
    });
  });

  form.querySelectorAll("[data-step-back]").forEach((button) => {
    button.addEventListener("click", () => showStep(currentStep - 1, "back"));
  });

  // Enter should advance rather than submit a half-filled form. The final step
  // has the real submit button, so it is left alone.
  form.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" || currentStep === steps.length - 1) {
      return;
    }

    const target = event.target;

    if (target instanceof HTMLElement && target.tagName !== "TEXTAREA") {
      event.preventDefault();

      if (leaveStep(currentStep)) {
        showStep(currentStep + 1, "forward");
      }
    }
  });

  /* --- Submit -----------------------------------------------------------
     auth.js validates on submit and focuses the first bad field, which does
     nothing if that field is on a hidden step. This runs first (it is bound to
     the form, auth.js listens on document) and surfaces the step so the focus
     that follows lands somewhere the user can see. */

  form.addEventListener("submit", () => {
    const firstInvalid = fieldsIn(form).find((field) => !auth.validateField(form, field));

    if (firstInvalid) {
      const stepIndex = stepIndexOf(firstInvalid);

      if (stepIndex >= 0 && stepIndex !== currentStep) {
        showStep(stepIndex, stepIndex < currentStep ? "back" : "forward");
      }
    }
  });

  /* --- Live plate preview ------------------------------------------------ */

  const plateInput = document.getElementById("signup-plate-number");
  const plateOutput = document.querySelector("[data-plate-preview]");
  const vehicleType = document.getElementById("signup-vehicle-type");
  const plateCard = document.querySelector("[data-plate-card]");

  function paintPlate() {
    if (!plateOutput) {
      return;
    }

    const value = String(plateInput?.value || "").toUpperCase().trim();

    plateOutput.textContent = value || "ABC 1234";
    plateOutput.classList.toggle("is-placeholder", value === "");

    if (plateCard) {
      plateCard.dataset.vehicle = vehicleType?.value || "";
    }
  }

  plateInput?.addEventListener("input", paintPlate);
  vehicleType?.addEventListener("change", paintPlate);
  paintPlate();

  showStep(0);
})();
