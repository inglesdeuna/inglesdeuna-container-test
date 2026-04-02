document.addEventListener("DOMContentLoaded", () => {
  const sourceData = Array.isArray(MATCH_DATA) ? MATCH_DATA : [];
  const data = [...sourceData].sort(() => Math.random() - 0.5);
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
  const pageParams = new URLSearchParams(window.location.search || "");
  const isStudentCourseFlow = pageParams.get("from") === "student_course";
  const studentStep = pageParams.get("step") || "0";
  const MAX_ROUNDS = 2;

  let matchedCount = 0;
  let firstTryCorrect = 0;
  let currentDraggedCard = null;
  let currentRound = 1;
  let activityLocked = false;
  const roundScores = [];
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

  function getAvailableHeightForBoard() {
    const topRow = document.querySelector(".top-row");
    const viewerHeader = document.querySelector(".viewer-header");
    const matchIntro = document.querySelector(".match-intro");

    const topRowH = topRow ? topRow.getBoundingClientRect().height + 2 : 0;
    const viewerHeaderH =
      viewerHeader && getComputedStyle(viewerHeader).display !== "none"
        ? viewerHeader.getBoundingClientRect().height + 10
        : 0;
    const introH = matchIntro ? matchIntro.getBoundingClientRect().height + 18 : 100;

    const bodyStyle = getComputedStyle(document.body);
    const bodyPadT = parseFloat(bodyStyle.paddingTop) || 18;
    const bodyPadB = parseFloat(bodyStyle.paddingBottom) || 24;

    const vcEl = document.querySelector(".viewer-content");
    const vcPadT = vcEl ? parseFloat(getComputedStyle(vcEl).paddingTop) || 18 : 18;
    const vcPadB = vcEl ? parseFloat(getComputedStyle(vcEl).paddingBottom) || 18 : 18;

    // 36 = 18px top + 18px bottom padding of .match-column-card
    const overhead = bodyPadT + topRowH + viewerHeaderH + vcPadT + introH + 36 + vcPadB + bodyPadB;
    return Math.max(100, window.innerHeight - overhead);
  }

  function getBoardConfig(count) {
    const vw = window.innerWidth;

    if (isTextOnlyMode) {
      const cols = vw <= 640 ? 1 : 2;
      return { cols, tileSize: 82 };
    }

    // Column count by number of pairs
    let cols;
    if (count <= 4) cols = 2;
    else if (count <= 6) cols = 3;
    else if (count <= 10) cols = 4;
    else cols = 5;

    const rows = Math.ceil(count / cols);

    // Height-based tile size
    const availH = getAvailableHeightForBoard();
    const gapV = 14;
    const tileHFromH = Math.floor((availH - gapV * (rows - 1)) / rows);

    // Width-based tile size
    // match-stage max-width 1060, body padding 44px, 30px gap between the two columns, 32px col-card padding (16+16)
    const stageW = Math.min(vw - 44, 1060);
    const colW = (stageW - 30) / 2 - 32;
    const gapH = 14;
    const tileWFromW = Math.floor((colW - gapH * (cols - 1)) / cols);

    const tileSize = Math.max(60, Math.min(tileHFromH, tileWFromW, 180));
    return { cols, tileSize };
  }

  function applyBoardLayout() {
    const count = normalizedData.length;
    const config = getBoardConfig(count);
    const { cols, tileSize } = config;

    leftBoard.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
    rightBoard.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;

    leftBoard.style.setProperty("--tile-height", `${tileSize}px`);
    rightBoard.style.setProperty("--tile-height", `${tileSize}px`);
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

  function fitTextToTile(el) {
    const textEl = el.querySelector(".match-text");
    if (!textEl) return;
    const isSingleWord = !textEl.textContent.trim().includes(" ");
    textEl.style.whiteSpace = isSingleWord ? "nowrap" : "";
    textEl.style.fontSize = "";
  }

  function fitAllTexts() {
    const tiles = document.querySelectorAll(".match-card, .match-target");
    tiles.forEach(fitTextToTile);
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

    // Run after layout paint so clientWidth is available
    requestAnimationFrame(fitAllTexts);
  }

  function buildReturnUrl(scoreValue) {
    if (!returnTo) {
      return "";
    }

    const total = normalizedData.length;
    const safeScore = Number.isFinite(scoreValue) ? scoreValue : firstTryCorrect;
    const correct = Math.max(0, Math.min(total, safeScore));
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

  function resetRoundState() {
    matchedCount = 0;
    firstTryCorrect = 0;
    currentDraggedCard = null;
    firstAttemptByTarget.clear();
  }

  function removeCompletedOverlay() {
    const overlay = document.getElementById("matchCompletedOverlay");
    if (overlay) {
      overlay.remove();
    }
    const finalScreen = document.getElementById("match-final-completed");
    if (finalScreen) {
      finalScreen.classList.remove("active");
    }
    const matchStageEl = document.querySelector(".match-stage");
    if (matchStageEl) {
      matchStageEl.style.display = "";
    }
  }

  function setActivityLocked(locked) {
    activityLocked = !!locked;
    if (matchStage) {
      matchStage.classList.toggle("is-locked", activityLocked);
    }

    const cards = leftBoard.querySelectorAll(".match-card");
    cards.forEach((card) => {
      card.draggable = !activityLocked;
      if (activityLocked) {
        card.classList.remove("dragging", "returning");
      }
    });
  }

  function startRound() {
    resetRoundState();
    setActivityLocked(false);
    renderBoard();
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
        keepalive: true,
      }).catch(() => {
        scorePersisted = false;
      });
    } catch (e) {
      scorePersisted = false;
    }
  }

  function navigateToReturn(targetUrl) {
    if (!targetUrl) {
      return;
    }

    try {
      if (window.top && window.top !== window.self) {
        window.top.location.href = targetUrl;
        return;
      }
    } catch (e) {
      // Fallback to current window navigation.
    }

    window.location.href = targetUrl;
  }

  function buildStudentResetUrl() {
    if (!returnTo || !activityId || !isStudentCourseFlow) {
      return "";
    }

    const hasQuery = returnTo.includes("?");
    const joiner = hasQuery ? "&" : "?";
    return (
      returnTo
      + joiner + "reset_activity=1"
      + "&reset_activity_id=" + encodeURIComponent(String(activityId))
      + "&reset_activity_type=" + encodeURIComponent("match")
      + "&step=" + encodeURIComponent(String(studentStep))
    );
  }

  async function showCompleted() {
    if (document.getElementById("matchCompletedOverlay")) {
      return;
    }

    const completedRound = currentRound;
    const isFinalRound = completedRound >= MAX_ROUNDS;
    const total = normalizedData.length;
    const correct = Math.max(0, Math.min(total, firstTryCorrect));
    const percent = total > 0 ? Math.round((correct / total) * 100) : 0;
    const saveUrl = buildReturnUrl(correct);

    roundScores[completedRound - 1] = correct;

    if (isFinalRound) {
      // Try to save the score via async fetch; if it fails, fall back to top-frame navigation.
      if (saveUrl && !scorePersisted) {
        scorePersisted = true;
        try {
          const resp = await fetch(saveUrl, {
            method: "GET",
            credentials: "same-origin",
            cache: "no-store",
          });
          if (!resp.ok) {
            scorePersisted = false;
          }
        } catch (e) {
          scorePersisted = false;
        }
        // If fetch failed, navigate the top frame to guarantee score is saved.
        if (!scorePersisted && saveUrl) {
          navigateToReturn(saveUrl);
          return;
        }
      }

      setActivityLocked(true);

      // Show standard completed screen
      const matchStageEl = document.querySelector(".match-stage");
      const finalScreen = document.getElementById("match-final-completed");

      if (finalScreen) {
        const scoreEl = document.getElementById("match-fc-score-text");
        const subEl = document.getElementById("match-fc-sub-text");
        const restartBtn = document.getElementById("match-fc-restart-btn");

        if (scoreEl) {
          scoreEl.textContent = "Score: " + correct + " / " + total + " (" + percent + "%)";
        }
        if (subEl) {
          subEl.textContent =
            "Round 1: " + (roundScores[0] || 0) + "/" + total + ".  Round 2: " + (roundScores[1] || 0) + "/" + total + ".";
        }
        if (restartBtn) {
          restartBtn.disabled = true;
          restartBtn.style.opacity = "0.5";
          restartBtn.style.cursor = "not-allowed";
        }

        if (matchStageEl) {
          matchStageEl.style.display = "none";
        }
        finalScreen.classList.add("active");
        playSound(winSound);
                const continueBtn = document.getElementById("match-fc-continue-btn");
                if (continueBtn && returnTo) {
                  continueBtn.style.display = "";
                  continueBtn.addEventListener("click", function () {
                    navigateToReturn(returnTo);
                  });
                }
        return;
      }
    }

    // Non-final round: use old overlay for intermediate feedback
    const subtitle = "Round " + completedRound + " done. One more attempt to go.";
    const replayButtonLabel = "Try Again";

    const overlay = document.createElement("div");
    overlay.id = "matchCompletedOverlay";
    overlay.className = "match-teacher-overlay";
    overlay.innerHTML = `
      <div class="match-teacher-completed-box">
        <div class="match-teacher-completed-icon">🏆</div>
        <div class="match-teacher-completed-title">Round ${completedRound} Done!</div>
        <div class="match-teacher-completed-score">Score: <strong>${correct} / ${total}</strong> (${percent}%)</div>
        <div class="match-teacher-completed-text">${subtitle}</div>
        <div class="match-teacher-completed-actions">
          <button type="button" class="match-teacher-completed-button" id="matchRestartBtn">${replayButtonLabel}</button>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);

    const restartBtn = document.getElementById("matchRestartBtn");
    if (restartBtn) {
      restartBtn.addEventListener("click", () => {
        removeCompletedOverlay();
        currentRound += 1;
        startRound();
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

  startRound();

  window.addEventListener("resize", () => { applyBoardLayout(); requestAnimationFrame(fitAllTexts); });

  document.addEventListener("dragstart", (e) => {
    if (activityLocked) {
      e.preventDefault();
      return;
    }

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
    if (activityLocked) {
      return;
    }

    const target = e.target.closest(".match-target");
    if (!target || target.dataset.matched === "1") {
      return;
    }

    e.preventDefault();
  });

  document.addEventListener("drop", (e) => {
    if (activityLocked) {
      return;
    }

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
