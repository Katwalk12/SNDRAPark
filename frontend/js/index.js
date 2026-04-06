document.addEventListener("DOMContentLoaded", () => {
  const carousel = document.querySelector("[data-carousel]");
  const revealElements = document.querySelectorAll(".reveal");

  if (carousel) {
    const slides = Array.from(carousel.querySelectorAll("[data-slide]"));
    const dotsContainer = carousel.querySelector("[data-dots]");
    const prevButton = carousel.querySelector('[data-direction="prev"]');
    const nextButton = carousel.querySelector('[data-direction="next"]');
    let currentIndex = slides.findIndex((slide) => slide.classList.contains("is-active"));
    let autoplayId = null;

    if (currentIndex < 0) {
      currentIndex = 0;
    }

    function renderDots() {
      if (!dotsContainer) {
        return;
      }

      dotsContainer.innerHTML = "";

      slides.forEach((slide, index) => {
        const dot = document.createElement("button");
        dot.type = "button";
        dot.className = "carousel-dot";
        dot.setAttribute("aria-label", `Go to slide ${index + 1}`);
        dot.dataset.index = String(index);

        if (index === currentIndex) {
          dot.classList.add("is-active");
        }

        dot.addEventListener("click", () => {
          showSlide(index);
          restartAutoplay();
        });

        dotsContainer.appendChild(dot);
      });
    }

    function showSlide(index) {
      currentIndex = (index + slides.length) % slides.length;

      slides.forEach((slide, slideIndex) => {
        const isActive = slideIndex === currentIndex;
        slide.classList.toggle("is-active", isActive);
        slide.classList.toggle("active", isActive);
        slide.setAttribute("aria-hidden", String(!isActive));
      });

      dotsContainer?.querySelectorAll(".carousel-dot").forEach((dot, dotIndex) => {
        dot.classList.toggle("is-active", dotIndex === currentIndex);
      });
    }

    function startAutoplay() {
      if (slides.length <= 1 || autoplayId) {
        return;
      }

      autoplayId = window.setInterval(() => {
        showSlide(currentIndex + 1);
      }, 4000);
    }

    function stopAutoplay() {
      if (autoplayId) {
        window.clearInterval(autoplayId);
        autoplayId = null;
      }
    }

    function restartAutoplay() {
      stopAutoplay();
      startAutoplay();
    }

    prevButton?.addEventListener("click", () => {
      showSlide(currentIndex - 1);
      restartAutoplay();
    });

    nextButton?.addEventListener("click", () => {
      showSlide(currentIndex + 1);
      restartAutoplay();
    });

    carousel.addEventListener("mouseenter", stopAutoplay);
    carousel.addEventListener("mouseleave", startAutoplay);
    carousel.addEventListener("focusin", stopAutoplay);
    carousel.addEventListener("focusout", startAutoplay);

    renderDots();
    showSlide(currentIndex);
    startAutoplay();
  }

  if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
          observer.unobserve(entry.target);
        }
      });
    }, {
      threshold: 0.2
    });

    revealElements.forEach((element) => {
      if (!element.classList.contains("is-visible")) {
        observer.observe(element);
      }
    });
  } else {
    revealElements.forEach((element) => element.classList.add("is-visible"));
  }
});
