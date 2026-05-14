document.addEventListener('DOMContentLoaded', function () {
  var AF = window.ActivityFeedback;
  var cards = Array.isArray(window.FLASHCARD_DATA) ? window.FLASHCARD_DATA : [];

  var progressLabelEl = document.getElementById('fc-progress-label');
  var progressFillEl  = document.getElementById('fc-progress-fill');
  var progressBadgeEl = document.getElementById('fc-progress-badge');
  var frontEl         = document.getElementById('fc-front');
  var backEl          = document.getElementById('fc-back');
  var cardEl          = document.getElementById('fc-card');
  var flipBtn         = document.getElementById('fc-flip');
  var knewBtn         = document.getElementById('fc-knew');
  var reviewBtn       = document.getElementById('fc-review');
  var showBtn         = document.getElementById('fc-show');
  var nextBtn         = document.getElementById('fc-next');
  var feedbackEl      = document.getElementById('fc-feedback');
  var activityEl      = document.getElementById('fc-activity');
  var completedEl     = document.getElementById('fc-completed');
  var winAudio        = new Audio('../../hangman/assets/win.mp3');

  var activityTitle = window.FLASHCARD_TITLE || 'Flashcards';
  var returnTo      = window.FLASHCARD_RETURN_TO || '';
  var activityId    = window.FLASHCARD_ACTIVITY_ID || '';

  if (!cards.length) {
    if (frontEl) frontEl.textContent = 'No cards available.';
    return;
  }

  var index    = 0;
  var flipped  = false;
  var answered = false;
  var scores   = cards.map(function () { return 0; });   // 1=knew -1=review 0=skipped
  var reviewItems = cards.map(function () { return {}; });

  function updateProgress() {
    var total   = cards.length;
    var current = index + 1;
    var pct     = Math.round((current / total) * 100);
    if (progressLabelEl) progressLabelEl.textContent = current + ' / ' + total;
    if (progressBadgeEl) progressBadgeEl.textContent = 'Card ' + current + ' of ' + total;
    if (progressFillEl)  progressFillEl.style.width  = pct + '%';
  }

  function loadCard() {
    var card = cards[index] || {};
    flipped  = false;
    answered = false;

    if (completedEl) completedEl.style.display = 'none';
    if (activityEl)  activityEl.style.display  = '';
    if (feedbackEl)  AF.clearFeedback(feedbackEl);

    if (cardEl)  cardEl.classList.remove('is-flipped');
    if (frontEl) frontEl.textContent = card.front || '';
    if (backEl)  backEl.textContent  = card.back  || '';

    updateProgress();

    [knewBtn, reviewBtn].forEach(function (b) { if (b) b.disabled = true; });
    if (flipBtn) flipBtn.disabled = false;
    if (showBtn) { showBtn.style.display = ''; showBtn.disabled = false; }
    if (nextBtn) {
      nextBtn.disabled  = true;
      nextBtn.textContent = index < cards.length - 1 ? 'Next \u2192' : 'Finish';
    }
  }

  function flipCard() {
    if (!cardEl) return;
    flipped = !flipped;
    cardEl.classList.toggle('is-flipped', flipped);
    if (flipped) {
      [knewBtn, reviewBtn].forEach(function (b) { if (b) b.disabled = false; });
      if (flipBtn) flipBtn.disabled = true;
    }
  }

  function grade(knew) {
    if (answered) return;
    answered = true;
    var card = cards[index] || {};
    scores[index] = knew ? 1 : -1;
    reviewItems[index] = {
      question:      card.front || '',
      yourAnswer:    knew ? 'I knew it' : 'Need to review',
      correctAnswer: card.back || '',
      score:         knew ? 1 : -1
    };
    if (feedbackEl) AF.showFeedback(feedbackEl, knew, card.back || '', false);
    [knewBtn, reviewBtn].forEach(function (b) { if (b) b.disabled = true; });
    if (showBtn) showBtn.style.display = 'none';
    if (nextBtn) nextBtn.disabled = false;
  }

  function showAnswer() {
    if (!cardEl.classList.contains('is-flipped')) flipCard();
    if (answered) return;
    answered = true;
    var card = cards[index] || {};
    scores[index] = -1;
    reviewItems[index] = { question: card.front || '', yourAnswer: '(revealed)', correctAnswer: card.back || '', score: -1 };
    if (feedbackEl) AF.showFeedback(feedbackEl, false, null, true);
    [knewBtn, reviewBtn].forEach(function (b) { if (b) b.disabled = true; });
    if (showBtn) showBtn.style.display = 'none';
    if (nextBtn) nextBtn.disabled = false;
  }

  function nextCard() {
    if (index < cards.length - 1) {
      index++;
      loadCard();
    } else {
      showCompleted();
    }
  }

  function showCompleted() {
    if (!completedEl) return;
    if (activityEl) activityEl.style.display = 'none';
    completedEl.style.display = '';

    // Remap: for flashcards, "knew" = correct, "review" = wrong, "revealed" = revealed
    var afScores = scores.map(function (s) {
      if (s === 1)  return 1;
      if (s === -1) return -1; // revealed or "need review" — both don't count
      return 0;
    });

    AF.showCompleted({
      target:        completedEl,
      scores:        afScores,
      title:         activityTitle,
      activityType:  'Flashcards',
      questionCount: cards.length,
      winAudio:      winAudio,
      onRetry:       restartActivity,
      onReview:      function () {
        AF.showReview({
          target:  completedEl,
          items:   reviewItems,
          onRetry: restartActivity
        });
      }
    });

    // Persist
    var result  = AF.computeScore(afScores);
    if (returnTo && activityId) {
      var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
      var url = returnTo + sep +
        'activity_percent=' + encodeURIComponent(String(result.percent)) +
        '&activity_errors='  + encodeURIComponent(String(result.wrong)) +
        '&activity_total='   + encodeURIComponent(String(result.total)) +
        '&activity_id='      + encodeURIComponent(String(activityId)) +
        '&activity_type=flashcards';
      fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    }
  }

  function restartActivity() {
    index       = 0;
    scores      = cards.map(function () { return 0; });
    reviewItems = cards.map(function () { return {}; });
    loadCard();
  }

  if (flipBtn)   flipBtn.addEventListener('click', flipCard);
  if (knewBtn)   knewBtn.addEventListener('click', function () { grade(true); });
  if (reviewBtn) reviewBtn.addEventListener('click', function () { grade(false); });
  if (showBtn)   showBtn.addEventListener('click', showAnswer);
  if (nextBtn)   nextBtn.addEventListener('click', nextCard);

  loadCard();
});
