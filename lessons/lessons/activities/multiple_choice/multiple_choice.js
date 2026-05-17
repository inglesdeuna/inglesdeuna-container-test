document.addEventListener('DOMContentLoaded', function () {
  var AF = window.ActivityFeedback;
  var questions = Array.isArray(window.MULTIPLE_CHOICE_DATA) ? window.MULTIPLE_CHOICE_DATA : [];

  var progressLabelEl = document.getElementById('mc-progress-label');
  var progressFillEl  = document.getElementById('mc-progress-fill');
  var progressBadgeEl = document.getElementById('mc-progress-badge');
  var questionEl      = document.getElementById('mc-question');
  var imageBoxEl      = document.getElementById('mc-image-box');
  var imageEl         = document.getElementById('mc-image');
  var optionsEl       = document.getElementById('mc-options');
  var feedbackEl      = document.getElementById('mc-feedback');
  var listenBtn       = document.getElementById('mc-listen');
  var checkBtn        = document.getElementById('mc-check');
  var showBtn         = document.getElementById('mc-show');
  var nextBtn         = document.getElementById('mc-next');
  var cardEl          = document.querySelector('.mc-card');
  var controlsEl      = document.querySelector('.mc-controls');
  var completedEl     = document.getElementById('mc-completed');
  var winAudio        = new Audio('../../hangman/assets/win.mp3');

  var activityTitle = window.MULTIPLE_CHOICE_TITLE || 'Multiple Choice';
  var returnTo      = window.MULTIPLE_CHOICE_RETURN_TO || '';
  var activityId    = window.MULTIPLE_CHOICE_ACTIVITY_ID || '';

  if (!questions.length) {
    if (questionEl) questionEl.textContent = 'No questions available.';
    [showBtn, nextBtn, listenBtn].forEach(function(b){ if(b) b.disabled = true; });
    return;
  }

  var index       = 0;
  var selected    = null;
  var revealed    = false;
  var answered    = false;
  var scores      = questions.map(function(){ return 0; }); /* 1=correct 0=wrong -1=revealed */
  var reviewItems = questions.map(function(){ return {}; });
  var activeListenText = '';
  var activeVoiceId    = 'josh';

  /* ── helpers ── */
  function safeOptions(item) {
    return item && Array.isArray(item.options) ? item.options : [];
  }

  function normalizeQuestion(item) {
    return String((item && item.question) || '').replace(/^Choose the correct basic command:s*/i,'').trim();
  }

  function updateProgress() {
    var total   = questions.length;
    var current = index + 1;
    var pct     = total > 0 ? Math.round((current / total) * 100) : 0;
    if (progressLabelEl) progressLabelEl.textContent = current + ' / ' + total;
    if (progressBadgeEl) progressBadgeEl.textContent = 'Q ' + current + ' of ' + total;
    if (progressFillEl)  progressFillEl.style.width  = pct + '%';
  }

  function renderOptions() {
    var item    = questions[index] || {};
    var correct = Number.isInteger(item.correct) ? item.correct : 0;
    var isImg   = item.option_type === 'image';

    optionsEl.innerHTML = '';
    safeOptions(item).forEach(function(opt, i) {
      var btn = document.createElement('button');
      btn.type      = 'button';
      btn.className = 'mc-option';
      btn.dataset.optIndex = String(i);

      if (isImg && opt !== '') {
        var img = document.createElement('img');
        img.src = opt;
        img.alt = 'Option ' + String.fromCharCode(65 + i);
        btn.appendChild(img);
      } else {
        btn.textContent = opt;
      }

      /* highlight state */
      if (answered || revealed) {
        if (i === correct)              AF.highlightOption(btn, 'correct');
        else if (i === selected && !revealed) AF.highlightOption(btn, 'wrong');
        btn.disabled = true;
      } else {
        if (selected === i) btn.classList.add('selected');
        btn.addEventListener('click', function() {
          if (answered || revealed) return;
          selected = i;
          renderOptions();
        });
      }
      optionsEl.appendChild(btn);
    });
  }

  function loadQuestion() {
    var item = questions[index] || {};
    var clean = normalizeQuestion(item);
    selected = null; revealed = false; answered = false;
    activeListenText = clean;
    activeVoiceId    = String(item.voice_id || 'josh');

    if (completedEl) completedEl.style.display = 'none';
    if (cardEl)     cardEl.style.display = '';
    if (controlsEl) controlsEl.style.display = '';
    if (feedbackEl) { AF.clearFeedback(feedbackEl); }

    updateProgress();
    if (questionEl) questionEl.textContent = clean || 'Choose the correct answer.';
    if (listenBtn)  listenBtn.disabled = (item.question_type !== 'listen' || !clean);

    if (item.image) {
      imageEl.src = item.image;
      imageBoxEl.classList.remove('is-empty');
    } else {
      imageEl.removeAttribute('src');
      imageBoxEl.classList.add('is-empty');
    }

    renderOptions();
    if (nextBtn)  nextBtn.textContent = (index < questions.length - 1) ? 'Next →' : 'Finish';
    if (checkBtn) checkBtn.style.display = '';
    if (showBtn)  showBtn.style.display  = '';
  }

  function checkAnswer() {
    if (answered || revealed || selected === null) return;
    var item    = questions[index] || {};
    var correct = Number.isInteger(item.correct) ? item.correct : 0;
    var opts    = safeOptions(item);
    var isRight = selected === correct;
    scores[index] = isRight ? 1 : 0;
    reviewItems[index] = {
      question:      normalizeQuestion(item),
      yourAnswer:    opts[selected] || '',
      correctAnswer: opts[correct] || '',
      score:         scores[index]
    };
    answered = true;
    renderOptions();
    if (feedbackEl) AF.showFeedback(feedbackEl, isRight, opts[correct] || '', false);
    if (checkBtn) checkBtn.style.display = 'none';
    if (showBtn)  showBtn.style.display  = 'none';
  }

  function showAnswer() {
    if (answered) return;
    revealed = true; answered = true;
    scores[index] = -1;
    var item    = questions[index] || {};
    var correct = Number.isInteger(item.correct) ? item.correct : 0;
    var opts    = safeOptions(item);
    reviewItems[index] = { question: normalizeQuestion(item), yourAnswer: '(revealed)', correctAnswer: opts[correct] || '', score: -1 };
    renderOptions();
    if (feedbackEl) AF.showFeedback(feedbackEl, false, null, true);
    if (checkBtn) checkBtn.style.display = 'none';
    if (showBtn)  showBtn.style.display  = 'none';
  }

  function nextQuestion() {
    if (!answered && !revealed) {
      /* grade on next without explicit answer = wrong */
      var item    = questions[index] || {};
      var correct = Number.isInteger(item.correct) ? item.correct : 0;
      var opts    = safeOptions(item);
      var isRight = (selected !== null && selected === correct);
      scores[index] = isRight ? 1 : 0;
      reviewItems[index] = {
        question:      normalizeQuestion(item),
        yourAnswer:    selected !== null ? (opts[selected] || '') : '',
        correctAnswer: opts[correct] || '',
        score:         scores[index]
      };
      answered = true;
      renderOptions();
      if (feedbackEl) AF.showFeedback(feedbackEl, isRight, opts[correct] || '', false);
      if (checkBtn) checkBtn.style.display = 'none';
      if (showBtn)  showBtn.style.display  = 'none';

      /* short pause before advancing */
      setTimeout(advance, 900);
      return;
    }
    advance();
  }

  function advance() {
    if (index < questions.length - 1) {
      index++;
      loadQuestion();
    } else {
      showCompleted();
    }
  }

  function showCompleted() {
    if (!completedEl) return;
    if (cardEl)     cardEl.style.display = 'none';
    if (controlsEl) controlsEl.style.display = 'none';
    if (feedbackEl) feedbackEl.innerHTML = '';

    AF.showCompleted({
      target:        completedEl,
      scores:        scores,
      title:         activityTitle,
      activityType:  'Multiple Choice',
      questionCount: questions.length,
      winAudio:      winAudio,
      onRetry:       restartActivity,
      onReview:      function() {
        AF.showReview({
          target:  completedEl,
          items:   reviewItems,
          onRetry: restartActivity,
          hideEl:  null
        });
      }
    });
    completedEl.style.display = '';

    /* persist score */
    var result  = AF.computeScore(scores);
    var saveUrl = buildSaveUrl(result.percent, result.wrong, result.total);
    if (saveUrl) {
      fetch(saveUrl, { method:'GET', credentials:'same-origin', cache:'no-store' }).catch(function(){});
    }
  }

  function buildSaveUrl(percent, errors, total) {
    if (!returnTo || !activityId) return '';
    var j = returnTo.indexOf('?') !== -1 ? '&' : '?';
    return returnTo + j +
      'activity_percent=' + encodeURIComponent(String(percent)) +
      '&activity_errors=' + encodeURIComponent(String(errors)) +
      '&activity_total='  + encodeURIComponent(String(total)) +
      '&activity_id='     + encodeURIComponent(String(activityId)) +
      '&activity_type=multiple_choice';
  }

  function restartActivity() {
    index = 0; selected = null; revealed = false; answered = false;
    scores      = questions.map(function(){ return 0; });
    reviewItems = questions.map(function(){ return {}; });
    loadQuestion();
  }

  /* ── button listeners ── */
  if (checkBtn) checkBtn.addEventListener('click', checkAnswer);
  if (showBtn)  showBtn.addEventListener('click', showAnswer);
  if (nextBtn)  nextBtn.addEventListener('click', nextQuestion);

  /* ── TTS ── */
  var currentAudio = null;
  var ttsCtrl = null;

  function stopSpeech() {
    if (ttsCtrl) { ttsCtrl.abort(); ttsCtrl = null; }
    if (currentAudio) { currentAudio.pause(); currentAudio = null; }
  }

  function speakText(text, voiceId) {
    if (!text) return;
    stopSpeech();
    ttsCtrl = new AbortController();
    var sig = ttsCtrl.signal;
    var fd  = new FormData();
    fd.append('text', text);
    fd.append('voice_id', voiceId || 'josh');
    fetch('tts.php', { method:'POST', body:fd, signal:sig })
      .then(function(r){ if (!r.ok) throw new Error('tts'); return r.blob(); })
      .then(function(blob){
        if (sig.aborted) return;
        currentAudio = new Audio(URL.createObjectURL(blob));
        currentAudio.play().catch(function(){});
      })
      .catch(function(){});
  }

  if (listenBtn) {
    listenBtn.addEventListener('click', function(){
      speakText(activeListenText, activeVoiceId);
    });
  }

  loadQuestion();
});
