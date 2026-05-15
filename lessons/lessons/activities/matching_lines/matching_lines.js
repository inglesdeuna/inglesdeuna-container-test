document.addEventListener('DOMContentLoaded', function () {

  /* ── Globals ── */
  var AF           = window.ActivityFeedback;
  var boards       = Array.isArray(window.MATCHING_LINES_DATA)        ? window.MATCHING_LINES_DATA        : [];
  var activityTitle= window.MATCHING_LINES_TITLE       || 'Matching Lines';
  var returnTo     = window.MATCHING_LINES_RETURN_TO   || '';
  var activityId   = window.MATCHING_LINES_ACTIVITY_ID || '';

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
  var winAudio        = new Audio('../../hangman/assets/win.mp3');

  if (!boards.length) {
    if (feedbackEl) feedbackEl.textContent = 'No boards available.';
    return;
  }

  /* ── State ── */
  var index       = 0;
  var answered    = false;
  var selectedLeft  = null;   /* left card index (0-based) or null */
  var selectedRight = null;   /* visual row index of right card or null */
  var connections   = [];     /* [{leftIdx, rightIdx, color}] */
  var shuffleMap    = [];     /* shuffleMap[originalIdx] = visualRowIdx */
  var reverseMap    = [];     /* reverseMap[visualRowIdx] = originalIdx */
  var scores        = boards.map(function () { return 0; });
  var reviewItems   = boards.map(function () { return {}; });
  var feedbackTimer = null;

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

  function showFeedbackText(text, color, autoClear) {
    if (!feedbackEl) return;
    if (feedbackTimer) { clearTimeout(feedbackTimer); feedbackTimer = null; }
    feedbackEl.textContent = text;
    feedbackEl.style.color = color || '#F97316';
    if (autoClear) {
      feedbackTimer = setTimeout(function () {
        feedbackEl.textContent = '';
      }, 2000);
    }
  }

  /* ── Render board ── */
  function loadBoard() {
    var board = boards[index] || {};
    var pairs = Array.isArray(board.pairs) ? board.pairs : [];

    answered      = false;
    selectedLeft  = null;
    selectedRight = null;
    connections   = [];

    if (completedEl) completedEl.style.display = 'none';
    if (activityEl)  activityEl.style.display  = '';
    if (feedbackEl)  { feedbackEl.textContent = ''; }

    updateProgress();
    setPts(0);

    /* build shuffle map */
    var indices = pairs.map(function (_, i) { return i; });
    var shuffled = shuffle(indices);
    shuffleMap  = new Array(pairs.length);
    reverseMap  = new Array(pairs.length);
    for (var i = 0; i < shuffled.length; i++) {
      /* shuffled[i] = original pair index placed at visual row i */
      reverseMap[i]          = shuffled[i];
      shuffleMap[shuffled[i]] = i;
    }

    renderColumns(pairs, shuffled);
    drawLines();

    if (checkBtn) { checkBtn.disabled = false; }
    if (showBtn)  { showBtn.disabled = false; }
    if (nextBtn)  {
      nextBtn.disabled = true;
      nextBtn.textContent = index < boards.length - 1 ? 'Next →' : 'Finish';
    }
  }

  function renderColumns(pairs, shuffled) {
    if (!leftColEl || !rightColEl) return;
    leftColEl.innerHTML  = '';
    rightColEl.innerHTML = '';

    /* Left cards — in original order */
    pairs.forEach(function (pair, i) {
      var card = document.createElement('div');
      card.className = 'ml-lcard';
      card.dataset.idx = String(i);

      var icon = document.createElement('div');
      icon.className = 'ml-lcard-icon';

      /* Detect image URL vs plain text */
      var leftVal = String(pair.left || '').trim();
      var isUrl   = /^https?:\/\//i.test(leftVal) || /\.(png|jpg|jpeg|gif|webp|svg)(\?|$)/i.test(leftVal);

      if (isUrl) {
        var img = document.createElement('img');
        img.src = leftVal;
        img.alt = '';
        img.className = 'ml-lcard-img';
        icon.appendChild(img);
      } else {
        var label = document.createElement('div');
        label.className = 'ml-lcard-label';
        label.textContent = leftVal;
        icon.appendChild(label);
      }

      var dot = document.createElement('div');
      dot.className = 'ml-dot-r';
      dot.dataset.leftIdx = String(i);
      dot.addEventListener('click', function (e) {
        e.stopPropagation();
        handleDotLeftClick(i);
      });

      card.appendChild(icon);
      card.appendChild(dot);
      leftColEl.appendChild(card);
    });

    /* Right cards — in shuffled order */
    shuffled.forEach(function (origIdx, visualRow) {
      var pair = pairs[origIdx];
      var card = document.createElement('div');
      card.className = 'ml-rcard';
      card.dataset.visualRow = String(visualRow);
      card.dataset.origIdx   = String(origIdx);

      var icon = document.createElement('div');
      icon.className = 'ml-rcard-icon';

      var label = document.createElement('div');
      label.className = 'ml-rcard-label';
      label.textContent = pair.right;

      var dot = document.createElement('div');
      dot.className = 'ml-dot-l';
      dot.dataset.visualRow = String(visualRow);
      dot.addEventListener('click', function (e) {
        e.stopPropagation();
        handleDotRightClick(visualRow);
      });

      icon.appendChild(label);
      card.appendChild(icon);
      card.appendChild(dot);
      rightColEl.appendChild(card);
    });

    equalizeRowHeights();
  }

  function equalizeRowHeights() {
    if (!leftColEl || !rightColEl) return;
    var lcards = leftColEl.querySelectorAll('.ml-lcard');
    var rcards = rightColEl.querySelectorAll('.ml-rcard');
    var count  = Math.min(lcards.length, rcards.length);
    for (var i = 0; i < count; i++) {
      /* reset first so natural height is measured */
      lcards[i].style.minHeight = '';
      rcards[i].style.minHeight = '';
    }
    for (var i = 0; i < count; i++) {
      var h = Math.max(lcards[i].offsetHeight, rcards[i].offsetHeight);
      lcards[i].style.minHeight = h + 'px';
      rcards[i].style.minHeight = h + 'px';
    }
  }

  /* ── Connection logic ── */
  function handleDotLeftClick(leftIdx) {
    if (answered) return;

    /* deselect previous left selection */
    document.querySelectorAll('.ml-dot-r.ml-selected').forEach(function (d) {
      d.classList.remove('ml-selected');
    });

    selectedLeft = leftIdx;
    var dot = leftColEl.querySelector('.ml-dot-r[data-left-idx="' + leftIdx + '"]');
    if (dot) dot.classList.add('ml-selected');

    if (selectedRight !== null) {
      tryConnect();
    }
  }

  function handleDotRightClick(visualRow) {
    if (answered) return;

    /* deselect previous right selection */
    document.querySelectorAll('.ml-dot-l.ml-selected').forEach(function (d) {
      d.classList.remove('ml-selected');
    });

    selectedRight = visualRow;
    var dot = rightColEl.querySelector('.ml-dot-l[data-visual-row="' + visualRow + '"]');
    if (dot) dot.classList.add('ml-selected');

    if (selectedLeft !== null) {
      tryConnect();
    }
  }

  function tryConnect() {
    if (selectedLeft === null || selectedRight === null) return;

    var li = selectedLeft;
    var ri = selectedRight;

    /* remove any existing connection from this left item */
    connections = connections.filter(function (c) { return c.leftIdx !== li; });
    /* remove any existing connection from this right item */
    connections = connections.filter(function (c) { return c.rightIdx !== ri; });

    var color = LINE_COLORS[connections.length % LINE_COLORS.length];
    connections.push({ leftIdx: li, rightIdx: ri, color: color });

    /* clear selection */
    document.querySelectorAll('.ml-dot-r.ml-selected, .ml-dot-l.ml-selected').forEach(function (d) {
      d.classList.remove('ml-selected');
    });
    selectedLeft  = null;
    selectedRight = null;

    drawLines();
  }

  /* ── SVG bezier lines ── */
  function drawLines() {
    if (!svgEl) return;
    svgEl.innerHTML = '';

    var svgRect = svgEl.getBoundingClientRect();
    if (!svgRect.width && !svgRect.height) return;

    connections.forEach(function (conn) {
      var dotR = leftColEl  ? leftColEl.querySelector('.ml-dot-r[data-left-idx="' + conn.leftIdx + '"]') : null;
      var dotL = rightColEl ? rightColEl.querySelector('.ml-dot-l[data-visual-row="' + conn.rightIdx + '"]') : null;
      if (!dotR || !dotL) return;

      var rR = dotR.getBoundingClientRect();
      var rL = dotL.getBoundingClientRect();

      var x1 = rR.left + rR.width  / 2 - svgRect.left;
      var y1 = rR.top  + rR.height / 2 - svgRect.top;
      var x2 = rL.left + rL.width  / 2 - svgRect.left;
      var y2 = rL.top  + rL.height / 2 - svgRect.top;

      var cx1 = x1 + 60;
      var cx2 = x2 - 60;

      var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', 'M ' + x1 + ',' + y1 + ' C ' + cx1 + ',' + y1 + ' ' + cx2 + ',' + y2 + ' ' + x2 + ',' + y2);
      path.setAttribute('stroke', conn.overrideColor || conn.color || '#7F77DD');
      path.setAttribute('stroke-width', '3.5');
      path.setAttribute('fill', 'none');
      path.setAttribute('stroke-linecap', 'round');
      svgEl.appendChild(path);
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

    /* evaluate connections */
    connections.forEach(function (conn) {
      /* a connection is correct when leftIdx === original pair index that matches rightIdx visual row */
      var origRight = reverseMap[conn.rightIdx]; /* which original pair is at this visual row */
      var isCorrect = (conn.leftIdx === origRight);
      if (isCorrect) {
        correct++;
        conn.overrideColor = '#22c55e';
      } else {
        conn.overrideColor = '#E24B4A';
      }
    });

    drawLines();

    /* pts */
    var pts = Math.round((correct / total) * 1000);
    setPts(pts);

    /* score */
    var allCorrect = correct === total && connections.length === total;
    scores[index] = allCorrect ? 1 : 0;

    reviewItems[index] = {
      question:      board.prompt || ('Board ' + (index + 1)),
      yourAnswer:    correct + '/' + total + ' correct',
      correctAnswer: pairs.map(function (p) { return p.left + ' → ' + p.right; }).join(', '),
      score:         scores[index]
    };

    /* feedback */
    if (allCorrect) {
      showFeedbackText('Perfect! 🎉', '#22c55e', true);
    } else {
      showFeedbackText(correct + ' / ' + total + ' correct', '#F97316', true);
    }

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

    /* build correct connections using shuffleMap */
    connections = pairs.map(function (p, origIdx) {
      return { leftIdx: origIdx, rightIdx: shuffleMap[origIdx], color: '#7F77DD' };
    });

    drawLines();

    reviewItems[index] = {
      question:      board.prompt || ('Board ' + (index + 1)),
      yourAnswer:    '(revealed)',
      correctAnswer: pairs.map(function (p) { return p.left + ' → ' + p.right; }).join(', '),
      score:         -1
    };

    showFeedbackText('Answers shown', '#7F77DD', true);
    setPts(0);

    if (checkBtn) checkBtn.disabled = true;
    if (showBtn)  showBtn.disabled  = true;
    if (nextBtn)  nextBtn.disabled  = false;
  }

  /* ── Next ── */
  function nextBoard() {
    if (index < boards.length - 1) {
      index++;
      loadBoard();
    } else {
      showCompleted();
    }
  }

  /* ── Completed ── */
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
      fetch(returnTo + sep +
        'activity_percent=' + result.percent +
        '&activity_errors=' + result.wrong +
        '&activity_total='  + result.total +
        '&activity_id='     + encodeURIComponent(activityId) +
        '&activity_type=matching_lines',
        { method: 'GET', credentials: 'same-origin', cache: 'no-store' }
      ).catch(function () {});
    }
  }

  /* ── Restart ── */
  function restartActivity() {
    index       = 0;
    scores      = boards.map(function () { return 0; });
    reviewItems = boards.map(function () { return {}; });
    loadBoard();
  }

  /* ── Resize → redraw ── */
  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      equalizeRowHeights();
      drawLines();
    }, 80);
  });

  /* ── Button listeners ── */
  if (checkBtn) checkBtn.addEventListener('click', checkAnswers);
  if (showBtn)  showBtn.addEventListener('click',  showAnswers);
  if (nextBtn)  nextBtn.addEventListener('click',  nextBoard);

  /* ── Boot ── */
  loadBoard();
});
