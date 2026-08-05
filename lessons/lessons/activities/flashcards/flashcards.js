document.addEventListener('DOMContentLoaded', function () {
  var AF      = window.ActivityFeedback;
  var cards   = Array.isArray(window.FLASHCARD_DATA) ? window.FLASHCARD_DATA : [];

  var progressLabelEl = document.getElementById('fc-progress-label');
  var progressFillEl  = document.getElementById('fc-progress-fill');
  var progressBadgeEl = document.getElementById('fc-progress-badge');
  var imgEl           = document.getElementById('fc-img');
  var backImgEl       = document.getElementById('fc-back-img');
  var imgPlaceholder  = document.getElementById('fc-image-placeholder');
  var wordEl          = document.getElementById('fc-word');
  var frontListenBtn  = document.getElementById('fc-front-listen');
  var backListenBtn   = document.getElementById('fc-back-listen');
  var showBtn         = document.getElementById('fc-show');
  var nextBtn         = document.getElementById('fc-next');
  var cardShell     = document.querySelector('.fc-card-shell');
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
  var flipped = false;
  var ttsCache = {};   /* key: voice_id + '|' + text → audio URL */
  var pendingTts = {};
  var currentAudio = null;
  var playbackToken = 0;

  /* ── ElevenLabs TTS via tts.php ────────────────────────────── */
  function stopAudio() {
    playbackToken += 1;
    if (currentAudio) { currentAudio.pause(); currentAudio.currentTime = 0; currentAudio = null; }
  }

  function setSideButton(button, loading) {
    if (!button) return;
    button.disabled = loading;
    button.textContent = loading ? '⏳ Loading…' : (button === frontListenBtn ? '🔊 Front' : '🔊 Back');
  }

  function playElevenLabs(text, voiceId, button, onError) {
    var cacheKey = (voiceId || '') + '|' + text;
    if (ttsCache[cacheKey]) {
      playUrl(ttsCache[cacheKey], button);
      return;
    }
    if (pendingTts[cacheKey]) return;
    setSideButton(button, true);

    var requestToken = playbackToken;
    var fd = new FormData();
    fd.append('text', text);
    if (voiceId) fd.append('voice_id', voiceId);

    pendingTts[cacheKey] = fetch('tts.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.url) {
          ttsCache[cacheKey] = data.url;
          if (requestToken === playbackToken) playUrl(data.url, button);
        } else {
          console.warn('TTS error:', data.error);
          if (feedbackEl) feedbackEl.textContent = 'Audio is unavailable. Please try again.';
          if (onError) onError();
        }
      })
      .catch(function (err) {
        console.warn('TTS fetch failed:', err);
        if (feedbackEl) feedbackEl.textContent = 'Audio is unavailable. Please try again.';
        if (onError) onError();
      })
      .finally(function () {
        delete pendingTts[cacheKey];
        setSideButton(button, false);
      });
  }

  function playUrl(url, button) {
    stopAudio();
    var token = playbackToken;
    currentAudio = new Audio(url);
    currentAudio.onended = function () { if (token === playbackToken) currentAudio = null; };
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
    stopAudio();
    var card    = cards[index] || {};
    wordVisible = false;
    flipped = false;

    if (completedEl) completedEl.style.display = 'none';
    if (activityEl)  activityEl.style.display  = '';
    if (feedbackEl)  AF.clearFeedback(feedbackEl);

    renderSide();

    if (showBtn)   { showBtn.disabled = false; showBtn.textContent = 'Show Word'; }
    if (nextBtn)   {
      nextBtn.disabled    = false;
      nextBtn.textContent = index < cards.length - 1 ? 'Next →' : 'Finish';
    }

    updateProgress();
  }

  function renderSide() {
    var card = cards[index] || {};
    var frontImg = card.front_image || '';
    var backImg  = card.back_image  || '';
    var currentImg = flipped ? backImg : frontImg;

    if (imgEl) {
      imgEl.src = frontImg;
      imgEl.style.display = (!flipped && frontImg) ? 'block' : 'none';
    }
    if (backImgEl) {
      backImgEl.src = backImg;
      backImgEl.style.display = (flipped && backImg) ? 'block' : 'none';
    }
    if (imgPlaceholder) imgPlaceholder.style.display = currentImg ? 'none' : 'flex';

    var currentText = flipped ? (card.back_text || '') : (card.front_text || '');
    if (wordEl) {
      wordEl.textContent = currentText;
      wordEl.style.display = currentText ? 'block' : 'none';
    }

    var frontText = card.front_text || '';
    var backText  = card.back_text  || '';
    if (frontListenBtn) { frontListenBtn.classList.toggle('visible', !flipped && !!frontText); frontListenBtn.disabled = !frontText; }
    if (backListenBtn)  { backListenBtn.classList.toggle('visible',   flipped && !!backText);  backListenBtn.disabled  = !backText; }
  }

  /* ── Listen (always ElevenLabs) ────────────────────────────── */
  function listen(button, side) {
    var card = cards[index] || {};

    /* If card already has a pre-generated audio URL, play it directly */
    var savedAudio = side === 'back' ? card.back_audio : card.front_audio;
    if (savedAudio) {
      playUrl(savedAudio, button);
      return;
    }

    /* Otherwise request ElevenLabs via tts.php */
    var text    = (side === 'back' ? card.back_text : card.front_text) || '';
    var voiceId = card.voice_id || '';
    if (!text) return;

    playElevenLabs(text, voiceId, button);
  }

  /* ── Show/Hide word ─────────────────────────────────────────── */
  function toggleWord() {
    if (!wordEl) return;
    wordVisible = !wordVisible;
    wordEl.style.display = wordVisible ? 'block' : 'none';
    renderSide();
    if (showBtn) showBtn.textContent = wordVisible ? 'Hide Word' : 'Show Word';
  }

  function flipCard() {
    if (!cards[index] || (!cards[index].back_image && !cards[index].back_text)) return;
    stopAudio();
    flipped = !flipped;
    wordVisible = false;
    if (cardShell) {
      cardShell.classList.remove('fc-flipping');
      void cardShell.offsetWidth;
      cardShell.classList.add('fc-flipping');
    }
    renderSide();
    if (showBtn) showBtn.textContent = 'Show Word';
  }

  /* ── Next ───────────────────────────────────────────────────── */
  function nextCard() {
    if (index < cards.length - 1) {
      index++;
      loadCard();
    } else {
      stopAudio();
      showCompleted();
    }
  }

  /* ── showPassiveDone helper ─────────────────────────────────── */
  function showPassiveDone(containerEl, opts) {
    containerEl.innerHTML =
      '<div class="passive-done" id="passive-done-card">' +
      '  <div class="passive-done-icon">🎉</div>' +
      '  <h2 class="passive-done-title">All Done!</h2>' +
      '  <p class="passive-done-text">' + (opts.text || 'Great work!') + '</p>' +
      '  <div class="passive-done-track"><div class="passive-done-fill" id="passive-fill"></div></div>' +
      '  <div><button class="passive-done-btn" id="passive-restart-btn">&#8635; ' + (opts.restartLabel || 'Play Again') + '</button></div>' +
      '</div>';
    var card = document.getElementById('passive-done-card');
    var fill = document.getElementById('passive-fill');
    var btn  = document.getElementById('passive-restart-btn');
    requestAnimationFrame(function () {
      card.classList.add('active');
      setTimeout(function () { if (fill) fill.style.width = '100%'; }, 80);
    });
    if (btn && opts.onRestart) btn.addEventListener('click', opts.onRestart);
    if (opts.winAudio) { try { opts.winAudio.currentTime = 0; opts.winAudio.play(); } catch(e){} }
    if (opts.returnTo && opts.activityId) {
      var sep = opts.returnTo.indexOf('?') !== -1 ? '&' : '?';
      fetch(opts.returnTo + sep + 'activity_percent=100&activity_errors=0&activity_total=' + (opts.total||1) +
        '&activity_id=' + encodeURIComponent(opts.activityId) +
        '&activity_type=' + encodeURIComponent(opts.activityType || 'activity'),
        { method: 'GET', credentials: 'same-origin', cache: 'no-store' }).catch(function(){});
    }
  }

  /* ── Completed ──────────────────────────────────────────────── */
  var fcRounds = 0;

  function showCompleted() {
    if (!completedEl) return;
    if (activityEl) activityEl.style.display = 'none';
    completedEl.style.display = '';
    fcRounds += 1;

    var total = cards.length;

    completedEl.innerHTML =
      '<div class="af-unscored__card">' +
      '  <div class="af-unscored__prog-label">CARDS REVIEWED</div>' +
      '  <div class="af-unscored__prog-track">' +
      '    <div class="af-unscored__prog-fill" id="af-prog-fill" style="width:0%"></div>' +
      '  </div>' +
      '  <div class="af-unscored__prog-nums">' +
      '    <span>0</span>' +
      '    <strong id="af-prog-text">0 / 0</strong>' +
      '  </div>' +
      '  <div class="af-unscored__icon">' +
      '    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#7F77DD" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>' +
      '  </div>' +
      '  <p class="af-unscored__title" id="af-comp-title">Vocabulary reviewed!</p>' +
      '  <p class="af-unscored__sub">You\'ve seen all the cards.</p>' +
      '  <div class="af-unscored__chips af-unscored__chips--2">' +
      '    <div class="af-unscored__chip">' +
      '      <div class="af-unscored__chip-val" id="af-stat1-val">0</div>' +
      '      <div class="af-unscored__chip-lbl">CARDS SEEN</div>' +
      '    </div>' +
      '    <div class="af-unscored__chip">' +
      '      <div class="af-unscored__chip-val" id="af-stat2-val">0</div>' +
      '      <div class="af-unscored__chip-lbl">ROUNDS</div>' +
      '    </div>' +
      '  </div>' +
      '  <div class="af-unscored__banner af-unscored__banner--orange">' +
      '    <div class="af-unscored__banner-icon af-unscored__banner-icon--orange">' +
      '      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>' +
      '    </div>' +
      '    <div class="af-unscored__banner-text af-unscored__banner-text--orange">' +
      '      <span class="af-unscored__banner-title">Ready to practice?</span>' +
      '      Try the next activity to use this vocabulary.' +
      '    </div>' +
      '  </div>' +
      '  <div class="af-unscored__btns">' +
      '    <button class="af-unscored__btn-secondary" id="af-btn-retry">↺ Review again</button>' +
      '    <button class="af-unscored__btn-primary" id="af-btn-next">Next →</button>' +
      '  </div>' +
      '</div>';

    /* Populate stats */
    var fillEl    = document.getElementById('af-prog-fill');
    var textEl    = document.getElementById('af-prog-text');
    var stat1El   = document.getElementById('af-stat1-val');
    var stat2El   = document.getElementById('af-stat2-val');
    var retryBtn  = document.getElementById('af-btn-retry');
    var nextBtn2  = document.getElementById('af-btn-next');

    if (fillEl)  fillEl.style.width  = '100%';
    if (textEl)  textEl.textContent  = total + ' / ' + total;
    if (stat1El) stat1El.textContent = String(total);
    if (stat2El) stat2El.textContent = String(fcRounds);

    if (retryBtn) retryBtn.addEventListener('click', restart);

    if (nextBtn2) {
      if (returnTo) {
        nextBtn2.addEventListener('click', function () {
          var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
          var saveUrl = returnTo + sep + 'activity_percent=100&activity_errors=0&activity_total=' + total +
            '&activity_id=' + encodeURIComponent(activityId) + '&activity_type=flashcards';
          fetch(saveUrl, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
            .catch(function(){});
          setTimeout(function () {
            try {
              if (window.top && window.top !== window.self) { window.top.location.href = returnTo; return; }
            } catch(e) {}
            window.location.href = returnTo;
          }, 200);
        });
      } else {
        nextBtn2.style.display = 'none';
      }
    }

    try { winAudio.currentTime = 0; winAudio.play(); } catch(e) {}

    /* Persist score silently */
    if (returnTo && activityId) {
      var sep = returnTo.indexOf('?') !== -1 ? '&' : '?';
      fetch(returnTo + sep + 'activity_percent=100&activity_errors=0&activity_total=' + total +
        '&activity_id=' + encodeURIComponent(activityId) + '&activity_type=flashcards',
        { method: 'GET', credentials: 'same-origin', cache: 'no-store' }).catch(function(){});
    }
  }

  /* ── Restart ────────────────────────────────────────────────── */
  function restart() {
    index = 0;
    completedEl.innerHTML = '';
    completedEl.style.display = 'none';
    loadCard();
  }

  /* ── Events ─────────────────────────────────────────────────── */
  if (frontListenBtn) frontListenBtn.addEventListener('click', function (event) { event.stopPropagation(); listen(frontListenBtn, 'front'); });
  if (backListenBtn) backListenBtn.addEventListener('click', function (event) { event.stopPropagation(); listen(backListenBtn, 'back'); });
  if (showBtn)   showBtn.addEventListener('click', toggleWord);
  if (nextBtn)   nextBtn.addEventListener('click', nextCard);
  if (cardShell) cardShell.addEventListener('click', flipCard);

  loadCard();
});
