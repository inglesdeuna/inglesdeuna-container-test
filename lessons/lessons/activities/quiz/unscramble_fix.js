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

  function getAfterElement(container, y) {
    var chips = Array.from(container.querySelectorAll('.us-chip:not(.dragging)'));

    return chips.reduce(function (closest, child) {
      var box = child.getBoundingClientRect();
      var offset = y - box.top - box.height / 2;

      if (offset < 0 && offset > closest.offset) {
        return { offset: offset, element: child };
      }

      return closest;
    }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
  }

  function renumber(list) {
    Array.from(list.querySelectorAll('.us-chip')).forEach(function (chip, index) {
      chip.style.setProperty('--us-num', '"' + (index + 1) + '"');
      chip.setAttribute('data-pos', String(index + 1));
    });
  }

  function enhanceUnscramble() {
    var form = findUnscrambleForm();
    if (!form || form.dataset.usEnhanced === '1') return;

    var list = document.getElementById('us-list');
    var answer = document.getElementById('us-answer');
    if (!list || !answer) return;

    form.dataset.usEnhanced = '1';
    list.classList.add('us-list-vertical');

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
      chip.classList.add('us-chip-vertical');

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
      var afterElement = getAfterElement(list, e.clientY);
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
    '.us-list.us-list-vertical{display:flex!important;flex-direction:column!important;flex-wrap:nowrap!important;gap:10px!important;min-height:220px!important;padding:14px!important;background:#fbfaff!important;border:1px solid #e9e3fb!important;border-radius:18px!important}',
    '.us-chip.us-chip-vertical{width:100%!important;display:flex!important;align-items:center!important;gap:12px!important;cursor:grab!important;text-align:left!important;padding:14px 16px!important;border-radius:16px!important;background:#fff!important;border:1px solid #EDE9FA!important;box-shadow:0 5px 15px rgba(127,119,221,.12)!important;color:#534AB7!important;font-weight:900!important;transition:transform .12s ease, border-color .12s ease, box-shadow .12s ease!important}',
    '.us-chip.us-chip-vertical:before{content:attr(data-pos);width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#EEEDFE;color:#534AB7;font-size:12px;font-weight:900;flex:0 0 auto}',
    '.us-chip.us-chip-vertical:hover{border-color:#7F77DD!important;transform:translateY(-1px)!important;box-shadow:0 8px 18px rgba(127,119,221,.18)!important}',
    '.us-chip.dragging{opacity:.35!important;transform:scale(.98)!important}',
    '.us-chip.selected-swap{outline:3px solid rgba(127,119,221,.28)!important;border-color:#7F77DD!important}',
    '@media(max-width:760px){.us-list.us-list-vertical{min-height:180px!important}.us-chip.us-chip-vertical{padding:13px 14px!important}}'
  ].join('\n');
  document.head.appendChild(style);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhanceUnscramble);
  } else {
    enhanceUnscramble();
  }
})();
