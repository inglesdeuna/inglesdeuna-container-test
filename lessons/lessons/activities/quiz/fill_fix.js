(function () {
  function norm(text) {
    return String(text || '')
      .toLowerCase()
      .trim()
      .replace(/[.,!?;:'"-]/g, '')
      .replace(/\s+/g, ' ');
  }

  function isFillPage() {
    var tag = document.querySelector('.tag');
    return !!(tag && /fill/i.test(tag.textContent || ''));
  }

  function findFillForm() {
    if (!isFillPage()) return null;
    return Array.from(document.querySelectorAll('form')).find(function (form) {
      return form.querySelector('input.input[name="answer"]');
    }) || null;
  }

  function enhanceFill() {
    var form = findFillForm();
    if (!form || form.dataset.fillEnhanced === '1') return;

    var input = form.querySelector('input.input[name="answer"]');
    if (!input) return;

    form.dataset.fillEnhanced = '1';

    var card = form.closest('.card');
    if (card) card.classList.add('fill-wide-card');

    input.classList.add('fill-quiz-input');
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('autocapitalize', 'none');
    input.setAttribute('spellcheck', 'false');
    input.placeholder = 'Type your answer';

    var nextButton = form.querySelector('button[type="submit"]:not([name="skip"])');
    if (nextButton) nextButton.style.display = 'none';

    var submitted = false;
    var timer = null;

    function submitNow() {
      if (submitted) return;
      submitted = true;
      form.submit();
    }

    input.addEventListener('input', function () {
      clearTimeout(timer);
      if (!norm(input.value)) return;
      timer = setTimeout(submitNow, 650);
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        submitNow();
      }
    });

    setTimeout(function () {
      try { input.focus(); } catch (e) {}
    }, 180);
  }

  var style = document.createElement('style');
  style.textContent = [
    '.card.fill-wide-card{max-width:820px!important;width:min(820px,96vw)!important}',
    '.fill-quiz-input{font-size:24px!important;line-height:1.25!important;padding:20px 22px!important;border-radius:18px!important;border:1.5px solid #EDE9FA!important;background:#fff!important;color:#534AB7!important;box-shadow:0 7px 18px rgba(127,119,221,.12)!important;font-weight:900!important;text-align:center!important}',
    '.fill-quiz-input:focus{outline:3px solid rgba(127,119,221,.20)!important;border-color:#8070dd!important}',
    '@media(max-width:760px){.card.fill-wide-card{width:100%!important}.fill-quiz-input{font-size:20px!important;padding:17px 18px!important}}'
  ].join('\n');
  document.head.appendChild(style);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhanceFill);
  } else {
    enhanceFill();
  }
})();
