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

let points = [];
let current = 1;
let completed = false;
let dragging = false;
let imageOpacity = 0;
let mouse = { x: 0, y: 0 };

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

  render();
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

function isNearDot(pos, dot) {
  return Math.hypot(pos.x - dot.x, pos.y - dot.y) < 42;
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
  } else {
    img.style.opacity = "0.08";
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
    ctx.beginPath();
    ctx.moveTo(points[points.length - 1].x, points[points.length - 1].y);
    ctx.lineTo(points[0].x, points[0].y);
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

function drawDots() {
  points.forEach((point, index) => {
    const active = index === current - 1;
    const done = index < current - 1;

    ctx.beginPath();
    ctx.arc(point.x, point.y, active ? 20 : 16, 0, Math.PI * 2);

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

    ctx.fillStyle = done || active ? "#ffffff" : "#534AB7";
    ctx.font = "bold 15px system-ui";
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.fillText(point.label || (index + 1), point.x, point.y);
  });
}

function render() {
  clearCanvas();
  setImageVisibility();

  drawPermanentLines();
  drawDraggingLine();

  if (!completed) {
    drawDots();
  }

  updateUI();
}

function fadeInImage() {
  imageOpacity = 0;

  function animate() {
    imageOpacity += 0.025;

    if (imageOpacity > 1) {
      imageOpacity = 1;
    }

    render();

    if (imageOpacity < 1) {
      requestAnimationFrame(animate);
    }
  }

  animate();
}

function resetGame() {
  current = 1;
  completed = false;
  dragging = false;
  imageOpacity = 0;

  img.style.opacity = "0.08";
  canvas.style.cursor = "grab";

  revealBtn.style.display = "none";
  continueBtn.style.display = "none";
  completionPanel.style.display = "none";

  render();
}

function completeGame() {
  completed = true;
  dragging = false;
  canvas.style.cursor = "default";

  revealBtn.style.display = "none";
  continueBtn.style.display = "inline-flex";
  completionPanel.style.display = "block";
  completionScore.textContent = "You connected all the dots.";

  fadeInImage();
}

function startDrag(event) {
  if (completed) return;
  if (points.length < 3) return;

  const pos = getPointerPosition(event);

  if (isNearDot(pos, points[current - 1])) {
    dragging = true;
    mouse = pos;
    canvas.style.cursor = "grabbing";
    render();
  }
}

function moveDrag(event) {
  if (!dragging || completed) return;

  event.preventDefault();
  mouse = getPointerPosition(event);
  render();
}

function endDrag(event) {
  if (!dragging || completed) return;

  const pos = getPointerPosition(event);
  const nextDot = points[current];

  if (nextDot && isNearDot(pos, nextDot)) {
    current++;

    if (current === points.length) {
      completeGame();
      return;
    }
  }

  dragging = false;
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

canvas.addEventListener("mousedown", startDrag);
canvas.addEventListener("mousemove", moveDrag);
canvas.addEventListener("mouseup", endDrag);

canvas.addEventListener("mouseleave", function () {
  dragging = false;

  if (!completed) {
    canvas.style.cursor = "grab";
  }

  render();
});

canvas.addEventListener("touchstart", function (event) {
  event.preventDefault();
  startDrag(event);
}, { passive: false });

canvas.addEventListener("touchmove", moveDrag, { passive: false });
canvas.addEventListener("touchend", endDrag);

resetBtn.addEventListener("click", resetGame);
hintBtn.addEventListener("click", showHint);
revealBtn.addEventListener("click", revealImage);

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
