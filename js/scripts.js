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

function playBackgroundVideo() {
  const video = document.getElementById("bgVideo");
  video.play();

  // Opcional: ocultar el botón después de reproducir
  const playBtn = document.querySelector(".btn-play");
  if (playBtn) playBtn.style.display = "none";
}
