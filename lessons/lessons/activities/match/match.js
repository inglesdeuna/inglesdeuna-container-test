document.addEventListener("DOMContentLoaded", () => {
  const data = Array.isArray(MATCH_DATA) ? MATCH_DATA : [];

  const imagesDiv = document.getElementById("match-images");
  const wordsDiv = document.getElementById("match-words");

  if (!imagesDiv || !wordsDiv) {
    return;
  }

  const shuffle = (arr) => [...arr].sort(() => Math.random() - 0.5);

  const errorSound = new Audio("sounds/error.mp3");
  const winSound = new Audio("sounds/win.mp3");

  let matchedCount = 0;
  let currentDraggedCard = null;

  function escapeHtml(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function renderBoard() {
    imagesDiv.innerHTML = "";
    wordsDiv.innerHTML = "";

    shuffle(data).forEach((item) => {
      imagesDiv.innerHTML += `
        <div class="card" data-id="${escapeHtml(item.id)}">
          <img
            src="${escapeHtml(item.image)}"
            draggable="true"
            data-id="${escapeHtml(item.id)}"
            class="image"
            alt="${escapeHtml(item.text || "")}"
          >
        </div>
      `;
    });

    shuffle(data).forEach((item) => {
      wordsDiv.innerHTML += `
        <div class="word" data-id="${escapeHtml(item.id)}">
          ${escapeHtml(item.text)}
        </div>
      `;
    });
  }

  function playError() {
    try {
      errorSound.currentTime = 0;
      errorSound.play();
    } catch (e) {}
  }

  function playWin() {
    try {
      winSound.currentTime = 0;
      winSound.play();
    } catch (e) {}
  }

  function showCompleted() {
    if (document.getElementById("matchCompletedOverlay")) {
      return;
    }

    const overlay = document.createElement("div");
    overlay.id = "matchCompletedOverlay";
    overlay.innerHTML = `
      <div class="match-completed-box">
        <div class="match-completed-emoji">🏆</div>
        <div class="match-completed-title">Completed!</div>
        <div class="match-completed-subtitle">Great job!</div>
      </div>
    `;
    document.body.appendChild(overlay);
    playWin();
  }

  function checkCompletion() {
    if (matchedCount >= data.length && data.length > 0) {
      showCompleted();
    }
  }

  function markCorrect(wordEl, cardEl) {
    if (!wordEl || !cardEl) {
      return;
    }

    wordEl.classList.add("correct");
    wordEl.classList.remove("wrong");
    wordEl.innerHTML = `✅ ${wordEl.textContent}`;

    const img = cardEl.querySelector(".image");
    if (img) {
      img.draggable = false;
    }

    cardEl.classList.add("matched-left");
    setTimeout(() => {
      cardEl.style.visibility = "hidden";
      cardEl.style.pointerEvents = "none";
    }, 250);

    wordEl.dataset.matched = "1";
    cardEl.dataset.matched = "1";

    matchedCount += 1;
    checkCompletion();
  }

  function markWrong(wordEl, cardEl) {
    if (wordEl) {
      wordEl.classList.add("wrong");
      setTimeout(() => {
        wordEl.classList.remove("wrong");
      }, 700);
    }

    if (cardEl) {
      cardEl.classList.add("returning");
      setTimeout(() => {
        cardEl.classList.remove("returning");
      }, 450);
    }

    playError();
  }

  renderBoard();

  document.addEventListener("dragstart", (e) => {
    if (!e.target.classList.contains("image")) {
      return;
    }

    const card = e.target.closest(".card");
    if (!card || card.dataset.matched === "1") {
      e.preventDefault();
      return;
    }

    currentDraggedCard = card;
    e.dataTransfer.setData("id", e.target.dataset.id || "");
    e.dataTransfer.effectAllowed = "move";

    setTimeout(() => {
      card.classList.add("dragging");
    }, 0);
  });

  document.addEventListener("dragend", (e) => {
    if (!e.target.classList.contains("image")) {
      return;
    }

    const card = e.target.closest(".card");
    if (card) {
      card.classList.remove("dragging");
    }

    currentDraggedCard = null;
  });

  document.addEventListener("dragover", (e) => {
    const word = e.target.closest(".word");
    if (!word) {
      return;
    }

    if (word.dataset.matched === "1") {
      return;
    }

    e.preventDefault();
  });

  document.addEventListener("drop", (e) => {
    const word = e.target.closest(".word");
    if (!word) {
      return;
    }

    if (word.dataset.matched === "1") {
      return;
    }

    e.preventDefault();

    const draggedId = e.dataTransfer.getData("id");
    const targetId = word.dataset.id;

    const card = currentDraggedCard || imagesDiv.querySelector(`.card[data-id="${CSS.escape(draggedId)}"]`);

    if (!draggedId || !card || card.dataset.matched === "1") {
      return;
    }

    if (draggedId === targetId) {
      markCorrect(word, card);
    } else {
      markWrong(word, card);
    }
  });
});
