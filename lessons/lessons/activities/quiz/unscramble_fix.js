(function () {
  function norm(text) {
    return String(text || '')
      .toLowerCase()
      .trim()
      .replace(/\s+/g, ' ');
  }

  function findUnscrambleForm() {
    return document.getElementById('us-form');
  }

  function currentSentence(list) {
    return Array.from(list.querySelectorAll('.us-chip'))
      .map(function (chip) { return chip.textContent.trim(); })
      .join(' ');
  }

  function answerIsCorrect(list, correct) {
    return norm(currentSentence(list)) === norm(correct);
  }

  function getAfterElement(container, x, y) {
    var chips = Array.from(container.querySelectorAll('.us-chip:not(.dragging)'));
    var sameRow = chips.filter(function (child) {
      var box = child.getBoundingClientRect();
      return y >= box.top - 8 && y <= box.bottom + 8;
    });
    var candidates = sameRow.length ? sameRow : chips;

    return candidates.reduce(function (closest, child) {
      var box = child.getBoundingClientRect();
      var offset = x - box.left - box.width / 2;

      if (offset < 0 && offset > closest.offset) {
        return { offset: offset, element: child };
      }

      return closest;
    }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
  }

  function renumber(list) {
    Array.from(list.querySelectorAll('.us-chip')).forEach(function (chip, index) {
      chip.setAttribute('data-pos', String(index + 1));
    });
  }

  function widenQuizCard() {
    var form = findUnscrambleForm();
    if (!form) return;
    var card = form.closest('.card');
    if (card) card.classList.add('us-wide-card');
  }

  function enhanceUnscramble() {
    var form = findUnscrambleForm();
    if (!form || form.dataset.usEnhanced === '1') return;

    var list = document.getElementById('us-list');
    var answer = document.getElementById('us-answer');
    if (!list || !answer) return;

    form.dataset.usEnhanced = '1';
    list.classList.add('us-list-horizontal');
    widenQuizCard();

    var chips = Array.from(list.querySelectorAll('.us-chip'));
    var correct = chips.map(function (chip) { return chip.textContent.trim(); }).sort().join(' ');

    var inlineScripts = Array.from(document.scripts).map(function (s) { return s.textContent || ''; }).join('\n');
    var match = inlineScripts.match(/var\s+correct\s*=\s*(["'])(.*?)\1/);
    if (match && match[2]) correct = match[2];

    var submitted = false;
    var selected = null;
    var dragged = null;

    function submitIfCorrect() {
      answer.value = currentSentence(list);
      renumber(list);
      if (!submitted && answerIsCorrect(list, correct)) {
        submitted = true;
        setTimeout(function () { form.submit(); }, 250);
      }
    }

    chips.forEach(function (chip) {
      chip.setAttribute('draggable', 'true');
      chip.classList.add('us-chip-horizontal');

      chip.addEventListener('dragstart', function () {
        dragged = chip;
        chip.classList.add('dragging');
      });

      chip.addEventListener('dragend', function () {
        chip.classList.remove('dragging');
        dragged = null;
        submitIfCorrect();
      });

      chip.addEventListener('click', function () {
        if (!selected) {
          selected = chip;
          chip.classList.add('selected-swap');
          return;
        }

        if (selected === chip) {
          chip.classList.remove('selected-swap');
          selected = null;
          return;
        }

        var a = selected;
        var b = chip;
        var marker = document.createElement('span');
        list.insertBefore(marker, a);
        list.insertBefore(a, b);
        list.insertBefore(b, marker);
        marker.remove();

        a.classList.remove('selected-swap');
        selected = null;
        submitIfCorrect();
      });
    });

    list.addEventListener('dragover', function (e) {
      e.preventDefault();
      if (!dragged) return;
      var afterElement = getAfterElement(list, e.clientX, e.clientY);
      if (afterElement == null) {
        list.appendChild(dragged);
      } else {
        list.insertBefore(dragged, afterElement);
      }
      renumber(list);
    });

    renumber(list);
    submitIfCorrect();
  }

  var style = document.createElement('style');
  style.textContent = [
    '.card.us-wide-card{max-width:980px!important;width:min(980px,96vw)!important}',
    '.us-list.us-list-horizontal{display:flex!important;flex-direction:row!important;flex-wrap:wrap!important;align-items:center!important;gap:12px!important;min-height:150px!important;padding:16px!important;background:#fbfaff!important;border:1px solid #e9e3fb!important;border-radius:18px!important}',
    '.us-chip.us-chip-horizontal{width:auto!important;min-width:86px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;cursor:grab!important;text-align:center!important;padding:13px 18px!important;border-radius:16px!important;background:#fff!important;border:1px solid #EDE9FA!important;box-shadow:0 5px 15px rgba(127,119,221,.12)!important;color:#534AB7!important;font-weight:900!important;transition:transform .12s ease, border-color .12s ease, box-shadow .12s ease!important}',
    '.us-chip.us-chip-horizontal:before{content:attr(data-pos);width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#EEEDFE;color:#534AB7;font-size:11px;font-weight:900;flex:0 0 auto}',
    '.us-chip.us-chip-horizontal:hover{border-color:#7F77DD!important;transform:translateY(-1px)!important;box-shadow:0 8px 18px rgba(127,119,221,.18)!important}',
    '.us-chip.dragging{opacity:.35!important;transform:scale(.98)!important}',
    '.us-chip.selected-swap{outline:3px solid rgba(127,119,221,.28)!important;border-color:#7F77DD!important}',
    '@media(max-width:760px){.card.us-wide-card{width:100%!important}.us-list.us-list-horizontal{min-height:150px!important}.us-chip.us-chip-horizontal{min-width:72px!important;padding:12px 14px!important}}'
  ].join('\n');
  document.head.appendChild(style);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhanceUnscramble);
  } else {
    enhanceUnscramble();
  }
})();
