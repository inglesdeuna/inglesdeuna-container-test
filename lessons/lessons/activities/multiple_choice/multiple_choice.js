document.addEventListener("DOMContentLoaded", () => {

  const container = document.getElementById("mc-container");
  if (!container || typeof MC_DATA === "undefined") return;

  container.innerHTML = `<div class="mc-grid"></div>`;
  const grid = container.querySelector(".mc-grid");

  MC_DATA.forEach(item => {

    const card = document.createElement("div");
    card.className = "mc-card";

    card.innerHTML = `
      <div class="mc-question">${item.question}</div>

      ${item.image ? `<img src="${item.image}" class="mc-image">` : ""}

      ${item.options.map((opt, i) => `
        <div class="mc-option" data-correct="${i === item.correct}">
          ${opt}
        </div>
      `).join("")}
    `;

    grid.appendChild(card);
  });

  document.addEventListener("click", e => {

    if (e.target.classList.contains("mc-option")) {

      const options = e.target.parentElement.querySelectorAll(".mc-option");
      options.forEach(opt => opt.classList.remove("mc-correct","mc-wrong"));

      if (e.target.dataset.correct === "true") {
        e.target.classList.add("mc-correct");
      } else {
        e.target.classList.add("mc-wrong");
      }
    }

  });

});
