document.addEventListener('DOMContentLoaded', function () {
  const allQuestions = Array.isArray(window.MULTIPLE_CHOICE_DATA) ? window.MULTIPLE_CHOICE_DATA : [];
  const questionRatio = Math.max(0.1, Math.min(1, Number(window.MULTIPLE_CHOICE_RATIO || 0.75)));

  const statusEl = document.getElementById('mc-status');
  const answeredEl = document.getElementById('mc-answered');
  const totalEl = document.getElementById('mc-total');
  const progressFillEl = document.getElementById('mc-progress-fill');
  const listEl = document.getElementById('mc-list');
  const feedbackEl = document.getElementById('mc-feedback');
  const finishBtn = document.getElementById('mc-finish');
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

  if (!allQuestions.length) {
    if (statusEl) {
      statusEl.textContent = 'No questions available.';
    }
    if (finishBtn) {
      finishBtn.disabled = true;
    }
    return;
  }

  function shuffle(list) {
    const cloned = list.slice();
    for (let i = cloned.length - 1; i > 0; i -= 1) {
      const j = Math.floor(Math.random() * (i + 1));
      const tmp = cloned[i];
      cloned[i] = cloned[j];
      cloned[j] = tmp;
    }
    return cloned;
  }

  function buildExamQuestions(rawQuestions) {
    const pool = shuffle(rawQuestions);
    const computedLimit = Math.max(1, Math.ceil(pool.length * questionRatio));
    const selected = pool.slice(0, Math.min(computedLimit, pool.length));

    return selected.map(function (q) {
      const options = Array.isArray(q.options) ? q.options : [];
      const correctIndex = Number.isInteger(q.correct) ? q.correct : 0;
      const optionObjects = options.map(function (text, idx) {
        return {
          text: String(text || ''),
          isCorrect: idx === correctIndex,
        };
      });

      return {
        question: String(q.question || ''),
        image: String(q.image || ''),
        options: shuffle(optionObjects),
      };
    });
  }

  let questions = buildExamQuestions(allQuestions);

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

  function countAnsweredAndPaint() {
    let answered = 0;

    questions.forEach(function (_q, idx) {
      const card = listEl.querySelector('.mc-question-card[data-index="' + idx + '"]');
      const checked = document.querySelector('input[name="mc_q_' + idx + '"]:checked');
      const hasAnswer = !!checked;

      if (hasAnswer) {
        answered += 1;
      }

      if (card) {
        card.classList.toggle('unanswered', !hasAnswer);
      }
    });

    return answered;
  }

  function updateProgress() {
    const total = questions.length;
    const answered = countAnsweredAndPaint();
    const pct = total > 0 ? Math.round((answered / total) * 100) : 0;

    if (answeredEl) {
      answeredEl.textContent = String(answered);
    }

    if (totalEl) {
      totalEl.textContent = String(total);
    }

    if (progressFillEl) {
      progressFillEl.style.width = String(pct) + '%';
    }

    return {
      answered: answered,
      total: total,
    };
  }

  function renderExam() {
    if (!listEl) {
      return;
    }

    listEl.innerHTML = '';

    questions.forEach(function (item, idx) {
      const card = document.createElement('div');
      card.className = 'mc-question-card';
      card.setAttribute('data-index', String(idx));

      const questionEl = document.createElement('div');
      questionEl.className = 'mc-question';
      questionEl.textContent = (idx + 1) + '. ' + item.question;
      card.appendChild(questionEl);

      if (item.image) {
        const image = document.createElement('img');
        image.className = 'mc-image';
        image.style.display = 'block';
        image.src = item.image;
        image.alt = '';
        card.appendChild(image);
      }

      const optionsWrap = document.createElement('div');
      optionsWrap.className = 'mc-options';

      item.options.forEach(function (option, optIdx) {
        const label = document.createElement('label');
        label.className = 'mc-option';

        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'mc_q_' + idx;
        radio.value = String(optIdx);

        const span = document.createElement('span');
        span.textContent = option.text;

        label.appendChild(radio);
        label.appendChild(span);
        optionsWrap.appendChild(label);
      });

      card.appendChild(optionsWrap);
      listEl.appendChild(card);
    });

    updateProgress();
  }

  function focusFirstMissing() {
    const first = listEl.querySelector('.mc-question-card.unanswered');
    if (!first) {
      return;
    }

    try {
      first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } catch (e) {
      first.scrollIntoView();
    }
  }

  function computeScore() {
    let correct = 0;
    const total = questions.length;

    questions.forEach(function (question, idx) {
      const checked = document.querySelector('input[name="mc_q_' + idx + '"]:checked');
      const selected = checked ? parseInt(checked.value || '-1', 10) : -1;
      if (selected >= 0 && question.options[selected] && question.options[selected].isCorrect) {
        correct += 1;
      }
    });

    const errors = Math.max(0, total - correct);
    const percent = total > 0 ? Math.round((correct / total) * 100) : 0;

    return {
      correct: correct,
      total: total,
      errors: errors,
      percent: percent,
    };
  }

  async function finishExam() {
    const progress = updateProgress();
    if (progress.answered < progress.total) {
      if (feedbackEl) {
        feedbackEl.textContent = 'Answer all questions before finishing.';
        feedbackEl.className = 'mc-feedback bad';
      }
      focusFirstMissing();
      return;
    }

    const result = computeScore();

    if (feedbackEl) {
      feedbackEl.textContent = '';
      feedbackEl.className = 'mc-feedback';
    }

    if (listEl) {
      listEl.style.display = 'none';
    }

    if (controlsEl) {
      controlsEl.style.display = 'none';
    }

    if (statusEl) {
      statusEl.textContent = 'Exam completed';
    }

    if (scoreTextEl) {
      scoreTextEl.textContent = 'Score: ' + result.correct + ' / ' + result.total + ' (' + result.percent + '%)';
    }

    if (completedEl) {
      completedEl.classList.add('active');
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

  function restartExam() {
    questions = buildExamQuestions(allQuestions);

    if (completedEl) {
      completedEl.classList.remove('active');
    }

    if (listEl) {
      listEl.style.display = '';
    }

    if (controlsEl) {
      controlsEl.style.display = 'flex';
    }

    if (statusEl) {
      statusEl.textContent = 'Answered: 0/' + String(questions.length);
    }

    if (feedbackEl) {
      feedbackEl.textContent = '';
      feedbackEl.className = 'mc-feedback';
    }

    renderExam();
  }

  if (listEl) {
    listEl.addEventListener('change', function (event) {
      const target = event.target;
      if (target && target.matches('input[type="radio"]')) {
        updateProgress();
      }
    });
  }

  if (finishBtn) {
    finishBtn.addEventListener('click', finishExam);
  }

  if (restartBtn) {
    restartBtn.addEventListener('click', restartExam);
  }

  renderExam();
});
