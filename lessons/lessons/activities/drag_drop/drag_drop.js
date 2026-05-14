document.addEventListener('DOMContentLoaded', function () {
  var AF = window.ActivityFeedback;
  var questions = Array.isArray(window.DRAGDROP_DATA) ? window.DRAGDROP_DATA : [];

  var progressLabelEl = document.getElementById('dd-progress-label');
  var progressFillEl  = document.getElementById('dd-progress-fill');
  var progressBadgeEl = document.getElementById('dd-progress-badge');
  var instructionEl   = document.getElementById('dd-instruction');
  var slotsEl         = document.getElementById('dd-slots');
  var wordsEl         = document.getElementById('dd-words');
  var checkBtn        = document.getElementById('dd-check');
  var showBtn         = document.getElementById('dd-show');
  var nextBtn         = document.getElementById('dd-next');
  var feedbackEl      = document.getElementById('dd-feedback');
  var activityEl      = document.getElementById('dd-activity');
  var completedEl     = document.getElementById('dd-completed');
  var winAudio        = new Audio('../../hangman/assets/win.mp3');

  var activityTitle = window.DRAGDROP_TITLE || 'Drag & Drop';
  var returnTo      = window.DRAGDROP_RETURN_TO || '';
  var activityId    = window.DRAGDROP_ACTIVITY_ID || '';

  if (!questions.length) {
    if (instructionEl) instructionEl.textContent = 'No questions available.';
    return;
  }

  var index        = 0;
  var answered     = false;
  var dragging     = null;
  var slotContents = {};   /* slotId -> word */
  var scores       = questions.map(function () { return 0; });
  var reviewItems  = questions.map(function () { return {}; });

  function updateProgress() {
    var total   = questions.length;
    var current = index + 1;
    var pct     = Math.round((current / total) * 100);
    if (progressLabelEl) progressLabelEl.textContent = current + ' / ' + total;
    if (progressBadgeEl) progressBadgeEl.textContent = 'Q ' + current + ' of ' + total;
    if (progressFillEl)  progressFillEl.style.width  = pct + '%';
  }

  function escHtml(s) {
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
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
    var q = questions[index] || {};
    answered    = false;
    slotContents = {};
    dragging    = null;

    if (completedEl) completedEl.style.display = 'none';
    if (activityEl)  activityEl.style.display  = '';
    if (feedbackEl)  AF.clearFeedback(feedbackEl);

    updateProgress();
    if (instructionEl) instructionEl.textContent = q.instruction || 'Fill in the blanks:';

    renderSlots(q);
    renderWords(q);

    if (checkBtn) checkBtn.disabled = false;
    if (showBtn)  { showBtn.style.display = ''; showBtn.disabled = false; }
    if (nextBtn)  { nextBtn.disabled = true; nextBtn.textContent = index < questions.length - 1 ? 'Next \u2192' : 'Finish'; }
  }

  function renderSlots(q) {
    if (!slotsEl) return;
    slotsEl.innerHTML = '';
    var slots = Array.isArray(q.slots) ? q.slots : [];
    slots.forEach(function (slot, i) {
      var slotId = 'slot-' + i;
      var div = document.createElement('div');
      div.className = 'dd-slot';
      div.dataset.slotId = slotId;
      div.dataset.answer = slot.answer || '';

      var numEl = document.createElement('span');
      numEl.className = 'dd-slot__num';
      numEl.textContent = String(i + 1);
      div.appendChild(numEl);

      var labelEl = document.createElement('span');
      labelEl.className = 'dd-slot__label';
      labelEl.textContent = slot.label || '';
      div.appendChild(labelEl);

      var dropzone = document.createElement('div');
      dropzone.className = 'dd-dropzone';
      dropzone.dataset.slotId = slotId;
      dropzone.textContent = 'Drop here';
      div.appendChild(dropzone);

      /* drop events */
      dropzone.addEventListener('dragover', function (e) { e.preventDefault(); dropzone.classList.add('dd-dropzone--over'); });
      dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('dd-dropzone--over'); });
      dropzone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropzone.classList.remove('dd-dropzone--over');
        if (!dragging || answered) return;
        var word = dragging.dataset.word;
        /* if slot already has a word, return it to word bank */
        var prevWord = slotContents[slotId];
        if (prevWord) returnWordToBank(prevWord);
        slotContents[slotId] = word;
        dropzone.textContent = word;
        dropzone.classList.add('dd-dropzone--filled');
        dragging.remove();
        dragging = null;
      });

      slotsEl.appendChild(div);
    });
  }

  function renderWords(q) {
    if (!wordsEl) return;
    wordsEl.innerHTML = '';
    var words = shuffle(Array.isArray(q.words) ? q.words : []);
    words.forEach(function (w) {
      addWordChip(w);
    });
  }

  function addWordChip(word) {
    if (!wordsEl) return;
    var chip = document.createElement('div');
    chip.className = 'dd-word';
    chip.draggable  = true;
    chip.dataset.word = word;
    chip.textContent  = word;
    chip.addEventListener('dragstart', function () { dragging = chip; chip.classList.add('dd-word--dragging'); });
    chip.addEventListener('dragend',   function () { chip.classList.remove('dd-word--dragging'); dragging = null; });
    wordsEl.appendChild(chip);
  }

  function returnWordToBank(word) {
    /* find and delete any slot content for this word first */
    Object.keys(slotContents).forEach(function (k) {
      if (slotContents[k] === word) delete slotContents[k];
    });
    addWordChip(word);
    /* clear the dropzone */
    slotsEl && slotsEl.querySelectorAll('.dd-dropzone').forEach(function (dz) {
      if (dz.textContent === word) { dz.textContent = 'Drop here'; dz.classList.remove('dd-dropzone--filled'); }
    });
  }

  function checkAnswers() {
    if (answered) return;
    var q = questions[index] || {};
    var slots = Array.isArray(q.slots) ? q.slots : [];
    var correct = 0;
    answered = true;

    /* highlight each slot */
    slotsEl && slotsEl.querySelectorAll('.dd-slot').forEach(function (slotDiv, i) {
      var slotId   = 'slot-' + i;
      var expected = slots[i] ? (slots[i].answer || '') : '';
      var given    = slotContents[slotId] || '';
      var isRight  = given.trim().toLowerCase() === expected.trim().toLowerCase();
      if (isRight) correct++;
      slotDiv.classList.add(isRight ? 'dd-slot--correct' : 'dd-slot--wrong');
    });

    var allRight = correct === slots.length;
    scores[index] = allRight ? 1 : 0;
    reviewItems[index] = {
      question:      q.instruction || ('Question ' + (index + 1)),
      yourAnswer:    correct + '/' + slots.length + ' correct',
      correctAnswer: slots.map(function (s) { return s.answer; }).join(', '),
      score:         scores[index]
    };

    if (feedbackEl) AF.showFeedback(feedbackEl, allRight, slots.map(function (s) { return s.answer; }).join(', '), false);
    if (checkBtn) checkBtn.disabled = true;
    if (showBtn)  showBtn.style.display = 'none';
    if (nextBtn)  nextBtn.disabled = false;
  }

  function showAnswers() {
    if (answered) return;
    var q = questions[index] || {};
    var slots = Array.isArray(q.slots) ? q.slots : [];
    answered = true;
    scores[index] = -1;

    /* fill all slots with correct answers */
    slotsEl && slotsEl.querySelectorAll('.dd-slot').forEach(function (slotDiv, i) {
      var dropzone = slotDiv.querySelector('.dd-dropzone');
      var expected = slots[i] ? (slots[i].answer || '') : '';
      if (dropzone) { dropzone.textContent = expected; dropzone.classList.add('dd-dropzone--filled'); }
      slotDiv.classList.add('dd-slot--revealed');
    });

    reviewItems[index] = { question: q.instruction || '', yourAnswer: '(revealed)', correctAnswer: '', score: -1 };
    if (feedbackEl) AF.showFeedback(feedbackEl, false, null, true);
    if (checkBtn) checkBtn.disabled = true;
    if (showBtn)  showBtn.style.display = 'none';
    if (nextBtn)  nextBtn.disabled = false;
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
      fetch(returnTo + sep + 'activity_percent=' + result.percent + '&activity_errors=' + result.wrong + '&activity_total=' + result.total + '&activity_id=' + encodeURIComponent(activityId) + '&activity_type=drag_drop',
        { method: 'GET', credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    }
  }

  function restartActivity() {
    index        = 0;
    scores       = questions.map(function () { return 0; });
    reviewItems  = questions.map(function () { return {}; });
    loadQuestion();
  }

  /* inject slot/word CSS */
  if (!document.getElementById('dd-feedback-css')) {
    var st = document.createElement('style');
    st.id = 'dd-feedback-css';
    st.textContent =
      '.dd-slot--correct{border-color:#22c55e!important;background:#f0fdf4!important}' +
      '.dd-slot--wrong{border-color:#ef4444!important;background:#fef2f2!important}' +
      '.dd-slot--revealed{border-color:#F97316!important;background:#FFF9F5!important}' +
      '.dd-dropzone--over{border-color:#7F77DD!important;background:#EEEDFE!important}';
    document.head.appendChild(st);
  }

  if (checkBtn) checkBtn.addEventListener('click', checkAnswers);
  if (showBtn)  showBtn.addEventListener('click', showAnswers);
  if (nextBtn)  nextBtn.addEventListener('click', nextQuestion);

  loadQuestion();
});
