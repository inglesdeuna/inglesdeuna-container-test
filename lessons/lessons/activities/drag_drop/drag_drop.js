document.addEventListener('DOMContentLoaded', function () {
  var AF = window.ActivityFeedback;
  var questions = Array.isArray(window.DRAGDROP_DATA) ? window.DRAGDROP_DATA : [];

  var progressLabelEl = document.getElementById('dd-progress-label');
  var progressFillEl = document.getElementById('dd-progress-fill');
  var progressBadgeEl = document.getElementById('dd-progress-badge');
  var promptRowEl = document.getElementById('dd-prompt-row');
  var instructionEl = document.getElementById('dd-instruction');
  var mediaEl = document.getElementById('dd-media');
  var imageEl = document.getElementById('dd-image');
  var wordsEl = document.getElementById('dd-words');
  var listenBtn = document.getElementById('dd-listen');
  var checkBtn = document.getElementById('dd-check');
  var showBtn = document.getElementById('dd-show');
  var nextBtn = document.getElementById('dd-next');
  var feedbackEl = document.getElementById('dd-feedback');
  var activityEl = document.getElementById('dd-activity');
  var completedEl = document.getElementById('dd-completed');
  var winAudio = new Audio('../../hangman/assets/win.mp3');

  var activityTitle = window.DRAGDROP_TITLE || 'Drag & Drop';
  var returnTo = window.DRAGDROP_RETURN_TO || '';
  var activityId = window.DRAGDROP_ACTIVITY_ID || '';

  if (!questions.length) {
    if (instructionEl) {
      instructionEl.textContent = 'No questions available.';
    }
    return;
  }

  var index = 0;
  var answered = false;
  var dragging = null;
  var slotContents = {};
  var scores = questions.map(function () { return 0; });
  var reviewItems = questions.map(function () { return {}; });
  var ttsAbortController = null;
  var currentAudioElement = null;
  var currentAudioUrl = '';

  function shuffle(arr) {
    var a = arr.slice();
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = a[i];
      a[i] = a[j];
      a[j] = t;
    }
    return a;
  }

  function updateProgress() {
    var total = questions.length;
    var current = index + 1;
    var pct = Math.round((current / total) * 100);

    if (progressLabelEl) progressLabelEl.textContent = current + ' / ' + total;
    if (progressBadgeEl) progressBadgeEl.textContent = 'Q ' + current + ' of ' + total;
    if (progressFillEl) progressFillEl.style.width = pct + '%';
  }

  function stopSpeech() {
    if (ttsAbortController) {
      ttsAbortController.abort();
      ttsAbortController = null;
    }

    if (currentAudioElement) {
      try {
        currentAudioElement.pause();
        currentAudioElement.currentTime = 0;
      } catch (e) {}
      currentAudioElement = null;
    }

    if (currentAudioUrl) {
      URL.revokeObjectURL(currentAudioUrl);
      currentAudioUrl = '';
    }

    if (listenBtn) {
      listenBtn.disabled = false;
      listenBtn.textContent = 'Listen';
    }
  }

  function speakText(text, voiceId) {
    if (!text || !listenBtn) {
      return;
    }

    stopSpeech();

    listenBtn.disabled = true;
    listenBtn.textContent = 'Loading...';

    var fd = new FormData();
    fd.append('text', text);
    fd.append('voice_id', voiceId || 'nzFihrBIvB34imQBuxub');

    ttsAbortController = new AbortController();
    var signal = ttsAbortController.signal;

    fetch('tts.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      signal: signal
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

        currentAudioUrl = URL.createObjectURL(audioBlob);
        currentAudioElement = new Audio(currentAudioUrl);

        currentAudioElement.onended = function () {
          if (listenBtn) {
            listenBtn.disabled = false;
            listenBtn.textContent = 'Listen';
          }
        };

        currentAudioElement.onerror = function () {
          if (listenBtn) {
            listenBtn.disabled = false;
            listenBtn.textContent = 'Listen';
          }
        };

        if (listenBtn) {
          listenBtn.disabled = false;
          listenBtn.textContent = 'Playing...';
        }

        currentAudioElement.play()
          .then(function () {})
          .catch(function () {
            if (listenBtn) {
              listenBtn.textContent = 'Listen';
            }
          });
      })
      .catch(function () {
        if (signal.aborted) {
          return;
        }
        if (listenBtn) {
          listenBtn.disabled = false;
          listenBtn.textContent = 'Listen';
        }
      });
  }

  function renderImage(q) {
    if (!mediaEl || !imageEl || !promptRowEl) {
      return;
    }

    var imageUrl = q && typeof q.image === 'string' ? q.image.trim() : '';

    if (!imageUrl) {
      mediaEl.style.display = 'none';
      mediaEl.setAttribute('aria-hidden', 'true');
      imageEl.removeAttribute('src');
      promptRowEl.classList.remove('dd-prompt-row--with-image');
      return;
    }

    imageEl.onerror = function () {
      mediaEl.style.display = 'none';
      mediaEl.setAttribute('aria-hidden', 'true');
      promptRowEl.classList.remove('dd-prompt-row--with-image');
      imageEl.onerror = null;
    };

    imageEl.src = imageUrl;
    mediaEl.style.display = 'block';
    mediaEl.setAttribute('aria-hidden', 'false');
    promptRowEl.classList.add('dd-prompt-row--with-image');
  }

  function renderInstruction(q) {
    if (!instructionEl) return;
    instructionEl.innerHTML = '';

    var text = q.instruction || '';
    var parts = text.split('___');

    parts.forEach(function (part, i) {
      if (part) {
        instructionEl.appendChild(document.createTextNode(part));
      }

      if (i < parts.length - 1) {
        var drop = document.createElement('span');
        drop.className = 'dd-inline-drop';
        drop.dataset.slot = String(i);
        drop.textContent = '___';
        bindDropZone(drop, i);
        instructionEl.appendChild(drop);
      }
    });
  }

  function renderWords(q) {
    if (!wordsEl) return;
    wordsEl.innerHTML = '';

    var words = shuffle(Array.isArray(q.words) ? q.words : []);
    words.forEach(addWordChip);
  }

  function addWordChip(word) {
    if (!wordsEl) return;

    var chip = document.createElement('div');
    chip.className = 'dd-chip';
    chip.draggable = true;
    chip.dataset.word = word;
    chip.textContent = word;

    chip.addEventListener('dragstart', function (e) {
      dragging = chip;
      chip.classList.add('dd-chip--dragging');
      e.dataTransfer.effectAllowed = 'move';
    });

    chip.addEventListener('dragend', function () {
      chip.classList.remove('dd-chip--dragging');
      dragging = null;
    });

    wordsEl.appendChild(chip);
  }

  function bindDropZone(drop, slotIndex) {
    drop.addEventListener('dragover', function (e) {
      e.preventDefault();
      if (!answered) {
        drop.classList.add('dd-inline-drop--over');
      }
    });

    drop.addEventListener('dragleave', function () {
      drop.classList.remove('dd-inline-drop--over');
    });

    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      drop.classList.remove('dd-inline-drop--over');

      if (!dragging || answered) {
        return;
      }

      var word = dragging.dataset.word;

      if (slotContents[slotIndex] !== undefined) {
        addWordChip(slotContents[slotIndex]);
      }

      slotContents[slotIndex] = word;
      drop.textContent = word;
      drop.classList.add('dd-inline-drop--filled');

      if (dragging.parentNode) {
        dragging.parentNode.removeChild(dragging);
      }
      dragging = null;
    });

    drop.addEventListener('click', function () {
      if (answered) {
        return;
      }

      if (slotContents[slotIndex] === undefined) {
        return;
      }

      var word = slotContents[slotIndex];
      delete slotContents[slotIndex];
      drop.textContent = '___';
      drop.classList.remove('dd-inline-drop--filled');
      addWordChip(word);
    });
  }

  function checkAnswers() {
    if (answered) return;

    var q = questions[index] || {};
    var slots = Array.isArray(q.slots) ? q.slots : [];
    var correct = 0;
    answered = true;

    if (instructionEl) {
      instructionEl.querySelectorAll('.dd-inline-drop').forEach(function (drop, i) {
        var expected = slots[i] ? String(slots[i].answer || '').trim().toLowerCase() : '';
        var given = slotContents[i] !== undefined ? String(slotContents[i]).trim().toLowerCase() : '';
        var isRight = given !== '' && given === expected;

        if (isRight) {
          correct++;
        }

        drop.classList.remove('dd-inline-drop--filled');
        drop.classList.add(isRight ? 'dd-inline-drop--correct' : 'dd-inline-drop--wrong');
      });
    }

    var allRight = correct === slots.length && slots.length > 0;
    scores[index] = allRight ? 1 : 0;

    reviewItems[index] = {
      question: q.tts_text || q.instruction || ('Question ' + (index + 1)),
      yourAnswer: correct + '/' + slots.length + ' correct',
      correctAnswer: slots.map(function (s) { return s.answer; }).join(', '),
      score: scores[index]
    };

    if (feedbackEl) {
      AF.showFeedback(feedbackEl, allRight, slots.map(function (s) { return s.answer; }).join(', '), false);
    }

    if (checkBtn) checkBtn.disabled = true;
    if (showBtn) showBtn.style.display = 'none';
    if (nextBtn) nextBtn.disabled = false;
  }

  function showAnswers() {
    if (answered) return;

    var q = questions[index] || {};
    var slots = Array.isArray(q.slots) ? q.slots : [];

    answered = true;
    scores[index] = -1;

    if (instructionEl) {
      instructionEl.querySelectorAll('.dd-inline-drop').forEach(function (drop, i) {
        var expected = slots[i] ? slots[i].answer || '' : '';
        drop.textContent = expected;
        drop.classList.remove('dd-inline-drop--filled');
        drop.classList.add('dd-inline-drop--revealed');
      });
    }

    reviewItems[index] = {
      question: q.tts_text || q.instruction || '',
      yourAnswer: '(revealed)',
      correctAnswer: slots.map(function (s) { return s.answer; }).join(', '),
      score: -1
    };

    if (feedbackEl) {
      AF.showFeedback(feedbackEl, false, null, true);
    }

    if (checkBtn) checkBtn.disabled = true;
    if (showBtn) showBtn.style.display = 'none';
    if (nextBtn) nextBtn.disabled = false;
  }

  function showCompleted() {
    if (!completedEl) return;

    stopSpeech();

    if (activityEl) activityEl.style.display = 'none';
    completedEl.style.display = '';

    AF.showCompleted({
      target: completedEl,
      scores: scores,
      title: activityTitle,
      activityType: 'Drag & Drop',
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
      fetch(
        returnTo + sep +
        'activity_percent=' + result.percent +
        '&activity_errors=' + result.wrong +
        '&activity_total=' + result.total +
        '&activity_id=' + encodeURIComponent(activityId) +
        '&activity_type=drag_drop',
        { method: 'GET', credentials: 'same-origin', cache: 'no-store' }
      ).catch(function () {});
    }
  }

  function nextQuestion() {
    stopSpeech();

    if (index < questions.length - 1) {
      index++;
      loadQuestion();
    } else {
      showCompleted();
    }
  }

  function restartActivity() {
    stopSpeech();

    index = 0;
    scores = questions.map(function () { return 0; });
    reviewItems = questions.map(function () { return {}; });
    loadQuestion();
  }

  function loadQuestion() {
    var q = questions[index] || {};

    answered = false;
    slotContents = {};
    dragging = null;

    if (completedEl) completedEl.style.display = 'none';
    if (activityEl) activityEl.style.display = '';
    if (feedbackEl) AF.clearFeedback(feedbackEl);

    updateProgress();
    renderInstruction(q);
    renderImage(q);
    renderWords(q);

    var canListen = !!q.listen_enabled && !!(q.tts_text || '').trim();
    if (listenBtn) {
      listenBtn.disabled = !canListen;
      listenBtn.textContent = canListen ? 'Listen' : 'Listen Off';
    }

    if (checkBtn) checkBtn.disabled = false;
    if (showBtn) {
      showBtn.style.display = '';
      showBtn.disabled = false;
    }
    if (nextBtn) {
      nextBtn.disabled = true;
      nextBtn.textContent = index < questions.length - 1 ? 'Next' : 'Finish';
    }
  }

  if (listenBtn) {
    listenBtn.addEventListener('click', function () {
      var q = questions[index] || {};
      if (!q.listen_enabled) {
        return;
      }
      speakText((q.tts_text || '').trim(), q.voice_id || 'nzFihrBIvB34imQBuxub');
    });
  }

  if (checkBtn) checkBtn.addEventListener('click', checkAnswers);
  if (showBtn) showBtn.addEventListener('click', showAnswers);
  if (nextBtn) nextBtn.addEventListener('click', nextQuestion);

  loadQuestion();
});
