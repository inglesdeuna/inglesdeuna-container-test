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

  function renderSentence(q, isAnswered) {
    if (!sentenceEl) return;
    var text = q.text || '';
    var answers = q.answers || [];
    var blankCount = 0;
    
    var html = text.replace(/___+/g, function () {
      var idx = blankCount++;
      var correct = answers[idx] || '';
      
      if (!isAnswered) {
        return '<input type="text" class="fb-input" data-blank-idx="' + idx + '" placeholder="Type..." autocomplete="off" spellcheck="false">';
      } else {
        var inputEl = sentenceEl.querySelector('[data-blank-idx="' + idx + '"]');
        var filled = inputEl ? inputEl.value.trim() : '';
        var isRight = normalize(filled) === normalize(correct);
        
        if (isRight) {
          return '<span class="fb-answer-correct">' + escHtml(filled) + '</span>';
        } else {
          return '<span class="fb-answer-wrong">' + escHtml(filled || '\u2014') + '</span>'
                + ' <span class="fb-answer-hint">' + escHtml(correct) + '</span>';
        }
      }
    });
    
    sentenceEl.innerHTML = html;
    
    if (!isAnswered) {
      attachInputListeners();
    }
  }

  function escHtml(s) {
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  function renderWordBank(options) {
    if (!wordBankWordsEl) return;
    wordBankWordsEl.innerHTML = '';
    
    options.forEach(function (option) {
      var chip = document.createElement('div');
      chip.className = 'fb-chip';
      chip.textContent = option;
      chip.style.cursor = 'default';
      chip.style.opacity = '1';
      chip.style.pointerEvents = 'none';
      wordBankWordsEl.appendChild(chip);
    });
  }

  function attachInputListeners() {
    var inputs = sentenceEl.querySelectorAll('.fb-input');
    inputs.forEach(function (input, idx) {
      input.addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
          checkAnswer();
        }
      });
      
      if (idx === 0) {
        setTimeout(function () { input.focus(); }, 0);
      }
    });
  }

  function loadQuestion() {
    var q = questions[index] || {};
    var options = (q.options && q.options.length > 0) ? q.options : [];
    
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
    renderSentence(q, false);
    renderWordBank(options);
  }

  function getAnswers() {
    var inputs = sentenceEl.querySelectorAll('.fb-input');
    var answers = [];
    inputs.forEach(function (input) {
      answers.push(input.value.trim());
    });
    return answers;
  }

  function checkAnswer() {
    if (answered) return;
    var q       = questions[index] || {};
    var correct = q.answers || [];
    var user    = getAnswers();
    var isRight = true;
    
    for (var i = 0; i < correct.length; i++) {
      if (normalize(user[i] || '') !== normalize(correct[i])) {
        isRight = false;
        break;
      }
    }

    answered    = true;
    scores[index] = isRight ? 1 : 0;
    reviewItems[index] = { 
      question: q.text || '', 
      yourAnswer: user.join(', '), 
      correctAnswer: correct.join(', '), 
      score: scores[index] 
    };

    renderSentence(q, true);
    if (feedbackEl) AF.showFeedback(feedbackEl, isRight, null, false);
    if (checkBtn)   checkBtn.disabled = true;
    if (showBtn)    showBtn.style.display = 'none';
    if (nextBtn)    nextBtn.disabled = false;
  }

  function showAnswer() {
    if (answered) return;
    var q = questions[index] || {};
    var correct = q.answers || [];
    answered = true;
    revealed = true;
    scores[index] = -1;
    reviewItems[index] = { 
      question: q.text || '', 
      yourAnswer: '(revealed)', 
      correctAnswer: correct.join(', '), 
      score: -1 
    };

    var inputs = sentenceEl.querySelectorAll('.fb-input');
    inputs.forEach(function (input, idx) {
      input.value = correct[idx] || '';
      input.disabled = true;
    });
    
    renderSentence(q, true);
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
    loadQuestion();
  }

  /* inject blank highlight CSS */
  if (!document.getElementById('fb-blank-css')) {
    var st = document.createElement('style');
    st.id = 'fb-blank-css';
    st.textContent =
      '.fb-answer-correct{display:inline-block;padding:2px 8px;border-radius:6px;font-weight:700;background:#f0fdf4;color:#166534;font-size:16px;vertical-align:bottom;margin:0 6px}' +
      '.fb-answer-wrong{display:inline-block;padding:2px 8px;border-radius:6px;font-weight:700;background:#fef2f2;color:#991b1b;text-decoration:line-through;font-size:16px;vertical-align:bottom;margin:0 6px}' +
      '.fb-answer-hint{display:inline-block;background:#EDE9FA;color:#7F77DD;border-radius:6px;padding:2px 8px;font-weight:800;font-size:14px;vertical-align:bottom;margin:0 6px}';
    document.head.appendChild(st);
  }

  if (checkBtn) checkBtn.addEventListener('click', checkAnswer);
  if (showBtn)  showBtn.addEventListener('click', showAnswer);
  if (nextBtn)  nextBtn.addEventListener('click', nextQuestion);

  loadQuestion();
});
