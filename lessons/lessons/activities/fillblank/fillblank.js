document.addEventListener('DOMContentLoaded', function () {
  var AF = window.ActivityFeedback;
  var questions = Array.isArray(window.FILLBLANK_DATA) ? window.FILLBLANK_DATA : [];

  var progressLabelEl = document.getElementById('fb-progress-label');
  var progressFillEl  = document.getElementById('fb-progress-fill');
  var progressBadgeEl = document.getElementById('fb-progress-badge');
  var sentenceEl      = document.getElementById('fb-sentence');
  var imageEl         = document.getElementById('fb-image');
  var wordBankWrapEl  = document.getElementById('fb-wordbank-wrap');
  var wordBankEl      = document.getElementById('fb-wordbank');
  var checkBtn        = document.getElementById('fb-check');
  var showBtn         = document.getElementById('fb-show');
  var nextBtn         = document.getElementById('fb-next');
  var feedbackEl      = document.getElementById('fb-feedback');
  var activityEl      = document.getElementById('fb-activity');
  var completedEl     = document.getElementById('fb-completed');
  var winAudio        = new Audio('../../hangman/assets/win.mp3');

  var activityTitle = window.FILLBLANK_TITLE    || 'Fill in the Blank';
  var returnTo      = window.FILLBLANK_RETURN_TO   || '';
  var activityId    = window.FILLBLANK_ACTIVITY_ID || '';

  if (!questions.length) {
    if (sentenceEl) sentenceEl.textContent = 'No questions available.';
    return;
  }

  /* Blank marker: 3 or more underscores */
  var BLANK_RE = /_{3,}/g;

  var index       = 0;
  var answered    = false;
  var revealed    = false;
  var scores      = questions.map(function () { return 0; });
  var reviewItems = questions.map(function () { return {}; });

  /* userAnswers[questionIdx] = string[] — one entry per blank */
  var userAnswers = questions.map(function (q) {
    var count = String(q.text || '').split(BLANK_RE).length - 1;
    return new Array(Math.max(0, count)).fill('');
  });

  /* ── helpers ── */
  function normalize(s) {
    return String(s || '').trim().toLowerCase().replace(/[^a-z0-9\s]/g, '').replace(/\s+/g, ' ');
  }

  function escHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function segments(text) {
    return String(text || '').split(BLANK_RE);
  }

  /* ── render image ── */
  function renderImage(q) {
    if (!imageEl) return;
    var url = q.image || '';
    if (url) {
      imageEl.innerHTML = '<img src="' + escHtml(url) + '" alt="" class="fb-image-img">';
      imageEl.style.display = '';
    } else {
      imageEl.innerHTML = '';
      imageEl.style.display = 'none';
    }
  }

  /* ── render sentence with blanks ── */
  function renderSentence(q, userAns, isAnswered) {
    if (!sentenceEl) return;
    var segs    = segments(q.text || '');
    var answers = q.answers || [];
    var hasOptions = (q.options || []).length > 0;
    var html    = '';

    segs.forEach(function (seg, i) {
      html += escHtml(seg);
      if (i >= segs.length - 1) return; /* no blank after last segment */

      var ua      = (userAns || [])[i] || '';
      var correct = answers[i] || '';

      if (isAnswered) {
        var ok = normalize(ua) === normalize(correct);
        if (ok) {
          html += '<span class="fb-answer-correct">' + escHtml(ua) + '</span>';
        } else {
          html += '<span class="fb-answer-wrong">' + escHtml(ua || '—') + '</span>';
          if (correct) html += '<span class="fb-answer-hint">' + escHtml(correct) + '</span>';
        }
      } else if (ua) {
        /* filled blank — click to clear */
        html += '<span class="fb-blank-chip" data-blank-idx="' + i + '">'
              + escHtml(ua)
              + '<span class="fb-blank-remove"> ✕</span>'
              + '</span>';
      } else if (hasOptions) {
        /* chip-based blank (empty) */
        html += '<span class="fb-blank" data-blank-idx="' + i + '"></span>';
      } else {
        /* text-input blank */
        html += '<input class="fb-input" type="text" data-blank-idx="' + i + '" '
              + 'value="" placeholder="..." autocomplete="off" spellcheck="false">';
      }
    });

    sentenceEl.innerHTML = html;
    attachSentenceListeners();
  }

  function attachSentenceListeners() {
    if (!sentenceEl) return;

    /* Remove-chip listeners */
    sentenceEl.querySelectorAll('.fb-blank-chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        if (answered || revealed) return;
        var idx = parseInt(chip.getAttribute('data-blank-idx') || '0', 10);
        userAnswers[index][idx] = '';
        renderSentence(questions[index], userAnswers[index], false);
        updateWordBank();
      });
    });

    /* Text-input listeners */
    sentenceEl.querySelectorAll('.fb-input').forEach(function (input) {
      input.addEventListener('input', function () {
        var idx = parseInt(input.getAttribute('data-blank-idx') || '0', 10);
        userAnswers[index][idx] = input.value;
      });
    });
  }

  /* ── word bank ── */
  function renderWordBank(q) {
    if (!wordBankWrapEl || !wordBankEl) return;
    var options = q.options || [];

    if (!options.length) {
      wordBankWrapEl.style.display = 'none';
      return;
    }

    wordBankWrapEl.style.display = '';
    wordBankEl.innerHTML = '';

    var used = userAnswers[index] || [];

    options.forEach(function (opt) {
      var chip = document.createElement('span');
      chip.className = 'fb-chip';
      chip.textContent = opt;
      if (used.indexOf(opt) !== -1) chip.classList.add('used');

      chip.addEventListener('click', function () {
        if (answered || revealed || chip.classList.contains('used')) return;
        fillNextBlank(opt);
      });

      wordBankEl.appendChild(chip);
    });
  }

  function fillNextBlank(word) {
    var q    = questions[index] || {};
    var segs = segments(q.text || '');
    var ans  = userAnswers[index];

    for (var i = 0; i < segs.length - 1; i++) {
      if (!ans[i]) {
        ans[i] = word;
        renderSentence(q, ans, false);
        updateWordBank();
        return;
      }
    }
  }

  function updateWordBank() {
    if (!wordBankEl) return;
    var used = userAnswers[index] || [];
    wordBankEl.querySelectorAll('.fb-chip').forEach(function (chip) {
      chip.classList.toggle('used', used.indexOf(chip.textContent) !== -1);
    });
  }

  /* ── progress ── */
  function updateProgress() {
    var total   = questions.length;
    var current = index + 1;
    var pct     = Math.round((current / total) * 100);
    if (progressLabelEl) progressLabelEl.textContent = current + ' / ' + total;
    if (progressBadgeEl) progressBadgeEl.textContent = 'Q ' + current + ' of ' + total;
    if (progressFillEl)  progressFillEl.style.width  = pct + '%';
  }

  /* ── load question ── */
  function loadQuestion() {
    var q = questions[index] || {};
    answered = false;
    revealed = false;

    if (completedEl) completedEl.style.display = 'none';
    if (activityEl)  activityEl.style.display  = '';
    if (feedbackEl)  AF.clearFeedback(feedbackEl);

    if (checkBtn) checkBtn.disabled = false;
    if (showBtn)  { showBtn.style.display = ''; showBtn.disabled = false; }
    if (nextBtn)  {
      nextBtn.disabled    = true;
      nextBtn.textContent = index < questions.length - 1 ? 'Next →' : 'Finish';
    }

    updateProgress();
    renderImage(q);
    renderSentence(q, userAnswers[index] || [], false);
    renderWordBank(q);
  }

  /* ── check ── */
  function syncInputValues() {
    if (!sentenceEl) return;
    sentenceEl.querySelectorAll('.fb-input').forEach(function (input) {
      var idx = parseInt(input.getAttribute('data-blank-idx') || '0', 10);
      userAnswers[index][idx] = input.value;
    });
  }

  function checkAnswer() {
    if (answered) return;
    syncInputValues();

    var q       = questions[index] || {};
    var answers = q.answers || [];
    var userAns = userAnswers[index] || [];

    var allCorrect = answers.length > 0;
    var anyFilled  = false;

    answers.forEach(function (correct, i) {
      var ua = userAns[i] || '';
      if (ua) anyFilled = true;
      if (normalize(ua) !== normalize(correct)) allCorrect = false;
    });

    if (!anyFilled) return;

    answered = true;
    scores[index] = allCorrect ? 1 : 0;
    reviewItems[index] = {
      question:      q.text || '',
      yourAnswer:    userAns.join(' / '),
      correctAnswer: answers.join(' / '),
      score:         scores[index],
    };

    renderSentence(q, userAns, true);
    if (feedbackEl) AF.showFeedback(feedbackEl, allCorrect, answers.join(', '), false);
    if (checkBtn)   checkBtn.disabled = true;
    if (showBtn)    showBtn.style.display = 'none';
    if (nextBtn)    nextBtn.disabled = false;
  }

  /* ── show answer ── */
  function showAnswer() {
    if (answered) return;
    var q       = questions[index] || {};
    var answers = q.answers || [];
    answered = true;
    revealed = true;
    scores[index] = -1;
    reviewItems[index] = {
      question:      q.text || '',
      yourAnswer:    '(revealed)',
      correctAnswer: answers.join(' / '),
      score:         -1,
    };

    renderSentence(q, answers, true);
    if (feedbackEl) AF.showFeedback(feedbackEl, false, null, true);
    if (checkBtn)   checkBtn.disabled = true;
    if (showBtn)    showBtn.style.display = 'none';
    if (nextBtn)    nextBtn.disabled = false;
  }

  /* ── next ── */
  function nextQuestion() {
    if (index < questions.length - 1) {
      index++;
      loadQuestion();
    } else {
      showCompleted();
    }
  }

  /* ── completed ── */
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
      },
    });

    var result = AF.computeScore(scores);
    if (returnTo && activityId) {
      var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
      fetch(returnTo + sep
        + 'activity_percent=' + result.percent
        + '&activity_errors=' + result.wrong
        + '&activity_total='  + result.total
        + '&activity_id='     + encodeURIComponent(activityId)
        + '&activity_type=fillblank',
        { method: 'GET', credentials: 'same-origin', cache: 'no-store' }
      ).catch(function () {});
    }
  }

  function restartActivity() {
    index       = 0;
    scores      = questions.map(function () { return 0; });
    reviewItems = questions.map(function () { return {}; });
    userAnswers = questions.map(function (q) {
      var count = String(q.text || '').split(BLANK_RE).length - 1;
      return new Array(Math.max(0, count)).fill('');
    });
    loadQuestion();
  }

  /* ── extra CSS injected once ── */
  if (!document.getElementById('fb-dyn-css')) {
    var st = document.createElement('style');
    st.id  = 'fb-dyn-css';
    st.textContent =
      '.fb-answer-correct{display:inline-block;padding:2px 8px;border-radius:6px;font-weight:700;background:#f0fdf4;color:#166534;font-size:1em;vertical-align:middle;margin:0 4px}' +
      '.fb-answer-wrong{display:inline-block;padding:2px 8px;border-radius:6px;font-weight:700;background:#fef2f2;color:#991b1b;text-decoration:line-through;font-size:1em;vertical-align:middle;margin:0 4px}' +
      '.fb-answer-hint{display:inline-block;background:#EDE9FA;color:#7F77DD;border-radius:6px;padding:2px 8px;font-weight:800;font-size:.9em;vertical-align:middle;margin:0 4px}' +
      '.fb-input{border:none;border-bottom:2.5px solid #7F77DD;background:transparent;font-family:inherit;font-size:inherit;font-weight:700;color:#534AB7;min-width:80px;width:120px;padding:2px 4px;outline:none;text-align:center;vertical-align:middle;margin:0 6px}' +
      '.fb-image-img{max-width:100%;max-height:200px;border-radius:14px;object-fit:contain;display:block;margin:0 auto}';
    document.head.appendChild(st);
  }

  if (checkBtn) checkBtn.addEventListener('click', checkAnswer);
  if (showBtn)  showBtn.addEventListener('click', showAnswer);
  if (nextBtn)  nextBtn.addEventListener('click', nextQuestion);

  loadQuestion();
});
