(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  function addStyle() {
    if (document.getElementById('mzkArrowModeStyle')) return;
    var style = document.createElement('style');
    style.id = 'mzkArrowModeStyle';
    style.textContent = '.mzk-node-badge,.mzk-node-badge-text{display:none!important}.mzk-node-flag{display:none!important}.mzk-arrow-controls{display:grid;grid-template-columns:56px 56px 56px;grid-template-rows:48px 48px;gap:8px;justify-content:center;margin-top:10px}.mzk-arrow-controls button{border:0;border-radius:12px;background:#7F77DD;color:#fff;font-weight:900;font-size:22px;cursor:pointer}.mzk-arrow-hint{text-align:center;color:#9B94BE;font-size:12px;font-weight:900;margin-top:6px}.mzk-player-token{filter:drop-shadow(0 4px 8px rgba(0,0,0,.18))}';
    document.head.appendChild(style);
  }

  function svgText(text, x, y, color, size) {
    var t = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    t.setAttribute('x', x);
    t.setAttribute('y', y);
    t.setAttribute('text-anchor', 'middle');
    t.setAttribute('font-size', size || '14');
    t.setAttribute('font-family', 'Nunito, Arial, sans-serif');
    t.setAttribute('font-weight', '900');
    t.setAttribute('fill', color || '#534AB7');
    t.textContent = text;
    return t;
  }

  function cleanNodeLabels(svg) {
    Array.prototype.slice.call(svg.querySelectorAll('g.mzk-node')).forEach(function (g) {
      var isStart = g.classList.contains('start');
      var isEnd = g.classList.contains('end');
      Array.prototype.slice.call(g.querySelectorAll('.mzk-node-badge,.mzk-node-badge-text,.mzk-node-flag')).forEach(function (el) { el.remove(); });
      if (isStart) g.appendChild(svgText('START ➜', 0, -40, '#F97316', '13'));
      if (isEnd) g.appendChild(svgText('🏠 HOME', 0, -40, '#16a34a', '13'));
    });
  }

  function dir(from, to) {
    var dx = to.x - from.x;
    var dy = to.y - from.y;
    if (Math.abs(dx) >= Math.abs(dy)) return dx >= 0 ? 'right' : 'left';
    return dy >= 0 ? 'down' : 'up';
  }

  // Base arrow glyph (see makeToken) points right, so 'right' maps to 0deg.
  function angleForDir(d) {
    if (d === 'down') return 90;
    if (d === 'left') return 180;
    if (d === 'up') return -90;
    return 0;
  }

  function installControls(step) {
    var stage = document.querySelector('.mzk-stage');
    if (!stage || stage.querySelector('.mzk-arrow-controls')) return;
    var hint = document.createElement('div');
    hint.className = 'mzk-arrow-hint';
    hint.textContent = 'Use the arrows, tap a picture, or swipe to move from START to HOME. Avoid wall animals.';
    var box = document.createElement('div');
    box.className = 'mzk-arrow-controls';
    box.innerHTML = '<span></span><button type="button" data-dir="up">↑</button><span></span><button type="button" data-dir="left">←</button><button type="button" data-dir="down">↓</button><button type="button" data-dir="right">→</button>';
    box.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-dir]');
      if (b) step(b.getAttribute('data-dir'));
    });
    stage.appendChild(hint);
    stage.appendChild(box);
  }

  // Lets the mouse/touch swipe directly on the maze stage move the token,
  // as an alternative to the arrow buttons/keyboard for desktop drag and
  // mobile swipe gestures. Small movements (taps/clicks on a node) are
  // ignored here so they fall through to the per-node tap handler.
  function installSwipe(step) {
    var stage = document.querySelector('.mzk-stage');
    if (!stage || stage.dataset.mzkSwipeBound) return;
    stage.dataset.mzkSwipeBound = '1';
    var SWIPE_THRESHOLD = 28;
    var startX = 0, startY = 0, tracking = false;
    stage.addEventListener('pointerdown', function (e) {
      tracking = true;
      startX = e.clientX;
      startY = e.clientY;
    });
    stage.addEventListener('pointerup', function (e) {
      if (!tracking) return;
      tracking = false;
      var dx = e.clientX - startX;
      var dy = e.clientY - startY;
      var absX = Math.abs(dx), absY = Math.abs(dy);
      if (Math.max(absX, absY) < SWIPE_THRESHOLD) return;
      step(absX >= absY ? (dx >= 0 ? 'right' : 'left') : (dy >= 0 ? 'down' : 'up'));
    });
    stage.addEventListener('pointercancel', function () { tracking = false; });
  }

  function makeToken(svg) {
    var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    g.setAttribute('class', 'mzk-player-token');
    var c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    c.setAttribute('r', '18');
    c.setAttribute('fill', '#F97316');
    c.setAttribute('stroke', '#fff');
    c.setAttribute('stroke-width', '4');
    g.appendChild(c);
    g.appendChild(svgText('➜', 0, 7, '#fff', '22'));
    svg.appendChild(g);
    return g;
  }

  function installArrowMode() {
    var wrap = document.getElementById('mzkMazeWrap');
    if (!wrap || typeof window.mzkBuildMaze !== 'function' || typeof window.generateMazeLayout !== 'function') return;
    if (window.__mzkArrowModeInstalled) return;
    window.__mzkArrowModeInstalled = true;
    addStyle();

    var originalBuild = window.mzkBuildMaze;
    var index = 0;
    var token = null;
    var pathNodes = [];
    var branchNodes = [];
    var facing = 'right';

    function placeToken() {
      if (!token || !pathNodes[index]) return;
      token.setAttribute('transform', 'translate(' + pathNodes[index].x + ',' + pathNodes[index].y + ') rotate(' + angleForDir(facing) + ')');
    }

    function rebuild() {
      originalBuild();
      var svg = wrap.querySelector('svg');
      if (!svg) return;
      var layout;
      try { layout = generateMazeLayout(MZK_PATH, MZK_BRANCHES, MZK_LAYOUT_POSITIONS); } catch (e) { return; }
      pathNodes = layout.nodes.filter(function (n) { return n.kind === 'path'; }).sort(function (a, b) { return a.index - b.index; });
      branchNodes = layout.nodes.filter(function (n) { return n.kind === 'branch'; });
      cleanNodeLabels(svg);
      index = 0;
      var startNode = layout.nodes.filter(function (n) { return n.kind === 'start'; })[0];
      facing = (startNode && pathNodes[0]) ? dir(startNode, pathNodes[0]) : 'right';
      token = makeToken(svg);
      placeToken();
      var hero = document.querySelector('.mzk-hero p');
      if (hero) hero.textContent = 'Use the arrows, tap a picture, or swipe to move from START to HOME!';
    }

    function updateProgress() {
      if (typeof window.mzkUpdateProgress === 'function') {
        window.mzkNextIndex = index;
        window.mzkUpdateProgress();
      }
    }

    function reset(msg, soundId) {
      index = 0;
      placeToken();
      updateProgress();
      if (typeof window.mzkSetFeedback === 'function') window.mzkSetFeedback(msg || 'Wrong way. Start again.', 'bad');
      var snd = document.getElementById(soundId || 'mzkLoseAudio');
      if (snd && typeof window.mzkPlay === 'function') window.mzkPlay(snd);
    }

    function step(which) {
      if (!pathNodes.length || !pathNodes[index]) return;
      facing = which;
      var here = pathNodes[index];
      for (var i = 0; i < branchNodes.length; i++) {
        var branchEntry = { x: branchNodes[i].entryX != null ? branchNodes[i].entryX : branchNodes[i].x, y: branchNodes[i].entryY != null ? branchNodes[i].entryY : branchNodes[i].y };
        if (branchNodes[i].attachAfterIndex === index && dir(here, branchEntry) === which) {
          reset('Dead end! Go back to START.', 'mzkWrongAudio');
          return;
        }
      }
      var next = pathNodes[index + 1];
      if (next && dir(here, next) === which) {
        index++;
        placeToken();
        updateProgress();
        if (typeof window.mzkSetFeedback === 'function') window.mzkSetFeedback('Good! Keep going.', 'good');
        if (index >= pathNodes.length - 1 && typeof window.mzkFinish === 'function') setTimeout(window.mzkFinish, 350);
        return;
      }
      reset('Wrong way. Follow the path.');
    }

    // Lets a click/tap directly on a maze picture move the token, as a
    // mouse/touch alternative to the arrow buttons and keyboard. Returns
    // true when the tap was handled here (so the legacy per-node tap
    // handler in viewer.php does not also run).
    function nodeTap(node) {
      if (!pathNodes.length || !pathNodes[index] || !node) return true;
      var here = pathNodes[index];
      step(dir(here, node));
      return true;
    }

    installControls(step);
    installSwipe(step);
    document.addEventListener('keydown', function (e) {
      var map = { ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'left', ArrowRight: 'right' };
      if (map[e.key]) { e.preventDefault(); step(map[e.key]); }
    });
    window.mzkBuildMaze = rebuild;
    window.mzkRestart = rebuild;
    window.mzkNodeTap = nodeTap;
    rebuild();
  }

  ready(function () { setTimeout(installArrowMode, 50); });
}());