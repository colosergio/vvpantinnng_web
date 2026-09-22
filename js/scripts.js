document.addEventListener("DOMContentLoaded", () => {
  const links = document.querySelectorAll(".item-pagination");
  const items = document.querySelectorAll(".promotions-list .blo4");
  const perPage = 4;

  function showPage(page) {
    items.forEach((item, i) => {
      const pageNum = Math.floor(i / perPage) + 1;
      item.style.display = pageNum === page ? "block" : "none";
    });
  }

  // Inicial en la página 1
  showPage(1);

  links.forEach((link) => {
    link.addEventListener("click", (e) => {
      e.preventDefault();
      const page = parseInt(link.dataset.page, 10);

      // Active button
      links.forEach((a) =>
        a.classList.toggle(
          "active-pagination",
          parseInt(a.dataset.page, 10) === page
        )
      );

      // Show correct slice
      showPage(page);
    });
  });
});

document.querySelectorAll(".item-gallery").forEach((item) => {
  // estilo de cursor para indicar clicable
  item.style.cursor = "pointer";

  item.addEventListener("click", (e) => {
    // si el clic es sobre un enlace real (ej. la lupa), que siga su curso
    if (e.target.closest("a[data-lightbox]")) return;

    // encuentra el enlace lightbox dentro de esta tarjeta y simula el clic
    const link = item.querySelector("a[data-lightbox]");
    if (link) link.click();
  });
});

function loadVideoSources(video) {
  if (!video || video.dataset.loaded === "true") return;

  video.querySelectorAll("source[data-src]").forEach((source) => {
    source.src = source.dataset.src;
    source.removeAttribute("data-src");
  });

  video.dataset.loaded = "true";
  video.load();
}

function loadDeferredImages(selector) {
  document.querySelectorAll(selector).forEach((image) => {
    if (!image.dataset.src) return;

    image.src = image.dataset.src;
    image.removeAttribute("data-src");
  });
}

document.addEventListener("DOMContentLoaded", () => {
  const lazyVideos = document.querySelectorAll(".lazy-video");
  const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  const shouldReduceMedia =
    window.matchMedia("(max-width: 767px)").matches ||
    window.matchMedia("(prefers-reduced-motion: reduce)").matches ||
    Boolean(connection && connection.saveData);

  if (shouldReduceMedia) {
    lazyVideos.forEach((video) => video.removeAttribute("autoplay"));
  } else if (!("IntersectionObserver" in window)) {
    lazyVideos.forEach((video) => loadVideoSources(video));
  } else {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;

          const video = entry.target;
          loadVideoSources(video);
          observer.unobserve(video);

          if (video.hasAttribute("autoplay")) {
            video.play().catch(() => {});
          }
        });
      },
      // Start fetching shortly before the module enters the viewport so the
      // poster transitions to video without an obvious loading pause.
      { rootMargin: "400px 0px" }
    );

    const beginObservingVideos = () => {
      window.setTimeout(() => {
        lazyVideos.forEach((video) => observer.observe(video));
      }, 250);
    };

    // The hero and critical CSS finish first; lower-page videos keep their
    // posters visible and begin loading shortly before the user reaches them.
    if (document.readyState === "complete") {
      beginObservingVideos();
    } else {
      window.addEventListener("load", beginObservingVideos, { once: true });
    }
  }

  document.querySelectorAll(".btn-show-sidebar").forEach((button) => {
    button.addEventListener("click", () => {
      loadDeferredImages(".lazy-sidebar-img");
    });
  });
});

function playBackgroundVideo() {
  const video = document.getElementById("bgVideo");
  loadVideoSources(video);
  video.play();

  // Opcional: ocultar el botón después de reproducir
  const playBtn = document.querySelector(".btn-play");
  if (playBtn) playBtn.style.display = "none";
}
