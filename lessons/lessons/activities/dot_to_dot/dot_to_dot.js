const data = window.DOT_TO_DOT_DATA || {};

const originalPoints = Array.isArray(data.points) ? data.points : [];

const stage = document.getElementById("d2dvStage");
const img = document.getElementById("d2dvImg");
const canvas = document.getElementById("d2dvCanvas");
const ctx = canvas.getContext("2d");

const progress = document.getElementById("d2dvProgress");
const counter = document.getElementById("d2dvCounter");
const statusText = document.getElementById("d2dvStatus");
const resetBtn = document.getElementById("d2dvResetBtn");
const hintBtn = document.getElementById("d2dvHintBtn");
const revealBtn = document.getElementById("d2dvRevealBtn");
const continueBtn = document.getElementById("d2dvContinueBtn");
const completionPanel = document.getElementById("d2dvCompletionPanel");
const completionScore = document.getElementById("d2dvCompletionScore");

const mainPanel = document.getElementById('d2dvMain');

const winAudio   = new Audio('../../hangman/assets/win.mp3');
const errorAudio = new Audio('../../hangman/assets/lose.mp3');

let points = [];
let current = 1;
let completed = false;
let dragging = false;
let activePointerId = null;
let usingTouch = false;
let imageOpacity = 0;
let closingLineProgress = 1; /* 0→1 while animating the last closing segment */
let mouse = { x: 0, y: 0 };
let d2dRounds = 0;

let stageSetupTime = 0;
let lastConnectedAt = -Infinity;
let idleAnimHandle = null;

/* Easing helpers for a more natural, lively feel */
function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }
function easeOutBack(t) {
  const c1 = 1.70158;
  const c3 = c1 + 1;
  return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2);
}

function setupCanvas() {
  const rect = stage.getBoundingClientRect();

  canvas.width = Math.round(rect.width);
  canvas.height = Math.round(rect.height);

  canvas.style.width = rect.width + "px";
  canvas.style.height = rect.height + "px";

  // Points are stored as 0-1 normalised fractions of the image size.
  points = originalPoints.map(point => ({
    x: Math.round(Number(point.x) * canvas.width),
    y: Math.round(Number(point.y) * canvas.height),
    label: point.label != null ? String(point.label) : ""
  }));

  stageSetupTime = performance.now();
  lastConnectedAt = -Infinity;

  render(stageSetupTime);
  startIdleLoop();
}

function startIdleLoop() {
  if (idleAnimHandle) cancelAnimationFrame(idleAnimHandle);

  function loop(ts) {
    if (completed) return;
    render(ts);
    idleAnimHandle = requestAnimationFrame(loop);
  }

  idleAnimHandle = requestAnimationFrame(loop);
}

function stopIdleLoop() {
  if (idleAnimHandle) {
    cancelAnimationFrame(idleAnimHandle);
    idleAnimHandle = null;
  }
}

function getPointerPosition(event) {
  const rect = canvas.getBoundingClientRect();

  let clientX;
  let clientY;

  if (event.touches && event.touches.length > 0) {
    clientX = event.touches[0].clientX;
    clientY = event.touches[0].clientY;
  } else if (event.changedTouches && event.changedTouches.length > 0) {
    clientX = event.changedTouches[0].clientX;
    clientY = event.changedTouches[0].clientY;
  } else {
    clientX = event.clientX;
    clientY = event.clientY;
  }

  return {
    x: (clientX - rect.left) * (canvas.width / rect.width),
    y: (clientY - rect.top) * (canvas.height / rect.height)
  };
}

/* Touch fingers are bigger and less precise than a mouse cursor, so widen
   the hit area on touch devices to make dragging between dots easier. */
function dotHitRadius() {
  return usingTouch ? 56 : 42;
}

function isNearDot(pos, dot) {
  return Math.hypot(pos.x - dot.x, pos.y - dot.y) < dotHitRadius();
}

function clearCanvas() {
  ctx.clearRect(0, 0, canvas.width, canvas.height);
}

function updateUI() {
  if (completed) {
    progress.textContent = "Completed!";
    counter.textContent = (points.length - 1) + " / " + (points.length - 1) + " lines";
    statusText.textContent = "Great job!";
    return;
  }

  progress.textContent = "Connect " + current + " to " + (current + 1);
  counter.textContent = (current - 1) + " / " + (points.length - 1) + " lines";
  statusText.textContent = "";
}

