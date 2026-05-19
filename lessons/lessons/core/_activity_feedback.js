/**
 * _activity_feedback.js
 * Unified feedback, scoring, and completed-screen system for all LETS activities.
 * Include this script BEFORE any activity script.
 *
 * Usage:
 *   ActivityFeedback.showFeedback(containerEl, isCorrect, correctAnswerText, wasRevealed)
 *   ActivityFeedback.clearFeedback(containerEl)
 *   ActivityFeedback.showCompleted(opts)
 *   ActivityFeedback.computeScore(scores)   // scores = array of 1|0|-1 (-1=revealed)
 *
 * CSS classes injected once into <head>:
 *   .af-feedback, .af-feedback--correct, .af-feedback--wrong, .af-feedback--revealed
 *   .af-completed  (full completed screen element)
 */
(function (global) {
  'use strict';

  /* ── CSS (injected once) ─────────────────────────────────── */
  var STYLE_ID = 'activity-feedback-styles';
  if (!document.getElementById(STYLE_ID)) {
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = [
      /* feedback pill */
      '.af-feedback{display:flex;align-items:flex-start;gap:8px;padding:10px 14px;border-radius:12px;font-family:"Nunito","Segoe UI",sans-serif;font-size:13px;font-weight:700;margin:8px 0;box-sizing:border-box;width:100%}',
      '.af-feedback--correct{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}',
      '.af-feedback--wrong{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;flex-direction:column;align-items:flex-start;gap:4px}',
      '.af-feedback--revealed{background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A}',
      '.af-feedback__row{display:flex;align-items:center;gap:6px}',
      '.af-feedback__answer{display:inline-block;font-size:12px;font-weight:700;color:#7F77DD;background:#EDE9FA;padding:3px 10px;border-radius:6px;margin-top:2px}',
      /* option highlight helpers (applied to existing option elements) */
      '.af-opt--correct{border-color:#22c55e!important;background:#f0fdf4!important;color:#166534!important}',
      '.af-opt--wrong{border-color:#ef4444!important;background:#fef2f2!important;color:#991b1b!important}',
      /* completed screen */
      '.af-completed{display:none;text-align:center;padding:32px 20px;max-width:520px;margin:0 auto;font-family:"Nunito","Segoe UI",sans-serif}',
      '.af-completed.af-completed--active{display:block}',
      '.af-completed__bg{background:linear-gradient(160deg,#EDE9FA,#FFF0E6);border-radius:24px;padding:28px 20px}',
      '.af-completed__emoji{font-size:52px;line-height:1;margin-bottom:8px}',
      '.af-completed__title{font-family:"Fredoka","Trebuchet MS",sans-serif;font-size:28px;font-weight:700;color:#7F77DD;margin:0 0 4px}',
      '.af-completed__sub{font-size:13px;color:#9B8FCC;font-weight:600;margin:0 0 14px}',
      '.af-completed__score{font-size:52px;font-weight:700;color:#F97316;line-height:1;font-family:monospace;margin:0 0 4px}',
      '.af-completed__score-label{font-size:12px;color:#9B8FCC;font-weight:600;margin:0 0 14px}',
      '.af-completed__chips{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-bottom:20px}',
      '.af-completed__chip{background:#fff;border:1px solid #EDE9FA;border-radius:999px;padding:6px 14px;font-size:12px;font-weight:700;color:#534AB7}',
      '.af-completed__chip--good{color:#166534;border-color:#bbf7d0;background:#f0fdf4}',
      '.af-completed__chip--bad{color:#991b1b;border-color:#fecaca;background:#fef2f2}',
      '.af-completed__btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}',
      '.af-btn-review{background:#7F77DD;color:#fff;border:none;border-radius:999px;padding:11px 22px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit}',
      '.af-btn-retry{background:transparent;border:1.5px solid #EDE9FA;color:#7F77DD;border-radius:999px;padding:11px 22px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit}',
      /* review list */
      '.af-review{display:none;max-width:520px;margin:0 auto;font-family:"Nunito","Segoe UI",sans-serif}',
      '.af-review--active{display:block}',
      '.af-review__title{font-family:"Fredoka","Trebuchet MS",sans-serif;font-size:18px;font-weight:700;color:#7F77DD;margin:0 0 12px;text-align:center}',
      '.af-review__list{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}',
      '.af-review__item{background:#fff;border:0.5px solid #EDE9FA;border-radius:14px;padding:12px;display:flex;flex-direction:column;gap:4px}',
      '.af-review__item--revealed{background:#FFF9F5;border-color:#FCDDBF}',
      '.af-review__q{font-size:13px;font-weight:700;color:#333}',
      '.af-review__row{display:flex;align-items:center;gap:6px;font-size:12px}',
      '.af-review__dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}',
      '.af-review__dot--ok{background:#22c55e}',
      '.af-review__dot--bad{background:#ef4444}',
      '.af-review__dot--revealed{background:#F97316}',
      '.af-review__label{color:#888;min-width:60px;font-weight:600}',
      '.af-review__val--ok{font-weight:700;color:#166534}',
      '.af-review__val--bad{font-weight:700;color:#991b1b}',
      '.af-review__note{font-size:11px;color:#9B8FCC;font-style:italic}',
      '.af-review__correct-val{font-weight:700;color:#7F77DD}',
    ].join('');
    document.head.appendChild(style);
  }

  /* ── Helpers ─────────────────────────────────────────────── */
  function h(tag, cls, html) {
    var el = document.createElement(tag);
    if (cls) el.className = cls;
    if (html) el.innerHTML = html;
    return el;
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* ── showFeedback ────────────────────────────────────────── */
  /**
   * @param {Element} container  - element that will hold the pill (cleared first)
   * @param {boolean} isCorrect
   * @param {string}  correctText - shown when wrong
   * @param {boolean} wasRevealed - true if "Show Answer" was used
   */
  function showFeedback(container, isCorrect, correctText, wasRevealed) {
    clearFeedback(container);
    var pill = h('div');
    if (wasRevealed) {
      pill.className = 'af-feedback af-feedback--revealed';
      pill.innerHTML = '<span style="font-size:15px">&#128064;</span>' +
        '<span>Answer revealed — this question won’t count toward your score.</span>';
    } else if (isCorrect) {
      pill.className = 'af-feedback af-feedback--correct';
      pill.innerHTML = '<span style="font-size:15px">&#9989;</span><span>Correct! Well done.</span>';
    } else {
      pill.className = 'af-feedback af-feedback--wrong';
      var ans = correctText ? '<span class="af-feedback__answer">' + esc(correctText) + '</span>' : '';
      pill.innerHTML =
        '<div class="af-feedback__row"><span style="font-size:15px">&#10060;</span>' +
        '<span>Not quite. The correct answer is:</span></div>' + ans;
    }
    container.appendChild(pill);
  }

  function clearFeedback(container) {
    var existing = container.querySelectorAll('.af-feedback');
    for (var i = 0; i < existing.length; i++) existing[i].remove();
  }

  /* ── highlightOption ─────────────────────────────────────── */
  /**
   * @param {Element} el        - the option button/div
   * @param {'correct'|'wrong'} kind
   */
  function highlightOption(el, kind) {
    el.classList.remove('af-opt--correct', 'af-opt--wrong');
    if (kind === 'correct') el.classList.add('af-opt--correct');
    if (kind === 'wrong')   el.classList.add('af-opt--wrong');
  }

  function clearHighlights(container) {
    var els = container.querySelectorAll('.af-opt--correct,.af-opt--wrong');
    for (var i = 0; i < els.length; i++) {
      els[i].classList.remove('af-opt--correct', 'af-opt--wrong');
    }
  }

  /* ── computeScore ────────────────────────────────────────── */
  /**
   * @param  {Array} scores  - each item: 1=correct, 0=wrong, -1=revealed(skip)
   * @return {object} { correct, wrong, revealed, scorable, total, percent }
   */
  function computeScore(scores) {
    var correct = 0, wrong = 0, revealed = 0;
    for (var i = 0; i < scores.length; i++) {
      if (scores[i] === 1) correct++;
      else if (scores[i] === -1) revealed++;
      else wrong++;
    }
    var scorable = scores.length - revealed;
    var percent  = scorable > 0 ? Math.round((correct / scorable) * 100) : 100;
    return {
      correct:  correct,
      wrong:    wrong,
      revealed: revealed,
      scorable: scorable,
      total:    scores.length,
      percent:  percent
    };
  }

  /* ── showCompleted ───────────────────────────────────────── */
  /**
   * @param {object} opts
   *   target       {Element}   container element to inject into
   *   scores       {Array}     array of 1|0|-1
   *   title        {string}    activity title
   *   activityType {string}    e.g. 'Multiple Choice'
   *   questionCount{number}
   *   onRetry      {Function}
   *   onReview     {Function}  optional – if omitted, "See review" is hidden
   *   hideActivity {Element}   optional element to hide when showing completed
   *   extraChips   {Array}     optional [{label, kind}] e.g. [{label:'16 moves',kind:''}]
   *   winAudio     {Element}   optional <audio> element
   */
  function showCompleted(opts) {
    var target      = opts.target;
    var scores      = opts.scores || [];
    var title       = opts.title || 'Activity';
    var actType     = opts.activityType || '';
    var onRetry     = opts.onRetry || null;
    var onReview    = opts.onReview || null;
    var hideEl      = opts.hideActivity || null;
    var extraChips  = opts.extraChips || [];
    var winAudio    = opts.winAudio || null;

    var result = computeScore(scores);
    var pct    = result.percent;

    var emoji, headline, bgStyle;
    if (pct >= 80) {
      emoji = '&#127942;'; headline = 'Activity complete!';
      bgStyle = 'background:linear-gradient(160deg,#EDE9FA,#FFF0E6)';
    } else if (pct >= 60) {
      emoji = '&#127919;'; headline = 'Good job!';
      bgStyle = 'background:linear-gradient(160deg,#EDE9FA,#FFF0E6)';
    } else {
      emoji = '&#128170;'; headline = 'Keep practicing!';
      bgStyle = 'background:linear-gradient(160deg,#fef2f2,#FFF0E6)';
    }

    /* remove previous completed screen */
    var old = target.querySelector('.af-completed');
    if (old) old.remove();

    var wrap = h('div', 'af-completed af-completed--active');
    var bg   = h('div', 'af-completed__bg');
    bg.setAttribute('style', bgStyle);

    bg.innerHTML =
      '<div class="af-completed__emoji">' + emoji + '</div>' +
      '<h2 class="af-completed__title">' + esc(headline) + '</h2>' +
      '<p class="af-completed__sub">' + esc(actType) + (actType && opts.questionCount ? ' · ' + opts.questionCount + ' questions' : '') + '</p>' +
      '<div class="af-completed__score">' + pct + '%</div>' +
      '<div class="af-completed__score-label">' + result.correct + ' out of ' + result.scorable + ' correct</div>' +
      '<div class="af-completed__chips" id="af-chips-inner"></div>' +
      '<div class="af-completed__btns" id="af-btns-inner"></div>';

    var chipsEl = bg.querySelector('#af-chips-inner');
    /* correct chip */
    var c1 = h('span', 'af-completed__chip af-completed__chip--good');
    c1.textContent = '✅ ' + result.correct + ' correct';
    chipsEl.appendChild(c1);
    /* wrong chip */
    if (result.wrong > 0) {
      var c2 = h('span', 'af-completed__chip af-completed__chip--bad');
      c2.textContent = '❌ ' + result.wrong + ' wrong';
      chipsEl.appendChild(c2);
    }
    /* extra chips */
    for (var i = 0; i < extraChips.length; i++) {
      var cx = h('span', 'af-completed__chip' + (extraChips[i].kind ? ' af-completed__chip--' + extraChips[i].kind : ''));
      cx.textContent = extraChips[i].label;
      chipsEl.appendChild(cx);
    }

    var btnsEl = bg.querySelector('#af-btns-inner');
    if (onReview) {
      var btnReview = h('button', 'af-btn-review');
      btnReview.textContent = 'See review';
      btnReview.addEventListener('click', onReview);
      btnsEl.appendChild(btnReview);
    }
    if (onRetry) {
      var btnRetry = h('button', 'af-btn-retry');
      btnRetry.textContent = 'Try again';
      btnRetry.addEventListener('click', onRetry);
      btnsEl.appendChild(btnRetry);
    }

    wrap.appendChild(bg);
    target.appendChild(wrap);

    if (hideEl) hideEl.style.display = 'none';
    if (winAudio) {
      try { winAudio.currentTime = 0; winAudio.play().catch(function(){}); } catch(e) {}
    }
  }

  /* ── showReview ──────────────────────────────────────────── */
  /**
   * @param {object} opts
   *   target    {Element}
   *   items     {Array}  [{question, yourAnswer, correctAnswer, score}]
   *             score: 1=correct, 0=wrong, -1=revealed
   *   onRetry   {Function}
   *   hideEl    {Element}  optional element to hide
   */
  function showReview(opts) {
    var target  = opts.target;
    var items   = opts.items || [];
    var onRetry = opts.onRetry || null;
    var hideEl  = opts.hideEl || null;

    var old = target.querySelector('.af-review');
    if (old) old.remove();

    var wrap = h('div', 'af-review af-review--active');
    wrap.innerHTML = '<h3 class="af-review__title">Review</h3>';

    var list = h('div', 'af-review__list');
    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      var isRevealed = it.score === -1;
      var isCorrect  = it.score === 1;
      var itemEl = h('div', 'af-review__item' + (isRevealed ? ' af-review__item--revealed' : ''));

      var qEl = h('div', 'af-review__q');
      qEl.textContent = 'Q' + (i + 1) + (it.question ? ' · ' + it.question : '');
      itemEl.appendChild(qEl);

      if (isRevealed) {
        var noteEl = h('div', 'af-review__note');
        noteEl.textContent = '👀 Answer was revealed — not counted';
        itemEl.appendChild(noteEl);
      } else {
        var row1 = h('div', 'af-review__row');
        var dot1 = h('span', 'af-review__dot ' + (isCorrect ? 'af-review__dot--ok' : 'af-review__dot--bad'));
        var lbl1 = h('span', 'af-review__label');
        lbl1.textContent = 'Your answer:';
        var val1 = h('span', isCorrect ? 'af-review__val--ok' : 'af-review__val--bad');
        val1.textContent = (it.yourAnswer || '—') + (isCorrect ? ' ✓' : ' ✕');
        row1.appendChild(dot1); row1.appendChild(lbl1); row1.appendChild(val1);
        itemEl.appendChild(row1);

        if (!isCorrect && it.correctAnswer) {
          var row2 = h('div', 'af-review__row');
          var sp = h('span'); sp.style.width = '8px'; sp.style.display = 'inline-block';
          var lbl2 = h('span', 'af-review__label');
          lbl2.textContent = 'Correct:';
          var val2 = h('span', 'af-review__correct-val');
          val2.textContent = it.correctAnswer;
          row2.appendChild(sp); row2.appendChild(lbl2); row2.appendChild(val2);
          itemEl.appendChild(row2);
        }
      }
      list.appendChild(itemEl);
    }
    wrap.appendChild(list);

    if (onRetry) {
      var btnWrap = h('div', 'af-completed__btns');
      var btnRetry = h('button', 'af-btn-retry');
      btnRetry.textContent = 'Try again';
      btnRetry.addEventListener('click', onRetry);
      btnWrap.appendChild(btnRetry);
      wrap.appendChild(btnWrap);
    }

    target.appendChild(wrap);
    if (hideEl) hideEl.style.display = 'none';
  }

  /* ── Public API ──────────────────────────────────────────── */
  global.ActivityFeedback = {
    showFeedback:    showFeedback,
    clearFeedback:   clearFeedback,
    highlightOption: highlightOption,
    clearHighlights: clearHighlights,
    computeScore:    computeScore,
    showCompleted:   showCompleted,
    showReview:      showReview
  };

}(window));
