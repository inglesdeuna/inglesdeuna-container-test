document.addEventListener('DOMContentLoaded', function () {
  var AF = window.ActivityFeedback;
  var questions = Array.isArray(window.UNSCRAMBLE_DATA) ? window.UNSCRAMBLE_DATA : [];

  var progressLabelEl = document.getElementById('us-progress-label');
  var progressFillEl  = document.getElementById('us-progress-fill');
  var progressBadgeEl = document.getElementById('us-progress-badge');
  var promptEl        = document.getElementById('us-prompt');
  var wordsAreaEl     = document.getElementById('us-words');
  var answerAreaEl    = document.getElementById('us-answer');
  var checkBtn        = document.getElementById('us-check');
  var showBtn         = document.getElementById('us-show');
  var nextBtn         = document.getElementById('us-next');
  var feedbackEl      = document.getElementById('us-feedback');
  var activityEl      = document.getElementById('us-activity');
  var completedEl     = document.getElementById('us-completed');
  var winAudio        = new Audio('../../hangman/assets/win.mp3');

  var activityTitle = window.UNSCRAMBLE_TITLE || 'Unscramble';
  var returnTo      = window.UNSCRAMBLE_RETURN_TO || '';
  var activityId    = window.UNSCRAMBLE_ACTIVITY_ID || '';

  if (!questions.length) {
    if (promptEl) promptEl.textContent = 'No questions available.';
    return;
  }

  var index        = 0;
  var answered     = false;
  var answerTokens = [];   /* ordered words the user has placed */
  var scores       = questions.map(function () { return 0; });
  var reviewItems  = questions.map(function () { return {}; });

  function shuffle(arr) {
    var a = arr.slice();
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = a[i]; a[i] = a[j]; a[j] = t;
    }
    return a;
  }

  function updateProgress() {
    var total   = questions.length;
    var current = index + 1;
    var pct     = Math.round((current / total) * 100);
    if (progressLabelEl) progressLabelEl.textContent = current + ' / ' + total;
    if (progressBadgeEl) progressBadgeEl.textContent = 'Q ' + current + ' of ' + total;
    if (progressFillEl)  progressFillEl.style.width  = pct + '%';
  }

  function loadQuestion() {
    var q = questions[index] || {};
    answered     = false;
    answerTokens = [];

    if (completedEl) completedEl.style.display = 'none';
    if (activityEl)  activityEl.style.display  = '';
    if (feedbackEl)  AF.clearFeedback(feedbackEl);

    updateProgress();
    if (promptEl) promptEl.textContent = q.prompt || 'Arrange the words:';

    renderWordBank(q);
    renderAnswerArea();

    if (checkBtn) checkBtn.disabled = false;
    if (showBtn)  { showBtn.style.display = ''; showBtn.disabled = false; }
    if (nextBtn)  { nextBtn.disabled = true; nextBtn.textContent = index < questions.length - 1 ? 'Next \u2192' : 'Finish'; }
  }

  function renderWordBank(q) {
    if (!wordsAreaEl) return;
    wordsAreaEl.innerHTML = '';
    var words = shuffle(Array.isArray(q.words) ? q.words : []);
    words.forEach(function (w) { addWordToBank(w); });
  }

  function addWordToBank(word) {
    if (!wordsAreaEl) return;
    var chip = document.createElement('button');
    chip.type      = 'button';
    chip.className = 'us-word';
    chip.textContent = word;
    chip.addEventListener('click', function () {
      if (answered) return;
      answerTokens.push(word);
      chip.remove();
      renderAnswerArea();
    });
    wordsAreaEl.appendChild(chip);
  }

  function renderAnswerArea() {
    if (!answerAreaEl) return;
    answerAreaEl.innerHTML = '';
    answerTokens.forEach(function (w, i) {
      var chip = document.createElement('button');
      chip.type      = 'button';
      chip.className = 'us-answer-word';
      chip.textContent = w;
      chip.addEventListener('click', function () {
        if (answered) return;
        answerTokens.splice(i, 1);
        addWordToBank(w);
        renderAnswerArea();
      });
      answerAreaEl.appendChild(chip);
    });
  }

  function checkAnswer() {
    if (answered) return;
    var q       = questions[index] || {};
    var correct = Array.isArray(q.correct) ? q.correct : (q.answer ? q.answer.split(' ') : []);
    var given   = answerTokens.join(' ').trim();
    var expected = correct.join(' ').trim();
    var isRight  = given.toLowerCase() === expected.toLowerCase();

    answered       = true;
    scores[index]  = isRight ? 1 : 0;
    reviewItems[index] = { question: q.prompt || '', yourAnswer: given, correctAnswer: expected, score: scores[index] };

    /* highlight answer area */
    answerAreaEl && answerAreaEl.querySelectorAll('.us-answer-word').forEach(function (c) {
      c.classList.add(isRight ? 'us-answer-word--correct' : 'us-answer-word--wrong');
      c.disabled = true;
    });

    if (feedbackEl) AF.showFeedback(feedbackEl, isRight, expected, false);
    if (checkBtn) checkBtn.disabled = true;
    if (showBtn)  showBtn.style.display = 'none';
    if (nextBtn)  nextBtn.disabled = false;
  }

  function showAnswer() {
    if (answered) return;
    var q       = questions[index] || {};
    var correct = Array.isArray(q.correct) ? q.correct : (q.answer ? q.answer.split(' ') : []);
    var expected = correct.join(' ');

    answered       = true;
    scores[index]  = -1;
    reviewItems[index] = { question: q.prompt || '', yourAnswer: '(revealed)', correctAnswer: expected, score: -1 };

    /* show correct answer in answer area */
    if (answerAreaEl) {
      answerAreaEl.innerHTML = '';
      correct.forEach(function (w) {
        var chip = document.createElement('span');
        chip.className   = 'us-answer-word us-answer-word--revealed';
        chip.textContent = w;
        answerAreaEl.appendChild(chip);
      });
    }
    if (wordsAreaEl) wordsAreaEl.innerHTML = '';

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
      activityType:  'Unscramble',
      questionCount: questions.length,
      winAudio:      winAudio,
      onRetry:       restartActivity,
      onReview:      function () { AF.showReview({ target: completedEl, items: reviewItems, onRetry: restartActivity }); }
    });

    var result = AF.computeScore(scores);
    if (returnTo && activityId) {
      var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
      fetch(returnTo + sep + 'activity_percent=' + result.percent + '&activity_errors=' + result.wrong + '&activity_total=' + result.total + '&activity_id=' + encodeURIComponent(activityId) + '&activity_type=unscramble',
        { method: 'GET', credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    }
  }

  function restartActivity() {
    index       = 0;
    scores      = questions.map(function () { return 0; });
    reviewItems = questions.map(function () { return {}; });
    loadQuestion();
  }

  /* inject word-chip feedback CSS */
  if (!document.getElementById('us-feedback-css')) {
    var st = document.createElement('style');
    st.id = 'us-feedback-css';
    st.textContent =
      '.us-answer-word--correct{background:#f0fdf4!important;border-color:#22c55e!important;color:#166534!important;box-shadow:0 3px 0 #16a34a!important;cursor:default!important}' +
      '.us-answer-word--wrong{background:#fef2f2!important;border-color:#ef4444!important;color:#991b1b!important;box-shadow:0 3px 0 #dc2626!important;cursor:default!important}' +
      '.us-answer-word--revealed{background:#EDE9FA!important;border-color:#7F77DD!important;color:#534AB7!important;box-shadow:0 3px 0 #534AB7!important;border-radius:10px!important;padding:8px 16px!important;font-weight:900!important;font-family:"Nunito",sans-serif!important}';
    document.head.appendChild(st);
  }

  if (checkBtn) checkBtn.addEventListener('click', checkAnswer);
  if (showBtn)  showBtn.addEventListener('click', showAnswer);
  if (nextBtn)  nextBtn.addEventListener('click', nextQuestion);

  loadQuestion();
});