function setImageVisibility() {
  if (completed) {
    img.style.opacity = imageOpacity;
    img.style.filter = "blur(" + ((1 - imageOpacity) * 6) + "px)";
    img.style.transform = "scale(" + (1.035 - imageOpacity * 0.035) + ")";
  } else {
    img.style.opacity = "0";
    img.style.filter = "none";
    img.style.transform = "scale(1)";
  }
}

function drawPermanentLines() {
  if (points.length < 2) return;

  ctx.strokeStyle = "#7F77DD";
  ctx.lineWidth = 5;
  ctx.lineCap = "round";
  ctx.lineJoin = "round";

  for (let i = 0; i < current - 1; i++) {
    if (!points[i + 1]) continue;

    ctx.beginPath();
    ctx.moveTo(points[i].x, points[i].y);
    ctx.lineTo(points[i + 1].x, points[i + 1].y);
    ctx.stroke();
  }

  if (completed && points.length > 2) {
    const from = points[points.length - 1];
    const to = points[0];
    ctx.beginPath();
    ctx.moveTo(from.x, from.y);
    ctx.lineTo(
      from.x + (to.x - from.x) * closingLineProgress,
      from.y + (to.y - from.y) * closingLineProgress
    );
    ctx.stroke();
  }
}

function drawDraggingLine() {
  if (!dragging || completed) return;
  if (current >= points.length) return;

  ctx.strokeStyle = "rgba(127,119,221,0.55)";
  ctx.lineWidth = 4;
  ctx.lineCap = "round";
  ctx.setLineDash([10, 8]);

  ctx.beginPath();
  ctx.moveTo(points[current - 1].x, points[current - 1].y);
  ctx.lineTo(mouse.x, mouse.y);
  ctx.stroke();

  ctx.setLineDash([]);
}

function drawDots(timestamp) {
  const now = timestamp != null ? timestamp : performance.now();

  points.forEach((point, index) => {
    const active = index === current - 1;
    const done = index < current - 1;

    /* Staggered "pop-in" entrance so dots feel placed with movement
       rather than appearing frozen on the image. */
    const entranceT = clamp01((now - stageSetupTime - index * 70) / 380);
    const entranceScale = entranceT <= 0 ? 0 : easeOutBack(entranceT);

    if (entranceScale <= 0) return;

    /* The active dot gently breathes to invite the next tap/drag —
       especially helpful as a touch affordance. */
    const pulse = active ? 1 + Math.sin(now / 260) * 0.07 : 1;

    /* A quick bounce plays on the dot that was just connected. */
    const sinceConnect = now - lastConnectedAt;
    const isJustConnected = done && index === current - 2 && sinceConnect < 320;
    const connectBounce = isJustConnected
      ? 1 + (1 - clamp01(sinceConnect / 320)) * 0.35
      : 1;

    const baseRadius = active ? 20 : 16;
    const radius = baseRadius * entranceScale * pulse * connectBounce;

    ctx.beginPath();
    ctx.arc(point.x, point.y, Math.max(radius, 0), 0, Math.PI * 2);

    if (done) {
      ctx.fillStyle = "#7F77DD";
    } else if (active) {
      ctx.fillStyle = "#F97316";
    } else {
      ctx.fillStyle = "#ffffff";
    }

    ctx.fill();

    ctx.strokeStyle = done || active ? "rgba(255,255,255,0.95)" : "#EDE9FA";
    ctx.lineWidth = 3;
    ctx.stroke();

    /* Soft glow ring around the active dot to make the touch target
       visually bigger and more inviting on small screens. */
    if (active) {
      ctx.beginPath();
      ctx.arc(point.x, point.y, radius + 8, 0, Math.PI * 2);
      ctx.strokeStyle = "rgba(249,115,22,0.35)";
      ctx.lineWidth = 3;
      ctx.stroke();
    }

    ctx.fillStyle = done || active ? "#ffffff" : "#534AB7";
    ctx.font = "bold 15px system-ui";
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.fillText(point.label || (index + 1), point.x, point.y);
  });
}

function clamp01(value) {
  return Math.max(0, Math.min(1, value));
}

function render(timestamp) {
  clearCanvas();
  setImageVisibility();

  drawPermanentLines();
  drawDraggingLine();

  if (!completed) {
    drawDots(timestamp);
  }

  updateUI();
}

