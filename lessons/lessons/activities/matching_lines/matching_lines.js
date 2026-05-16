document.addEventListener('DOMContentLoaded', function () {

  /* ── Globals ── */
  var AF           = window.ActivityFeedback;
  var boards       = Array.isArray(window.MATCHING_LINES_DATA)   ? window.MATCHING_LINES_DATA   : [];
  var activityTitle= window.MATCHING_LINES_TITLE                 || 'Matching Lines';
  var returnTo     = window.MATCHING_LINES_RETURN_TO             || '';
  var activityId   = window.MATCHING_LINES_ACTIVITY_ID          || '';

  /* ── DOM refs ── */
  var progressLabelEl = document.getElementById('ml-progress-label');
  var progressFillEl  = document.getElementById('ml-progress-fill');
  var progressBadgeEl = document.getElementById('ml-progress-badge');
  var ptsNumEl        = document.getElementById('ml-pts-num');
  var leftColEl       = document.getElementById('ml-left-col');
  var rightColEl      = document.getElementById('ml-right-col');
  var svgEl           = document.getElementById('ml-svg');
  var checkBtn        = document.getElementById('ml-check');
  var showBtn         = document.getElementById('ml-show');
  var nextBtn         = document.getElementById('ml-next');
  var feedbackEl      = document.getElementById('ml-feedback');
  var activityEl      = document.getElementById('ml-activity');
  var completedEl     = document.getElementById('ml-completed');
  var stageEl         = svgEl ? svgEl.parentElement : null;
  var winAudio        = new Audio('../../hangman/assets/win.mp3');

  if (!boards.length) {
    if (feedbackEl) feedbackEl.textContent = 'No boards available.';
    return;
  }

  /* ── State ── */
  var index       = 0;
  var answered    = false;
  var connections = [];   /* [{leftIdx, shuffledRight, color, overrideColor}] */
  var shuffleMap  = [];   /* shuffleMap[origIdx] = visualRow */
  var reverseMap  = [];   /* reverseMap[visualRow] = origIdx */
  var scores      = boards.map(function () { return 0; });
  var reviewItems = boards.map(function () { return {}; });
  var feedbackTimer  = null;

  /* drag state */
  var drag = null;  /* {leftIdx, startX, startY, curX, curY} */

  var LINE_COLORS = ['#7F77DD', '#F97316', '#534AB7', '#C2580A'];

  /* ── Helpers ── */
  function shuffle(arr) {
    var a = arr.slice();
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = a[i]; a[i] = a[j]; a[j] = t;
    }
    return a;
  }

  function updateProgress() {
    var total   = boards.length;
    var current = index + 1;
    var pct     = Math.round((current / total) * 100);
    if (progressLabelEl) progressLabelEl.textContent = current + ' / ' + total;
    if (progressBadgeEl) progressBadgeEl.textContent = 'Q ' + current + ' of ' + total;
    if (progressFillEl)  progressFillEl.style.width  = pct + '%';
  }

  function setPts(val) {
    if (ptsNumEl) ptsNumEl.textContent = String(val);
  }

  function showFeedbackText(text, color) {
    if (!feedbackEl) return;
    if (feedbackTimer) { clearTimeout(feedbackTimer); feedbackTimer = null; }
    feedbackEl.textContent = text;
    feedbackEl.style.color = color || '#F97316';
    feedbackTimer = setTimeout(function () { feedbackEl.textContent = ''; }, 2000);
  }

  /* ── SVG sync ── */
  function syncSvg() {
    if (!svgEl || !stageEl) return;
    svgEl.setAttribute('width',  stageEl.offsetWidth);
    svgEl.setAttribute('height', stageEl.offsetHeight);
  }

  /* Get center of a dot in stage-relative coords */
  function dotCenter(dotEl) {
    var sr = stageEl.getBoundingClientRect();
    var dr = dotEl.getBoundingClientRect();
    return {
      x: dr.left + dr.width  / 2 - sr.left,
      y: dr.top  + dr.height / 2 - sr.top
    };
  }

  /* Get client point from mouse or touch event */
  function clientPoint(e) {
    if (e.touches && e.touches.length) {
      return { x: e.touches[0].clientX, y: e.touches[0].clientY };
    }
    return { x: e.clientX, y: e.clientY };
  }

  /* ── Drawing ── */
  function makePath(x1, y1, x2, y2, color, temp) {
    var dx  = Math.max(40, Math.abs(x2 - x1) * 0.45);
    var d   = 'M ' + x1 + ' ' + y1
            + ' C ' + (x1 + dx) + ' ' + y1
            + ',' + (x2 - dx) + ' ' + y2
            + ',' + x2 + ' ' + y2;
    var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    p.setAttribute('d', d);
    p.setAttribute('stroke', color);
    p.setAttribute('stroke-width', '3.5');
    p.setAttribute('fill', 'none');
    p.setAttribute('stroke-linecap', 'round');
    if (temp) p.setAttribute('data-temp', '1');
    svgEl.appendChild(p);
  }

  function drawLines() {
    if (!svgEl) return;
    syncSvg();
    /* remove permanent lines, keep temp drag line */
    Array.prototype.forEach.call(svgEl.querySelectorAll('path:not([data-temp])'), function (n) { n.remove(); });

    connections.forEach(function (conn) {
      var dotR = leftColEl  ? leftColEl.querySelector('.ml-dot-r[data-left-idx="'   + conn.leftIdx      + '"]') : null;
      var dotL = rightColEl ? rightColEl.querySelector('.ml-dot-l[data-visual-row="' + conn.shuffledRight + '"]') : null;
      if (!dotR || !dotL) return;
      var p1 = dotCenter(dotR);
      var p2 = dotCenter(dotL);
      makePath(p1.x, p1.y, p2.x, p2.y, conn.overrideColor || conn.color);
    });
  }

  function updateDragLine(curX, curY) {
    if (!svgEl) return;
    /* remove old temp path */
    Array.prototype.forEach.call(svgEl.querySelectorAll('[data-temp]'), function (n) { n.remove(); });
    if (!drag) return;
    var sr = stageEl.getBoundingClientRect();
    var x2 = curX - sr.left;
    var y2 = curY - sr.top;
    makePath(drag.startX, drag.startY, x2, y2, '#7F77DD', true);
  }

  /* ── Load board ── */
  function loadBoard() {
    var board = boards[index] || {};
    var pairs = Array.isArray(board.pairs) ? board.pairs : [];

    answered    = false;
    connections = [];
    drag        = null;

    if (completedEl) completedEl.style.display = 'none';
    if (activityEl)  activityEl.style.display  = '';
    if (feedbackEl)  feedbackEl.textContent     = '';

    updateProgress();
    setPts(0);

    /* Build shuffle map */
    var indices = pairs.map(function (_, i) { return i; });
    var shuffled = shuffle(indices);
    shuffleMap = new Array(pairs.length);
    reverseMap = new Array(pairs.length);
    for (var i = 0; i < shuffled.length; i++) {
      reverseMap[i]           = shuffled[i];
      shuffleMap[shuffled[i]] = i;
    }

    renderColumns(pairs, shuffled);

    if (checkBtn) { checkBtn.disabled = false; }
    if (showBtn)  { showBtn.disabled = false; }
    if (nextBtn)  { nextBtn.disabled = true; nextBtn.textContent = index < boards.length - 1 ? 'Next →' : 'Finish'; }
  }

  /* ── Render columns ── */
  function renderColumns(pairs, shuffled) {
    if (!leftColEl || !rightColEl) return;
    leftColEl.innerHTML  = '';
    rightColEl.innerHTML = '';

    /* Left cards */
    pairs.forEach(function (pair, i) {
      var card = document.createElement('div');
      card.className = 'ml-lcard';

      var icon = document.createElement('div');
      icon.className = 'ml-lcard-icon';

      var leftVal = String(pair.left || '').trim();
      var isUrl   = /^https?:\/\//i.test(leftVal) || /\.(png|jpg|jpeg|gif|webp|svg)(\?|$)/i.test(leftVal);
      if (isUrl) {
        var img = document.createElement('img');
        img.src = leftVal;
        img.alt = '';
        img.className = 'ml-lcard-img';
        icon.appendChild(img);
      } else {
        var lbl = document.createElement('div');
        lbl.className = 'ml-lcard-label';
        lbl.textContent = leftVal;
        icon.appendChild(lbl);
      }

      var dot = document.createElement('div');
      dot.className = 'ml-dot-r';
      dot.dataset.leftIdx = String(i);

      card.appendChild(icon);
      card.appendChild(dot);
      leftColEl.appendChild(card);
    });

    /* Right cards — shuffled */
    shuffled.forEach(function (origIdx, visualRow) {
      var pair = pairs[origIdx];
      var card = document.createElement('div');
      card.className = 'ml-rcard';
      card.dataset.visualRow = String(visualRow);
      card.dataset.origIdx   = String(origIdx);

      var dot = document.createElement('div');
      dot.className = 'ml-dot-l';
      dot.dataset.visualRow = String(visualRow);

      var icon = document.createElement('div');
      icon.className = 'ml-rcard-icon';
      var lbl = document.createElement('div');
      lbl.className = 'ml-rcard-label';
      lbl.textContent = String(pair.right || '').trim();
      icon.appendChild(lbl);

      card.appendChild(dot);
      card.appendChild(icon);
      rightColEl.appendChild(card);
    });

    /* Equalize heights after render + after images load */
    equalizeRowHeights();

    var imgs = leftColEl.querySelectorAll('img');
    if (imgs.length) {
      var loaded = 0, total = imgs.length;
      function onLoad() {
        loaded++;
        if (loaded >= total) { equalizeRowHeights(); drawLines(); }
      }
      Array.prototype.forEach.call(imgs, function (img) {
        if (img.complete) { onLoad(); }
        else { img.addEventListener('load', onLoad); img.addEventListener('error', onLoad); }
      });
    } else {
      drawLines();
    }
  }

  function equalizeRowHeights() {
    if (!leftColEl || !rightColEl) return;
    var lc = leftColEl.querySelectorAll('.ml-lcard');
    var rc = rightColEl.querySelectorAll('.ml-rcard');
    var n  = Math.min(lc.length, rc.length);
    /* Reset */
    for (var i = 0; i < n; i++) { lc[i].style.minHeight = ''; rc[i].style.minHeight = ''; }
    /* Sync */
    for (var i = 0; i < n; i++) {
      var h = Math.max(lc[i].offsetHeight, rc[i].offsetHeight);
      lc[i].style.minHeight = h + 'px';
      rc[i].style.minHeight = h + 'px';
    }
  }

  /* ── Drag logic ── */
  function startDrag(e, leftIdx) {
    if (answered) return;
    e.preventDefault();

    var dot = leftColEl.querySelector('.ml-dot-r[data-left-idx="' + leftIdx + '"]');
    if (!dot) return;

    syncSvg();
    var p = dotCenter(dot);
    var cp = clientPoint(e);

    drag = { leftIdx: leftIdx, startX: p.x, startY: p.y, curX: cp.x, curY: cp.y };

    /* highlight source dot */
    dot.classList.add('ml-dot-active');
  }

  function moveDrag(e) {
    if (!drag) return;
    e.preventDefault();
    var cp = clientPoint(e);
    drag.curX = cp.x;
    drag.curY = cp.y;
    updateDragLine(cp.x, cp.y);
  }

  function endDrag(e) {
    if (!drag) return;

    /* Clear temp line and dot highlight */
    Array.prototype.forEach.call(svgEl.querySelectorAll('[data-temp]'), function (n) { n.remove(); });
    var srcDot = leftColEl.querySelector('.ml-dot-r[data-left-idx="' + drag.leftIdx + '"]');
    if (srcDot) srcDot.classList.remove('ml-dot-active');

    /* Find which right card / dot is under the pointer */
    var cp     = clientPoint(e.changedTouches ? e : e);
    if (e.changedTouches && e.changedTouches.length) { cp = { x: e.changedTouches[0].clientX, y: e.changedTouches[0].clientY }; }
    var target = document.elementFromPoint(cp.x, cp.y);

    /* Walk up to find .ml-rcard or .ml-dot-l */
    var rcard = null;
    var el = target;
    while (el && el !== document.body) {
      if (el.classList && (el.classList.contains('ml-rcard') || el.classList.contains('ml-dot-l'))) {
        rcard = el.classList.contains('ml-rcard') ? el : el.closest('.ml-rcard');
        break;
      }
      el = el.parentElement;
    }

    if (rcard) {
      var visualRow = parseInt(rcard.dataset.visualRow, 10);
      connect(drag.leftIdx, visualRow);
    }

    drag = null;
  }

  function connect(leftIdx, visualRow) {
    /* Remove old connection from this left or this right */
    connections = connections.filter(function (c) { return c.leftIdx !== leftIdx && c.shuffledRight !== visualRow; });

    var color = LINE_COLORS[connections.length % LINE_COLORS.length];
    connections.push({ leftIdx: leftIdx, shuffledRight: visualRow, color: color });

    drawLines();
  }

  /* ── Bind drag events to stage ── */
  function bindDragEvents() {
    if (!stageEl) return;

    /* mousedown on left dot */
    stageEl.addEventListener('mousedown', function (e) {
      var dot = e.target.closest ? e.target.closest('.ml-dot-r') : null;
      if (!dot) return;
      startDrag(e, parseInt(dot.dataset.leftIdx, 10));
    });

    window.addEventListener('mousemove', function (e) {
      if (drag) moveDrag(e);
    });

    window.addEventListener('mouseup', function (e) {
      if (drag) endDrag(e);
    });

    /* touch */
    stageEl.addEventListener('touchstart', function (e) {
      var dot = e.target.closest ? e.target.closest('.ml-dot-r') : null;
      if (!dot) return;
      startDrag(e, parseInt(dot.dataset.leftIdx, 10));
    }, { passive: false });

    window.addEventListener('touchmove', function (e) {
      if (drag) moveDrag(e);
    }, { passive: false });

    window.addEventListener('touchend', function (e) {
      if (drag) endDrag(e);
    });
  }

  /* ── Check ── */
  function checkAnswers() {
    if (answered) return;
    var board = boards[index] || {};
    var pairs = Array.isArray(board.pairs) ? board.pairs : [];
    var total   = pairs.length;
    var correct = 0;

    answered = true;

    connections.forEach(function (conn) {
      var origRight = reverseMap[conn.shuffledRight];
      var ok = (conn.leftIdx === origRight);
      conn.overrideColor = ok ? '#22c55e' : '#E24B4A';
      if (ok) correct++;
    });

    drawLines();

    var pts = Math.round((correct / total) * 1000);
    setPts(pts);

    var allOk = correct === total && connections.length === total;
    scores[index] = allOk ? 1 : 0;

    reviewItems[index] = {
      question:      board.prompt || ('Board ' + (index + 1)),
      yourAnswer:    correct + '/' + total + ' correct',
      correctAnswer: pairs.map(function (p) { return p.left + ' → ' + p.right; }).join(', '),
      score:         scores[index]
    };

    if (allOk) { showFeedbackText('Perfect! 🎉', '#22c55e'); }
    else        { showFeedbackText(correct + ' / ' + total + ' correct', '#F97316'); }

    if (checkBtn) checkBtn.disabled = true;
    if (showBtn)  showBtn.disabled  = true;
    if (nextBtn)  nextBtn.disabled  = false;
  }

  /* ── Show Answers ── */
  function showAnswers() {
    if (answered) return;
    var board = boards[index] || {};
    var pairs = Array.isArray(board.pairs) ? board.pairs : [];
    answered = true;
    scores[index] = -1;

    connections = pairs.map(function (p, origIdx) {
      return { leftIdx: origIdx, shuffledRight: shuffleMap[origIdx], color: '#7F77DD' };
    });

    drawLines();

    reviewItems[index] = {
      question:      board.prompt || ('Board ' + (index + 1)),
      yourAnswer:    '(revealed)',
      correctAnswer: pairs.map(function (p) { return p.left + ' → ' + p.right; }).join(', '),
      score:         -1
    };

    showFeedbackText('Answers shown', '#7F77DD');
    setPts(0);
    if (checkBtn) checkBtn.disabled = true;
    if (showBtn)  showBtn.disabled  = true;
    if (nextBtn)  nextBtn.disabled  = false;
  }

  /* ── Next / Completed ── */
  function nextBoard() {
    if (index < boards.length - 1) { index++; loadBoard(); }
    else { showCompleted(); }
  }

  function showCompleted() {
    if (!completedEl) return;
    if (activityEl) activityEl.style.display = 'none';
    completedEl.style.display = '';

    AF.showCompleted({
      target:        completedEl,
      scores:        scores,
      title:         activityTitle,
      activityType:  'Matching Lines',
      questionCount: boards.length,
      winAudio:      winAudio,
      onRetry:       restartActivity,
      onReview: function () {
        AF.showReview({ target: completedEl, items: reviewItems, onRetry: restartActivity });
      }
    });

    var result = AF.computeScore(scores);
    if (returnTo && activityId) {
      var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
      fetch(returnTo + sep
        + 'activity_percent=' + result.percent
        + '&activity_errors=' + result.wrong
        + '&activity_total='  + result.total
        + '&activity_id='     + encodeURIComponent(activityId)
        + '&activity_type=matching_lines',
        { method: 'GET', credentials: 'same-origin', cache: 'no-store' }
      ).catch(function () {});
    }
  }

  function restartActivity() {
    index       = 0;
    scores      = boards.map(function () { return 0; });
    reviewItems = boards.map(function () { return {}; });
    loadBoard();
  }

  /* ── Resize ── */
  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () { equalizeRowHeights(); drawLines(); }, 80);
  });

  /* ── Button events ── */
  if (checkBtn) checkBtn.addEventListener('click', checkAnswers);
  if (showBtn)  showBtn.addEventListener('click',  showAnswers);
  if (nextBtn)  nextBtn.addEventListener('click',  nextBoard);

  /* ── Boot ── */
  bindDragEvents();
  loadBoard();
});
