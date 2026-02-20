document.addEventListener("DOMContentLoaded", function () {

  if (typeof MATCH_DATA === "undefined") {
    console.error("MATCH_DATA no está definido");
    return;
  }

  const shuffle = arr => arr.sort(() => Math.random() - 0.5);

  const imagesDiv = document.getElementById("match-images");
  const wordsDiv  = document.getElementById("match-words");

  if (!imagesDiv || !wordsDiv) {
    console.error("Contenedores match-images o match-words no encontrados");
    return;
  }

  imagesDiv.innerHTML = "";
  wordsDiv.innerHTML  = "";

  shuffle([...MATCH_DATA]).forEach(item => {
    imagesDiv.innerHTML += `
      <div class="match-card">
        <img src="${item.image}"
             draggable="true"
             data-id="${item.id}"
             class="match-img">
      </div>
    `;
  });

  shuffle([...MATCH_DATA]).forEach(item => {
    wordsDiv.innerHTML += `
      <div class="match-word"
           data-id="${item.id}">
        ${item.text}
      </div>
    `;
  });

  document.addEventListener("dragstart", function (e) {
    if (e.target.classList.contains("match-img")) {
      e.dataTransfer.setData("id", e.target.dataset.id);
    }
  });

  document.addEventListener("dragover", function (e) {
    if (e.target.classList.contains("match-word")) {
      e.preventDefault();
    }
  });

  document.addEventListener("drop", function (e) {
    if (e.target.classList.contains("match-word")) {
      e.preventDefault();

      const draggedId = e.dataTransfer.getData("id");
      const targetId  = e.target.dataset.id;

      if (draggedId === targetId) {
        e.target.classList.add("correct");
      } else {
        e.target.classList.add("wrong");
        setTimeout(() => e.target.classList.remove("wrong"), 800);
      }
    }
  });

});
