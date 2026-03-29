document.addEventListener("DOMContentLoaded", () => {
  const data = Array.isArray(MATCH_DATA) ? MATCH_DATA : [];
  const normalizedData = data.map((item, idx) => ({
    ...item,
    pairKey: `pair_${idx}`,
  }));

  const leftBoard = document.getElementById("match-left");
  const rightBoard = document.getElementById("match-right");
  const matchStage = document.querySelector(".match-stage");

  if (!leftBoard || !rightBoard) {
    return;
  }

  const isTextOnlyMode = normalizedData.length > 0 && normalizedData.every((item) => {
    const leftImage = String(item.left_image || "").trim();
    const rightImage = String(item.right_image || "").trim();
    return leftImage === "" && rightImage === "";
  });

  if (matchStage) {
    matchStage.classList.toggle("text-only-mode", isTextOnlyMode);
  }

  const shuffle = (arr) => [...arr].sort(() => Math.random() - 0.5);

  const correctSound = new Audio("../../hangman/assets/realcorrect.mp3");
  const errorSound = new Audio("../../hangman/assets/losefun.mp3");
  const winSound = new Audio("../../hangman/assets/win.mp3");
  const returnTo = typeof MATCH_RETURN_TO === "string" ? MATCH_RETURN_TO : "";
  const activityId = typeof MATCH_ACTIVITY_ID === "string" ? MATCH_ACTIVITY_ID : "";

  let matchedCount = 0;
  let firstTryCorrect = 0;
  let currentDraggedCard = null;
  const firstAttemptByTarget = new Set();
  let scorePersisted = false;

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

  function getBoardConfig(count) {
    if (isTextOnlyMode) {
      if (window.innerWidth <= 640) {
        return { cols: 1, width: 226, height: 82 };
      }
      if (window.innerWidth <= 980) {
        return { cols: 2, width: 154, height: 82 };
      }
      return { cols: 2, width: 172, height: 82 };
    }

    if (count <= 2) {
      return { cols: 2, width: 150, height: 150 };
    }
    if (count <= 4) {
      return { cols: 2, width: 130, height: 130 };
    }
    if (count <= 6) {
      return { cols: 3, width: 108, height: 108 };
    }
    if (count <= 8) {
      return { cols: 4, width: 92, height: 92 };
    }
    if (count <= 10) {
      return { cols: 4, width: 84, height: 84 };
    }
    return { cols: 5, width: 74, height: 74 };
  }

  function applyBoardLayout() {
    const count = normalizedData.length;
    const config = getBoardConfig(count);

    leftBoard.style.gridTemplateColumns = `repeat(${config.cols}, minmax(${config.width}px, 1fr))`;
    rightBoard.style.gridTemplateColumns = `repeat(${config.cols}, minmax(${config.width}px, 1fr))`;

    leftBoard.style.setProperty("--tile-width", `${config.width}px`);
    rightBoard.style.setProperty("--tile-width", `${config.width}px`);
    leftBoard.style.setProperty("--tile-height", `${config.height}px`);
    rightBoard.style.setProperty("--tile-height", `${config.height}px`);
  }

  function renderTileContent(text, image, side) {
    const safeText = escapeHtml(text || "");
    const safeImage = escapeHtml(image || "");
    const media = safeImage !== ""
      ? `<img src="${safeImage}" class="match-media" alt="${safeText || side}">`
      : "";
    const label = safeText !== ""
      ? `<div class="match-text">${safeText}</div>`
      : "";

    return `
      <div class="match-tile-inner ${safeImage !== "" ? "has-image" : ""} ${safeText !== "" ? "has-text" : ""}">
        ${media}
        ${label}
      </div>
    `;
  }

  function renderBoard() {
    leftBoard.innerHTML = "";
    rightBoard.innerHTML = "";

    applyBoardLayout();

    shuffle(normalizedData).forEach((item) => {
      leftBoard.innerHTML += `
        <div class="card match-card" data-pair-key="${escapeHtml(item.pairKey)}" draggable="true">
          ${renderTileContent(item.left_text, item.left_image, "left")}
        </div>
      `;
    });

    shuffle(normalizedData).forEach((item) => {
      rightBoard.innerHTML += `
        <div class="word match-target" data-pair-key="${escapeHtml(item.pairKey)}">
          ${renderTileContent(item.right_text, item.right_image, "right")}
        </div>
      `;
    });
  }

  function buildReturnUrl() {
    if (!returnTo) {
      return "";
    }

    const total = normalizedData.length;
    const correct = Math.max(0, Math.min(total, firstTryCorrect));
    const errors = Math.max(0, total - correct);
    const percent = total > 0 ? Math.round((correct / total) * 100) : 0;
    const hasQuery = returnTo.includes("?");
    const joiner = hasQuery ? "&" : "?";

    return (
      returnTo
      + joiner + "activity_percent=" + encodeURIComponent(String(percent))
      + "&activity_errors=" + encodeURIComponent(String(errors))
      + "&activity_total=" + encodeURIComponent(String(total))
      + "&activity_id=" + encodeURIComponent(String(activityId))
      + "&activity_type=" + encodeURIComponent("match")
    );
  }

  function persistScoreSilently(saveUrl) {
    if (!saveUrl || scorePersisted) {
      return;
    }

    scorePersisted = true;
    try {
      fetch(saveUrl, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
      }).catch(() => {
        scorePersisted = false;
      });
    } catch (e) {
      scorePersisted = false;
    }
  }

  function showCompleted() {
    if (document.getElementById("matchCompletedOverlay")) {
      return;
    }

    const total = normalizedData.length;
    const correct = Math.max(0, Math.min(total, firstTryCorrect));
    const errors = Math.max(0, total - correct);
    const secondTry = errors;
    const percent = total > 0 ? Math.round((correct / total) * 100) : 0;
    const saveUrl = buildReturnUrl();

    persistScoreSilently(saveUrl);

    const overlay = document.createElement("div");
    overlay.id = "matchCompletedOverlay";
    overlay.innerHTML = `
      <div class="match-completed-box">
        <div class="match-completed-emoji">🏆</div>
        <div class="match-completed-title">Completed!</div>
        <div class="match-completed-score">Score: <strong>${correct} / ${total}</strong> (${percent}%)</div>
        <div class="match-completed-subtitle">First attempt: ${correct}/${total}. Second attempt: ${secondTry}/${total}.</div>
        <div class="match-completed-actions">
          <button type="button" class="match-completed-btn secondary" id="matchRestartBtn">Play again</button>
          ${returnTo !== "" ? '<button type="button" class="match-completed-btn" id="matchReturnBtn">Return</button>' : ""}
        </div>
      </div>
    `;
    document.body.appendChild(overlay);

    const restartBtn = document.getElementById("matchRestartBtn");
    if (restartBtn) {
      restartBtn.addEventListener("click", () => {
        window.location.reload();
      });
    }

    const returnBtn = document.getElementById("matchReturnBtn");
    if (returnBtn) {
      returnBtn.addEventListener("click", () => {
        window.location.href = saveUrl || returnTo;
      });
    }

    playSound(winSound);
  }

  function checkCompletion() {
    if (matchedCount >= normalizedData.length && normalizedData.length > 0) {
      showCompleted();
    }
  }

  function markCorrect(targetEl, cardEl) {
    if (!targetEl || !cardEl) {
      return;
    }

    targetEl.classList.add("correct");
    targetEl.classList.remove("wrong");
    targetEl.dataset.matched = "1";

    if (!targetEl.querySelector(".match-badge")) {
      const badge = document.createElement("div");
      badge.className = "match-badge";
      badge.textContent = "✓";
      targetEl.appendChild(badge);
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

  function markWrong(targetEl, cardEl) {
    if (targetEl) {
      targetEl.classList.add("wrong");
      setTimeout(() => {
        targetEl.classList.remove("wrong");
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

  window.addEventListener("resize", applyBoardLayout);

  document.addEventListener("dragstart", (e) => {
    const card = e.target.closest(".match-card");
    if (!card) {
      return;
    }

    if (card.dataset.matched === "1") {
      e.preventDefault();
      return;
    }

    currentDraggedCard = card;
    e.dataTransfer.setData("pairKey", card.dataset.pairKey || "");
    e.dataTransfer.effectAllowed = "move";

    setTimeout(() => {
      card.classList.add("dragging");
    }, 0);
  });

  document.addEventListener("dragend", (e) => {
    const card = e.target.closest(".match-card");
    if (!card) {
      return;
    }

    card.classList.remove("dragging");
    currentDraggedCard = null;
  });

  document.addEventListener("dragover", (e) => {
    const target = e.target.closest(".match-target");
    if (!target || target.dataset.matched === "1") {
      return;
    }

    e.preventDefault();
  });

  document.addEventListener("drop", (e) => {
    const target = e.target.closest(".match-target");
    if (!target || target.dataset.matched === "1") {
      return;
    }

    e.preventDefault();

    const draggedPairKey = e.dataTransfer.getData("pairKey");
    const targetPairKey = target.dataset.pairKey || "";

    const selectorPairKey = window.CSS && CSS.escape ? CSS.escape(draggedPairKey) : draggedPairKey;
    const card = currentDraggedCard || leftBoard.querySelector(`.card[data-pair-key="${selectorPairKey}"]`);

    if (!draggedPairKey || !targetPairKey || !card || card.dataset.matched === "1") {
      return;
    }

    const isFirstAttempt = !firstAttemptByTarget.has(targetPairKey);
    if (isFirstAttempt) {
      firstAttemptByTarget.add(targetPairKey);
    }

    if (draggedPairKey === targetPairKey) {
      if (isFirstAttempt) {
        firstTryCorrect += 1;
      }
      markCorrect(target, card);
    } else {
      markWrong(target, card);
    }
  });
});
