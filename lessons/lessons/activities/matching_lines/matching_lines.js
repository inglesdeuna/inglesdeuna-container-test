document.addEventListener('DOMContentLoaded', function () {
  var AF = window.ActivityFeedback;
  var questions = Array.isArray(window.MATCHING_DATA) ? window.MATCHING_DATA : [];

  var progressLabelEl = document.getElementById('ml-progress-label');
  var progressFillEl  = document.getElementById('ml-progress-fill');
  var progressBadgeEl = document.getElementById('ml-progress-badge');
  var promptEl        = document.getElementById('ml-prompt');
  var leftColEl       = document.getElementById('ml-left');
  var rightColEl      = document.getElementById('ml-right');
  var svgEl           = document.getElementById('ml-lines');
  var checkBtn        = document.getElementById('ml-check');
  var showBtn         = document.getElementById('ml-show');
  var nextBtn         = document.getElementById('ml-next');
  var feedbackEl      = document.getElementById('ml-feedback');
  var activityEl      = document.getElementById('ml-activity');
  var completedEl     = document.getElementById('ml-completed');
  var winAudio        = new Audio('../../hangman/assets/win.mp3');

  var activityTitle = window.MATCHING_TITLE || 'Matching';
  var returnTo      = window.MATCHING_RETURN_TO || '';
  var activityId    = window.MATCHING_ACTIVITY_ID || '';

  if (!questions.length) {
    if (promptEl) promptEl.textContent = 'No questions available.';
    return;
  }

  var index       = 0;
  var answered    = false;
  var selectedLeft  = null;  /* element */
  var selectedRight = null;
  var connections   = [];    /* [{leftId, rightId}] */
  var scores        = questions.map(function () { return 0; });
  var reviewItems   = questions.map(function () { return {}; });

  /* Color pool for connection lines */
  var COLORS = ['#7F77DD','#F97316','#22c55e','#f43f5e','#0ea5e9','#a855f7','#14b8a6','#fb923c'];

  function updateProgress() {
    var total   = questions.length;
    var current = index + 1;
    var pct     = Math.round((current / total) * 100);
    if (progressLabelEl) progressLabelEl.textContent = current + ' / ' + total;
    if (progressBadgeEl) progressBadgeEl.textContent = 'Q ' + current + ' of ' + total;
    if (progressFillEl)  progressFillEl.style.width  = pct + '%';
  }

  function shuffle(arr) {
    var a = arr.slice();
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = a[i]; a[i] = a[j]; a[j] = t;
    }
    return a;
  }

  function loadQuestion() {
    var q = questions[index] || {};
    answered    = false;
    selectedLeft  = null;
    selectedRight = null;
    connections   = [];

    if (completedEl) completedEl.style.display = 'none';
    if (activityEl)  activityEl.style.display  = '';
    if (feedbackEl)  AF.clearFeedback(feedbackEl);

    updateProgress();
    if (promptEl) promptEl.textContent = q.prompt || 'Match each item on the left with the right.';

    renderColumns(q);
    drawLines();

    if (checkBtn) checkBtn.disabled = false;
    if (showBtn)  { showBtn.style.display = ''; showBtn.disabled = false; }
    if (nextBtn)  { nextBtn.disabled = true; nextBtn.textContent = index < questions.length - 1 ? 'Next \u2192' : 'Finish'; }
  }

  function renderColumns(q) {
    var pairs = Array.isArray(q.pairs) ? q.pairs : [];
    var leftItems  = pairs.map(function (p, i) { return { id: 'l' + i, text: p.left,  pairId: i }; });
    var rightItems = shuffle(pairs.map(function (p, i) { return { id: 'r' + i, text: p.right, pairId: i }; }));

    if (leftColEl) {
      leftColEl.innerHTML = '';
      leftItems.forEach(function (it) {
        var btn = document.createElement('button');
        btn.type      = 'button';
        btn.className = 'ml-item ml-item--left';
        btn.id        = 'ml-' + it.id;
        btn.dataset.id     = it.id;
        btn.dataset.pairId = String(it.pairId);
        btn.textContent    = it.text;
        btn.addEventListener('click', function () { handleLeftClick(btn); });
        leftColEl.appendChild(btn);
      });
    }

    if (rightColEl) {
      rightColEl.innerHTML = '';
      rightItems.forEach(function (it) {
        var btn = document.createElement('button');
        btn.type      = 'button';
        btn.className = 'ml-item ml-item--right';
        btn.id        = 'ml-' + it.id;
        btn.dataset.id     = it.id;
        btn.dataset.pairId = String(it.pairId);
        btn.textContent    = it.text;
        btn.addEventListener('click', function () { handleRightClick(btn); });
        rightColEl.appendChild(btn);
      });
    }
  }

  function handleLeftClick(btn) {
    if (answered) return;
    document.querySelectorAll('.ml-item--left').forEach(function (b) { b.classList.remove('ml-item--selected'); });
    selectedLeft = btn;
    btn.classList.add('ml-item--selected');
    tryConnect();
  }

  function handleRightClick(btn) {
    if (answered) return;
    document.querySelectorAll('.ml-item--right').forEach(function (b) { b.classList.remove('ml-item--selected'); });
    selectedRight = btn;
    btn.classList.add('ml-item--selected');
    tryConnect();
  }

  function tryConnect() {
    if (!selectedLeft || !selectedRight) return;
    var leftId  = selectedLeft.dataset.id;
    var rightId = selectedRight.dataset.id;

    /* remove any existing connection from this left item */
    connections = connections.filter(function (c) { return c.leftId !== leftId && c.rightId !== rightId; });
    connections.push({ leftId: leftId, rightId: rightId, color: COLORS[connections.length % COLORS.length] });

    selectedLeft.classList.remove('ml-item--selected');
    selectedRight.classList.remove('ml-item--selected');
    selectedLeft  = null;
    selectedRight = null;
    drawLines();
  }

  function drawLines() {
    if (!svgEl) return;
    svgEl.innerHTML = '';
    var containerRect = svgEl.getBoundingClientRect();

    connections.forEach(function (conn) {
      var leftEl  = document.getElementById('ml-' + conn.leftId);
      var rightEl = document.getElementById('ml-' + conn.rightId);
      if (!leftEl || !rightEl) return;

      var lr = leftEl.getBoundingClientRect();
      var rr = rightEl.getBoundingClientRect();

      var x1 = lr.right  - containerRect.left;
      var y1 = lr.top    - containerRect.top + lr.height / 2;
      var x2 = rr.left   - containerRect.left;
      var y2 = rr.top    - containerRect.top + rr.height / 2;

      var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
      line.setAttribute('x1', String(x1));
      line.setAttribute('y1', String(y1));
      line.setAttribute('x2', String(x2));
      line.setAttribute('y2', String(y2));
      line.setAttribute('stroke', conn.color || '#7F77DD');
      line.setAttribute('stroke-width', '3');
      line.setAttribute('stroke-linecap', 'round');
      svgEl.appendChild(line);
    });
  }

  function checkAnswers() {
    if (answered) return;
    var q     = questions[index] || {};
    var pairs = Array.isArray(q.pairs) ? q.pairs : [];
    var correct = 0;
    answered   = true;

    connections.forEach(function (conn) {
      var leftEl  = document.getElementById('ml-' + conn.leftId);
      var rightEl = document.getElementById('ml-' + conn.rightId);
      if (!leftEl || !rightEl) return;
      var isRight = leftEl.dataset.pairId === rightEl.dataset.pairId;
      if (isRight) {
        correct++;
        AF.highlightOption(leftEl,  'correct');
        AF.highlightOption(rightEl, 'correct');
      } else {
        AF.highlightOption(leftEl,  'wrong');
        AF.highlightOption(rightEl, 'wrong');
      }
    });

    var allRight = correct === pairs.length && connections.length === pairs.length;
    scores[index] = allRight ? 1 : 0;
    reviewItems[index] = {
      question:      q.prompt || ('Question ' + (index + 1)),
      yourAnswer:    correct + '/' + pairs.length + ' correct',
      correctAnswer: pairs.map(function (p) { return p.left + ' \u2192 ' + p.right; }).join(', '),
      score:         scores[index]
    };

    if (feedbackEl) AF.showFeedback(feedbackEl, allRight, pairs.map(function (p) { return p.left + '\u2192' + p.right; }).join(' | '), false);
    if (checkBtn) checkBtn.disabled = true;
    if (showBtn)  showBtn.style.display = 'none';
    if (nextBtn)  nextBtn.disabled = false;
  }

  function showAnswers() {
    if (answered) return;
    var q     = questions[index] || {};
    var pairs = Array.isArray(q.pairs) ? q.pairs : [];
    answered  = true;
    scores[index] = -1;

    /* show correct connections */
    connections = pairs.map(function (p, i) { return { leftId: 'l' + i, rightId: 'r' + i, color: COLORS[i % COLORS.length] }; });
    drawLines();
    pairs.forEach(function (p, i) {
      var leftEl  = document.getElementById('ml-l' + i);
      var rightEl = document.getElementById('ml-r' + i);
      if (leftEl)  leftEl.style.borderColor  = COLORS[i % COLORS.length];
      if (rightEl) rightEl.style.borderColor = COLORS[i % COLORS.length];
    });

    reviewItems[index] = { question: q.prompt || '', yourAnswer: '(revealed)', correctAnswer: '', score: -1 };
    if (feedbackEl) AF.showFeedback(feedbackEl, false, null, true);
    if (checkBtn) checkBtn.disabled = true;
    if (showBtn)  showBtn.style.display = 'none';
    if (nextBtn)  nextBtn.disabled = false;
  }

  function nextQuestion() {
    if (index < questions.length - 1) {
      index++;
      loadQuestion();
    } else {
      showCompleted();
    }
  }

  function showCompleted() {
    if (!completedEl) return;
    if (activityEl) activityEl.style.display = 'none';
    completedEl.style.display = '';

    AF.showCompleted({
      target:        completedEl,
      scores:        scores,
      title:         activityTitle,
      activityType:  'Matching',
      questionCount: questions.length,
      winAudio:      winAudio,
      onRetry:       restartActivity,
      onReview:      function () { AF.showReview({ target: completedEl, items: reviewItems, onRetry: restartActivity }); }
    });

    var result = AF.computeScore(scores);
    if (returnTo && activityId) {
      var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
      fetch(returnTo + sep + 'activity_percent=' + result.percent + '&activity_errors=' + result.wrong + '&activity_total=' + result.total + '&activity_id=' + encodeURIComponent(activityId) + '&activity_type=matching_lines',
        { method: 'GET', credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    }
  }

  function restartActivity() {
    index       = 0;
    scores      = questions.map(function () { return 0; });
    reviewItems = questions.map(function () { return {}; });
    loadQuestion();
  }

  /* redraw lines on window resize */
  window.addEventListener('resize', drawLines);

  if (checkBtn) checkBtn.addEventListener('click', checkAnswers);
  if (showBtn)  showBtn.addEventListener('click', showAnswers);
  if (nextBtn)  nextBtn.addEventListener('click', nextQuestion);

  loadQuestion();
});
