document.addEventListener('DOMContentLoaded', function () {
  const payload = window.DOT_TO_DOT_DATA || {};
  const points = Array.isArray(payload.points) ? payload.points : [];
  const labelSettings = payload.labelSettings || {};

// dot_to_dot.js
// (Optional: Place shared JS logic here if needed in the future)
  const stage = document.getElementById('d2dvStage');
  const canvas = document.getElementById('d2dvCanvas');
  const image = document.getElementById('d2dvFinalImage');
  const progressEl = document.getElementById('d2dvProgress');
  const counterEl = document.getElementById('d2dvCounter');
  const statusEl = document.getElementById('d2dvStatus');
  const resetBtn = document.getElementById('d2dvResetBtn');
  const hintBtn = document.getElementById('d2dvHintBtn');
  const revealBtn = document.getElementById('d2dvRevealBtn');
  const continueBtn = document.getElementById('d2dvContinueBtn');

  if (!stage || !canvas || !image || points.length < 3) {
    return;
  }

  const ctx = canvas.getContext('2d');
  const normalizedPoints = points
    .map(function (p) {
      return {
        x: Number(p.x),
        y: Number(p.y),
        label: (p && typeof p.label !== 'undefined') ? String(p.label) : ''
      };
    })
    .filter(function (p) {
      return Number.isFinite(p.x) && Number.isFinite(p.y) && p.x >= 0 && p.x <= 1 && p.y >= 0 && p.y <= 1;
    });

  if (normalizedPoints.length < 3) {
    return;
  }

  function numberToLetters(value) {
    if (value < 1) {
      return String(value);
    }
    let n = value;
    let letters = '';
    while (n > 0) {
      n -= 1;
      letters = String.fromCharCode(65 + (n % 26)) + letters;
      n = Math.floor(n / 26);
    }
    return letters;
  }

  function numberToWordsEn(value) {
    const ones = ['zero','one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
    const tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    if (value < 20) return ones[value] || String(value);
    if (value < 100) {
      const ten = Math.floor(value / 10);
      const rest = value % 10;
      return rest === 0 ? tens[ten] : (tens[ten] + '-' + ones[rest]);
    }
    if (value < 1000) {
      const hundred = Math.floor(value / 100);
      const rest = value % 100;
      return rest === 0 ? (ones[hundred] + ' hundred') : (ones[hundred] + ' hundred ' + numberToWordsEn(rest));
    }
    return String(value);
  }

  function fallbackLabel(index) {
    const mode = String(labelSettings.mode || 'number');
    const start = Math.max(1, Number(labelSettings.start || 1));
    const step = Math.max(1, Number(labelSettings.step || 1));
    const value = start + (index * step);
    if (mode === 'letter') {
      return numberToLetters(value);
    }
    if (mode === 'word') {
      return numberToWordsEn(value);
    }
    return String(value);
  }

  function pointLabel(index) {
    const point = normalizedPoints[index];
    if (point && point.label) {
      return point.label;
    }
    return fallbackLabel(index);
  }

  const lineSound = new Audio('../../hangman/assets/correct.wav');
  const okSound   = new Audio('../../hangman/assets/correct.wav');
  const failSound = new Audio('../../hangman/assets/lose.mp3');
  const winSound  = new Audio('../../hangman/assets/win.mp3');

  let currentIndex = 0;
  let errors = 0;
  let dragging = false;
  let dragPoint = null;
  let done = false;
  let scoreSaved = false;
  let completionUrl = '';

  function play(audio) {
    try {
      audio.pause();
      audio.currentTime = 0;
      audio.play();
    } catch (e) {
      // ignore audio policy failures
    }
  }

  function stageRect() {
    return canvas.getBoundingClientRect();
  }

  function resizeCanvas() {
    const rect = stageRect();
    const dpr = window.devicePixelRatio || 1;

    canvas.width = Math.round(rect.width * dpr);
    canvas.height = Math.round(rect.height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    draw();
  }

  function pointPx(index) {
    const rect = stageRect();
    const p = normalizedPoints[index];
    return {
      x: p.x * rect.width,
      y: p.y * rect.height
    };
  }

  function distance(a, b) {
    const dx = a.x - b.x;
    const dy = a.y - b.y;
    return Math.sqrt(dx * dx + dy * dy);
  }

  function pointerToLocal(event) {
    const rect = stageRect();
    return {
      x: event.clientX - rect.left,
      y: event.clientY - rect.top
    };
  }

  function hitRadius() {
    const rect = stageRect();
    return Math.max(16, Math.min(28, Math.min(rect.width, rect.height) * 0.04));
  }

  function drawBase() {
    const rect = stageRect();
    ctx.clearRect(0, 0, rect.width, rect.height);

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, rect.width, rect.height);

    if (currentIndex > 0) {
      ctx.strokeStyle = '#1d4ed8';
      ctx.lineWidth = 5;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.beginPath();
      const first = pointPx(0);
      ctx.moveTo(first.x, first.y);
      for (let i = 1; i <= currentIndex; i += 1) {
        const p = pointPx(i);
        ctx.lineTo(p.x, p.y);
      }
      ctx.stroke();
    }

    normalizedPoints.forEach(function (_, i) {
      const p = pointPx(i);
      const isConnected = i <= currentIndex;
      const isCurrent = i === currentIndex && !done;

      ctx.beginPath();
      ctx.fillStyle = isCurrent ? '#0ea5e9' : (isConnected ? '#1d4ed8' : '#111827');
      ctx.arc(p.x, p.y, 6, 0, Math.PI * 2);
      ctx.fill();

      ctx.fillStyle = '#111827';
      ctx.font = '700 16px Nunito, sans-serif';
      ctx.fillText(pointLabel(i), p.x + 8, p.y - 8);
    });
  }

  function drawDraggingLine() {
    if (!dragging || !dragPoint) {
      return;
    }

    const start = pointPx(currentIndex);
    ctx.strokeStyle = '#0ea5e9';
    ctx.lineWidth = 4;
    ctx.lineCap = 'round';
    ctx.beginPath();
    ctx.moveTo(start.x, start.y);
    ctx.lineTo(dragPoint.x, dragPoint.y);
    ctx.stroke();
  }

  function draw() {
    drawBase();
    drawDraggingLine();
  }

  function updateStatus() {
    const totalSegments = normalizedPoints.length - 1;

    if (done) {
      progressEl.textContent = 'Great job!';
      counterEl.textContent = totalSegments + ' / ' + totalSegments + ' lines';
      statusEl.textContent = stage.classList.contains('revealed')
        ? 'You completed the picture!'
        : 'All dots connected! Click Reveal Image to see the picture.';
      return;
    }

    const nextA = pointLabel(currentIndex);
    const nextB = pointLabel(currentIndex + 1);
    progressEl.textContent = 'Connect ' + nextA + ' to ' + nextB;
    counterEl.textContent = currentIndex + ' / ' + totalSegments + ' lines';
    statusEl.textContent = 'Draw from point ' + nextA + ' to point ' + nextB + '.';
    continueBtn.style.display = 'none';
  }

  function buildReturnUrl(percent, err, total) {
    const pageParams = new URLSearchParams(window.location.search || '');
    let baseReturn = typeof payload.returnTo === 'string' ? payload.returnTo : '';

    if (!baseReturn) {
      const unit = pageParams.get('unit') || '';
      const assignment = pageParams.get('assignment') || '';
      const source = pageParams.get('source') || '';
      const from = pageParams.get('from') || '';

      if (assignment && unit && (from === 'student_course' || pageParams.get('embedded') === '1')) {
        baseReturn = '../../academic/student_course.php?assignment=' + encodeURIComponent(assignment) + '&unit=' + encodeURIComponent(unit);
      } else if (unit) {
        baseReturn = '../../academic/unit_view.php?unit=' + encodeURIComponent(unit);
        if (source) {
          baseReturn += '&source=' + encodeURIComponent(source);
        }
      }
    }

    if (!baseReturn) {
      return '';
    }

    const joiner = baseReturn.indexOf('?') === -1 ? '?' : '&';

    return baseReturn
      + joiner + 'activity_percent=' + encodeURIComponent(String(percent))
      + '&activity_errors=' + encodeURIComponent(String(err))
      + '&activity_total=' + encodeURIComponent(String(total))
      + '&activity_id=' + encodeURIComponent(String(payload.activityId || ''))
      + '&activity_type=' + encodeURIComponent('dot_to_dot');
  }

  function persistCompletion() {
    if (scoreSaved) {
      return;
    }

    const totalSegments = normalizedPoints.length - 1;
    completionUrl = buildReturnUrl(100, errors, totalSegments);

    if (!completionUrl) {
      scoreSaved = true;
      return;
    }

    scoreSaved = true;

    try {
      fetch(completionUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        keepalive: true,
      }).catch(function () {
        scoreSaved = false;
      });
    } catch (e) {
      scoreSaved = false;
    }
  }

  function completeActivity() {
    done = true;
    dragging = false;
    dragPoint = null;
    play(winSound);
    persistCompletion();
    if (revealBtn) revealBtn.style.display = '';
    updateStatus();
    draw();
  }

  function resetActivity() {
    currentIndex = 0;
    errors = 0;
    dragging = false;
    dragPoint = null;
    done = false;
    scoreSaved = false;
    completionUrl = '';
    stage.classList.remove('revealed');
    if (revealBtn) revealBtn.style.display = 'none';
    continueBtn.style.display = 'none';
    updateStatus();
    draw();
  }

  function maybeStartDrag(point) {
    if (done) {
      return;
    }

    const start = pointPx(currentIndex);
    if (distance(start, point) > hitRadius()) {
      return;
    }

    dragging = true;
    dragPoint = point;
    play(lineSound);
  }

  function maybeFinishDrag(point) {
    if (!dragging || done) {
      return;
    }

    dragging = false;
    dragPoint = null;

    const target = pointPx(currentIndex + 1);
    if (distance(target, point) <= hitRadius()) {
      currentIndex += 1;
      play(okSound);
      if (currentIndex >= normalizedPoints.length - 1) {
        completeActivity();
        return;
      }
    } else {
      errors += 1;
      play(failSound);
      statusEl.textContent = 'Try again. Start from point ' + pointLabel(currentIndex) + '.';
    }

    updateStatus();
    draw();
  }

  canvas.addEventListener('pointerdown', function (event) {
    event.preventDefault();
    maybeStartDrag(pointerToLocal(event));
    draw();
  });

  canvas.addEventListener('pointermove', function (event) {
    if (!dragging || done) {
      return;
    }
    dragPoint = pointerToLocal(event);
    draw();
  });

  canvas.addEventListener('pointerup', function (event) {
    event.preventDefault();
    maybeFinishDrag(pointerToLocal(event));
  });

  canvas.addEventListener('pointercancel', function () {
    dragging = false;
    dragPoint = null;
    draw();
  });

  hintBtn.addEventListener('click', function () {
    if (done) {
      return;
    }
    errors += 1;
    const nextA = pointLabel(currentIndex);
    const nextB = pointLabel(currentIndex + 1);
    statusEl.textContent = 'Hint: connect ' + nextA + ' to ' + nextB + '.';
  });

  resetBtn.addEventListener('click', function () {
    resetActivity();
  });

  if (revealBtn) {
    revealBtn.addEventListener('click', function () {
      stage.classList.add('revealed');
      revealBtn.style.display = 'none';
      continueBtn.style.display = completionUrl ? '' : 'none';
      updateStatus();
    });
  }

  continueBtn.addEventListener('click', function () {
    if (!completionUrl) {
      return;
    }

    try {
      if (window.top && window.top !== window.self) {
        window.top.location.href = completionUrl;
        return;
      }
    } catch (e) {
      // fall back to current frame
    }

    window.location.href = completionUrl;
  });

  window.addEventListener('resize', resizeCanvas);

  if (image.complete) {
    resizeCanvas();
  } else {
    image.addEventListener('load', resizeCanvas);
  }

  // Always start with image hidden and canvas visible
  image.style.opacity = '0';
  canvas.style.opacity = '1';
  canvas.style.pointerEvents = '';

  updateStatus();
  draw();
});
