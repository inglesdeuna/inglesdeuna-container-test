document.addEventListener('DOMContentLoaded', function () {
  var AF = window.ActivityFeedback;
  var questions = Array.isArray(window.DRAGDROP_DATA) ? window.DRAGDROP_DATA : [];

  var progressLabelEl = document.getElementById('dd-progress-label');
  var progressFillEl  = document.getElementById('dd-progress-fill');
  var progressBadgeEl = document.getElementById('dd-progress-badge');
  var instructionEl   = document.getElementById('dd-instruction');
  var wordsEl         = document.getElementById('dd-words');
  var checkBtn        = document.getElementById('dd-check');
  var showBtn         = document.getElementById('dd-show');
  var nextBtn         = document.getElementById('dd-next');
  var feedbackEl      = document.getElementById('dd-feedback');
  var activityEl      = document.getElementById('dd-activity');
  var completedEl     = document.getElementById('dd-completed');
  var winAudio        = new Audio('../../hangman/assets/win.mp3');

  var activityTitle = window.DRAGDROP_TITLE       || 'Drag & Drop';
  var returnTo      = window.DRAGDROP_RETURN_TO   || '';
  var activityId    = window.DRAGDROP_ACTIVITY_ID || '';

  if (!questions.length) {
    if (instructionEl) instructionEl.textContent = 'No questions available.';
    return;
  }

  var index        = 0;
  var answered     = false;
  var dragging     = null;
  var slotContents = {};
  var scores       = questions.map(function () { return 0; });
  var reviewItems  = questions.map(function () { return {}; });

  /* score strip DOM refs */
  var scoreCorrectEl = document.getElementById('dd-score-correct');
  var scoreWrongEl   = document.getElementById('dd-score-wrong');
  var scorePctEl     = document.getElementById('dd-score-pct');
  var scoreStripEl   = document.getElementById('dd-score-strip');

  function updateProgress() {
    var total   = questions.length;
    var current = index + 1;
    var pct     = Math.round((current / total) * 100);
    if (progressLabelEl) progressLabelEl.textContent = current + ' / ' + total;
    if (progressBadgeEl) progressBadgeEl.textContent = 'Q ' + current + ' of ' + total;
    if (progressFillEl)  progressFillEl.style.width  = pct + '%';
  }

  function updateScoreCards(show) {
    if (show && scoreStripEl) scoreStripEl.style.display = '';
    var checkedCount = 0;
    var correctCount = 0;
    for (var i = 0; i < scores.length; i++) {
      if (i < index || (i === index && answered)) {
        checkedCount++;
        if (scores[i] === 1) correctCount++;
      }
    }
    var wrongCount = checkedCount - correctCount;
    var total      = questions.length;
    var pct        = total > 0 ? Math.round((correctCount / total) * 100) : 0;
    if (scoreCorrectEl) scoreCorrectEl.textContent = String(correctCount);
    if (scoreWrongEl)   scoreWrongEl.textContent   = String(wrongCount);
    if (scorePctEl)     scorePctEl.textContent     = pct + '%';
  }

  function shuffle(arr) {
    var a = arr.slice();
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = a[i]; a[i] = a[j]; a[j] = t;
    }
    return a;
  }

  function loadQuestion() {
    answered     = false;
    slotContents = {};
    updateProgress();
    if (checkBtn) { checkBtn.disabled = false; checkBtn.style.display = ''; }
    if (showBtn)  showBtn.style.display = '';
    if (nextBtn)  nextBtn.disabled = true;
    if (nextBtn)  nextBtn.textContent = index < questions.length - 1 ? 'Next \u2192' : 'Finish';
    var q = questions[index] || {};
    renderInstruction(q);
    renderWords(q);
    if (feedbackEl) feedbackEl.innerHTML = '';
  }

  function renderInstruction(q) {
    if (!instructionEl) return;
    instructionEl.innerHTML = '';
    var text  = q.instruction || '';
    var parts = text.split('___');
    parts.forEach(function (part, i) {
      if (part) instructionEl.appendChild(document.createTextNode(part));
      if (i < parts.length - 1) {
        var drop = document.createElement('span');
        drop.className    = 'dd-inline-drop';
        drop.dataset.slot = String(i);
        drop.textContent  = '\u00b7\u00b7\u00b7';
        drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('dd-inline-drop--over'); });
        drop.addEventListener('dragleave', function () { drop.classList.remove('dd-inline-drop--over'); });
        drop.addEventListener('drop', function (e) {
          e.preventDefault();
          drop.classList.remove('dd-inline-drop--over');
          if (!dragging) return;
          var word    = dragging.dataset.word;
          var slotIdx = Number(drop.dataset.slot);
          if (slotContents[slotIdx] !== undefined) returnWordToBank(slotContents[slotIdx]);
          slotContents[slotIdx] = word;
          drop.textContent = word;
          drop.classList.add('dd-inline-drop--filled');
          dragging.remove();
          dragging = null;
        });
        drop.addEventListener('click', function () {
          if (answered) return;
          var active = wordsEl ? wordsEl.querySelector('.dd-chip--selected') : null;
          if (!active) return;
          var word    = active.dataset.word;
          var slotIdx = Number(drop.dataset.slot);
          if (slotContents[slotIdx] !== undefined) returnWordToBank(slotContents[slotIdx]);
          slotContents[slotIdx] = word;
          drop.textContent = word;
          drop.classList.add('dd-inline-drop--filled');
          active.remove();
        });
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
    chip.className    = 'dd-chip';
    chip.draggable    = true;
    chip.dataset.word = word;
    chip.textContent  = word;
    chip.addEventListener('dragstart', function (e) {
      dragging = chip;
      chip.classList.add('dd-chip--dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    chip.addEventListener('dragend', function () {
      chip.classList.remove('dd-chip--dragging');
      dragging = null;
    });
    chip.addEventListener('click', function () {
      if (answered) return;
      var prev = wordsEl.querySelector('.dd-chip--selected');
      if (prev) prev.classList.remove('dd-chip--selected');
      chip.classList.toggle('dd-chip--selected');
    });
    wordsEl.appendChild(chip);
  }

  function returnWordToBank(word) { addWordChip(word); }

  function checkAnswers() {
    if (answered) return;
    var q     = questions[index] || {};
    var slots = Array.isArray(q.slots) ? q.slots : [];
    var correct = 0;
    answered = true;
    if (instructionEl) {
      instructionEl.querySelectorAll('.dd-inline-drop').forEach(function (drop, i) {
        var expected = slots[i] ? (slots[i].answer || '').trim().toLowerCase() : '';
        var given    = (slotContents[i] !== undefined ? slotContents[i] : '').trim().toLowerCase();
        var isRight  = given !== '' && given === expected;
        if (isRight) correct++;
        drop.classList.remove('dd-inline-drop--filled');
        drop.classList.add(isRight ? 'dd-inline-drop--correct' : 'dd-inline-drop--wrong');
      });
    }
    var allRight = correct === slots.length && slots.length > 0;
    scores[index]      = allRight ? 1 : 0;
    reviewItems[index] = {
      question:      q.instruction || '',
      yourAnswer:    Object.values(slotContents).join(', '),
      correctAnswer: slots.map(function(s){ return s.answer || ''; }).join(', '),
      score:         scores[index]
    };
    updateScoreCards(true);
    if (feedbackEl) AF.showFeedback(feedbackEl, allRight, null, true);
    if (checkBtn) checkBtn.disabled = true;
    if (showBtn)  showBtn.style.display = 'none';
    if (nextBtn)  nextBtn.disabled = false;
    if (allRight && winAudio) { winAudio.currentTime = 0; winAudio.play().catch(function(){}); }
  }

  function showAnswers() {
    if (answered) return;
    answered = true;
    var q     = questions[index] || {};
    var slots = Array.isArray(q.slots) ? q.slots : [];
    if (instructionEl) {
      instructionEl.querySelectorAll('.dd-inline-drop').forEach(function (drop, i) {
        var expected     = slots[i] ? (slots[i].answer || '') : '';
        drop.textContent = expected;
        drop.classList.remove('dd-inline-drop--filled');
        drop.classList.add('dd-inline-drop--revealed');
      });
    }
    reviewItems[index] = { question: q.instruction || '', yourAnswer: '(revealed)', correctAnswer: '', score: -1 };
    if (feedbackEl) AF.showFeedback(feedbackEl, false, null, true);
    if (checkBtn) checkBtn.disabled = true;
    if (showBtn)  showBtn.style.display = 'none';
    if (nextBtn)  nextBtn.disabled = false;
  }

  function nextQuestion() {
    if (index < questions.length - 1) { index++; loadQuestion(); }
    else showCompleted();
  }

  function showCompleted() {
    if (!completedEl) return;
    if (activityEl) activityEl.style.display = 'none';
    completedEl.style.display = '';
    AF.showCompleted({
      target:        completedEl,
      scores:        scores,
      title:         activityTitle,
      activityType:  'Drag & Drop',
      questionCount: questions.length,
      winAudio:      winAudio,
      onRetry:       restartActivity,
      onReview:      function () { AF.showReview({ target: completedEl, items: reviewItems, onRetry: restartActivity }); }
    });
    var result = AF.computeScore(scores);
    if (returnTo && activityId) {
      var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
      fetch(
        returnTo + sep +
        'activity_percent='  + result.percent +
        '&activity_errors='  + result.wrong   +
        '&activity_total='   + result.total   +
        '&activity_id='      + encodeURIComponent(activityId) +
        '&activity_type=drag_drop',
        { method: 'GET', credentials: 'same-origin', cache: 'no-store' }
      ).catch(function () {});
    }
  }

  function restartActivity() {
    index       = 0;
    scores      = questions.map(function () { return 0; });
    reviewItems = questions.map(function () { return {}; });
    if (scoreStripEl) scoreStripEl.style.display = 'none';
    loadQuestion();
  }

  if (checkBtn) checkBtn.addEventListener('click', checkAnswers);
  if (showBtn)  showBtn.addEventListener('click',  showAnswers);
  if (nextBtn)  nextBtn.addEventListener('click',  nextQuestion);

  loadQuestion();
});
