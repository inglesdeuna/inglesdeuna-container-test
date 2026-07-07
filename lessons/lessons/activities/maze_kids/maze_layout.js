/**
 * maze_layout.js
 * Shared by maze_kids/editor.php and maze_kids/viewer.php.
 * generateMazeLayout() is the single source of truth for node positions,
 * used identically by the editor live preview and the student viewer.
 */
(function (global) {
  'use strict';

  var STEP_X = 148;
  var STEP_Y = 132;
  var PAD = 92;
  var MIN_CANVAS_WIDTH = 860;

  function pointKey(p) { return Math.round(p.x) + ':' + Math.round(p.y); }

  function pointsToPathD(points) {
    if (!points.length) return '';
    var d = 'M ' + points[0].x.toFixed(1) + ' ' + points[0].y.toFixed(1);
    for (var i = 1; i < points.length; i++) d += ' L ' + points[i].x.toFixed(1) + ' ' + points[i].y.toFixed(1);
    return d;
  }

  function normalizeCustomPositions(customPositions) {
    if (!customPositions || typeof customPositions !== 'object') return null;
    var out = {};
    Object.keys(customPositions).forEach(function (key) {
      var p = customPositions[key] || {};
      var x = Number(p.x), y = Number(p.y);
      if (isFinite(x) && isFinite(y)) out[key] = { x: x, y: y };
    });
    return Object.keys(out).length ? out : null;
  }

  function mzkHash(str) {
    var h = 0;
    for (var i = 0; i < str.length; i++) {
      h = (h * 31 + str.charCodeAt(i)) | 0;
    }
    return Math.abs(h);
  }

  function seedFromPath(pathSequence) {
    if (!pathSequence.length) return 0;
    return mzkHash(pathSequence.join('|'));
  }

  function autoMainPoint(index, cols, reversed) {
    var row = Math.floor(index / cols);
    var posInRow = index % cols;
    var rowIsForward = reversed ? (row % 2 === 1) : (row % 2 === 0);
    var col = rowIsForward ? posInRow : (cols - 1 - posInRow);
    return { x: col * STEP_X, y: row * STEP_Y };
  }

  function buildMainPoints(count, customPositions, seed) {
    var points = [];
    var cols = 4 + (seed % 3);
    cols = Math.min(cols, Math.max(1, count));
    var reversed = (seed % 2) === 1;
    for (var i = 0; i < count; i++) {
      var saved = customPositions && customPositions['path_' + i];
      points.push(saved ? { x: saved.x, y: saved.y } : autoMainPoint(i, cols, reversed));
    }
    return points;
  }

  function localDirection(mainPoints, idx) {
    var cur = mainPoints[idx];
    var ref = mainPoints[idx + 1] || mainPoints[idx - 1] || cur;
    var dx = ref.x - cur.x, dy = ref.y - cur.y;
    return (Math.abs(dx) >= Math.abs(dy)) ? 'h' : 'v';
  }

  function buildBranchCandidates(base, orientation) {
    if (orientation === 'h') {
      return [
        { x: base.x, y: base.y - STEP_Y },
        { x: base.x, y: base.y + STEP_Y },
        { x: base.x, y: base.y - STEP_Y * 1.4 },
        { x: base.x, y: base.y + STEP_Y * 1.4 }
      ];
    }
    return [
      { x: base.x + STEP_X, y: base.y },
      { x: base.x - STEP_X, y: base.y },
      { x: base.x + STEP_X * 1.4, y: base.y },
      { x: base.x - STEP_X * 1.4, y: base.y }
    ];
  }

  function chooseBranchEndpoint(mainPoints, attachIndex, branchOrdinal, usedPoints, customPositions, seed) {
    if (customPositions && customPositions['branch_' + branchOrdinal]) {
      var saved = customPositions['branch_' + branchOrdinal];
      return { x: saved.x, y: saved.y };
    }
    var idx = Math.max(0, Math.min(mainPoints.length - 1, attachIndex));
    var base = mainPoints[idx] || { x: 0, y: 0 };
    var orientation = localDirection(mainPoints, idx);
    var candidates = buildBranchCandidates(base, orientation);
    var rot = (branchOrdinal + seed) % candidates.length;
    candidates = candidates.slice(rot).concat(candidates.slice(0, rot));
    for (var i = 0; i < candidates.length; i++) if (!usedPoints[pointKey(candidates[i])]) return candidates[i];
    return { x: base.x, y: base.y + STEP_Y * (branchOrdinal + 1) };
  }

  function shouldSpreadBranches(branches, customPositions) {
    if (!Array.isArray(branches) || branches.length < 2 || customPositions) return false;
    var first = parseInt(branches[0].attach_after_index, 10) || 0;
    for (var i = 1; i < branches.length; i++) {
      if ((parseInt(branches[i].attach_after_index, 10) || 0) !== first) return false;
    }
    return true;
  }

  function generateMazeLayout(pathSequence, distractorBranches, customPositions) {
    pathSequence = Array.isArray(pathSequence) ? pathSequence : [];
    distractorBranches = Array.isArray(distractorBranches) ? distractorBranches : [];
    customPositions = normalizeCustomPositions(customPositions);

    var seed = seedFromPath(pathSequence);
    var mainPoints = buildMainPoints(pathSequence.length, customPositions, seed);
    var nodes = [];
    var usedPoints = {};
    var spreadBranches = shouldSpreadBranches(distractorBranches, customPositions) && mainPoints.length > 2;

    for (var i = 0; i < pathSequence.length; i++) {
      usedPoints[pointKey(mainPoints[i])] = true;
      nodes.push({ id: 'path_' + i, index: i, x: mainPoints[i].x, y: mainPoints[i].y, kind: 'path', vocabularyId: pathSequence[i], attachAfterIndex: null });
    }

    var branchSegments = [];
    for (var b = 0; b < distractorBranches.length; b++) {
      var branch = distractorBranches[b] || {};
      var autoAttach = spreadBranches ? ((b + seed) % Math.max(1, mainPoints.length - 1)) : (parseInt(branch.attach_after_index, 10) || 0);
      var attachAfterIndex = Math.max(0, Math.min(mainPoints.length - 1, autoAttach));
      var startPoint = mainPoints[attachAfterIndex] || { x: 0, y: 0 };
      var endpoint = chooseBranchEndpoint(mainPoints, attachAfterIndex, b, usedPoints, customPositions, seed);
      usedPoints[pointKey(endpoint)] = true;
      nodes.push({ id: 'branch_' + b, index: pathSequence.length + b, x: endpoint.x, y: endpoint.y, kind: 'branch', vocabularyId: branch.vocabulary_id || '', attachAfterIndex: attachAfterIndex });
      // A single dead-end point reads as decoration, not an obstacle. Every wall is built from
      // two blockages (a mid-span barrier plus the dead-end itself) so it behaves like a real
      // wall segment that must be routed around, not just one lonely tile.
      var mid = { x: (startPoint.x + endpoint.x) / 2, y: (startPoint.y + endpoint.y) / 2 };
      branchSegments.push({ from: startPoint, to: endpoint, mid: mid });
    }

    var xs = mainPoints.map(function (p) { return p.x; });
    var ys = mainPoints.map(function (p) { return p.y; });
    branchSegments.forEach(function (seg) { xs.push(seg.to.x); ys.push(seg.to.y); });
    if (!xs.length) { xs = [0]; ys = [0]; }
    var minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
    var minY = Math.min.apply(null, ys), maxY = Math.max.apply(null, ys);

    var rawWidth = (maxX - minX) + PAD * 2;
    var rawHeight = (maxY - minY) + PAD * 2;
    var finalWidth = Math.max(rawWidth, MIN_CANVAS_WIDTH);
    var centerX = (finalWidth - rawWidth) / 2;
    var offsetX = PAD - minX + centerX;
    var offsetY = PAD - minY;

    var shiftedMain = mainPoints.map(function (p) { return { x: p.x + offsetX, y: p.y + offsetY }; });
    nodes.forEach(function (n) { n.x += offsetX; n.y += offsetY; });
    branchSegments.forEach(function (seg) {
      seg.from = { x: seg.from.x + offsetX, y: seg.from.y + offsetY };
      seg.to = { x: seg.to.x + offsetX, y: seg.to.y + offsetY };
      seg.mid = { x: seg.mid.x + offsetX, y: seg.mid.y + offsetY };
    });

    var mainPathD = pointsToPathD(shiftedMain);
    var branchPathD = branchSegments.map(function (seg) { return pointsToPathD([seg.from, seg.mid, seg.to]); }).join(' ');
    var branchEndpoints = branchSegments.map(function (seg) { return seg.to; });
    var branchBlockers = branchSegments.map(function (seg) { return seg.mid; });

    return {
      nodes: nodes,
      mainPath: shiftedMain,
      wallPathD: mainPathD,
      corridorPathD: mainPathD,
      branchPathD: branchPathD,
      branchEndpoints: branchEndpoints,
      branchBlockers: branchBlockers,
      width: Math.round(finalWidth),
      height: Math.round(rawHeight),
      offsetX: offsetX,
      offsetY: offsetY
    };
  }

  function shuffleArray(arr) {
    var a = arr.slice();
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var tmp = a[i]; a[i] = a[j]; a[j] = tmp;
    }
    return a;
  }

  function addEditorGridEnhancer() {
    if (!global.document) return;
    document.addEventListener('DOMContentLoaded', function () {
      setTimeout(function () {
        var wrap = document.getElementById('mzkePreviewWrap');
        if (!wrap || typeof global.mzkeRenderPreview !== 'function') return;
        var style = document.createElement('style');
        style.textContent = '#mzkePreviewWrap{background-color:#F8F7FF;background-image:linear-gradient(#DDD9FA 1px,transparent 1px),linear-gradient(90deg,#DDD9FA 1px,transparent 1px);background-size:74px 74px}.mzke-grid-legend{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 10px}.mzke-grid-legend span{border-radius:999px;padding:5px 10px;font-size:11px;font-weight:900;background:#fff;border:1px solid #EDE9FA}.mzke-grid-legend .start{color:#C2580A;border-color:#FDBA74}.mzke-grid-legend .home{color:#15803d;border-color:#86efac}.mzke-grid-legend .wall{color:#b91c1c;border-color:#fecaca}';
        document.head.appendChild(style);
        var note = document.querySelector('.mzke-preview-note span');
        if (note) note.textContent = 'Drag the pictures to build the maze. START is an arrow. HOME is a house. Wall animals are obstacles.';
        var previewSection = wrap.closest('.mzke-section');
        if (previewSection && !previewSection.querySelector('.mzke-grid-legend')) {
          var legend = document.createElement('div');
          legend.className = 'mzke-grid-legend';
          legend.innerHTML = '<span class="start">START arrow</span><span class="home">HOME house</span><span>Safe path animals</span><span class="wall">Wall animals / obstacles</span>';
          previewSection.insertBefore(legend, wrap);
        }
        var originalRender = global.mzkeRenderPreview;
        global.mzkeRenderPreview = function () {
          originalRender.apply(this, arguments);
          var svg = wrap.querySelector('svg');
          if (!svg) return;
          Array.prototype.slice.call(svg.querySelectorAll('g.mzke-preview-node')).forEach(function (g) {
            var texts = Array.prototype.slice.call(g.querySelectorAll('text'));
            var values = texts.map(function (x) { return x.textContent.trim(); });
            var num = values.find(function (v) { return /^\d+$/.test(v); }) || '';
            var isWall = values.indexOf('x') !== -1;
            var max = 0;
            Array.prototype.slice.call(svg.querySelectorAll('g.mzke-preview-node text')).forEach(function (x) {
              var v = x.textContent.trim();
              if (/^\d+$/.test(v)) max = Math.max(max, parseInt(v, 10));
            });
            texts.forEach(function (x) { if (/^\d+$/.test(x.textContent.trim()) || x.textContent.trim() === 'x') x.style.display = 'none'; });
            if (g.querySelector('.mzke-extra-flag')) return;
            var flag = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            flag.setAttribute('class', 'mzke-extra-flag');
            flag.setAttribute('x', '0');
            flag.setAttribute('y', '-38');
            flag.setAttribute('text-anchor', 'middle');
            flag.setAttribute('font-size', isWall ? '10' : '16');
            flag.setAttribute('font-family', 'Nunito, sans-serif');
            flag.setAttribute('font-weight', '900');
            if (num === '1') { flag.setAttribute('fill', '#F97316'); flag.textContent = 'START ->'; }
            else if (num && parseInt(num, 10) === max) { flag.setAttribute('fill', '#16a34a'); flag.textContent = 'HOME'; }
            else if (isWall) { flag.setAttribute('fill', '#b91c1c'); flag.textContent = 'WALL'; }
            else return;
            g.appendChild(flag);
          });
        };
        global.mzkeRenderPreview();
      }, 0);
    });
  }

  function loadArrowMode() {
    if (!global.document) return;
    document.addEventListener('DOMContentLoaded', function () {
      if (!document.getElementById('mzkMazeWrap')) return;
      var s = document.createElement('script');
      s.src = 'maze_arrow_mode.js?v=2';
      document.body.appendChild(s);
    });
  }

  global.generateMazeLayout = generateMazeLayout;
  global.mzkShuffleArray = shuffleArray;
  addEditorGridEnhancer();
  loadArrowMode();
}(typeof window !== 'undefined' ? window : this));
