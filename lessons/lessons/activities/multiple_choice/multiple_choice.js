document.addEventListener('DOMContentLoaded', function () {
  const questions = Array.isArray(window.MULTIPLE_CHOICE_DATA) ? window.MULTIPLE_CHOICE_DATA : [];

  const statusEl = document.getElementById('mc-status');
  const questionEl = document.getElementById('mc-question');
  const imageEl = document.getElementById('mc-image');
  const optionsEl = document.getElementById('mc-options');
  const feedbackEl = document.getElementById('mc-feedback');
  const checkBtn = document.getElementById('mc-check');
  const nextBtn = document.getElementById('mc-next');

  if (!questions.length) {
    if (questionEl) {
      questionEl.textContent = 'No questions available.';
    }
    return;
  }

  let index = 0;
  let selected = null;

  function safeOptions(item) {
    return item && Array.isArray(item.options) ? item.options : [];
  }

  function loadQuestion() {
    const item = questions[index] || {};

    selected = null;
    feedbackEl.textContent = '';
    feedbackEl.className = 'mc-feedback';

    statusEl.textContent = 'Question ' + (index + 1) + ' of ' + questions.length;
    questionEl.textContent = item.question || '';

    if (item.image) {
      imageEl.style.display = 'block';
      imageEl.src = item.image;
    } else {
      imageEl.style.display = 'none';
      imageEl.removeAttribute('src');
    }

    optionsEl.innerHTML = '';

    safeOptions(item).forEach(function (optionText, optIndex) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'mc-option';
      button.textContent = optionText;
      button.addEventListener('click', function () {
        selected = optIndex;
        Array.prototype.forEach.call(optionsEl.querySelectorAll('.mc-option'), function (node) {
          node.classList.remove('selected');
        });
        button.classList.add('selected');
      });
      optionsEl.appendChild(button);
    });
  }

  function checkAnswer() {
    const item = questions[index] || {};
    const correct = Number.isInteger(item.correct) ? item.correct : 0;
    const options = optionsEl.querySelectorAll('.mc-option');

    if (selected === null) {
      feedbackEl.textContent = 'Selecciona una opción primero.';
      feedbackEl.className = 'mc-feedback bad';
      return;
    }

    Array.prototype.forEach.call(options, function (node, optIndex) {
      node.classList.remove('correct', 'wrong');
      if (optIndex === correct) {
        node.classList.add('correct');
      }
      if (optIndex === selected && selected !== correct) {
        node.classList.add('wrong');
      }
    });

    if (selected === correct) {
      feedbackEl.textContent = '🌟 Excellent!';
      feedbackEl.className = 'mc-feedback good';
    } else {
      feedbackEl.textContent = '🔁 Try again!';
      feedbackEl.className = 'mc-feedback bad';
    }
  }

  function nextQuestion() {
    if (index < questions.length - 1) {
      index += 1;
      loadQuestion();
      return;
    }

    feedbackEl.textContent = '🏆 Completed!';
    feedbackEl.className = 'mc-feedback good';
  }

  checkBtn.addEventListener('click', checkAnswer);
  nextBtn.addEventListener('click', nextQuestion);

  loadQuestion();
});