function fadeInImage(durationMs, onDone) {
  imageOpacity = 0;
  const dur = durationMs || 2800;
  let startTime = null;

  function animate(timestamp) {
    if (!startTime) startTime = timestamp;
    const linearT = Math.min((timestamp - startTime) / dur, 1);
    /* Ease-out reveal so the image "settles" into view rather than
       fading in at a constant, mechanical rate. */
    imageOpacity = easeOutCubic(linearT);
    render(timestamp);
    if (linearT < 1) {
      requestAnimationFrame(animate);
    } else if (onDone) {
      onDone();
    }
  }

  requestAnimationFrame(animate);
}

function launchConfetti() {
  try { winAudio.currentTime = 0; winAudio.play(); } catch(e) {}

  const cc = document.createElement('canvas');
  cc.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9999;';
  document.body.appendChild(cc);
  const cx = cc.getContext('2d');
  cc.width  = window.innerWidth;
  cc.height = window.innerHeight;

  const colors = ['#F97316','#7F77DD','#EC4899','#22C55E','#EAB308','#3B82F6','#F43F5E','#A855F7','#06B6D4'];
  const shapes = ['rect', 'rect', 'circle', 'star'];

  const particles = Array.from({ length: 180 }, function () {
    return {
      x: Math.random() * cc.width,
      y: -20 - Math.random() * cc.height * 0.6,
      w: 7 + Math.random() * 9,
      h: 4 + Math.random() * 6,
      color: colors[Math.floor(Math.random() * colors.length)],
      shape: shapes[Math.floor(Math.random() * shapes.length)],
      rot: Math.random() * Math.PI * 2,
      rotV: (Math.random() - 0.5) * 0.18,
      vx: (Math.random() - 0.5) * 2.5,
      vy: 1.8 + Math.random() * 3.5,
    };
  });

  const totalDur = 4200;
  let startTime = null;

  function drawStar(ctx2, r) {
    const pts = 5;
    ctx2.beginPath();
    for (let i = 0; i < pts * 2; i++) {
      const angle = (i * Math.PI) / pts - Math.PI / 2;
      const rad = i % 2 === 0 ? r : r * 0.45;
      i === 0 ? ctx2.moveTo(Math.cos(angle) * rad, Math.sin(angle) * rad)
              : ctx2.lineTo(Math.cos(angle) * rad, Math.sin(angle) * rad);
    }
    ctx2.closePath();
    ctx2.fill();
  }

  function animateConfetti(timestamp) {
    if (!startTime) startTime = timestamp;
    const elapsed = timestamp - startTime;
    const alpha = Math.max(0, 1 - elapsed / totalDur);

    cx.clearRect(0, 0, cc.width, cc.height);

    particles.forEach(function (p) {
      p.x  += p.vx;
      p.y  += p.vy;
      p.vy += 0.06;
      p.rot += p.rotV;

      cx.save();
      cx.globalAlpha = alpha;
      cx.translate(p.x, p.y);
      cx.rotate(p.rot);
      cx.fillStyle = p.color;

      if (p.shape === 'circle') {
        cx.beginPath();
        cx.arc(0, 0, p.w / 2, 0, Math.PI * 2);
        cx.fill();
      } else if (p.shape === 'star') {
        drawStar(cx, p.w / 2);
      } else {
        cx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
      }

      cx.restore();
    });

    if (elapsed < totalDur) {
      requestAnimationFrame(animateConfetti);
    } else {
      if (cc.parentNode) cc.parentNode.removeChild(cc);
    }
  }

  requestAnimationFrame(animateConfetti);
}

function resetGame() {
  current = 1;
  completed = false;
  dragging = false;
  activePointerId = null;
  imageOpacity = 0;
  closingLineProgress = 1;
  stageSetupTime = performance.now();
  lastConnectedAt = -Infinity;

  img.style.opacity = "0";
  img.style.filter = "none";
  img.style.transform = "scale(1)";
  canvas.style.cursor = "grab";

  revealBtn.style.display = "none";
  continueBtn.style.display = "none";
  completionPanel.style.display = "none";
  completionPanel.innerHTML = "";
  if (mainPanel) mainPanel.style.display = "";

  render(stageSetupTime);
  startIdleLoop();
}

