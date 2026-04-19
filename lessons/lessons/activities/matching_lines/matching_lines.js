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
  const returnTo = typeof window.MATCHING_LINES_RETURN_TO === 'string' ? window.MATCHING_LINES_RETURN_TO : '';
  const activityId = typeof window.MATCHING_LINES_ACTIVITY_ID === 'string' ? window.MATCHING_LINES_ACTIVITY_ID : '';

  if (!stage || !leftCol || !rightCol || !svg || boards.length === 0) {
    return;
  }

  const stateByBoardId = {};
  let currentIndex = 0;
  let dragging = null;
  let wrongAttempts = 0;
  let scorePersisted = false;

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

  function createCardHtml(pair, side, index) {
    const pairId = esc(pair.id || '');
    const text = side === 'left' ? esc(pair.left_text || '') : esc(pair.right_text || '');
    const image = side === 'left' ? esc(pair.left_image || '') : esc(pair.right_image || '');
    const mediaClass = side === 'left' ? 'mlv-media mlv-media-left' : 'mlv-media mlv-media-right';
    const media = image !== '' ? '<img class="' + mediaClass + '" src="' + image + '" alt="item">' : '';
    const label = text !== '' ? '<div class="mlv-text">' + text + '</div>' : '';
    const badge = side === 'left' ? '<span class="mlv-index">' + String(index + 1) + '</span>' : '';
    const sideClass = side === 'left' ? 'mlv-card-left' : 'mlv-card-right';

    return '<button type="button" class="mlv-card ' + sideClass + '" data-pair-id="' + pairId + '">'
      + badge + media + label + '<span class="mlv-anchor" aria-hidden="true"></span></button>';
  }

  function buildReturnUrl(scorePercent, errors, total) {
    if (!returnTo) {
      return '';
    }

    const hasQuery = returnTo.indexOf('?') !== -1;
    const joiner = hasQuery ? '&' : '?';

    return returnTo
      + joiner + 'activity_percent=' + encodeURIComponent(String(scorePercent))
      + '&activity_errors=' + encodeURIComponent(String(errors))
      + '&activity_total=' + encodeURIComponent(String(total))
      + '&activity_id=' + encodeURIComponent(String(activityId))
      + '&activity_type=' + encodeURIComponent('matching_lines');
  }

  function getTotalPairs() {
    return boards.reduce(function (sum, board) {
      const n = Array.isArray(board.pairs) ? board.pairs.length : 0;
      return sum + n;
    }, 0);
  }

  function getMatchedTotal() {
    return boards.reduce(function (sum, board, idx) {
      const boardId = String(board.id || 'board_' + idx);
      const boardState = stateByBoardId[boardId];
      const n = boardState && boardState.matches ? Object.keys(boardState.matches).length : 0;
      return sum + n;
    }, 0);
  }

  function isAllBoardsCompleted() {
    return boards.every(function (board, idx) {
      const total = Array.isArray(board.pairs) ? board.pairs.length : 0;
      const boardId = String(board.id || 'board_' + idx);
      const boardState = stateByBoardId[boardId] || { matches: {} };
      return Object.keys(boardState.matches || {}).length >= total;
    });
  }

  function persistScoreIfCompleted() {
    if (scorePersisted || !isAllBoardsCompleted()) {
      return;
    }

    const total = getTotalPairs();
    const matched = getMatchedTotal();
    if (total <= 0) {
      return;
    }

    const percent = Math.round((matched / total) * 100);
    const safeErrors = Math.max(0, Math.min(total, wrongAttempts));
    const saveUrl = buildReturnUrl(percent, safeErrors, total);

    if (!saveUrl) {
      return;
    }

    scorePersisted = true;
    try {
      fetch(saveUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        keepalive: true,
      }).catch(function () {
        scorePersisted = false;
      });
    } catch (e) {
      scorePersisted = false;
    }
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

  function clientPointToStage(clientX, clientY) {
    const rect = stage.getBoundingClientRect();
    return {
      x: clientX - rect.left,
      y: clientY - rect.top,
    };
  }

  function drawPath(x1, y1, x2, y2, color, width, dashArray, temp) {
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    const dx = Math.max(30, Math.abs(x2 - x1) * 0.4);
    const d = 'M ' + x1 + ' ' + y1 + ' C ' + (x1 + dx) + ' ' + y1 + ', ' + (x2 - dx) + ' ' + y2 + ', ' + x2 + ' ' + y2;
    path.setAttribute('d', d);
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', color);
    path.setAttribute('stroke-width', String(width));
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('stroke-linejoin', 'round');
    if (dashArray) {
      path.setAttribute('stroke-dasharray', dashArray);
    }
    if (temp) {
      path.setAttribute('data-temp', '1');
    }
    svg.appendChild(path);

    const dot1 = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    dot1.setAttribute('cx', String(x1));
    dot1.setAttribute('cy', String(y1));
    dot1.setAttribute('r', String(Math.max(3, Math.floor(width * 0.9))));
    dot1.setAttribute('fill', color);
    if (temp) {
      dot1.setAttribute('data-temp', '1');
    }
    svg.appendChild(dot1);

    const dot2 = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    dot2.setAttribute('cx', String(x2));
    dot2.setAttribute('cy', String(y2));
    dot2.setAttribute('r', String(Math.max(3, Math.floor(width * 0.9))));
    dot2.setAttribute('fill', color);
    if (temp) {
      dot2.setAttribute('data-temp', '1');
    }
    svg.appendChild(dot2);
  }

  function clearTempPath() {
    svg.querySelectorAll('[data-temp="1"]').forEach(function (node) {
      node.remove();
    });
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
      drawPath(p1.x, p1.y, p2.x, p2.y, '#111827', 4, '', false);
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
        drawPath(p1.x, p1.y, p2.x, p2.y, '#7c3aed', 3, '8 6', false);
      });
    }

    if (dragging && dragging.active) {
      drawPath(dragging.startX, dragging.startY, dragging.endX, dragging.endY, '#2563eb', 4, '', true);
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
    drawPath(p1.x, p1.y, p2.x, p2.y, '#ef4444', 4, '', false);

    setTimeout(function () {
      renderCurrentBoard();
    }, 420);
  }

  function attemptMatch(leftId, rightId, board, boardState) {
    if (!leftId || !rightId) {
      return;
    }

    const matches = boardState.matches;

    if (matches[leftId] || isRightAlreadyUsed(matches, rightId)) {
      return;
    }

    const correct = leftId === rightId;
    if (correct) {
      matches[leftId] = rightId;
      const leftCard = leftCol.querySelector('[data-pair-id="' + CSS.escape(leftId) + '"]');
      const rightCard = rightCol.querySelector('[data-pair-id="' + CSS.escape(rightId) + '"]');
      if (leftCard) {
        leftCard.classList.add('matched', 'correct-glow');
      }
      if (rightCard) {
        rightCard.classList.add('matched', 'correct-glow');
      }
    } else {
      wrongAttempts += 1;
      flashWrongLine(leftId, rightId);
    }

    updateProgress(board, boardState);
    renderLines(board, boardState);
    persistScoreIfCompleted();
  }

  function clearDragClasses() {
    stage.querySelectorAll('.drag-source').forEach(function (el) {
      el.classList.remove('drag-source');
    });
    stage.querySelectorAll('.drop-hover').forEach(function (el) {
      el.classList.remove('drop-hover');
    });
    stage.querySelectorAll('.active-anchor').forEach(function (el) {
      el.classList.remove('active-anchor');
    });
  }

  function getRightCardUnderPoint(clientX, clientY) {
    const hit = document.elementFromPoint(clientX, clientY);
    if (!hit) {
      return null;
    }
    const card = hit.closest('.mlv-right .mlv-card');
    if (!card) {
      return null;
    }
    return card;
  }

  function endDrag(clientX, clientY, board, boardState) {
    if (!dragging || !dragging.active) {
      return;
    }

    const hoveredRight = getRightCardUnderPoint(clientX, clientY);
    let rightId = '';
    if (hoveredRight) {
      rightId = String(hoveredRight.getAttribute('data-pair-id') || '');
    }

    clearTempPath();
    clearDragClasses();

    if (rightId) {
      attemptMatch(dragging.leftId, rightId, board, boardState);
    } else {
      renderLines(board, boardState);
    }

    dragging = null;
  }

  function beginDrag(ev, leftCard, board, boardState) {
    const leftId = String(leftCard.getAttribute('data-pair-id') || '');
    if (!leftId || boardState.matches[leftId]) {
      return;
    }

    const startPoint = getCardCenter(leftCard, true);
    dragging = {
      active: true,
      leftId: leftId,
      startX: startPoint.x,
      startY: startPoint.y,
      endX: startPoint.x,
      endY: startPoint.y,
    };

    clearDragClasses();
    leftCard.classList.add('drag-source');
    const anchor = leftCard.querySelector('.mlv-anchor');
    if (anchor) {
      anchor.classList.add('active-anchor');
    }

    renderLines(board, boardState);
    ev.preventDefault();
  }

  function bindDrag(board, boardState) {
    leftCol.querySelectorAll('.mlv-card').forEach(function (card) {
      card.addEventListener('pointerdown', function (ev) {
        beginDrag(ev, card, board, boardState);
      });
    });

    if (stage.getAttribute('data-drag-bound') === '1') {
      return;
    }

    stage.setAttribute('data-drag-bound', '1');

    const getCurrent = function () {
      const currentBoard = boards[currentIndex];
      return {
        board: currentBoard,
        boardState: currentBoard ? getBoardState(currentBoard) : null,
      };
    };

    stage.addEventListener('pointermove', function (ev) {
      if (!dragging || !dragging.active) {
        return;
      }

      const current = getCurrent();
      if (!current.board || !current.boardState) {
        return;
      }

      const pos = clientPointToStage(ev.clientX, ev.clientY);
      dragging.endX = pos.x;
      dragging.endY = pos.y;

      clearDragClasses();
      const leftCard = leftCol.querySelector('[data-pair-id="' + CSS.escape(dragging.leftId) + '"]');
      if (leftCard) {
        leftCard.classList.add('drag-source');
        const anchor = leftCard.querySelector('.mlv-anchor');
        if (anchor) {
          anchor.classList.add('active-anchor');
        }
      }

      const rightCard = getRightCardUnderPoint(ev.clientX, ev.clientY);
      if (rightCard) {
        const rightId = String(rightCard.getAttribute('data-pair-id') || '');
        if (!isRightAlreadyUsed(current.boardState.matches, rightId)) {
          rightCard.classList.add('drop-hover');
          const rightAnchor = rightCard.querySelector('.mlv-anchor');
          if (rightAnchor) {
            rightAnchor.classList.add('active-anchor');
          }
        }
      }

      renderLines(current.board, current.boardState);
    });

    stage.addEventListener('pointerup', function (ev) {
      const current = getCurrent();
      if (!current.board || !current.boardState) {
        return;
      }
      endDrag(ev.clientX, ev.clientY, current.board, current.boardState);
    });

    window.addEventListener('pointerup', function (ev) {
      const current = getCurrent();
      if (!current.board || !current.boardState) {
        return;
      }
      endDrag(ev.clientX, ev.clientY, current.board, current.boardState);
    });

    stage.addEventListener('pointercancel', function () {
      const current = getCurrent();
      if (!current.board || !current.boardState) {
        return;
      }
      if (!dragging || !dragging.active) {
        return;
      }
      clearTempPath();
      clearDragClasses();
      dragging = null;
      renderLines(current.board, current.boardState);
    });

    stage.addEventListener('pointerleave', function (ev) {
      const current = getCurrent();
      if (!current.board || !current.boardState) {
        return;
      }
      if (!dragging || !dragging.active) {
        return;
      }
      const pos = clientPointToStage(ev.clientX, ev.clientY);
      dragging.endX = pos.x;
      dragging.endY = pos.y;
      renderLines(current.board, current.boardState);
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

    leftCol.innerHTML = leftItems.map(function (pair, idx) {
      return createCardHtml(pair, 'left', idx);
    }).join('');

    rightCol.innerHTML = orderedRight.map(function (pair, idx) {
      return createCardHtml(pair, 'right', idx);
    }).join('');

    boardTitle.textContent = board.title || ('Board ' + (currentIndex + 1));
    updateProgress(board, boardState);
    updateButtonState(boardState);
    dragging = null;

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

    bindDrag(board, boardState);
    renderLines(board, boardState);
    persistScoreIfCompleted();
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
    if (boardState.showAnswer) {
      wrongAttempts += 1;
    }
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
    scorePersisted = false;
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
