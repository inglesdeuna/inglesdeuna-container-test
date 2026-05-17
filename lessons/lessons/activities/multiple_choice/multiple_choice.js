document.addEventListener('DOMContentLoaded', function () {
  const questions = Array.isArray(window.MULTIPLE_CHOICE_DATA) ? window.MULTIPLE_CHOICE_DATA : [];

  const progressLabelEl = document.getElementById('mc-progress-label');
  const progressFillEl = document.getElementById('mc-progress-fill');
  const progressBadgeEl = document.getElementById('mc-progress-badge');
  const questionEl = document.getElementById('mc-question');
  const imageBoxEl = document.getElementById('mc-image-box');
  const imageEl = document.getElementById('mc-image');
  const optionsEl = document.getElementById('mc-options');
  const feedbackEl = document.getElementById('mc-feedback');
  const listenBtn = document.getElementById('mc-listen');
  const showBtn = document.getElementById('mc-show');
  const nextBtn = document.getElementById('mc-next');
  const cardEl = document.querySelector('.mc-card');
  const controlsEl = document.querySelector('.mc-controls');
  const completedEl = document.getElementById('mc-completed');
  const completedTitleEl = document.getElementById('mc-completed-title');
  const completedTextEl = document.getElementById('mc-completed-text');
  const scoreTextEl = document.getElementById('mc-score-text');
  const restartBtn = document.getElementById('mc-restart');
  const scoreGridEl = document.getElementById('mc-score-grid');
  const scoreCorrectEl = document.getElementById('mc-s-correct');
  const scoreWrongEl = document.getElementById('mc-s-wrong');
  const scorePctEl = document.getElementById('mc-s-pct');

  const activityTitle = window.MULTIPLE_CHOICE_TITLE || 'Multiple Choice';
  const returnTo = window.MULTIPLE_CHOICE_RETURN_TO || '';
  const activityId = window.MULTIPLE_CHOICE_ACTIVITY_ID || '';

  const completedSound = new Audio('../../hangman/assets/win.mp3');
  const correctSound = new Audio('../../hangman/assets/realcorrect.mp3');
  const wrongSound = new Audio('../../hangman/assets/lose.mp3');

  if (!questions.length) {
    if (questionEl) {
      questionEl.textContent = 'No questions available.';
    }
    if (showBtn) {
      showBtn.disabled = true;
    }
    if (nextBtn) {
      nextBtn.disabled = true;
    }
    if (listenBtn) {
      listenBtn.disabled = true;
    }
    return;
  }

  let index = 0;
  let selected = null;
  let revealed = false;
  let answeredCurrent = false;
  let finished = false;
  let scoreVisible = false;
  let questionScores = questions.map(function () { return null; });
  let activeListenText = '';
  let activeVoiceId = 'josh';

  function playSound(audio) {
    try {
      audio.pause();
      audio.currentTime = 0;
      audio.play();
    } catch (e) {}
  }

  if (completedTitleEl) {
    completedTitleEl.textContent = activityTitle;
  }

  if (completedTextEl) {
    completedTextEl.textContent = "You've completed " + activityTitle + '. Great job practicing.';
  }

  function playCompletedSound() {
    playSound(completedSound);
  }

  function persistScoreSilently(targetUrl) {
    if (!targetUrl) {
      return Promise.resolve(false);
    }

    return fetch(targetUrl, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
    }).then(function (response) {
      return !!(response && response.ok);
    }).catch(function () {
      return false;
    });
  }

  function navigateToReturn(targetUrl) {
    if (!targetUrl) {
      return;
    }

    try {
      if (window.top && window.top !== window.self) {
        window.top.location.href = targetUrl;
        return;
      }
    } catch (e) {}

    window.location.href = targetUrl;
  }

  function buildSaveUrl(percent, errors, total) {
    if (!returnTo || !activityId) {
      return '';
    }

    const joiner = returnTo.indexOf('?') !== -1 ? '&' : '?';
    return returnTo
      + joiner + 'activity_percent=' + encodeURIComponent(String(percent))
      + '&activity_errors=' + encodeURIComponent(String(errors))
      + '&activity_total=' + encodeURIComponent(String(total))
      + '&activity_id=' + encodeURIComponent(String(activityId))
      + '&activity_type=multiple_choice';
  }

  function computeScore() {
    const total = questions.length;
    let correct = 0;
    let wrong = 0;
    let revealedCount = 0;

    questionScores.forEach(function (value) {
      if (value === 1) {
        correct += 1;
      } else if (value === 0) {
        wrong += 1;
      } else if (value === -1) {
        revealedCount += 1;
      }
    });

    const scorable = correct + wrong;
    const percent = scorable > 0 ? Math.round((correct / scorable) * 100) : 0;

    return {
      correct: correct,
      total: total,
      wrong: wrong,
      errors: wrong,
      revealed: revealedCount,
      percent: percent,
    };
  }

  function updateScoreCards(show) {
    if (typeof show === 'boolean') {
      scoreVisible = show;
    }

    const result = computeScore();

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

  function safeOptions(item) {
    return item && Array.isArray(item.options) ? item.options : [];
  }

  function normalizeQuestion(item) {
    const rawQuestion = String((item && item.question) || '');
    return rawQuestion.replace(/^Choose the correct basic command:\s*/i, '').trim();
  }

  function updateProgress() {
    const total = questions.length;
    const current = index + 1;
    const percent = total > 0 ? Math.round((current / total) * 100) : 0;

    if (progressLabelEl) {
      progressLabelEl.textContent = current + ' / ' + total;
    }

    if (progressBadgeEl) {
      progressBadgeEl.textContent = 'Q ' + current + ' of ' + total;
    }

    if (progressFillEl) {
      progressFillEl.style.width = percent + '%';
    }
  }

  function getCorrectIndex(item) {
    const raw = parseInt(item && item.correct, 10);
    if (!Number.isFinite(raw)) {
      return 0;
    }
    return Math.max(0, Math.min(2, raw));
  }

  function renderOptions() {
    const item = questions[index] || {};
    const correct = getCorrectIndex(item);
    const isImageOpts = item.option_type === 'image';
    const score = questionScores[index];

    optionsEl.innerHTML = '';

    safeOptions(item).forEach(function (optionText, optIndex) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'mc-option';

      if (selected === optIndex) {
        button.classList.add('selected');
      }

      if ((revealed || score === -1) && optIndex === correct) {
        button.classList.add('correct');
      }

      if (score === 1 && selected === optIndex) {
        button.classList.add('correct');
      }

      if (score === 0) {
        if (selected === optIndex) {
          button.classList.add('wrong');
        }
        if (optIndex === correct) {
          button.classList.add('correct');
        }
      }

      if (isImageOpts && optionText !== '') {
        const img = document.createElement('img');
        img.src = optionText;
        img.alt = 'Option ' + String.fromCharCode(65 + optIndex);
        button.appendChild(img);
      } else {
        button.textContent = optionText;
      }

      button.addEventListener('click', function () {
        if (finished || answeredCurrent) {
          return;
        }

        validateSelection(optIndex);
      });

      button.disabled = finished || answeredCurrent;

      optionsEl.appendChild(button);
    });
  }

  function validateSelection(optIndex) {
    if (finished || answeredCurrent) {
      return;
    }

    const item = questions[index] || {};
    const correct = getCorrectIndex(item);
    const isCorrectPick = optIndex === correct;

    selected = optIndex;
    revealed = false;
    answeredCurrent = true;
    questionScores[index] = isCorrectPick ? 1 : 0;

    renderOptions();
    updateScoreCards(true);

    if (feedbackEl) {
      if (isCorrectPick) {
        feedbackEl.textContent = 'Correct! Great job.';
        feedbackEl.className = 'mc-feedback good';
      } else {
        feedbackEl.textContent = 'Incorrect. Correct option highlighted.';
        feedbackEl.className = 'mc-feedback bad';
      }
    }

    if (showBtn) {
      showBtn.disabled = true;
    }

    if (nextBtn) {
      nextBtn.disabled = false;
    }

    playSound(isCorrectPick ? correctSound : wrongSound);
  }

  function loadQuestion() {
    const item = questions[index] || {};
    const cleanQuestion = normalizeQuestion(item);
    const isListen = item.question_type === 'listen';

    selected = null;
    revealed = false;
    answeredCurrent = false;
    finished = false;
    activeListenText = cleanQuestion;
    activeVoiceId = String(item.voice_id || 'josh');

    if (completedEl) {
      completedEl.classList.remove('active');
    }

    if (cardEl) {
      cardEl.style.display = 'block';
    }

    if (controlsEl) {
      controlsEl.style.display = 'flex';
    }

    if (feedbackEl) {
      feedbackEl.textContent = '';
      feedbackEl.className = 'mc-feedback';
    }

    updateProgress();

    if (questionEl) {
      questionEl.textContent = cleanQuestion || 'Choose the correct answer.';
    }

    if (listenBtn) {
      listenBtn.disabled = !isListen || cleanQuestion === '';
    }

    if (item.image) {
      imageEl.src = item.image;
      imageBoxEl.classList.remove('is-empty');
    } else {
      imageEl.removeAttribute('src');
      imageBoxEl.classList.add('is-empty');
    }

    renderOptions();

    if (showBtn) {
      showBtn.disabled = false;
    }

    if (nextBtn) {
      nextBtn.textContent = index < questions.length - 1 ? 'Next →' : 'Finish';
      nextBtn.disabled = true;
    }
  }

  function showAnswer() {
    if (finished || answeredCurrent) {
      return;
    }

    selected = null;
    revealed = true;
    answeredCurrent = true;
    questionScores[index] = -1;
    renderOptions();
    updateScoreCards(true);

    if (feedbackEl) {
      feedbackEl.textContent = 'Answer revealed — this question does not affect score.';
      feedbackEl.className = 'mc-feedback';
    }

    if (showBtn) {
      showBtn.disabled = true;
    }

    if (nextBtn) {
      nextBtn.disabled = false;
    }
  }

  async function showCompleted() {
    finished = true;

    if (feedbackEl) {
      feedbackEl.textContent = '';
      feedbackEl.className = 'mc-feedback';
    }

    const result = computeScore();

    if (cardEl) {
      cardEl.style.display = 'none';
    }

    if (controlsEl) {
      controlsEl.style.display = 'none';
    }

    if (completedEl) {
      completedEl.classList.add('active');
    }

    updateScoreCards(true);

    if (scoreTextEl) {
      scoreTextEl.textContent = result.correct + ' correct · ' + result.wrong + ' wrong · ' + result.percent + '%';
    }

    playCompletedSound();

    const saveUrl = buildSaveUrl(result.percent, result.errors, result.total);
    if (saveUrl) {
      const ok = await persistScoreSilently(saveUrl);
      if (!ok) {
        navigateToReturn(saveUrl);
      }
    }
  }

  function nextQuestion() {
    if (finished) {
      return;
    }

    if (!answeredCurrent) {
      if (feedbackEl) {
        feedbackEl.textContent = 'Select an option or tap Show Answer first.';
        feedbackEl.className = 'mc-feedback bad';
      }
      return;
    }

    if (index < questions.length - 1) {
      index += 1;
      loadQuestion();
      return;
    }

    showCompleted();
  }

  function restartActivity() {
    index = 0;
    selected = null;
    revealed = false;
    answeredCurrent = false;
    finished = false;
    scoreVisible = false;
    questionScores = questions.map(function () { return null; });
    updateScoreCards(false);
    loadQuestion();
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

  if (listenBtn) {
    listenBtn.addEventListener('click', function () {
      if (!activeListenText) {
        return;
      }
      speakText(activeListenText, activeVoiceId);
    });
  }

  let userInteracted = false;
  let pendingSpeech = '';
  let currentAudioElement = null;
  let ttsAbortController = null;

  function stopSpeech() {
    if (ttsAbortController) {
      ttsAbortController.abort();
      ttsAbortController = null;
    }
    if (currentAudioElement) {
      currentAudioElement.pause();
      currentAudioElement.currentTime = 0;
      currentAudioElement = null;
    }
  }

  function speakText(text, voiceId) {
    if (!text) {
      return;
    }

    voiceId = voiceId || 'josh';
    stopSpeech();

    ttsAbortController = new AbortController();
    const signal = ttsAbortController.signal;

    const formData = new FormData();
    formData.append('text', text);
    formData.append('voice_id', voiceId);

    fetch('tts.php', {
      method: 'POST',
      body: formData,
      signal: signal,
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('TTS request failed: ' + response.status);
        }
        return response.blob();
      })
      .then(function (audioBlob) {
        if (signal.aborted) {
          return;
        }

        const audioUrl = URL.createObjectURL(audioBlob);
        currentAudioElement = new Audio(audioUrl);
        currentAudioElement.play().catch(function () {});
      })
      .catch(function (error) {
        if (signal.aborted) {
          return;
        }
        console.error('TTS error:', error);
      });
  }

  function speakWhenReady(text, voiceId) {
    if (!text) {
      return;
    }
    if (userInteracted) {
      speakText(text, voiceId);
    } else {
      pendingSpeech = { text: text, voiceId: voiceId };
    }
  }

  document.addEventListener('click', function onFirstInteraction() {
    userInteracted = true;
    if (pendingSpeech && typeof pendingSpeech === 'object') {
      speakText(pendingSpeech.text, pendingSpeech.voiceId);
      pendingSpeech = '';
    }
    document.removeEventListener('click', onFirstInteraction);
  }, { once: true });

  updateScoreCards(false);
  loadQuestion();
  if ((questions[0] || {}).question_type === 'listen') {
    speakWhenReady(activeListenText, activeVoiceId);
  }
});