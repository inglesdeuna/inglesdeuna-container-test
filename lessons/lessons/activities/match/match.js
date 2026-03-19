document.addEventListener("DOMContentLoaded", () => {
  const data = Array.isArray(MATCH_DATA) ? MATCH_DATA : [];

  const imagesDiv = document.getElementById("match-images");
  const wordsDiv = document.getElementById("match-words");

  if (!imagesDiv || !wordsDiv) {
    return;
  }

  const shuffle = (arr) => [...arr].sort(() => Math.random() - 0.5);

  const correctSound = new Audio("../../hangman/assets/correct.wav");
  const errorSound = new Audio("../../hangman/assets/losefun.mp3");
  const winSound = new Audio("../../hangman/assets/win.mp3");

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

  function playSound(audio) {
    try {
      audio.pause();
      audio.currentTime = 0;
      audio.play();
    } catch (e) {}
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
      </div>
    `;
    document.body.appendChild(overlay);
    playSound(winSound);
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
    wordEl.dataset.matched = "1";
    wordEl.innerHTML = `✓ ${wordEl.textContent}`;

    const img = cardEl.querySelector(".image");
    if (img) {
      img.draggable = false;
    }

    cardEl.dataset.matched = "1";
    cardEl.classList.add("matched-left");

    setTimeout(() => {
      cardEl.style.visibility = "hidden";
      cardEl.style.pointerEvents = "none";
    }, 220);

    matchedCount += 1;
    playSound(correctSound);
    checkCompletion();
  }

  function markWrong(wordEl, cardEl) {
    if (wordEl) {
      wordEl.classList.add("wrong");
      setTimeout(() => {
        wordEl.classList.remove("wrong");
      }, 650);
    }

    if (cardEl) {
      cardEl.classList.add("returning");
      setTimeout(() => {
        cardEl.classList.remove("returning");
      }, 420);
    }

    playSound(errorSound);
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
    if (!word || word.dataset.matched === "1") {
      return;
    }

    e.preventDefault();
  });

  document.addEventListener("drop", (e) => {
    const word = e.target.closest(".word");
    if (!word || word.dataset.matched === "1") {
      return;
    }

    e.preventDefault();

    const draggedId = e.dataTransfer.getData("id");
    const targetId = word.dataset.id;

    const selectorId = window.CSS && CSS.escape ? CSS.escape(draggedId) : draggedId;
    const card = currentDraggedCard || imagesDiv.querySelector(`.card[data-id="${selectorId}"]`);

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
