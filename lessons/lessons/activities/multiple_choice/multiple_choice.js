document.addEventListener('DOMContentLoaded', function () {
  const questions = Array.isArray(window.MULTIPLE_CHOICE_DATA) ? window.MULTIPLE_CHOICE_DATA : [];

  const statusEl = document.getElementById('mc-status');
  const questionEl = document.getElementById('mc-question');
  const imageEl = document.getElementById('mc-image');
  const optionsEl = document.getElementById('mc-options');
  const feedbackEl = document.getElementById('mc-feedback');
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
    return;
  }

  let index = 0;
  let selected = null;
  let checked = false;
  let finished = false;
  let questionScores = questions.map(function () { return 0; });

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

  function checkAnswer() {
    if (finished) {
      return;
    }

    const item = questions[index] || {};
    const correct = Number.isInteger(item.correct) ? item.correct : 0;
    const options = optionsEl.querySelectorAll('.mc-option');

    if (selected === null) {
      feedbackEl.textContent = 'Select an option first.';
      feedbackEl.className = 'mc-feedback bad';
      return;
    }

    checked = true;

    Array.prototype.forEach.call(options, function (node, optIndex) {
      node.classList.remove('correct', 'wrong');

      if (optIndex === correct) {
        node.classList.add('correct');
      }

      if (optIndex === selected && selected !== correct) {
        node.classList.add('wrong');
      }
    });

    if (selected === correct) {
      questionScores[index] = 1;
      feedbackEl.textContent = '\u2714 Right';
      feedbackEl.className = 'mc-feedback good';
    } else {
      questionScores[index] = 0;
      feedbackEl.textContent = '\u2718 Wrong';
      feedbackEl.className = 'mc-feedback bad';
    }
  }

  function loadQuestion() {
    const item = questions[index] || {};

    selected = null;
    checked = false;
    finished = false;

    if (completedEl) {
      completedEl.classList.remove('active');
    }

    if (cardEl) {
      cardEl.style.display = 'block';
    }

    if (controlsEl) {
      controlsEl.style.display = 'flex';
    }

    feedbackEl.textContent = '';
    feedbackEl.className = 'mc-feedback';

    statusEl.textContent = 'Question ' + (index + 1) + ' of ' + questions.length;
    const rawQuestion = String(item.question || '');
    const cleanQuestion = rawQuestion.replace(/^Choose the correct basic command:\s*/i, '');

    const isListen = item.question_type === 'listen';
    const isImageOpts = item.option_type === 'image';

    if (isListen) {
      questionEl.innerHTML = '';
      const listenBtn = document.createElement('button');
      listenBtn.type = 'button';
      listenBtn.className = 'mc-listen-btn';
      listenBtn.innerHTML = '&#128266; Listen';
      listenBtn.addEventListener('click', function () { speakText(cleanQuestion); });
      questionEl.appendChild(listenBtn);
      // auto-play on load
      setTimeout(function () { speakText(cleanQuestion); }, 300);
    } else {
      questionEl.textContent = cleanQuestion;
    }

    if (item.image) {
      imageEl.style.display = 'block';
      imageEl.src = item.image;
    } else {
      imageEl.style.display = 'none';
      imageEl.removeAttribute('src');
    }

    optionsEl.innerHTML = '';
    optionsEl.classList.toggle('mc-options-images', isImageOpts);

    safeOptions(item).forEach(function (optionText, optIndex) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'mc-option';

      if (isImageOpts && optionText !== '') {
        const img = document.createElement('img');
        img.src = optionText;
        img.alt = 'Option ' + String.fromCharCode(65 + optIndex);
        img.className = 'mc-option-img';
        button.appendChild(img);
      } else {
        button.textContent = optionText;
      }

      button.addEventListener('click', function () {
        if (checked || finished) {
          return;
        }

        selected = optIndex;

        Array.prototype.forEach.call(optionsEl.querySelectorAll('.mc-option'), function (node) {
          node.classList.remove('selected');
        });

        button.classList.add('selected');
        checkAnswer();
      });

      optionsEl.appendChild(button);
    });

    if (showBtn) {
      showBtn.disabled = false;
    }

    if (nextBtn) {
      nextBtn.disabled = false;
      nextBtn.textContent = index < questions.length - 1 ? 'Next' : 'Finish';
    }
  }

  function showAnswer() {
    if (finished) {
      return;
    }

    const item = questions[index] || {};
    const correct = Number.isInteger(item.correct) ? item.correct : 0;
    const options = optionsEl.querySelectorAll('.mc-option');

    checked = true;
    selected = correct;

    Array.prototype.forEach.call(options, function (node, optIndex) {
      node.classList.remove('selected', 'wrong');
      if (optIndex === correct) {
        node.classList.add('selected', 'correct');
      }
    });

    feedbackEl.textContent = 'Show The Answer';
    feedbackEl.className = 'mc-feedback good';
    if (questionScores[index] !== 1) {
      questionScores[index] = 0;
    }
  }

  async function showCompleted() {
    finished = true;
    feedbackEl.textContent = '';
    feedbackEl.className = 'mc-feedback';

    const result = computeScore();

    if (cardEl) {
      cardEl.style.display = 'none';
    }

    if (controlsEl) {
      controlsEl.style.display = 'none';
    }

    if (statusEl) {
      statusEl.textContent = 'Completed';
    }

    if (completedEl) {
      completedEl.classList.add('active');
    }

    if (scoreTextEl) {
      scoreTextEl.textContent = 'Score: ' + result.correct + ' / ' + result.total + ' (' + result.percent + '%)';
    }

    if (showBtn) {
      showBtn.disabled = true;
      showBtn.textContent = 'Show Answer';
    }

    if (nextBtn) {
      nextBtn.disabled = true;
      nextBtn.textContent = 'Completed';
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

    if (index < questions.length - 1) {
      index += 1;
      loadQuestion();
      return;
    }

    showCompleted();
  }

  function restartActivity() {
    index = 0;
    questionScores = questions.map(function () { return 0; });
    loadQuestion();
  }

  showBtn.addEventListener('click', showAnswer);
  nextBtn.addEventListener('click', nextQuestion);

  if (restartBtn) {
    restartBtn.addEventListener('click', restartActivity);
  }

  loadQuestion();

  function speakText(text) {
    if (!text || !window.speechSynthesis) return;
    window.speechSynthesis.cancel();
    var utt = new SpeechSynthesisUtterance(text);
    utt.lang = 'en-US';
    utt.rate = 0.9;
    window.speechSynthesis.speak(utt);
  }
});
