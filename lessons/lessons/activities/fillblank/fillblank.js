document.addEventListener('DOMContentLoaded', function () {
  var AF = window.ActivityFeedback;
  var questions = Array.isArray(window.FILLBLANK_DATA) ? window.FILLBLANK_DATA : [];

  var progressLabelEl = document.getElementById('fb-progress-label');
  var progressFillEl = document.getElementById('fb-progress-fill');
  var progressBadgeEl = document.getElementById('fb-progress-badge');
  var sentenceEl = document.getElementById('fb-sentence');
  var imageWrapEl = document.getElementById('fb-image-wrap');
  var imageEl = document.getElementById('fb-image');
  var wordBankWordsEl = document.getElementById('fb-wb-words');
  var checkBtn = document.getElementById('fb-check');
  var showBtn = document.getElementById('fb-show');
  var nextBtn = document.getElementById('fb-next');
  var feedbackEl = document.getElementById('fb-feedback');
  var activityEl = document.getElementById('fb-activity');
  var completedEl = document.getElementById('fb-completed');
  var winAudio = new Audio('../../hangman/assets/win.mp3');

  var activityTitle = window.FILLBLANK_TITLE || 'Fill in the Blank';
  var returnTo = window.FILLBLANK_RETURN_TO || '';
  var activityId = window.FILLBLANK_ACTIVITY_ID || '';

  if (!questions.length) {
    if (sentenceEl) {
      sentenceEl.textContent = 'No questions available.';
    }
    return;
  }

  var index = 0;
  var answered = false;
  var revealed = false;
  var scores = questions.map(function () { return 0; });
  var reviewItems = questions.map(function () { return {}; });

  var selectedAnswers = questions.map(function (q) {
    var answerCount = Array.isArray(q.answers) ? q.answers.length : 1;
    return new Array(Math.max(1, answerCount)).fill('');
  });

  var usedOptionIndexes = questions.map(function () { return {}; });

  function normalize(s) {
    return String(s || '')
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9\s]/g, '')
      .replace(/\s+/g, ' ');
  }

  function escHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function splitTextByBlanks(text) {
    var source = String(text || '');
    return source.split(/___+/g);
  }

  function getBlankCount(q) {
    var parts = splitTextByBlanks(q.text || '');
    var fromText = Math.max(0, parts.length - 1);
    var fromAnswers = Array.isArray(q.answers) ? q.answers.length : 0;
    return Math.max(1, fromText, fromAnswers);
  }

  function getOptionsForQuestion(q) {
    if (Array.isArray(q.options) && q.options.length) {
      return q.options.slice();
    }
    return Array.isArray(q.answers) ? q.answers.slice() : [];
  }

  function updateProgress() {
    var total = questions.length;
    var current = index + 1;
    var pct = Math.round((current / total) * 100);

    if (progressLabelEl) {
      progressLabelEl.textContent = current + ' / ' + total;
    }
    if (progressBadgeEl) {
      progressBadgeEl.textContent = 'Q ' + current + ' of ' + total;
    }
    if (progressFillEl) {
      progressFillEl.style.width = pct + '%';
    }
  }

  function renderImage(q) {
    if (!imageWrapEl || !imageEl) {
      return;
    }

    var imageUrl = String(q.image_url || q.image || '').trim();
    if (imageUrl === '') {
      imageWrapEl.style.display = 'none';
      imageEl.removeAttribute('src');
      return;
    }

    imageEl.src = imageUrl;
    imageWrapEl.style.display = 'block';
  }

  function findFirstEmptyBlank(values) {
    for (var i = 0; i < values.length; i++) {
      if (!String(values[i] || '').trim()) {
        return i;
      }
    }
    return -1;
  }

  function renderSentence(q, values, isAnswered) {
    if (!sentenceEl) {
      return;
    }

    var parts = splitTextByBlanks(q.text || '');
    var blankCount = getBlankCount(q);
    var answers = Array.isArray(q.answers) ? q.answers : [];
    var html = '';

    for (var i = 0; i < blankCount; i++) {
      var leftPart = parts[i] !== undefined ? parts[i] : '';
      html += escHtml(leftPart);

      var value = String(values[i] || '');

      if (!isAnswered) {
        if (value) {
          html += ' <span class="fb-blank-filled" data-blank-index="' + i + '"><span class="fb-blank-text">' + escHtml(value) + '</span><span class="fb-blank-remove" title="Clear">✕</span></span> ';
        } else {
          html += ' <span class="fb-blank" data-blank-index="' + i + '"></span> ';
        }
      } else {
        var correct = String(answers[i] || '');
        var ok = normalize(value) === normalize(correct);

        if (revealed) {
          html += ' <span class="fb-blank-filled" style="background:#7F77DD; cursor:default;"><span class="fb-blank-text">' + escHtml(correct) + '</span></span> ';
        } else if (ok) {
          html += ' <span class="fb-blank-filled" style="background:#22c55e; cursor:default;"><span class="fb-blank-text">' + escHtml(value) + '</span></span> ';
        } else {
          html += ' <span class="fb-blank-filled" style="background:#ef4444; cursor:default;"><span class="fb-blank-text" style="text-decoration:line-through;">' + escHtml(value || '\u2014') + '</span></span> ';
          html += ' <span style="background:#EDE9FA; color:#7F77DD; border-radius:8px; padding:2px 8px; font-weight:800; display:inline-flex; align-items:center; vertical-align:bottom; margin:0 6px;">' + escHtml(correct) + '</span> ';
        }
      }
    }

    html += escHtml(parts[blankCount] !== undefined ? parts[blankCount] : '');
    sentenceEl.innerHTML = html;
  }

  function renderWordBank(q) {
    if (!wordBankWordsEl) {
      return;
    }

    wordBankWordsEl.innerHTML = '';

    var options = getOptionsForQuestion(q);
    if (!options.length) {
      return;
    }

    options.forEach(function (word, optionIndex) {
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'fb-chip';
      chip.textContent = word;
      chip.dataset.optionIndex = String(optionIndex);

      if (usedOptionIndexes[index] && usedOptionIndexes[index][optionIndex]) {
        chip.classList.add('used');
        chip.disabled = true;
      }

      chip.addEventListener('click', function () {
        if (answered || revealed || chip.disabled) {
          return;
        }

        var blankIdx = findFirstEmptyBlank(selectedAnswers[index]);
        if (blankIdx === -1) {
          return;
        }

        selectedAnswers[index][blankIdx] = word;
        usedOptionIndexes[index][optionIndex] = true;
        renderSentence(questions[index], selectedAnswers[index], false);
        attachSentenceListeners();
        updateWordBankUI();
      });

      wordBankWordsEl.appendChild(chip);
    });
  }

  function updateWordBankUI() {
    if (!wordBankWordsEl) {
      return;
    }

    var chips = wordBankWordsEl.querySelectorAll('.fb-chip');
    chips.forEach(function (chip) {
      var optionIdx = Number(chip.dataset.optionIndex || '-1');
      var used = !!(usedOptionIndexes[index] && usedOptionIndexes[index][optionIdx]);
      chip.classList.toggle('used', used);
      chip.disabled = answered || revealed || used;
    });
  }

  function releaseUsedOption(optionText) {
    var q = questions[index] || {};
    var options = getOptionsForQuestion(q);

    for (var i = 0; i < options.length; i++) {
      if (String(options[i]) === String(optionText) && usedOptionIndexes[index][i]) {
        delete usedOptionIndexes[index][i];
        return;
      }
    }
  }

  function clearBlank(blankIndex) {
    if (answered || revealed) {
      return;
    }

    var current = String(selectedAnswers[index][blankIndex] || '');
    if (!current) {
      return;
    }

    releaseUsedOption(current);
    selectedAnswers[index][blankIndex] = '';
    renderSentence(questions[index], selectedAnswers[index], false);
    attachSentenceListeners();
    updateWordBankUI();
  }

  function attachSentenceListeners() {
    if (!sentenceEl) {
      return;
    }

    var removeButtons = sentenceEl.querySelectorAll('.fb-blank-remove');
    removeButtons.forEach(function (btn) {
      btn.addEventListener('click', function (evt) {
        evt.stopPropagation();
        var parent = btn.closest('[data-blank-index]');
        if (!parent) {
          return;
        }
        var blankIdx = Number(parent.getAttribute('data-blank-index'));
        clearBlank(blankIdx);
      });
    });

    var filled = sentenceEl.querySelectorAll('.fb-blank-filled[data-blank-index]');
    filled.forEach(function (node) {
      node.addEventListener('click', function () {
        var blankIdx = Number(node.getAttribute('data-blank-index'));
        clearBlank(blankIdx);
      });
    });
  }

  function isAllCorrect(q, values) {
    var answers = Array.isArray(q.answers) ? q.answers : [];
    var count = Math.max(getBlankCount(q), answers.length);

    for (var i = 0; i < count; i++) {
      if (normalize(values[i] || '') !== normalize(answers[i] || '')) {
        return false;
      }
    }
    return true;
  }

  function buildReviewQuestionText(q) {
    return String(q.text || '');
  }

  function buildAnswerSummary(arr) {
    return (arr || []).map(function (v) {
      return String(v || '').trim() || '\u2014';
    }).join(' | ');
  }

  function loadQuestion() {
    var q = questions[index] || {};
    answered = false;
    revealed = false;

    if (completedEl) {
      completedEl.style.display = 'none';
    }
    if (activityEl) {
      activityEl.style.display = '';
    }
    if (feedbackEl && AF && typeof AF.clearFeedback === 'function') {
      AF.clearFeedback(feedbackEl);
    }

    if (checkBtn) {
      checkBtn.disabled = false;
    }
    if (showBtn) {
      showBtn.style.display = '';
      showBtn.disabled = false;
    }
    if (nextBtn) {
      nextBtn.disabled = true;
      nextBtn.textContent = index < questions.length - 1 ? 'Next \u2192' : 'Finish';
    }

    updateProgress();
    renderImage(q);
    renderSentence(q, selectedAnswers[index], false);
    renderWordBank(q);
    attachSentenceListeners();
    updateWordBankUI();
  }

  function checkAnswer() {
    if (answered) {
      return;
    }

    var q = questions[index] || {};
    var user = selectedAnswers[index].slice();
    var correct = Array.isArray(q.answers) ? q.answers : [];
    var allCorrect = isAllCorrect(q, user);

    answered = true;
    scores[index] = allCorrect ? 1 : 0;

    reviewItems[index] = {
      question: buildReviewQuestionText(q),
      yourAnswer: buildAnswerSummary(user),
      correctAnswer: buildAnswerSummary(correct),
      score: scores[index]
    };

    renderSentence(q, user, true);
    updateWordBankUI();

    if (feedbackEl && AF && typeof AF.showFeedback === 'function') {
      AF.showFeedback(feedbackEl, allCorrect, buildAnswerSummary(correct), false);
    }
    if (checkBtn) {
      checkBtn.disabled = true;
    }
    if (showBtn) {
      showBtn.style.display = 'none';
    }
    if (nextBtn) {
      nextBtn.disabled = false;
    }
  }

  function showAnswer() {
    if (answered) {
      return;
    }

    var q = questions[index] || {};
    var correct = Array.isArray(q.answers) ? q.answers.slice() : [];

    answered = true;
    revealed = true;
    scores[index] = -1;
    selectedAnswers[index] = correct.slice();

    reviewItems[index] = {
      question: buildReviewQuestionText(q),
      yourAnswer: '(revealed)',
      correctAnswer: buildAnswerSummary(correct),
      score: -1
    };

    renderSentence(q, correct, true);
    updateWordBankUI();

    if (feedbackEl && AF && typeof AF.showFeedback === 'function') {
      AF.showFeedback(feedbackEl, false, null, true);
    }
    if (checkBtn) {
      checkBtn.disabled = true;
    }
    if (showBtn) {
      showBtn.style.display = 'none';
    }
    if (nextBtn) {
      nextBtn.disabled = false;
    }
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
    if (!completedEl || !AF) {
      return;
    }

    if (activityEl) {
      activityEl.style.display = 'none';
    }
    completedEl.style.display = '';

    AF.showCompleted({
      target: completedEl,
      scores: scores,
      title: activityTitle,
      activityType: 'Fill in the Blank',
      questionCount: questions.length,
      winAudio: winAudio,
      onRetry: restartActivity,
      onReview: function () {
        AF.showReview({
          target: completedEl,
          items: reviewItems,
          onRetry: restartActivity
        });
      }
    });

    var result = AF.computeScore(scores);
    if (returnTo && activityId) {
      var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
      var reportUrl = returnTo
        + sep + 'activity_percent=' + result.percent
        + '&activity_errors=' + result.wrong
        + '&activity_total=' + result.total
        + '&activity_id=' + encodeURIComponent(activityId)
        + '&activity_type=fillblank';

      fetch(reportUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
      }).catch(function () {});
    }
  }

  function restartActivity() {
    index = 0;
    answered = false;
    revealed = false;
    scores = questions.map(function () { return 0; });
    reviewItems = questions.map(function () { return {}; });

    selectedAnswers = questions.map(function (q) {
      var answerCount = Array.isArray(q.answers) ? q.answers.length : 1;
      return new Array(Math.max(1, answerCount)).fill('');
    });
    usedOptionIndexes = questions.map(function () { return {}; });

    loadQuestion();
  }

  if (checkBtn) {
    checkBtn.addEventListener('click', checkAnswer);
  }
  if (showBtn) {
    showBtn.addEventListener('click', showAnswer);
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', nextQuestion);
  }

  loadQuestion();
});
