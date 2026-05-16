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
  const checkBtn = document.getElementById('mc-check');
  const showBtn = document.getElementById('mc-show');
  const nextBtn = document.getElementById('mc-next');
  const cardEl = document.querySelector('.mc-card');
  const controlsEl = document.querySelector('.mc-controls');
  const completedEl = document.getElementById('mc-completed');
  const completedTitleEl = document.getElementById('mc-completed-title');
  const completedTextEl = document.getElementById('mc-completed-text');
  const scoreTextEl = document.getElementById('mc-score-text');
  const restartBtn = document.getElementById('mc-restart');

  const activityTitle = window.MULTIPLE_CHOICE_TITLE || 'Multiple Choice';
  const returnTo = window.MULTIPLE_CHOICE_RETURN_TO || '';
  const activityId = window.MULTIPLE_CHOICE_ACTIVITY_ID || '';

  const completedSound = new Audio('../../hangman/assets/win.mp3');

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
  let checked = false;   /* true after Check is clicked */
  let revealed = false;
  let finished = false;
  let questionScores = questions.map(function () { return 0; });
  let activeListenText = '';
  let activeVoiceId = 'josh';

  if (completedTitleEl) {
    completedTitleEl.textContent = activityTitle;
  }

  if (completedTextEl) {
    completedTextEl.textContent = "You've completed " + activityTitle + '. Great job practicing.';
  }

  function playCompletedSound() {
    try {
      completedSound.pause();
      completedSound.currentTime = 0;
      completedSound.play();
    } catch (e) {}
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
    const correct = questionScores.reduce(function (sum, value) {
      return sum + (value ? 1 : 0);
    }, 0);
    const errors = Math.max(0, total - correct);
    const percent = total > 0 ? Math.round((correct / total) * 100) : 0;

    return {
      correct: correct,
      total: total,
      errors: errors,
      percent: percent,
    };
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

  function renderOptions() {
    const item = questions[index] || {};
    const correct = Number.isInteger(item.correct) ? item.correct : 0;
    const isImageOpts = item.option_type === 'image';
    const locked = checked || revealed || finished;

    optionsEl.innerHTML = '';

    safeOptions(item).forEach(function (optionText, optIndex) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'mc-option';

      if (selected === optIndex && !checked && !revealed) {
        button.classList.add('selected');
      }

      /* After Check: green = correct answer, red = wrong selection */
      if (checked) {
        if (optIndex === correct) {
          button.classList.add('correct');
        } else if (selected === optIndex) {
          button.classList.add('wrong');
        }
      }

      /* After Show Answer: highlight correct */
      if (revealed && optIndex === correct) {
        button.classList.add('correct');
      }

      if (locked) {
        button.disabled = true;
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
        if (locked) return;
        selected = optIndex;
        renderOptions();
        if (checkBtn) checkBtn.disabled = false;
      });

      optionsEl.appendChild(button);
    });
  }

  function loadQuestion() {
    const item = questions[index] || {};
    const cleanQuestion = normalizeQuestion(item);
    const isListen = item.question_type === 'listen';

    selected = null;
    checked  = false;
    revealed = false;
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

    if (checkBtn) { checkBtn.disabled = true; checkBtn.style.display = ''; }
    if (showBtn)  { showBtn.style.display = ''; showBtn.disabled = false; }
    if (nextBtn)  nextBtn.disabled = false;

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

    if (nextBtn) {
      nextBtn.textContent = index < questions.length - 1 ? 'Next →' : 'Finish';
    }
  }

  function checkAnswer() {
    if (finished || checked || revealed) return;
    if (selected === null) return;

    const item    = questions[index] || {};
    const correct = Number.isInteger(item.correct) ? item.correct : 0;
    const isRight = selected === correct;

    checked = true;
    renderOptions();

    if (feedbackEl) {
      feedbackEl.textContent = isRight ? '✅ Correct!' : '❌ Incorrect!';
      feedbackEl.className   = 'mc-feedback ' + (isRight ? 'good' : 'bad');
    }
    if (checkBtn) checkBtn.style.display = 'none';
    if (showBtn)  showBtn.style.display  = 'none';
  }

  function showAnswer() {
    if (finished) return;

    checked  = false;
    revealed = true;
    renderOptions();

    if (feedbackEl) {
      feedbackEl.textContent = 'Correct option highlighted.';
      feedbackEl.className   = 'mc-feedback';
    }
    if (checkBtn) checkBtn.style.display = 'none';
    if (showBtn)  showBtn.style.display  = 'none';
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

    if (scoreTextEl) {
      scoreTextEl.textContent = 'Score: ' + result.correct + ' / ' + result.total + ' (' + result.percent + '%)';
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

    const item    = questions[index] || {};
    const correct = Number.isInteger(item.correct) ? item.correct : 0;
    /* Score 1 only if user checked and got it right (not revealed) */
    const isCorrectPick = checked && selected === correct;
    questionScores[index] = isCorrectPick ? 1 : 0;

    if (index < questions.length - 1) {
      index += 1;
      loadQuestion();
      return;
    }

    showCompleted();
  }

  function restartActivity() {
    index    = 0;
    selected = null;
    checked  = false;
    revealed = false;
    finished = false;
    questionScores = questions.map(function () { return 0; });
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

  loadQuestion();
  if ((questions[0] || {}).question_type === 'listen') {
    speakWhenReady(activeListenText, activeVoiceId);
  }
});