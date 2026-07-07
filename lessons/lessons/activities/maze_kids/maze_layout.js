/**
 * maze_layout.js
 * Pure JS maze-layout generator shared between maze_kids/editor.php (live preview)
 * and maze_kids/viewer.php (real interactive maze).
 *
 * generateMazeLayout(pathSequence, distractorBranches, customPositions) -> {
 *   nodes: [{ id, index, x, y, kind:'path'|'branch', vocabularyId, attachAfterIndex }],
 *   mainPath: [{x,y}, ...],
 *   wallPathD, corridorPathD, branchPathD, branchEndpoints,
 *   width, height, offsetX, offsetY
 * }
 */
(function (global) {
  'use strict';

  var STEP_X = 148;
  var STEP_Y = 132;
  var PAD = 92;
  var MIN_CANVAS_WIDTH = 860;
  var MAX_AUTO_COLS = 5;

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

  function autoMainPoint(index, count) {
    var cols = Math.min(MAX_AUTO_COLS, Math.max(1, count));
    var row = Math.floor(index / cols);
    var posInRow = index % cols;
    var col = row % 2 === 0 ? posInRow : (cols - 1 - posInRow);
    return { x: col * STEP_X, y: row * STEP_Y };
  }

  function buildMainPoints(count, customPositions) {
    var points = [];
    for (var i = 0; i < count; i++) {
      var saved = customPositions && customPositions['path_' + i];
      points.push(saved ? { x: saved.x, y: saved.y } : autoMainPoint(i, count));
    }
    return points;
  }

  function buildBranchCandidates(base) {
    return [
      { x: base.x, y: base.y - STEP_Y },
      { x: base.x, y: base.y + STEP_Y },
      { x: base.x + STEP_X, y: base.y },
      { x: base.x - STEP_X, y: base.y },
      { x: base.x + STEP_X, y: base.y - STEP_Y },
      { x: base.x - STEP_X, y: base.y - STEP_Y },
      { x: base.x + STEP_X, y: base.y + STEP_Y },
      { x: base.x - STEP_X, y: base.y + STEP_Y },
      { x: base.x + STEP_X * 1.35, y: base.y },
      { x: base.x - STEP_X * 1.35, y: base.y }
    ];
  }

  function chooseBranchEndpoint(mainPoints, attachIndex, branchOrdinal, usedPoints, customPositions) {
    if (customPositions && customPositions['branch_' + branchOrdinal]) {
      var saved = customPositions['branch_' + branchOrdinal];
      return { x: saved.x, y: saved.y };
    }
    var idx = Math.max(0, Math.min(mainPoints.length - 1, attachIndex));
    var base = mainPoints[idx] || { x: 0, y: 0 };
    var candidates = buildBranchCandidates(base);
    var rot = branchOrdinal % candidates.length;
    candidates = candidates.slice(rot).concat(candidates.slice(0, rot));
    for (var i = 0; i < candidates.length; i++) if (!usedPoints[pointKey(candidates[i])]) return candidates[i];
    return { x: base.x, y: base.y + STEP_Y * (branchOrdinal + 1) };
  }

  function generateMazeLayout(pathSequence, distractorBranches, customPositions) {
    pathSequence = Array.isArray(pathSequence) ? pathSequence : [];
    distractorBranches = Array.isArray(distractorBranches) ? distractorBranches : [];
    customPositions = normalizeCustomPositions(customPositions);

    var mainPoints = buildMainPoints(pathSequence.length, customPositions);
    var nodes = [];
    var usedPoints = {};

    for (var i = 0; i < pathSequence.length; i++) {
      usedPoints[pointKey(mainPoints[i])] = true;
      nodes.push({ id: 'path_' + i, index: i, x: mainPoints[i].x, y: mainPoints[i].y, kind: 'path', vocabularyId: pathSequence[i], attachAfterIndex: null });
    }

    var branchSegments = [];
    for (var b = 0; b < distractorBranches.length; b++) {
      var branch = distractorBranches[b] || {};
      var attachAfterIndex = Math.max(0, Math.min(mainPoints.length - 1, parseInt(branch.attach_after_index, 10) || 0));
      var startPoint = mainPoints[attachAfterIndex] || { x: 0, y: 0 };
      var endpoint = chooseBranchEndpoint(mainPoints, attachAfterIndex, b, usedPoints, customPositions);
      usedPoints[pointKey(endpoint)] = true;
      nodes.push({ id: 'branch_' + b, index: pathSequence.length + b, x: endpoint.x, y: endpoint.y, kind: 'branch', vocabularyId: branch.vocabulary_id || '', attachAfterIndex: attachAfterIndex });
      branchSegments.push({ from: startPoint, to: endpoint });
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
    });

    var mainPathD = pointsToPathD(shiftedMain);
    var branchPathD = branchSegments.map(function (seg) { return pointsToPathD([seg.from, seg.to]); }).join(' ');
    var branchEndpoints = branchSegments.map(function (seg) { return seg.to; });

    return {
      nodes: nodes,
      mainPath: shiftedMain,
      wallPathD: mainPathD,
      corridorPathD: mainPathD,
      branchPathD: branchPathD,
      branchEndpoints: branchEndpoints,
      width: Math.round(finalWidth),
      height: Math.round(rawHeight),
      offsetX: offsetX,
      offsetY: offsetY
    };
  }

  global.generateMazeLayout = generateMazeLayout;
}(typeof window !== 'undefined' ? window : this));