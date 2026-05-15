document.addEventListener('DOMContentLoaded', function () {
  var AF      = window.ActivityFeedback;
  var cards   = Array.isArray(window.FLASHCARD_DATA) ? window.FLASHCARD_DATA : [];

  var progressLabelEl = document.getElementById('fc-progress-label');
  var progressFillEl  = document.getElementById('fc-progress-fill');
  var progressBadgeEl = document.getElementById('fc-progress-badge');
  var imgEl           = document.getElementById('fc-img');
  var imgPlaceholder  = document.getElementById('fc-image-placeholder');
  var wordEl          = document.getElementById('fc-word');
  var listenBtn       = document.getElementById('fc-listen');
  var showBtn         = document.getElementById('fc-show');
  var nextBtn         = document.getElementById('fc-next');
  var feedbackEl      = document.getElementById('fc-feedback');
  var activityEl      = document.getElementById('fc-activity');
  var completedEl     = document.getElementById('fc-completed');
  var winAudio        = new Audio('../../hangman/assets/win.mp3');

  var activityTitle = window.FLASHCARD_TITLE       || 'Flashcards';
  var returnTo      = window.FLASHCARD_RETURN_TO   || '';
  var activityId    = window.FLASHCARD_ACTIVITY_ID || '';

  if (!cards.length) {
    if (wordEl) wordEl.textContent = 'No cards available.';
    return;
  }

  var index = 0;
  var wordVisible = false;

  /* ── Voice helpers ─────────────────────────────────────────── */
  function voiceProfile(voiceId) {
    var id = String(voiceId || '').trim();
    if (id === 'NoOVOzCQFLOvtsMoNcdT') return 'female';
    if (id === 'Nggzl2QAXh3OijoXD116') return 'child';
    return 'male';
  }

  function pickVoice(voices, profile) {
    if (!voices || !voices.length) return null;
    var keys = {
      male:   [' male','david','guy','daniel','george','matthew'],
      female: [' female','zira','jenny','susan','aria','sara','rachel'],
      child:  ['child','kid','junior','young','lily']
    }[profile] || [];
    var best = null, bestScore = -1;
    for (var i = 0; i < voices.length; i++) {
      var v = voices[i];
      var name = (v.name || '').toLowerCase();
      var lang = (v.lang || '').toLowerCase();
      var score = 0;
      if (lang.indexOf('en') === 0) score += 4;
      for (var k = 0; k < keys.length; k++) {
        if (name.indexOf(keys[k]) !== -1) score += 6;
      }
      if (profile === 'male'   && name.indexOf('female') !== -1) score -= 3;
      if (profile === 'female' && name.indexOf('male')   !== -1) score -= 3;
      if (score > bestScore) { best = v; bestScore = score; }
    }
    return best || voices[0];
  }

  function speakText(text, profile) {
    if (!window.speechSynthesis) return;
    window.speechSynthesis.cancel();
    var utt = new SpeechSynthesisUtterance(text);
    function go() {
      var voices = window.speechSynthesis.getVoices();
      utt.voice  = pickVoice(voices, profile || 'male');
      utt.rate   = 0.88;
      utt.pitch  = 0.95;
      utt.volume = 1;
      window.speechSynthesis.speak(utt);
    }
    if (window.speechSynthesis.getVoices().length === 0) {
      window.speechSynthesis.onvoiceschanged = go;
    } else {
      go();
    }
  }

  /* ── Progress ───────────────────────────────────────────────── */
  function updateProgress() {
    var total   = cards.length;
    var current = index + 1;
    var pct     = Math.round((current / total) * 100);
    if (progressLabelEl) progressLabelEl.textContent = current + ' / ' + total;
    if (progressBadgeEl) progressBadgeEl.textContent = 'Card ' + current + ' of ' + total;
    if (progressFillEl)  progressFillEl.style.width  = pct + '%';
  }

  /* ── Load card ──────────────────────────────────────────────── */
  function loadCard() {
    var card    = cards[index] || {};
    wordVisible = false;

    if (completedEl) completedEl.style.display = 'none';
    if (activityEl)  activityEl.style.display  = '';
    if (feedbackEl)  AF.clearFeedback(feedbackEl);

    /* Image */
    if (card.image) {
      imgEl.src = card.image;
      imgEl.style.display = 'block';
      if (imgPlaceholder) imgPlaceholder.style.display = 'none';
    } else {
      imgEl.src = '';
      imgEl.style.display = 'none';
      if (imgPlaceholder) imgPlaceholder.style.display = 'flex';
    }

    /* Word hidden until Listen or Show */
    if (wordEl) {
      wordEl.textContent   = card.text || '';
      wordEl.style.display = 'none';
    }

    if (listenBtn) listenBtn.disabled = false;
    if (showBtn)   { showBtn.disabled = false; showBtn.textContent = 'Show Word'; }
    if (nextBtn)   {
      nextBtn.disabled    = false;
      nextBtn.textContent = index < cards.length - 1 ? 'Next →' : 'Finish';
    }

    updateProgress();
  }

  /* ── Listen ─────────────────────────────────────────────────── */
  function listen() {
    var card = cards[index] || {};
    /* Reveal word */
    if (wordEl) wordEl.style.display = 'block';
    wordVisible = true;
    if (showBtn) showBtn.textContent = 'Hide Word';

    if (card.audio) {
      var aud = new Audio(card.audio);
      aud.play().catch(function () {
        speakText(card.text || '', voiceProfile(card.voice_id));
      });
    } else {
      speakText(card.text || '', voiceProfile(card.voice_id));
    }
  }

  /* ── Show/Hide word ─────────────────────────────────────────── */
  function toggleWord() {
    if (!wordEl) return;
    wordVisible = !wordVisible;
    wordEl.style.display = wordVisible ? 'block' : 'none';
    if (showBtn) showBtn.textContent = wordVisible ? 'Hide Word' : 'Show Word';
  }

  /* ── Next ───────────────────────────────────────────────────── */
  function nextCard() {
    if (index < cards.length - 1) {
      index++;
      loadCard();
    } else {
      showCompleted();
    }
  }

  /* ── Completed ──────────────────────────────────────────────── */
  function showCompleted() {
    if (!completedEl) return;
    if (activityEl) activityEl.style.display = 'none';
    completedEl.style.display = '';

    /* Learning activity — no graded scores; pass all as 1 */
    var fakeScores = cards.map(function () { return 1; });

    AF.showCompleted({
      target:        completedEl,
      scores:        fakeScores,
      title:         activityTitle,
      activityType:  'Flashcards',
      questionCount: cards.length,
      winAudio:      winAudio,
      onRetry:       restart,
      onReview:      null
    });

    if (returnTo && activityId) {
      var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
      fetch(
        returnTo + sep +
        'activity_percent=100&activity_errors=0&activity_total=' + cards.length +
        '&activity_id=' + encodeURIComponent(activityId) +
        '&activity_type=flashcards',
        { method: 'GET', credentials: 'same-origin', cache: 'no-store' }
      ).catch(function () {});
    }
  }

  /* ── Restart ────────────────────────────────────────────────── */
  function restart() {
    index = 0;
    loadCard();
  }

  /* ── Events ─────────────────────────────────────────────────── */
  if (listenBtn) listenBtn.addEventListener('click', listen);
  if (showBtn)   showBtn.addEventListener('click', toggleWord);
  if (nextBtn)   nextBtn.addEventListener('click', nextCard);

  loadCard();
});
