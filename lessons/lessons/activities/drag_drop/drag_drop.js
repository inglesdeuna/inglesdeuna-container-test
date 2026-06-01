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
  var mediaNoteEl = document.getElementById('dd-media-note');
  var wordsEl = document.getElementById('dd-words');
  var listenBtn = document.getElementById('dd-listen');
  var checkBtn = document.getElementById('dd-check');
  var showBtn = document.getElementById('dd-show');
  var nextBtn = document.getElementById('dd-next');
  var feedbackEl = document.getElementById('dd-feedback');
  var activityEl = document.getElementById('dd-activity');
  var cardShellEl = document.getElementById('dd-card-shell');
  var cardBodyEl = document.getElementById('dd-card-body');
  var completedEl = document.getElementById('dd-completed');

  var completedTitleEl = document.getElementById('dd-completed-title');
  var completedTextEl = document.getElementById('dd-completed-text');
  var scoreTextEl = document.getElementById('dd-score-text');
  var restartBtn = document.getElementById('dd-restart');
  var scoreGridEl = document.getElementById('dd-score-grid');

  var scoreCompletedCorrectEl = document.getElementById('dd-s-correct');
  var scoreCompletedWrongEl = document.getElementById('dd-s-wrong');
  var scoreCompletedPctEl = document.getElementById('dd-s-pct');

  var winAudio = new Audio('../../hangman/assets/win.mp3');

  var activityTitle = window.DRAGDROP_TITLE || 'Drag & Drop';
  var returnTo = window.DRAGDROP_RETURN_TO || '';
  var activityId = window.DRAGDROP_ACTIVITY_ID || '';

  if (!questions.length) {
    if (instructionEl) instructionEl.textContent = 'No questions available.';
    return;
  }

  var index = 0;
  var answered = false;
  var dragging = null;
  var slotContents = {};
  var scoreVisible = false;
  var scores = questions.map(function () { return 0; });
  var slotCounts = questions.map(function () { return 1; });
  var reviewItems = questions.map(function () { return {}; });

  var ttsAbortController = null;
  var currentAudioElement = null;
  var currentAudioUrl = '';
  var currentAudioQuestionIndex = -1;
  var isTtsLoading = false;

  function clearTtsError() {
    if (!feedbackEl) return;
    var old = document.getElementById('dd-tts-error');
    if (old) old.remove();
  }

  function showTtsError(message) {
    if (!feedbackEl) return;
    clearTtsError();
    var box = document.createElement('div');
    box.id = 'dd-tts-error';
    box.style.marginTop = '8px';
    box.style.padding = '10px 12px';
    box.style.borderRadius = '10px';
    box.style.border = '1px solid #fecaca';
    box.style.background = '#fff1f2';
    box.style.color = '#991b1b';
    box.style.fontWeight = '800';
    box.style.fontSize = '13px';
    box.textContent = message || 'TTS failed.';
    feedbackEl.prepend(box);
  }

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

  function updateScoreCards(show, includeAll) {
    if (typeof show === 'boolean') {
      scoreVisible = show;
    }

    var totalChips = 0;
    var correctChips = 0;

    for (var i = 0; i < scores.length; i++) {
      if (includeAll || i < index || (i === index && answered)) {
        totalChips += slotCounts[i] || 1;
        correctChips += Math.max(0, scores[i] || 0);
      }
    }

    var wrongChips = totalChips - correctChips;
    var pct = totalChips > 0 ? Math.round((correctChips / totalChips) * 100) : 0;

    if (scoreCompletedCorrectEl) scoreCompletedCorrectEl.textContent = String(correctChips);
    if (scoreCompletedWrongEl) scoreCompletedWrongEl.textContent = String(wrongChips);
    if (scoreCompletedPctEl) scoreCompletedPctEl.textContent = String(pct) + '%';
    if (scoreGridEl) {
      scoreGridEl.classList.toggle('visible', !!scoreVisible);
    }
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

    currentAudioQuestionIndex = -1;
    isTtsLoading = false;

    if (listenBtn) {
      listenBtn.disabled = false;
      listenBtn.textContent = 'Listen';
    }

    clearTtsError();
  }

  function speakText(text, voiceId) {
    if (!text || !listenBtn) {
      return;
    }

    stopSpeech();
    clearTtsError();

    isTtsLoading = true;
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

        var contentType = String(response.headers.get('content-type') || '').toLowerCase();
        if (contentType.indexOf('audio/') !== -1) {
          return response.blob();
        }

        return response.text().then(function (textBody) {
          var parsedMessage = textBody;
          try {
            var json = JSON.parse(textBody);
            parsedMessage = json.error || textBody;
          } catch (e) {}
          throw new Error(parsedMessage || 'TTS returned non-audio response.');
        });
      })
      .then(function (audioBlob) {
        if (signal.aborted) {
          return;
        }

        currentAudioUrl = URL.createObjectURL(audioBlob);
        currentAudioElement = new Audio(currentAudioUrl);
        currentAudioQuestionIndex = index;

        currentAudioElement.onended = function () {
          isTtsLoading = false;
          if (listenBtn) {
            listenBtn.disabled = false;
            listenBtn.textContent = 'Listen';
          }
        };

        currentAudioElement.onerror = function () {
          isTtsLoading = false;
          if (listenBtn) {
            listenBtn.disabled = false;
            listenBtn.textContent = 'Listen';
          }
        };

        currentAudioElement.play()
          .then(function () {
            isTtsLoading = false;
            if (listenBtn) {
              listenBtn.disabled = false;
              listenBtn.textContent = 'Pause';
            }
          })
          .catch(function () {
            isTtsLoading = false;
            showTtsError('ElevenLabs audio could not be played in this browser.');
            if (listenBtn) {
              listenBtn.textContent = 'Listen';
            }
          });
      })
      .catch(function (error) {
        if (signal.aborted) {
          return;
        }

        isTtsLoading = false;

        var msg = 'ElevenLabs TTS error. Check API key/voice configuration.';
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
          msg = 'ElevenLabs: ' + error.message.trim();
        }
        showTtsError(msg);

        if (listenBtn) {
          listenBtn.disabled = false;
          listenBtn.textContent = 'Listen';
        }
      });
  }

  function togglePauseResume() {
    if (!currentAudioElement || currentAudioQuestionIndex !== index) {
      return false;
    }

    if (currentAudioElement.ended) {
      return false;
    }

    if (currentAudioElement.paused) {
      currentAudioElement.play()
        .then(function () {
          if (listenBtn) {
            listenBtn.textContent = 'Pause';
          }
        })
        .catch(function () {
          showTtsError('Unable to resume ElevenLabs audio.');
          if (listenBtn) {
            listenBtn.textContent = 'Listen';
          }
        });
      return true;
    }

    currentAudioElement.pause();
    if (listenBtn) {
      listenBtn.textContent = 'Resume';
    }
    return true;
  }

  function renderImage(q) {
    if (!mediaEl || !imageEl || !promptRowEl) {
      return;
    }

    var imageUrl = q && typeof q.image === 'string' ? q.image.trim() : '';
    var candidates = [];

    function addCandidate(url) {
      if (!url) return;
      if (candidates.indexOf(url) === -1) candidates.push(url);
    }

    function buildCandidates(raw) {
      var normalized = raw.trim();
      if (!normalized) return [];

      addCandidate(normalized);

      if (/^http:\/\//i.test(normalized)) {
        addCandidate(normalized.replace(/^http:\/\//i, 'https://'));
      }

      if (!/^https?:\/\//i.test(normalized) && normalized.indexOf('data:') !== 0) {
        var noLead = normalized.replace(/^\/+/, '');
        addCandidate('/' + noLead);

        if (noLead.indexOf('uploads/') === 0) {
          addCandidate('/lessons/lessons/' + noLead);
        }

        if (noLead.indexOf('lessons/lessons/uploads/') === 0) {
          addCandidate('/' + noLead);
          addCandidate('/' + noLead.replace(/^lessons\/lessons\//, ''));
        }
      }

      return candidates;
    }

    function hideImagePanel() {
      mediaEl.style.display = 'none';
      mediaEl.setAttribute('aria-hidden', 'true');
      imageEl.removeAttribute('src');
      if (mediaNoteEl) mediaNoteEl.style.display = 'none';
      promptRowEl.classList.remove('dd-prompt-row--with-image');
    }

    function showImagePanel() {
      mediaEl.style.display = 'block';
      mediaEl.setAttribute('aria-hidden', 'false');
      promptRowEl.classList.add('dd-prompt-row--with-image');
    }

    if (!imageUrl) {
      hideImagePanel();
      return;
    }

    var queue = buildCandidates(imageUrl);
    var cursor = 0;

    if (!queue.length) {
      hideImagePanel();
      return;
    }

    showImagePanel();
    if (mediaNoteEl) mediaNoteEl.style.display = 'none';

    imageEl.onerror = function () {
      cursor += 1;
      if (cursor < queue.length) {
        imageEl.src = queue[cursor];
        return;
      }

      imageEl.removeAttribute('src');
      if (mediaNoteEl) mediaNoteEl.style.display = 'block';
      imageEl.onerror = null;
    };

    imageEl.onload = function () {
      if (mediaNoteEl) mediaNoteEl.style.display = 'none';
      imageEl.onload = null;
    };

    imageEl.src = queue[cursor];
  }

  function renderInstruction(q) {
    if (!instructionEl) return;
    instructionEl.innerHTML = '';

    var text = q.instruction || '';
    var parts = text.split('___');

    parts.forEach(function (part, i) {
      if (part) instructionEl.appendChild(document.createTextNode(part));

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

  function decodeHtmlEntities(value) {
    var text = document.createElement('textarea');
    text.innerHTML = String(value == null ? '' : value);
    return text.value;
  }

  function normalizeForCompare(value) {
    var s = decodeHtmlEntities(value);
    s = s.replace(/\u00A0/g, ' ');
    s = s.replace(/[\u2018\u2019\u02BC\u2032]/g, "'");
    s = s.replace(/[\u201C\u201D\u2033]/g, '"');
    s = s.replace(/[\u2010-\u2015]/g, '-');

    if (typeof s.normalize === 'function') {
      s = s.normalize('NFKC');
    }

    s = s.replace(/\s+/g, ' ').trim().toLowerCase();
    s = s.replace(/^[\s"'“”‘’.,;:!?()\[\]{}]+|[\s"'“”‘’.,;:!?()\[\]{}]+$/g, '');
    return s;
  }

  function bindDropZone(drop, slotIndex) {
    drop.addEventListener('dragover', function (e) {
      e.preventDefault();
      if (!answered) drop.classList.add('dd-inline-drop--over');
    });

    drop.addEventListener('dragleave', function () {
      drop.classList.remove('dd-inline-drop--over');
    });

    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      drop.classList.remove('dd-inline-drop--over');

      if (!dragging || answered) return;

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
      if (answered) return;
      if (slotContents[slotIndex] === undefined) return;

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
        var expected = slots[i] ? normalizeForCompare(slots[i].answer || '') : '';
        var given = slotContents[i] !== undefined ? normalizeForCompare(slotContents[i]) : '';
        var isRight = given !== '' && given === expected;

        if (isRight) correct++;

        drop.classList.remove('dd-inline-drop--filled');
        drop.classList.add(isRight ? 'dd-inline-drop--correct' : 'dd-inline-drop--wrong');
      });
    }

    var allRight = correct === slots.length && slots.length > 0;
    scores[index] = correct;
    slotCounts[index] = slots.length || 1;
    updateScoreCards(true, false);

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
    slotCounts[index] = slots.length || 1;
    updateScoreCards(true, false);

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

    if (cardShellEl) cardShellEl.style.display = 'none';
    if (feedbackEl) {
      feedbackEl.textContent = '';
      feedbackEl.className = '';
    }
    completedEl.style.display = 'block';
    completedEl.classList.add('active');

    var total = 0;
    var correct = 0;
    for (var i = 0; i < scores.length; i++) {
      total += slotCounts[i] || 1;
      correct += Math.max(0, scores[i] || 0);
    }

    var wrong = total - correct;
    var pct = total > 0 ? Math.round((correct / total) * 100) : 0;

    updateScoreCards(true, true);

    if (completedTitleEl) {
      completedTitleEl.textContent = activityTitle;
    }
    if (completedTextEl) {
      completedTextEl.textContent = "You've completed " + activityTitle + '. Great job practicing.';
    }
    if (scoreTextEl) {
      scoreTextEl.textContent = String(correct) + ' correct · ' + String(wrong) + ' wrong · ' + String(pct) + '%';
    }

    try {
      winAudio.pause();
      winAudio.currentTime = 0;
      winAudio.play();
    } catch (e) {}

    if (returnTo && activityId) {
      var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
      fetch(
        returnTo + sep +
        'activity_percent=' + pct +
        '&activity_errors=' + wrong +
        '&activity_total=' + total +
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
    slotCounts = questions.map(function () { return 1; });
    reviewItems = questions.map(function () { return {}; });
    scoreVisible = false;

    updateScoreCards(false, false);
    clearTtsError();
    loadQuestion();
  }

  function loadQuestion() {
    var q = questions[index] || {};

    answered = false;
    slotContents = {};
    dragging = null;

    if (completedEl) {
      completedEl.style.display = 'none';
      completedEl.classList.remove('active');
    }
    if (cardShellEl) cardShellEl.style.display = '';
    if (cardBodyEl) cardBodyEl.style.display = '';
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
      if (!q.listen_enabled) return;
      if (isTtsLoading) return;

      if (togglePauseResume()) return;

      speakText((q.tts_text || '').trim(), q.voice_id || 'nzFihrBIvB34imQBuxub');
    });
  }

  if (checkBtn) checkBtn.addEventListener('click', checkAnswers);
  if (showBtn) showBtn.addEventListener('click', showAnswers);
  if (nextBtn) nextBtn.addEventListener('click', nextQuestion);
  if (restartBtn) restartBtn.addEventListener('click', restartActivity);

  loadQuestion();
});