function showCompletedPanel() {
  if (mainPanel) mainPanel.style.display = "none";

  d2dRounds += 1;
  var total     = points.length - 1;
  var returnTo  = data.returnTo  || '';
  var activityId = data.activityId || '';

  completionPanel.innerHTML =
    '<div class="af-unscored__card">' +
    '  <div class="af-unscored__prog-label">DOTS CONNECTED</div>' +
    '  <div class="af-unscored__prog-track"><div class="af-unscored__prog-fill" id="af-prog-fill" style="width:0%"></div></div>' +
    '  <div class="af-unscored__prog-nums"><span>0</span><strong id="af-prog-text">0 / 0</strong></div>' +
    '  <div class="af-unscored__icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#7F77DD" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></div>' +
    '  <p class="af-unscored__title">Picture revealed!</p>' +
    '  <p class="af-unscored__sub">You connected all the dots!</p>' +
    '  <div class="af-unscored__chips af-unscored__chips--2">' +
    '    <div class="af-unscored__chip"><div class="af-unscored__chip-val" id="af-stat1-val">0</div><div class="af-unscored__chip-lbl">CONNECTIONS</div></div>' +
    '    <div class="af-unscored__chip"><div class="af-unscored__chip-val" id="af-stat2-val">0</div><div class="af-unscored__chip-lbl">ROUNDS</div></div>' +
    '  </div>' +
    '  <div class="af-unscored__banner af-unscored__banner--orange">' +
    '    <div class="af-unscored__banner-icon af-unscored__banner-icon--orange"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div>' +
    '    <div class="af-unscored__banner-text af-unscored__banner-text--orange"><span class="af-unscored__banner-title">Ready to practice?</span>Try the next activity to use this vocabulary.</div>' +
    '  </div>' +
    '  <div class="af-unscored__btns">' +
    '    <button class="af-unscored__btn-secondary" id="af-btn-retry">&#8635; Play again</button>' +
    '    <button class="af-unscored__btn-primary" id="af-btn-next"' + (returnTo ? '' : ' style="display:none"') + '>Next →</button>' +
    '  </div>' +
    '</div>';

  completionPanel.style.display = "block";

  var fillEl   = document.getElementById('af-prog-fill');
  var textEl   = document.getElementById('af-prog-text');
  var stat1El  = document.getElementById('af-stat1-val');
  var stat2El  = document.getElementById('af-stat2-val');
  var retryBtn = document.getElementById('af-btn-retry');
  var nextBtn  = document.getElementById('af-btn-next');

  setTimeout(function () { if (fillEl) fillEl.style.width = '100%'; }, 120);
  if (textEl)  textEl.textContent  = total + ' / ' + total;
  if (stat1El) stat1El.textContent = String(total);
  if (stat2El) stat2El.textContent = String(d2dRounds);

  if (retryBtn) retryBtn.addEventListener('click', resetGame);

  if (nextBtn && returnTo) {
    nextBtn.addEventListener('click', function () {
      if (activityId) {
        var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
        fetch(returnTo + sep + 'activity_percent=100&activity_errors=0&activity_total=' + total +
          '&activity_id=' + encodeURIComponent(activityId) + '&activity_type=dot_to_dot',
          { method: 'GET', credentials: 'same-origin', cache: 'no-store' }).catch(function(){});
      }
      setTimeout(function () {
        try {
          if (window.top && window.top !== window.self) { window.top.location.href = returnTo; return; }
        } catch(e) {}
        window.location.href = returnTo;
      }, 200);
    });
  }

  if (returnTo && activityId) {
    var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
    fetch(returnTo + sep + 'activity_percent=100&activity_errors=0&activity_total=' + total +
      '&activity_id=' + encodeURIComponent(activityId) + '&activity_type=dot_to_dot',
      { method: 'GET', credentials: 'same-origin', cache: 'no-store' }).catch(function(){});
  }
}

function completeGame() {
  completed = true;
  dragging = false;
  stopIdleLoop();
  canvas.style.cursor = "default";
  revealBtn.style.display = "none";
  continueBtn.style.display = "none";

  /* 1 — Animate the closing segment (last dot → first dot) */
  closingLineProgress = 0;
  render();

  const closeDur = 520;
  let closeStart = null;

  function animateClose(ts) {
    if (!closeStart) closeStart = ts;
    closingLineProgress = Math.min((ts - closeStart) / closeDur, 1);
    render();
    if (closingLineProgress < 1) {
      requestAnimationFrame(animateClose);
    } else {
      /* 2 — Brief pause so kids see the complete outline */
      setTimeout(function () {
        /* 3 — Confetti burst */
        launchConfetti();
        /* 4 — Slow image reveal (lines stay visible underneath) */
        fadeInImage(3000, function () {
          /* 5 — Show completed panel after image is fully visible */
          setTimeout(showCompletedPanel, 400);
        });
      }, 350);
    }
  }

  requestAnimationFrame(animateClose);
}

