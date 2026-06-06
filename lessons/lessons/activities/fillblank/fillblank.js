document.addEventListener('DOMContentLoaded', function () {
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
  var cardEl = activityEl ? activityEl.querySelector('.fb-card-shell') : null;
  var completedEl = document.getElementById('fb-completed');
  var completedTitleEl = document.getElementById('fb-completed-title');
  var completedTextEl = document.getElementById('fb-completed-text');
  var scoreTextEl = document.getElementById('fb-score-text');
  var restartBtn = document.getElementById('fb-restart');
  var scoreGridEl = document.getElementById('fb-score-grid');
  var scoreCorrectEl = document.getElementById('fb-s-correct');
  var scoreWrongEl = document.getElementById('fb-s-wrong');
  var scorePctEl = document.getElementById('fb-s-pct');
  var winAudio = new Audio('../../hangman/assets/win.mp3');

  var activityTitle = window.FILLBLANK_TITLE || 'Fill in the Blank';
  var returnTo = window.FILLBLANK_RETURN_TO || '';
  var activityId = window.FILLBLANK_ACTIVITY_ID || '';
  var mediaType = String(window.FILLBLANK_MEDIA_TYPE || 'none');
  var mediaUrl = String(window.FILLBLANK_MEDIA_URL || '').trim();
  var ttsAudioUrl = String(window.FILLBLANK_TTS_AUDIO_URL || '').trim();
  var ttsText = String(window.FILLBLANK_TTS_TEXT || '').trim();
  var voiceId = String(window.FILLBLANK_VOICE_ID || 'nzFihrBIvB34imQBuxub');
  var ttsUrl = String(window.FILLBLANK_TTS_URL || 'tts.php');
  var listenBtn = document.getElementById('fb-listen-btn');
  var mediaAudioEl = document.getElementById('fb-audio-player');
  var streamAudio = null;
  var streamAudioUrl = '';

  if (!questions.length) {
    if (sentenceEl) {
      sentenceEl.textContent = 'No questions available.';
    }
    return;
  }

  var index = 0;
  var answered = false;
  var revealed = false;
  var finished = false;
  var scoreVisible = false;
  var scores = questions.map(function () { return null; });

  var selectedAnswers = questions.map(function (q) {
    var answerCount = Array.isArray(q.answers) ? q.answers.length : 1;
    return new Array(Math.max(1, answerCount)).fill('');
  });

  function normalize(s) {
    return String(s || '')
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9\s]/g, '')
      .replace(/\s+/g, ' ');
  }

  function resetListenButton() {
    if (listenBtn) {
      listenBtn.disabled = false;
      listenBtn.textContent = 'Listen';
    }
  }

  function cleanupStreamAudio() {
    if (streamAudio) {
      try { streamAudio.pause(); } catch (e) {}
      try { streamAudio.currentTime = 0; } catch (e) {}
      streamAudio = null;
    }
    if (streamAudioUrl) {
      try { URL.revokeObjectURL(streamAudioUrl); } catch (e) {}
      streamAudioUrl = '';
    }
  }

  function playMediaAudio() {
    if (!mediaAudioEl || !listenBtn) {
      return;
    }

    if (!mediaAudioEl.src) {
      var chosen = mediaType === 'audio' ? mediaUrl : ttsAudioUrl;
      if (chosen) {
        mediaAudioEl.src = chosen;
      }
    }

    if (!mediaAudioEl.src) {
      return;
    }

    if (!mediaAudioEl.paused) {
      mediaAudioEl.pause();
      listenBtn.textContent = 'Resume';
      return;
    }

    mediaAudioEl.play().then(function () {
      listenBtn.textContent = 'Pause';
    }).catch(function () {
      resetListenButton();
    });
  }

  function playStreamTts() {
    if (!listenBtn || !ttsText) {
      return;
    }

    if (streamAudio) {
      if (!streamAudio.paused) {
        streamAudio.pause();
        listenBtn.textContent = 'Resume';
      } else {
        streamAudio.play().then(function () {
          listenBtn.textContent = 'Pause';
        }).catch(function () {
          resetListenButton();
        });
      }
      return;
    }

    listenBtn.disabled = true;
    listenBtn.textContent = '...';

    var fd = new FormData();
    fd.append('text', ttsText);
    fd.append('voice_id', voiceId || 'nzFihrBIvB34imQBuxub');
    fd.append('response_type', 'stream');

    fetch(ttsUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) throw new Error('TTS error ' + res.status);
        return res.blob();
      })
      .then(function (blob) {
        streamAudioUrl = URL.createObjectURL(blob);
        streamAudio = new Audio(streamAudioUrl);

        streamAudio.onended = function () {
          cleanupStreamAudio();
          resetListenButton();
        };

        streamAudio.onpause = function () {
          if (streamAudio && streamAudio.currentTime < (streamAudio.duration || Infinity)) {
            listenBtn.textContent = 'Resume';
          }
        };

        return streamAudio.play();
      })
      .then(function () {
        listenBtn.disabled = false;
        listenBtn.textContent = 'Pause';
      })
      .catch(function () {
        cleanupStreamAudio();
        resetListenButton();
      });
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
    return [];
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
      html += escHtml(leftPart).replace(/\n/g, '<br>');

      var value = String(values[i] || '');

      if (!isAnswered) {
        html += ' <input class="fb-blank" data-blank-input="' + i + '" type="text" autocomplete="off" value="' + escHtml(value) + '" style="border:none;border-bottom:2.5px solid #7F77DD;border-radius:0;background:transparent;outline:none;text-align:center;font:700 16px Nunito,sans-serif;color:#5A51C0;padding:0 4px;min-width:44px;max-width:100%;width:4ch;height:24px;"> ';
      } else {
        var correct = String(answers[i] || '');
        var ok = normalize(value) === normalize(correct);

        if (revealed) {
          html += ' <span class="fb-blank-filled" style="background:#f0fdf4; color:#15803d; cursor:default;"><span class="fb-blank-text">' + escHtml(correct) + '</span></span> ';
        } else if (ok) {
          html += ' <span class="fb-blank-filled" style="background:#f0fdf4; color:#15803d; cursor:default;"><span class="fb-blank-text">' + escHtml(value) + '</span></span> ';
        } else {
          html += ' <span class="fb-blank-filled" style="background:#fef2f2; color:#b91c1c; cursor:default;"><span class="fb-blank-text" style="text-decoration:line-through;">' + escHtml(value || '\u2014') + '</span></span> ';
          html += ' <span style="background:#f0fdf4; color:#15803d; border-radius:8px; padding:2px 8px; font-weight:800; display:inline-flex; align-items:center; vertical-align:bottom; margin:0 6px;">' + escHtml(correct) + '</span> ';
        }
      }
    }

    html += escHtml(parts[blankCount] !== undefined ? parts[blankCount] : '').replace(/\n/g, '<br>');
    sentenceEl.innerHTML = html;
  }

  function renderWordBank(q) {
    if (!wordBankWordsEl) {
      return;
    }

    wordBankWordsEl.innerHTML = '';
    var wordBankWrap = wordBankWordsEl.closest('.fb-wordbank');

    var options = getOptionsForQuestion(q);
    if (!options.length) {
      if (wordBankWrap) {
        wordBankWrap.style.display = 'none';
      }
      return;
    }

    if (wordBankWrap) {
      wordBankWrap.style.display = '';
    }

    options.forEach(function (word) {
      var chip = document.createElement('span');
      chip.className = 'fb-chip';
      chip.textContent = word;

      wordBankWordsEl.appendChild(chip);
    });
  }

  function attachSentenceListeners() {
    if (!sentenceEl) {
      return;
    }

    var inputEls = sentenceEl.querySelectorAll('input[data-blank-input]');
    inputEls.forEach(function (input, idx) {
      resizeBlankInput(input);

      input.addEventListener('input', function () {
        if (answered || revealed) {
          return;
        }
        selectedAnswers[index][idx] = input.value;
        resizeBlankInput(input);
      });

      input.addEventListener('keydown', function (evt) {
        if (evt.key === 'Enter') {
          evt.preventDefault();
          if (!answered && !revealed) {
            checkAnswer();
          }
        }
      });
    });

    if (inputEls.length) {
      inputEls[0].focus();
      inputEls[0].select();
    }
  }

  function resizeBlankInput(input) {
    var typed = String(input.value || '').trim();
    var minChars = 4;
    var maxChars = 20;
    var targetChars = Math.min(maxChars, Math.max(minChars, typed.length + 1));
    input.style.width = targetChars + 'ch';
  }

  function computeQuestionScore(q, values) {
    var answers = Array.isArray(q.answers) ? q.answers : [];
    var count = Math.max(getBlankCount(q), answers.length);
    var earned = 0;

    for (var i = 0; i < count; i++) {
      if (normalize(values[i] || '') === normalize(answers[i] || '')) {
        earned += 1;
      }
    }
    return {
      earned: earned,
      possible: count,
      allCorrect: count > 0 && earned === count
    };
  }

  function computeScoreLikeMultipleChoice() {
    var total = 0;
    var scorable = 0;
    var correct = 0;
    var revealedCount = 0;

    scores.forEach(function (value, idx) {
      var q = questions[idx] || {};
      var possible = value && typeof value.possible === 'number' ? value.possible : getBlankCount(q);
      total += possible;

      if (value && value.revealed) {
        revealedCount += possible;
      } else {
        scorable += possible;
        if (value && typeof value.earned === 'number') {
          correct += value.earned;
        }
      }
    });

    var wrong = Math.max(0, scorable - correct);
    var percent = scorable > 0 ? Math.round((correct / scorable) * 100) : 0;

    return {
      correct: correct,
      total: scorable,
      wrong: wrong,
      errors: wrong,
      revealed: revealedCount,
      percent: percent
    };
  }

  function updateScoreCards(show) {
    if (typeof show === 'boolean') {
      scoreVisible = show;
    }

    var result = computeScoreLikeMultipleChoice();

    if (scoreCorrectEl) {
      scoreCorrectEl.textContent = String(result.correct);
    }
    if (scoreWrongEl) {
      scoreWrongEl.textContent = String(result.wrong);
    }
    if (scorePctEl) {
      scorePctEl.textContent = result.percent + '%';
    }
    if (scoreGridEl) {
      scoreGridEl.classList.toggle('visible', !!scoreVisible);
    }
  }

  function loadQuestion() {
    var q = questions[index] || {};
    answered = false;
    revealed = false;
    finished = false;

    if (completedEl) {
      completedEl.classList.remove('active');
    }
    if (activityEl) {
      activityEl.style.display = '';
    }
    if (cardEl) {
      cardEl.style.display = 'block';
    }
    if (feedbackEl) {
      feedbackEl.textContent = '';
      feedbackEl.className = 'fb-feedback';
      feedbackEl.style.display = '';
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
      nextBtn.textContent = 'Next \u2192';
    }

    updateProgress();
    renderImage(q);
    renderSentence(q, selectedAnswers[index], false);
    renderWordBank(q);
    attachSentenceListeners();
  }

  function checkAnswer() {
    if (answered || finished) {
      return;
    }

    var q = questions[index] || {};
    var user = selectedAnswers[index].slice();
    var score = computeQuestionScore(q, user);
    var allCorrect = score.allCorrect;

    answered = true;
    scores[index] = { earned: score.earned, possible: score.possible, revealed: false };

    renderSentence(q, user, true);
    updateScoreCards(true);

    if (feedbackEl) {
      if (allCorrect) {
        feedbackEl.textContent = 'Correct! Great job.';
        feedbackEl.className = 'fb-feedback good';
      } else if (score.earned > 0) {
        feedbackEl.textContent = 'Partially correct: ' + score.earned + ' / ' + score.possible + ' words.';
        feedbackEl.className = 'fb-feedback';
      } else {
        feedbackEl.textContent = 'Incorrect. Correct option highlighted.';
        feedbackEl.className = 'fb-feedback bad';
      }
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
    if (answered || finished) {
      return;
    }

    var q = questions[index] || {};
    var correct = Array.isArray(q.answers) ? q.answers.slice() : [];
    var possible = Math.max(getBlankCount(q), correct.length);

    answered = true;
    revealed = true;
    scores[index] = { earned: 0, possible: possible, revealed: true };
    selectedAnswers[index] = correct.slice();

    renderSentence(q, correct, true);
    updateScoreCards(true);

    if (feedbackEl) {
      feedbackEl.textContent = 'Answer revealed — these words do not affect score.';
      feedbackEl.className = 'fb-feedback';
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
    if (finished) {
      return;
    }

    if (index < questions.length - 1) {
      index++;
      loadQuestion();
    } else {
      showCompleted();
    }
  }

  function showCompleted() {
    if (!completedEl) {
      return;
    }

    finished = true;

    if (feedbackEl) {
      feedbackEl.textContent = '';
      feedbackEl.className = 'fb-feedback';
    }

    var result = computeScoreLikeMultipleChoice();

    if (cardEl) {
      cardEl.style.display = 'none';
    }
    if (feedbackEl) {
      feedbackEl.style.display = 'none';
    }
    completedEl.classList.add('active');

    updateScoreCards(true);

    if (completedTitleEl) {
      completedTitleEl.textContent = activityTitle;
    }
    if (completedTextEl) {
      completedTextEl.textContent = "You've completed " + activityTitle + '. Great job practicing.';
    }
    if (scoreTextEl) {
      scoreTextEl.textContent = result.correct + ' correct · ' + result.wrong + ' wrong · ' + result.percent + '%';
    }

    try {
      winAudio.pause();
      winAudio.currentTime = 0;
      winAudio.play();
    } catch (e) {}

    if (returnTo && activityId) {
      var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
      var reportUrl = returnTo
        + sep + 'activity_percent=' + result.percent
        + '&activity_errors=' + result.errors
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
    finished = false;
    scoreVisible = false;
    scores = questions.map(function () { return null; });
    updateScoreCards(false);

    selectedAnswers = questions.map(function (q) {
      var answerCount = Array.isArray(q.answers) ? q.answers.length : 1;
      return new Array(Math.max(1, answerCount)).fill('');
    });

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
  if (restartBtn) {
    restartBtn.addEventListener('click', restartActivity);
  }

  if (mediaAudioEl) {
    mediaAudioEl.addEventListener('ended', resetListenButton);
    mediaAudioEl.addEventListener('pause', function () {
      if (mediaAudioEl.currentTime < (mediaAudioEl.duration || Infinity) && listenBtn) {
        listenBtn.textContent = 'Resume';
      }
    });
  }

  if (listenBtn) {
    listenBtn.addEventListener('click', function () {
      if ((mediaType === 'audio' && mediaUrl) || ttsAudioUrl) {
        playMediaAudio();
        return;
      }
      if (mediaType === 'tts' && ttsText) {
        playStreamTts();
      }
    });
  }

  loadQuestion();
});
