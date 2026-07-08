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
    style.textContent = [
      '.mzk-node-badge,.mzk-node-badge-text,.mzk-node-flag,.mzk-node-label{display:none!important}',
      '.mzk-stage{position:relative!important}',
      '.mzk-arrow-panel{width:100%;min-height:190px;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:14px;border-radius:28px;background:#FFFFFF;border:1px solid #EDE9FA;box-shadow:0 8px 24px rgba(127,119,221,.09)}',
      '.mzk-arrow-controls{display:grid;grid-template-columns:54px 54px 54px;grid-template-rows:54px 54px;gap:8px;margin:0}',
      '.mzk-arrow-controls button{border:0;border-radius:18px;background:#7F77DD;color:#fff;font-weight:900;font-size:28px;cursor:pointer;line-height:1;box-shadow:0 8px 18px rgba(127,119,221,.22)}',
      '.mzk-arrow-controls button:active{transform:translateY(1px)}',
      '.mzk-player-token{filter:drop-shadow(0 4px 8px rgba(0,0,0,.18));transition:transform .18s ease}',
      '@media(max-width:760px){.mzk-arrow-panel{min-height:128px;padding:10px;border-radius:24px}.mzk-arrow-controls{grid-template-columns:44px 44px 44px;grid-template-rows:44px 44px;gap:6px}.mzk-arrow-controls button{font-size:22px;border-radius:14px}}'
    ].join('');
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
      Array.prototype.slice.call(g.querySelectorAll('.mzk-node-badge,.mzk-node-badge-text,.mzk-node-flag,.mzk-node-label')).forEach(function (el) { el.remove(); });
    });
  }

  function pointKey(p) { return Math.round(p.x) + ',' + Math.round(p.y); }

  function dir(from, to) {
    var dx = to.x - from.x;
    var dy = to.y - from.y;
    if (Math.abs(dx) >= Math.abs(dy)) return dx >= 0 ? 'right' : 'left';
    return dy >= 0 ? 'down' : 'up';
  }

  function angleForDir(d) {
    if (d === 'down') return 90;
    if (d === 'left') return 180;
    if (d === 'up') return -90;
    return 0;
  }

  function installControls(step) {
    var rail = document.getElementById('mzkSideRail');
    var host = rail || document.querySelector('.mzk-stage');
    if (!host || host.querySelector('.mzk-arrow-panel')) return;
    var panel = document.createElement('div');
    panel.className = 'mzk-arrow-panel';
    var box = document.createElement('div');
    box.className = 'mzk-arrow-controls';
    box.innerHTML = '<span></span><button type="button" data-dir="up" aria-label="Move up">↑</button><span></span><button type="button" data-dir="left" aria-label="Move left">←</button><button type="button" data-dir="down" aria-label="Move down">↓</button><button type="button" data-dir="right" aria-label="Move right">→</button>';
    box.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-dir]');
      if (b) step(b.getAttribute('data-dir'));
    });
    panel.appendChild(box);
    host.insertBefore(panel, host.firstChild);
  }

  function installSwipe(step) {
    var stage = document.querySelector('.mzk-stage');
    if (!stage || stage.dataset.mzkSwipeBound) return;
    stage.dataset.mzkSwipeBound = '1';
    var threshold = 28;
    var startX = 0, startY = 0, tracking = false;
    stage.addEventListener('pointerdown', function (e) {
      if (e.target.closest('.mzk-arrow-panel,.mzk-controls')) return;
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
      if (Math.max(absX, absY) < threshold) return;
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
    var token = null;
    var layout = null;
    var current = null;
    var facing = 'right';
    var graph = {};
    var pathNodes = [];
    var branchNodes = [];
    var startNode = null;
    var homeNode = null;
    var pathIndex = 0;
    var failed = false;

    function samePoint(a, b) { return a && b && pointKey(a) === pointKey(b); }

    function addGraphPoint(p) {
      var key = pointKey(p);
      if (!graph[key]) graph[key] = { point: { x: p.x, y: p.y }, neighbors: [] };
    }

    function addEdge(a, b) {
      var ak = pointKey(a), bk = pointKey(b);
      addGraphPoint(a); addGraphPoint(b);
      graph[ak].neighbors.push({ key: bk, point: { x: b.x, y: b.y } });
      graph[bk].neighbors.push({ key: ak, point: { x: a.x, y: a.y } });
    }

    function buildGraph() {
      graph = {};
      (layout.connectors || []).forEach(function (pair) { addEdge(pair[0], pair[1]); });
      (layout.nodes || []).forEach(addGraphPoint);
    }

    function placeToken() {
      if (!token || !current) return;
      token.setAttribute('transform', 'translate(' + current.x + ',' + current.y + ') rotate(' + angleForDir(facing) + ')');
    }

    function syncViewerProgress() {
      if (typeof window.mzkSyncArrowProgress === 'function') window.mzkSyncArrowProgress(pathIndex);
      else if (typeof window.mzkUpdateProgress === 'function') window.mzkUpdateProgress(pathIndex);
    }

    function rebuild() {
      if (typeof window.mzkClearAnswerPath === 'function') window.mzkClearAnswerPath();
      originalBuild();
      var svg = wrap.querySelector('svg');
      if (!svg) return;
      try { layout = generateMazeLayout(MZK_PATH, MZK_BRANCHES, MZK_LAYOUT_POSITIONS); } catch (e) { return; }
      pathNodes = layout.nodes.filter(function (n) { return n.kind === 'path'; }).sort(function (a, b) { return a.index - b.index; });
      branchNodes = layout.nodes.filter(function (n) { return n.kind === 'branch'; });
      startNode = layout.nodes.filter(function (n) { return n.kind === 'start'; })[0] || pathNodes[0];
      homeNode = layout.nodes.filter(function (n) { return n.kind === 'home'; })[0] || pathNodes[pathNodes.length - 1];
      buildGraph();
      cleanNodeLabels(svg);
      pathIndex = 0;
      failed = false;
      current = { x: startNode.x, y: startNode.y };
      facing = pathNodes[0] ? dir(current, pathNodes[0]) : 'right';
      token = makeToken(svg);
      placeToken();
      syncViewerProgress();
      if (typeof window.mzkSetFeedback === 'function') window.mzkSetFeedback('', '');
    }

    function playSound(id) {
      var snd = document.getElementById(id || 'mzkLoseAudio');
      if (snd && typeof window.mzkPlay === 'function') window.mzkPlay(snd);
    }

    function fail(message, soundId) {
      failed = true;
      syncViewerProgress();
      if (typeof window.mzkSetFeedback === 'function') window.mzkSetFeedback(message || 'Wrong way. Restart and try again.', 'bad');
      playSound(soundId || 'mzkLoseAudio');
    }

    function completeIfReady() {
      if (!homeNode || !samePoint(current, homeNode)) return false;
      if (pathIndex < pathNodes.length) {
        fail('You found HOME, but you missed part of the path. Restart and try again.', 'mzkWrongAudio');
        return true;
      }
      if (typeof window.mzkFinish === 'function') setTimeout(window.mzkFinish, 250);
      return true;
    }

    function markPathNodeIfNeeded() {
      var next = pathNodes[pathIndex];
      if (next && samePoint(current, next)) {
        var group = wrap.querySelector('g.mzk-node[data-node-id="path_' + next.index + '"]');
        if (group) group.classList.add('done');
        pathIndex++;
        syncViewerProgress();
        if (typeof window.mzkSetFeedback === 'function') window.mzkSetFeedback('Good! Keep going.', 'good');
        playSound('mzkWinAudio');
      }
    }

    function branchAtCurrent() {
      for (var i = 0; i < branchNodes.length; i++) if (samePoint(current, branchNodes[i])) return branchNodes[i];
      return null;
    }

    function step(which) {
      if (failed || !current || !layout) return;
      facing = which;
      var hereKey = pointKey(current);
      var here = graph[hereKey];
      if (!here) return;
      var choices = here.neighbors.filter(function (n) { return dir(current, n.point) === which; });
      if (!choices.length) {
        placeToken();
        fail('Wrong way. Follow the path.', 'mzkLoseAudio');
        return;
      }
      current = { x: choices[0].point.x, y: choices[0].point.y };
      placeToken();
      var branch = branchAtCurrent();
      if (branch) {
        var group = wrap.querySelector('g.mzk-node[data-node-id="' + branch.id + '"]');
        if (group) group.classList.add('wrong', 'shake');
        fail('Dead end! Restart and try another path.', 'mzkWrongAudio');
        return;
      }
      markPathNodeIfNeeded();
      if (!completeIfReady() && typeof window.mzkSetFeedback === 'function' && pathIndex > 0) window.mzkSetFeedback('Good! Keep going.', 'good');
    }

    function nodeTap(node) {
      if (failed || !current || !node) return true;
      step(dir(current, node));
      return true;
    }

    installControls(step);
    installSwipe(step);
    document.addEventListener('keydown', function (e) {
      var map = { ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'left', ArrowRight: 'right' };
      if (map[e.key]) { e.preventDefault(); step(map[e.key]); }
    });
    window.mzkBuildMaze = rebuild;
    window.mzkRestart = function () {
      if (typeof window.mzkResetViewerState === 'function') window.mzkResetViewerState();
      rebuild();
    };
    window.mzkNodeTap = nodeTap;
    rebuild();
  }

  ready(function () { setTimeout(installArrowMode, 50); });
}());
