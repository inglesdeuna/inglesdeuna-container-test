document.addEventListener('DOMContentLoaded', function () {
  const boards = Array.isArray(window.MATCHING_LINES_DATA) ? window.MATCHING_LINES_DATA : [];

  const stage = document.getElementById('mlvStage');
  const leftCol = document.getElementById('mlvLeft');
  const rightCol = document.getElementById('mlvRight');
  const svg = document.getElementById('mlvLines');
  const boardTitle = document.getElementById('mlvBoardTitle');
  const progress = document.getElementById('mlvProgress');
  const prevBtn = document.getElementById('mlvPrevBtn');
  const nextBtn = document.getElementById('mlvNextBtn');
  const showBtn = document.getElementById('mlvShowBtn');
  const resetBtn = document.getElementById('mlvResetBtn');

  if (!stage || !leftCol || !rightCol || !svg || boards.length === 0) {
    return;
  }

  const stateByBoardId = {};
  let currentIndex = 0;
  let selectedLeftId = '';
  let selectedRightId = '';

  function shuffle(items) {
    const copied = items.slice();
    for (let i = copied.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      const temp = copied[i];
      copied[i] = copied[j];
      copied[j] = temp;
    }
    return copied;
  }

  function esc(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function getBoardState(board) {
    const boardId = String(board.id || 'board_' + currentIndex);
    if (!stateByBoardId[boardId]) {
      const rightOrder = shuffle((board.pairs || []).map((pair) => String(pair.id || '')));
      stateByBoardId[boardId] = {
        rightOrder,
        matches: {},
        showAnswer: false,
      };
    }
    return stateByBoardId[boardId];
  }

  function createCardHtml(pair, side) {
    const pairId = esc(pair.id || '');
    const text = side === 'left' ? esc(pair.left_text || '') : esc(pair.right_text || '');
    const image = side === 'left' ? esc(pair.left_image || '') : esc(pair.right_image || '');
    const media = image !== '' ? '<img class="mlv-media" src="' + image + '" alt="item">' : '';
    const label = text !== '' ? '<div class="mlv-text">' + text + '</div>' : '';

    return '<button type="button" class="mlv-card" data-pair-id="' + pairId + '">'
      + media + label + '<span class="mlv-anchor" aria-hidden="true"></span></button>';
  }

  function getCardCenter(card, isLeft) {
    const stageRect = stage.getBoundingClientRect();
    const anchor = card.querySelector('.mlv-anchor') || card;
    const rect = anchor.getBoundingClientRect();

    const x = isLeft
      ? rect.right - stageRect.left
      : rect.left - stageRect.left;

    const y = rect.top + rect.height / 2 - stageRect.top;

    return { x, y };
  }

  function drawLine(x1, y1, x2, y2, color, width, dashArray) {
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    line.setAttribute('x1', String(x1));
    line.setAttribute('y1', String(y1));
    line.setAttribute('x2', String(x2));
    line.setAttribute('y2', String(y2));
    line.setAttribute('stroke', color);
    line.setAttribute('stroke-width', String(width));
    line.setAttribute('stroke-linecap', 'round');
    if (dashArray) {
      line.setAttribute('stroke-dasharray', dashArray);
    }
    svg.appendChild(line);
  }

  function renderLines(board, boardState) {
    svg.innerHTML = '';

    const matches = boardState.matches || {};
    Object.keys(matches).forEach(function (leftId) {
      const rightId = matches[leftId];
      const leftCard = leftCol.querySelector('[data-pair-id="' + CSS.escape(leftId) + '"]');
      const rightCard = rightCol.querySelector('[data-pair-id="' + CSS.escape(rightId) + '"]');
      if (!leftCard || !rightCard) {
        return;
      }
      const p1 = getCardCenter(leftCard, true);
      const p2 = getCardCenter(rightCard, false);
      drawLine(p1.x, p1.y, p2.x, p2.y, '#0f766e', 4, '');
    });

    if (boardState.showAnswer) {
      (board.pairs || []).forEach(function (pair) {
        const id = String(pair.id || '');
        if (matches[id]) {
          return;
        }
        const leftCard = leftCol.querySelector('[data-pair-id="' + CSS.escape(id) + '"]');
        const rightCard = rightCol.querySelector('[data-pair-id="' + CSS.escape(id) + '"]');
        if (!leftCard || !rightCard) {
          return;
        }
        const p1 = getCardCenter(leftCard, true);
        const p2 = getCardCenter(rightCard, false);
        drawLine(p1.x, p1.y, p2.x, p2.y, '#7c3aed', 3, '8 6');
      });
    }
  }

  function updateButtonState(boardState) {
    prevBtn.disabled = currentIndex <= 0;
    nextBtn.disabled = currentIndex >= boards.length - 1;
    showBtn.textContent = boardState.showAnswer ? 'Hide Answer' : 'Show Answer';
  }

  function updateProgress(board, boardState) {
    const total = Array.isArray(board.pairs) ? board.pairs.length : 0;
    const done = Object.keys(boardState.matches || {}).length;
    progress.textContent = 'Matched ' + done + ' / ' + total;
  }

  function clearSelection() {
    selectedLeftId = '';
    selectedRightId = '';
    leftCol.querySelectorAll('.mlv-card.selected').forEach(function (el) {
      el.classList.remove('selected');
    });
    rightCol.querySelectorAll('.mlv-card.selected').forEach(function (el) {
      el.classList.remove('selected');
    });
  }

  function isRightAlreadyUsed(matches, rightId) {
    return Object.values(matches).indexOf(rightId) !== -1;
  }

  function flashWrongLine(leftId, rightId) {
    const leftCard = leftCol.querySelector('[data-pair-id="' + CSS.escape(leftId) + '"]');
    const rightCard = rightCol.querySelector('[data-pair-id="' + CSS.escape(rightId) + '"]');
    if (!leftCard || !rightCard) {
      return;
    }

    const p1 = getCardCenter(leftCard, true);
    const p2 = getCardCenter(rightCard, false);
    drawLine(p1.x, p1.y, p2.x, p2.y, '#ef4444', 4, '');

    setTimeout(function () {
      renderCurrentBoard();
    }, 420);
  }

  function attemptMatch(board, boardState) {
    if (!selectedLeftId || !selectedRightId) {
      return;
    }

    const matches = boardState.matches;

    if (matches[selectedLeftId] || isRightAlreadyUsed(matches, selectedRightId)) {
      clearSelection();
      return;
    }

    const correct = selectedLeftId === selectedRightId;
    if (correct) {
      matches[selectedLeftId] = selectedRightId;
      const leftCard = leftCol.querySelector('[data-pair-id="' + CSS.escape(selectedLeftId) + '"]');
      const rightCard = rightCol.querySelector('[data-pair-id="' + CSS.escape(selectedRightId) + '"]');
      if (leftCard) {
        leftCard.classList.add('matched', 'correct-glow');
      }
      if (rightCard) {
        rightCard.classList.add('matched', 'correct-glow');
      }
    } else {
      flashWrongLine(selectedLeftId, selectedRightId);
    }

    clearSelection();
    updateProgress(board, boardState);
    renderLines(board, boardState);
  }

  function bindCards(board, boardState) {
    leftCol.querySelectorAll('.mlv-card').forEach(function (card) {
      card.addEventListener('click', function () {
        const leftId = String(card.getAttribute('data-pair-id') || '');
        if (!leftId || boardState.matches[leftId]) {
          return;
        }

        leftCol.querySelectorAll('.mlv-card').forEach(function (other) {
          other.classList.remove('selected');
        });
        card.classList.add('selected');
        selectedLeftId = leftId;

        if (selectedRightId) {
          attemptMatch(board, boardState);
        }
      });
    });

    rightCol.querySelectorAll('.mlv-card').forEach(function (card) {
      card.addEventListener('click', function () {
        const rightId = String(card.getAttribute('data-pair-id') || '');
        if (!rightId || isRightAlreadyUsed(boardState.matches, rightId)) {
          return;
        }

        rightCol.querySelectorAll('.mlv-card').forEach(function (other) {
          other.classList.remove('selected');
        });
        card.classList.add('selected');
        selectedRightId = rightId;

        if (selectedLeftId) {
          attemptMatch(board, boardState);
        }
      });
    });
  }

  function renderCurrentBoard() {
    const board = boards[currentIndex];
    if (!board || !Array.isArray(board.pairs)) {
      return;
    }

    const boardState = getBoardState(board);
    const leftItems = board.pairs.slice();

    const rightMap = {};
    board.pairs.forEach(function (pair) {
      rightMap[String(pair.id || '')] = pair;
    });

    const orderedRight = boardState.rightOrder
      .map(function (pairId) { return rightMap[String(pairId)]; })
      .filter(Boolean);

    leftCol.innerHTML = leftItems.map(function (pair) {
      return createCardHtml(pair, 'left');
    }).join('');

    rightCol.innerHTML = orderedRight.map(function (pair) {
      return createCardHtml(pair, 'right');
    }).join('');

    boardTitle.textContent = board.title || ('Board ' + (currentIndex + 1));
    updateProgress(board, boardState);
    updateButtonState(boardState);
    clearSelection();

    const matches = boardState.matches || {};
    Object.keys(matches).forEach(function (leftId) {
      const rightId = matches[leftId];
      const leftCard = leftCol.querySelector('[data-pair-id="' + CSS.escape(leftId) + '"]');
      const rightCard = rightCol.querySelector('[data-pair-id="' + CSS.escape(rightId) + '"]');
      if (leftCard) {
        leftCard.classList.add('matched');
      }
      if (rightCard) {
        rightCard.classList.add('matched');
      }
    });

    bindCards(board, boardState);
    renderLines(board, boardState);
  }

  prevBtn.addEventListener('click', function () {
    if (currentIndex <= 0) {
      return;
    }
    currentIndex -= 1;
    renderCurrentBoard();
  });

  nextBtn.addEventListener('click', function () {
    if (currentIndex >= boards.length - 1) {
      return;
    }
    currentIndex += 1;
    renderCurrentBoard();
  });

  showBtn.addEventListener('click', function () {
    const board = boards[currentIndex];
    if (!board) {
      return;
    }
    const boardState = getBoardState(board);
    boardState.showAnswer = !boardState.showAnswer;
    updateButtonState(boardState);
    renderLines(board, boardState);
  });

  resetBtn.addEventListener('click', function () {
    const board = boards[currentIndex];
    if (!board) {
      return;
    }
    const boardId = String(board.id || 'board_' + currentIndex);
    stateByBoardId[boardId] = {
      rightOrder: shuffle((board.pairs || []).map(function (pair) {
        return String(pair.id || '');
      })),
      matches: {},
      showAnswer: false,
    };
    renderCurrentBoard();
  });

  window.addEventListener('resize', function () {
    const board = boards[currentIndex];
    if (!board) {
      return;
    }
    const boardState = getBoardState(board);
    renderLines(board, boardState);
  });

  renderCurrentBoard();
});
