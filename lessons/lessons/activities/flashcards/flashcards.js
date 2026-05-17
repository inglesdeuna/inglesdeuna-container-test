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
  var ttsCache = {};   /* key: voice_id + '|' + text → audio URL */
  var currentAudio = null;

  /* ── ElevenLabs TTS via tts.php ────────────────────────────── */
  function playElevenLabs(text, voiceId, onError) {
    var cacheKey = (voiceId || '') + '|' + text;
    if (ttsCache[cacheKey]) {
      playUrl(ttsCache[cacheKey]);
      return;
    }

    if (listenBtn) { listenBtn.disabled = true; listenBtn.textContent = '⏳ Loading…'; }

    var fd = new FormData();
    fd.append('text', text);
    if (voiceId) fd.append('voice_id', voiceId);

    fetch('tts.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (listenBtn) { listenBtn.disabled = false; listenBtn.textContent = '🔊 Listen'; }
        if (data.url) {
          ttsCache[cacheKey] = data.url;
          playUrl(data.url);
        } else {
          console.warn('TTS error:', data.error);
          if (onError) onError();
        }
      })
      .catch(function (err) {
        if (listenBtn) { listenBtn.disabled = false; listenBtn.textContent = '🔊 Listen'; }
        console.warn('TTS fetch failed:', err);
        if (onError) onError();
      });
  }

  function playUrl(url) {
    if (currentAudio) { currentAudio.pause(); currentAudio = null; }
    currentAudio = new Audio(url);
    currentAudio.play().catch(function (e) { console.warn('Audio play failed:', e); });
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

  /* ── Listen (always ElevenLabs) ────────────────────────────── */
  function listen() {
    var card = cards[index] || {};

    /* Reveal word when user listens */
    if (wordEl) wordEl.style.display = 'block';
    wordVisible = true;
    if (showBtn) showBtn.textContent = 'Hide Word';

    /* If card already has a pre-generated audio URL, play it directly */
    if (card.audio) {
      playUrl(card.audio);
      return;
    }

    /* Otherwise request ElevenLabs via tts.php */
    var text    = card.text    || '';
    var voiceId = card.voice_id || '';
    if (!text) return;

    playElevenLabs(text, voiceId);
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