function startDrag(event) {
  if (completed) return;
  if (points.length < 3) return;

  usingTouch = event.pointerType ? event.pointerType !== "mouse" : usingTouch;

  const pos = getPointerPosition(event);

  if (isNearDot(pos, points[current - 1])) {
    dragging = true;
    mouse = pos;
    activePointerId = event.pointerId != null ? event.pointerId : null;
    if (activePointerId != null && canvas.setPointerCapture) {
      try { canvas.setPointerCapture(activePointerId); } catch (e) {}
    }
    canvas.style.cursor = "grabbing";
    render();
  }
}

function moveDrag(event) {
  if (!dragging || completed) return;
  if (activePointerId != null && event.pointerId != null && event.pointerId !== activePointerId) return;

  event.preventDefault();
  mouse = getPointerPosition(event);
  render();
}

function endDrag(event) {
  if (!dragging || completed) return;
  if (activePointerId != null && event.pointerId != null && event.pointerId !== activePointerId) return;

  const pos = getPointerPosition(event);
  const nextDot = points[current];

  if (nextDot && isNearDot(pos, nextDot)) {
    current++;
    lastConnectedAt = performance.now();
    if (navigator.vibrate) { try { navigator.vibrate(15); } catch (e) {} }

    if (current === points.length) {
      completeGame();
      dragging = false;
      activePointerId = null;
      return;
    }
  } else {
    /* Released near a wrong dot — play error sound */
    const nearWrong = points.some(function (p, i) {
      return i !== current && isNearDot(pos, p);
    });
    if (nearWrong) {
      try { errorAudio.currentTime = 0; errorAudio.play(); } catch(e) {}
    }
  }

  dragging = false;
  activePointerId = null;
  canvas.style.cursor = "grab";
  render();
}

function showHint() {
  if (completed) return;
  if (!points[current - 1] || !points[current]) return;

  const from = points[current - 1];
  const to = points[current];

  ctx.save();
  ctx.strokeStyle = "#facc15";
  ctx.lineWidth = 4;
  ctx.setLineDash([8, 8]);

  ctx.beginPath();
  ctx.moveTo(from.x, from.y);
  ctx.lineTo(to.x, to.y);
  ctx.stroke();

  ctx.restore();

  statusText.textContent = "Follow the yellow hint line.";
}

function revealImage() {
  completeGame();
}

canvas.addEventListener("pointerdown", function (event) {
  if (event.pointerType === "touch") event.preventDefault();
  startDrag(event);
}, { passive: false });

canvas.addEventListener("pointermove", function (event) {
  if (dragging) event.preventDefault();
  moveDrag(event);
}, { passive: false });

canvas.addEventListener("pointerup", endDrag);
canvas.addEventListener("pointercancel", endDrag);

canvas.addEventListener("pointerleave", function () {
  if (usingTouch) return; /* touch drags are captured; ignore leave */

  dragging = false;
  activePointerId = null;

  if (!completed) {
    canvas.style.cursor = "grab";
  }

  render();
});

resetBtn.addEventListener("click", resetGame);
hintBtn.addEventListener("click", showHint);
revealBtn.addEventListener("click", revealImage);

// continueBtn is hidden in the new All Done screen; handled by showPassiveDone
continueBtn.addEventListener("click", function () {
  if (data.returnTo) {
    window.location.href = data.returnTo;
  }
});

document.addEventListener("keydown", function (event) {
  if (!event) return;
  const key = String(event.key || "");

  if (key === "h" || key === "H" || key === "Enter") {
    event.preventDefault();
    if (!completed) showHint();
    return;
  }

  if (key === "r" || key === "R" || key === "ArrowLeft") {
    event.preventDefault();
    resetGame();
    return;
  }

  if (key === "ArrowRight") {
    event.preventDefault();
    if (completed && data.returnTo) {
      window.location.href = data.returnTo;
      return;
    }
    if (!completed) {
      showHint();
    }
  }
});

img.addEventListener("load", function () {
  setupCanvas();
});

window.addEventListener("resize", setupCanvas);

if (img.complete) {
  setupCanvas();
}
