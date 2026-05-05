(function (window, document) {
  'use strict';

  function createMatchEngine(config) {
    const pairs = Array.isArray(config.data) ? config.data : [];
    const total = pairs.length;
    const selectors = config.selectors || {};
    const returnTo = typeof config.returnTo === 'string' ? config.returnTo : '';

    const $ = (selector) => selector ? document.querySelector(selector) : null;

    const stage = $(selectors.stage);
    const hint = $(selectors.hint);
    const progressFill = $(selectors.progressFill);
    const progressCount = $(selectors.progressCount);
    const toggle = $(selectors.toggle);
    const scoreCards = $(selectors.scoreCards);
    const scoreCorrect = $(selectors.scoreCorrect);
    const scoreWrong = $(selectors.scoreWrong);
    const scorePercent = $(selectors.scorePercent);
    const resetBtn = $(selectors.resetButton);
    const answerBtn = $(selectors.answerButton);
    const checkBtn = $(selectors.checkButton);
    const continueBtn = $(selectors.continueButton);
    const finalScore = $(selectors.finalScore);
    const finalSubText = $(selectors.finalSubText);

    const hasImages = pairs.some((item) => {
      return String(item.left_image || item.right_image || item.image || item.img || '').trim() !== '';
    });

    let selectedEn = null;
    let selectedMatch = null;
    let matched = [];
    let wrongs = 0;
    let viewMode = hasImages ? 'image' : 'text';
    let wrongFlash = null;
    let hintState = 'default';
    let hintText = 'Tap a word to start';
    let scoreVisible = false;

    function escapeHtml(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function getLeftText(item) {
      return String(item.left_text || item.text || item.word || 'Word').trim();
    }

    function getRightText(item) {
      return String(item.right_text || item.translation || item.text || item.word || 'Match').trim();
    }

    function getImage(item) {
      return String(item.right_image || item.left_image || item.image || item.img || '').trim();
    }

    function setHint(text, state) {
      hintText = text;
      hintState = state || 'default';

      if (!hint) {
        return;
      }

      hint.textContent = hintText;
      hint.className = 'match-hint' + (hintState !== 'default' ? ' is-' + hintState : '');
    }

    function isMatched(id) {
      return matched.indexOf(String(id)) !== -1;
    }

    function updateProgress() {
      const count = matched.length;
      const percent = total > 0 ? Math.round((count / total) * 100) : 0;

      if (progressFill) {
        progressFill.style.width = percent + '%';
      }

      if (progressCount) {
        progressCount.textContent = count + ' / ' + total;
      }
    }

    function getScorePercent() {
      const attempts = matched.length + wrongs;
      return attempts > 0 ? Math.round((matched.length / attempts) * 100) : 0;
    }

    function updateScores(show) {
      const percent = getScorePercent();

      if (scoreCorrect) {
        scoreCorrect.textContent = matched.length;
      }

      if (scoreWrong) {
        scoreWrong.textContent = wrongs;
      }

      if (scorePercent) {
        scorePercent.textContent = percent + '%';
      }

      if (scoreCards) {
        scoreCards.classList.toggle('is-visible', !!show);
      }
    }

    function chipClasses(item, side, baseClass) {
      const id = String(item.id);
      const classes = ['match-chip', baseClass || ''];

      if (side === 'en') {
        classes.push('match-en');

        if (selectedEn === id) {
          classes.push('is-selected-en');
        }
      } else {
        classes.push('match-pair');

        if (selectedMatch === id) {
          classes.push('is-selected-match');
        }
      }

      if (isMatched(id)) {
        classes.push('is-matched');
      }

      if (wrongFlash && wrongFlash.id === id && wrongFlash.side === side) {
        classes.push('is-wrong');
      }

      return classes.filter(Boolean).join(' ');
    }

    function renderDivider(label, purple) {
      return '<div class="match-divider"><span class="match-divider-label"><span class="match-dot' + (purple ? ' match-dot-purple' : '') + '"></span>' + escapeHtml(label) + '</span></div>';
    }

    function renderImageMode() {
      const english = pairs.map((item) => {
        const id = escapeHtml(item.id);
        const word = escapeHtml(getLeftText(item));

        return '<button type="button" class="' + chipClasses(item, 'en', 'match-card-chip') + '" data-side="en" data-id="' + id + '">' +
          '<span class="match-badge">✓</span>' +
          '<div class="match-chip-media">' + word + '</div>' +
          '<div class="match-chip-label">' + word + '</div>' +
          '</button>';
      }).join('');

      const images = pairs.map((item) => {
        const id = escapeHtml(item.id);
        const label = escapeHtml(getRightText(item));
        const image = getImage(item);
        const media = image
          ? '<img src="' + escapeHtml(image) + '" alt="' + label + '">'
          : label;

        return '<button type="button" class="' + chipClasses(item, 'match', 'match-card-chip') + '" data-side="match" data-id="' + id + '">' +
          '<span class="match-badge">✓</span>' +
          '<div class="match-chip-media">' + media + '</div>' +
          '<div class="match-chip-label">' + label + '</div>' +
          '</button>';
      }).join('');

      stage.innerHTML = '<div class="match-rows">' +
        renderDivider('English Words', false) +
        '<div class="match-image-row">' + english + '</div>' +
        renderDivider('Images / Matches', true) +
        '<div class="match-image-row">' + images + '</div>' +
        '</div>';
    }

    function renderTextMode() {
      const english = pairs.map((item) => {
        const id = escapeHtml(item.id);

        return '<button type="button" class="' + chipClasses(item, 'en', 'match-text-chip') + '" data-side="en" data-id="' + id + '">' +
          '<span class="match-badge">✓</span>' + escapeHtml(getLeftText(item)) + '</button>';
      }).join('');

      const matches = pairs.map((item) => {
        const id = escapeHtml(item.id);

        return '<button type="button" class="' + chipClasses(item, 'match', 'match-text-chip') + '" data-side="match" data-id="' + id + '">' +
          '<span class="match-badge">✓</span>' + escapeHtml(getRightText(item)) + '</button>';
      }).join('');

      stage.innerHTML = '<div class="match-text-grid">' +
        '<section>' + renderDivider('English', false) + '<div class="match-text-column">' + english + '</div></section>' +
        '<section>' + renderDivider('Spanish / Match', true) + '<div class="match-text-column">' + matches + '</div></section>' +
        '</div>';
    }

    function renderToggle() {
      if (!toggle) {
        return;
      }

      toggle.classList.toggle('is-hidden', !hasImages);

      toggle.querySelectorAll('[data-mode]').forEach((btn) => {
        btn.classList.toggle('is-active', btn.getAttribute('data-mode') === viewMode);
      });
    }

    function render() {
      updateProgress();
      updateScores(scoreVisible);
      renderToggle();

      if (!stage) {
        return;
      }

      if (viewMode === 'image') {
        renderImageMode();
      } else {
        renderTextMode();
      }

      stage.querySelectorAll('[data-side][data-id]').forEach((btn) => {
        btn.addEventListener('click', () => handleTap(btn.getAttribute('data-side'), btn.getAttribute('data-id')));
      });
    }

    function handleTap(side, id) {
      id = String(id);

      if (isMatched(id)) {
        return;
      }

      if (side === 'en') {
        selectedEn = id;
        selectedMatch = null;

        const item = pairs.find((pair) => String(pair.id) === id);
        setHint((item ? getLeftText(item) : 'Word') + ' selected — tap its match', 'selected');
        render();
        return;
      }

      if (selectedEn === null) {
        setHint('Tap an English word first', 'wrong');
        return;
      }

      selectedMatch = id;
      render();
      tryMatch();
    }

    function tryMatch() {
      const enId = selectedEn;
      const matchId = selectedMatch;

      if (enId === null || matchId === null) {
        return;
      }

      if (String(enId) === String(matchId)) {
        matched.push(String(enId));
        selectedEn = null;
        selectedMatch = null;

        if (matched.length === total) {
          setHint('🎉 All pairs matched!', 'complete');
          scoreVisible = true;
          render();
          showScore();
        } else {
          setHint('Correct! Keep going ✓', 'correct');
          render();
        }

        return;
      }

      wrongs += 1;
      wrongFlash = { id: String(enId), side: 'en' };
      setHint('Not a match — try again', 'wrong');
      render();

      window.setTimeout(() => {
        wrongFlash = { id: String(matchId), side: 'match' };
        render();
      }, 150);

      window.setTimeout(() => {
        selectedEn = null;
        selectedMatch = null;
        wrongFlash = null;
        setHint('Tap a word to start', 'default');
        render();
      }, 700);
    }

    function showScore() {
      scoreVisible = true;
      updateScores(true);

      const percent = getScorePercent();

      if (finalScore) {
        finalScore.textContent = matched.length + ' correct · ' + wrongs + ' wrong · ' + percent + '%';
      }

      if (finalSubText) {
        finalSubText.textContent = matched.length === total ? 'Great job! You matched every pair.' : 'Keep practicing and try again.';
      }

      if (continueBtn && returnTo) {
        continueBtn.style.display = 'inline-block';
      }
    }

    function resetGame() {
      selectedEn = null;
      selectedMatch = null;
      matched = [];
      wrongs = 0;
      wrongFlash = null;
      scoreVisible = false;
      setHint('Tap a word to start', 'default');
      render();
    }

    function showAnswers() {
      matched = pairs.map((item) => String(item.id));
      selectedEn = null;
      selectedMatch = null;
      wrongFlash = null;
      scoreVisible = true;
      setHint('🎉 All pairs matched!', 'complete');
      render();
      showScore();
    }

    function setMode(mode) {
      viewMode = mode === 'image' && hasImages ? 'image' : 'text';
      selectedEn = null;
      selectedMatch = null;
      wrongFlash = null;
      setHint('Tap a word to start', 'default');
      render();
    }

    function bindControls() {
      if (toggle) {
        toggle.querySelectorAll('[data-mode]').forEach((btn) => {
          btn.addEventListener('click', () => setMode(btn.getAttribute('data-mode')));
        });
      }

      if (resetBtn) {
        resetBtn.addEventListener('click', resetGame);
      }

      if (answerBtn) {
        answerBtn.addEventListener('click', showAnswers);
      }

      if (checkBtn) {
        checkBtn.addEventListener('click', showScore);
      }

      if (continueBtn) {
        continueBtn.addEventListener('click', () => {
          if (returnTo) {
            window.location.href = returnTo;
          }
        });
      }
    }

    function init() {
      bindControls();
      render();
    }

    return {
      init,
      render,
      reset: resetGame,
      showAnswers,
      showScore,
      setMode,
      getState: function () {
        return {
          selectedEn,
          selectedMatch,
          matched: matched.slice(),
          wrongs,
          viewMode,
          scoreVisible,
          total
        };
      }
    };
  }

  window.MatchEngine = {
    init: function (config) {
      const engine = createMatchEngine(config || {});
      engine.init();
      return engine;
    },
    create: createMatchEngine
  };
})(window, document);
