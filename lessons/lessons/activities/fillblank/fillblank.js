document.addEventListener('DOMContentLoaded', function () {
  var AF = window.ActivityFeedback;
  var questions = Array.isArray(window.FILLBLANK_DATA) ? window.FILLBLANK_DATA : [];

  var progressLabelEl = document.getElementById('fb-progress-label');
  var progressFillEl  = document.getElementById('fb-progress-fill');
  var progressBadgeEl = document.getElementById('fb-progress-badge');
  var sentenceEl      = document.getElementById('fb-sentence');
  var wordBankWordsEl = document.getElementById('fb-wb-words');
  var checkBtn        = document.getElementById('fb-check');
  var showBtn         = document.getElementById('fb-show');
  var nextBtn         = document.getElementById('fb-next');
  var feedbackEl      = document.getElementById('fb-feedback');
  var activityEl      = document.getElementById('fb-activity');
  var completedEl     = document.getElementById('fb-completed');
  var winAudio        = new Audio('../../hangman/assets/win.mp3');

  var activityTitle = window.FILLBLANK_TITLE || 'Fill in the Blank';
  var returnTo      = window.FILLBLANK_RETURN_TO || '';
  var activityId    = window.FILLBLANK_ACTIVITY_ID || '';

  if (!questions.length) {
    if (sentenceEl) sentenceEl.textContent = 'No questions available.';
    return;
  }

  var index       = 0;
  var answered    = false;
  var revealed    = false;
  var scores      = questions.map(function () { return 0; });
  var reviewItems = questions.map(function () { return {}; });
  var userAnswers = questions.map(function () { return ''; });
  var usedChips   = questions.map(function () { return {}; });

  function normalize(s) {
    return String(s || '').trim().toLowerCase().replace(/[^a-z0-9\s]/g, '').replace(/\s+/g, ' ');
  }

  function updateProgress() {
    var total   = questions.length;
    var current = index + 1;
    var pct     = Math.round((current / total) * 100);
    if (progressLabelEl) progressLabelEl.textContent = current + ' / ' + total;
    if (progressBadgeEl) progressBadgeEl.textContent = 'Q ' + current + ' of ' + total;
    if (progressFillEl)  progressFillEl.style.width  = pct + '%';
  }

  function renderSentence(q, userAnswer, isAnswered) {
    if (!sentenceEl) return;
    var before = q.before || '';
    var after  = q.after  || '';
    var correct = q.answer || '';

    if (!isAnswered) {
      if (userAnswer) {
        sentenceEl.innerHTML = escHtml(before) + ' <span class="fb-blank-filled"><span class="fb-blank-text">' + escHtml(userAnswer) + '</span><span class="fb-blank-remove">✕</span></span> ' + escHtml(after);
      } else {
        sentenceEl.innerHTML = escHtml(before) + ' <span class="fb-blank"></span> ' + escHtml(after);
      }
      return;
    }

    var isRight = normalize(userAnswer) === normalize(correct);
    var blankHtml = '';
    if (isRight) {
      blankHtml = '<span class="fb-blank-filled" style="background: #22c55e; cursor: default;"><span class="fb-blank-text">' + escHtml(userAnswer) + '</span></span>';
    } else {
      blankHtml = '<span class="fb-blank-filled" style="background: #ef4444; cursor: default;"><span class="fb-blank-text" style="text-decoration: line-through;">' + escHtml(userAnswer || '\u2014') + '</span></span>'
                + ' <span style="background: #EDE9FA; color: #7F77DD; border-radius: 8px; padding: 2px 8px; font-weight: 800; display: inline-flex; align-items: center; vertical-align: bottom; margin: 0 6px;">' + escHtml(correct) + '</span>';
    }
    sentenceEl.innerHTML = escHtml(before) + ' ' + blankHtml + ' ' + escHtml(after);
  }

  function escHtml(s) {
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  function renderWordBank(answers) {
    if (!wordBankWordsEl) return;
    wordBankWordsEl.innerHTML = '';
    
    answers.forEach(function (answer, idx) {
      var chip = document.createElement('div');
      chip.className = 'fb-chip';
      chip.textContent = answer;
      chip.dataset.index = idx;
      chip.dataset.answer = answer;
      
      if (usedChips[index] && usedChips[index][idx]) {
        chip.classList.add('used');
      }
      
      chip.addEventListener('click', function () {
        if (answered || revealed || chip.classList.contains('used')) return;
        selectWordFromBank(answer, idx);
      });
      
      wordBankWordsEl.appendChild(chip);
    });
  }

  function selectWordFromBank(answer, chipIndex) {
    userAnswers[index] = answer;
    if (!usedChips[index]) usedChips[index] = {};
    usedChips[index][chipIndex] = true;
    
    renderSentence(questions[index], answer, false);
    attachRemoveListener();
    updateWordBankUI();
  }

  function attachRemoveListener() {
    var removeBtn = sentenceEl.querySelector('.fb-blank-remove');
    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        clearAnswer();
      });
    }
  }

  function clearAnswer() {
    if (answered || revealed) return;
    var chipIndex = Object.keys(usedChips[index] || {}).find(function (k) { return usedChips[index][k]; });
    if (chipIndex !== undefined) {
      delete usedChips[index][chipIndex];
    }
    userAnswers[index] = '';
    renderSentence(questions[index], '', false);
    updateWordBankUI();
  }

  function updateWordBankUI() {
    var chips = wordBankWordsEl ? wordBankWordsEl.querySelectorAll('.fb-chip') : [];
    chips.forEach(function (chip) {
      var idx = parseInt(chip.dataset.index, 10);
      if (usedChips[index] && usedChips[index][idx]) {
        chip.classList.add('used');
      } else {
        chip.classList.remove('used');
      }
    });
  }

  function loadQuestion() {
    var q = questions[index] || {};
    var answers = q.answer ? [q.answer] : [];
    
    answered = false;
    revealed = false;

    if (completedEl) completedEl.style.display = 'none';
    if (activityEl)  activityEl.style.display  = '';
    if (feedbackEl)  AF.clearFeedback(feedbackEl);

    if (checkBtn) checkBtn.disabled = false;
    if (showBtn)  { showBtn.style.display = ''; showBtn.disabled = false; }
    if (nextBtn)  {
      nextBtn.disabled    = true;
      nextBtn.textContent = index < questions.length - 1 ? 'Next \u2192' : 'Finish';
    }

    updateProgress();
    renderSentence(q, userAnswers[index] || '', false);
    renderWordBank(answers);
    attachRemoveListener();
  }

  function checkAnswer() {
    if (answered) return;
    var q       = questions[index] || {};
    var correct = q.answer || '';
    var user    = userAnswers[index] || '';
    var isRight = normalize(user) === normalize(correct);

    answered    = true;
    scores[index] = isRight ? 1 : 0;
    reviewItems[index] = { question: (q.before || '') + ' ___ ' + (q.after || ''), yourAnswer: user, correctAnswer: correct, score: scores[index] };

    renderSentence(q, user, true);
    if (feedbackEl) AF.showFeedback(feedbackEl, isRight, correct, false);
    if (checkBtn)   checkBtn.disabled = true;
    if (showBtn)    showBtn.style.display = 'none';
    if (nextBtn)    nextBtn.disabled = false;
  }

  function showAnswer() {
    if (answered) return;
    var q = questions[index] || {};
    var correct = q.answer || '';
    answered = true;
    revealed = true;
    scores[index] = -1;
    reviewItems[index] = { question: (q.before || '') + ' ___ ' + (q.after || ''), yourAnswer: '(revealed)', correctAnswer: correct, score: -1 };

    renderSentence(q, correct, true);
    if (feedbackEl) AF.showFeedback(feedbackEl, false, null, true);
    if (checkBtn)   checkBtn.disabled = true;
    if (showBtn)    showBtn.style.display = 'none';
    if (nextBtn)    nextBtn.disabled = false;
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
      activityType:  'Fill in the Blank',
      questionCount: questions.length,
      winAudio:      winAudio,
      onRetry:       restartActivity,
      onReview:      function () {
        AF.showReview({ target: completedEl, items: reviewItems, onRetry: restartActivity });
      }
    });

    var result = AF.computeScore(scores);
    if (returnTo && activityId) {
      var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
      fetch(returnTo + sep + 'activity_percent=' + result.percent + '&activity_errors=' + result.wrong + '&activity_total=' + result.total + '&activity_id=' + encodeURIComponent(activityId) + '&activity_type=fillblank',
        { method: 'GET', credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    }
  }

  function restartActivity() {
    index       = 0;
    scores      = questions.map(function () { return 0; });
    reviewItems = questions.map(function () { return {}; });
    userAnswers = questions.map(function () { return ''; });
    usedChips   = questions.map(function () { return {}; });
    loadQuestion();
  }

  /* inject blank highlight CSS */
  if (!document.getElementById('fb-blank-css')) {
    var st = document.createElement('style');
    st.id = 'fb-blank-css';
    st.textContent =
      '.fb-blank{display:inline-block;padding:2px 8px;border-radius:6px;font-weight:800}' +
      '.fb-blank--correct{background:#f0fdf4;border-bottom:2px solid #22c55e;color:#166534}' +
      '.fb-blank--wrong{background:#fef2f2;border-bottom:2px solid #ef4444;color:#991b1b;text-decoration:line-through;margin-right:4px}' +
      '.fb-blank--hint{background:#EDE9FA;color:#7F77DD;border-radius:6px;padding:2px 8px;font-weight:800}';
    document.head.appendChild(st);
  }

  if (checkBtn) checkBtn.addEventListener('click', checkAnswer);
  if (showBtn)  showBtn.addEventListener('click', showAnswer);
  if (nextBtn)  nextBtn.addEventListener('click', nextQuestion);

  loadQuestion();
});
