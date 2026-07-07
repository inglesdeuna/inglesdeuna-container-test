/**
 * maze_layout.js
 * Pure JS maze-layout generator shared between maze_kids/editor.php (live preview)
 * and maze_kids/viewer.php (real interactive maze).
 *
 * generateMazeLayout(pathSequence, distractorBranches) -> {
 *   nodes: [{ id, index, x, y, kind:'path'|'branch', vocabularyId, attachAfterIndex }],
 *   mainPath: [{x,y}, ...],                // points along the main corridor, one per path node
 *   wallPathD: 'M ... L ...',              // thick outer "wall" stroke — MAIN corridor only
 *   corridorPathD: 'M ... L ...',          // thin dashed walkable stroke — MAIN corridor only
 *   branchPathD: 'M ... L ...',            // short dead-end distractor segments, rendered separately
 *   branchEndpoints: [{x,y}, ...],         // tip of each distractor branch (for the dead-end cap marker)
 *   width, height
 * }
 *
 * No DOM access here — safe to reuse in editor preview and viewer.
 */
(function (global) {
  'use strict';

  var STEP_X = 140;   // horizontal distance covered per zigzag leg
  var STEP_Y = 120;   // vertical distance between rows
  var TURN_EVERY = 2; // change horizontal direction every N nodes
  var PAD = 90;        // outer padding so nodes/branches never clip the SVG

  /**
   * Builds the zig-zag point list for the main path:
   * right -> down -> left -> down -> right ... alternating every TURN_EVERY nodes.
   */
  function buildMainPoints(count) {
    var points = [];
    var x = 0, y = 0;
    var dir = 1; // 1 = moving right, -1 = moving left
    for (var i = 0; i < count; i++) {
      points.push({ x: x, y: y });
      if (i === count - 1) break;
      var stepInRow = i % TURN_EVERY;
      if (stepInRow === TURN_EVERY - 1) {
        // time to drop a row and flip direction
        y += STEP_Y;
        dir *= -1;
      } else {
        x += STEP_X * dir;
      }
    }
    return points;
  }

  function pointsToPathD(points) {
    if (!points.length) return '';
    var d = 'M ' + points[0].x.toFixed(1) + ' ' + points[0].y.toFixed(1);
    for (var i = 1; i < points.length; i++) {
      d += ' L ' + points[i].x.toFixed(1) + ' ' + points[i].y.toFixed(1);
    }
    return d;
  }

  /**
   * Computes a short perpendicular branch endpoint from a main-path point,
   * alternating above/below the corridor to avoid overlaps.
   */
  function branchEndpoint(mainPoints, attachIndex, branchOrdinal) {
    var idx = Math.max(0, Math.min(mainPoints.length - 1, attachIndex));
    var base = mainPoints[idx];
    var next = mainPoints[idx + 1] || mainPoints[idx - 1] || base;
    // direction of travel at this node (used to find the perpendicular)
    var dx = next.x - base.x;
    var dy = next.y - base.y;
    var len = Math.sqrt(dx * dx + dy * dy) || 1;
    var ux = dx / len, uy = dy / len;
    // perpendicular vector
    var px = -uy, py = ux;
    var side = (branchOrdinal % 2 === 0) ? 1 : -1;
    var dist = STEP_Y * 0.85;
    return {
      x: base.x + px * dist * side + ux * (STEP_X * 0.15),
      y: base.y + py * dist * side + uy * (STEP_Y * 0.15)
    };
  }

  /**
   * @param {Array} pathSequence         array of vocabulary_bank ids, in correct order
   * @param {Array} distractorBranches   [{ attach_after_index, vocabulary_id }]
   * @return {object} layout
   */
  function generateMazeLayout(pathSequence, distractorBranches) {
    pathSequence = Array.isArray(pathSequence) ? pathSequence : [];
    distractorBranches = Array.isArray(distractorBranches) ? distractorBranches : [];

    var mainPoints = buildMainPoints(pathSequence.length);
    var nodes = [];

    for (var i = 0; i < pathSequence.length; i++) {
      nodes.push({
        id: 'path_' + i,
        index: i,
        x: mainPoints[i].x,
        y: mainPoints[i].y,
        kind: 'path',
        vocabularyId: pathSequence[i],
        attachAfterIndex: null
      });
    }

    var branchSegments = [];
    for (var b = 0; b < distractorBranches.length; b++) {
      var branch = distractorBranches[b] || {};
      var attachAfterIndex = Math.max(0, Math.min(mainPoints.length - 1, parseInt(branch.attach_after_index, 10) || 0));
      var endpoint = branchEndpoint(mainPoints, attachAfterIndex, b);
      var startPoint = mainPoints[attachAfterIndex] || { x: 0, y: 0 };

      nodes.push({
        id: 'branch_' + b,
        index: pathSequence.length + b,
        x: endpoint.x,
        y: endpoint.y,
        kind: 'branch',
        vocabularyId: branch.vocabulary_id || '',
        attachAfterIndex: attachAfterIndex
      });

      branchSegments.push({ from: startPoint, to: endpoint });
    }

    // ── bounds (for width/height + centering offset) ──
    var xs = mainPoints.map(function (p) { return p.x; });
    var ys = mainPoints.map(function (p) { return p.y; });
    branchSegments.forEach(function (seg) {
      xs.push(seg.to.x);
      ys.push(seg.to.y);
    });
    if (!xs.length) { xs = [0]; ys = [0]; }
    var minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
    var minY = Math.min.apply(null, ys), maxY = Math.max.apply(null, ys);

    var offsetX = PAD - minX;
    var offsetY = PAD - minY;

    var shiftedMain = mainPoints.map(function (p) { return { x: p.x + offsetX, y: p.y + offsetY }; });
    nodes.forEach(function (n) { n.x += offsetX; n.y += offsetY; });
    branchSegments.forEach(function (seg) {
      seg.from = { x: seg.from.x + offsetX, y: seg.from.y + offsetY };
      seg.to = { x: seg.to.x + offsetX, y: seg.to.y + offsetY };
    });

    var mainPathD = pointsToPathD(shiftedMain);
    var branchPathD = branchSegments.map(function (seg) {
      return pointsToPathD([seg.from, seg.to]);
    }).join(' ');
    var branchEndpoints = branchSegments.map(function (seg) { return seg.to; });

    return {
      nodes: nodes,
      mainPath: shiftedMain,
      /* main corridor only — rendered as the double-stroke "path" */
      wallPathD: mainPathD,
      corridorPathD: mainPathD,
      /* dead-end distractor branches — rendered as a distinct dashed red
         line that never reconnects to the main corridor */
      branchPathD: branchPathD,
      branchEndpoints: branchEndpoints,
      width: Math.round((maxX - minX) + PAD * 2),
      height: Math.round((maxY - minY) + PAD * 2)
    };
  }

  global.generateMazeLayout = generateMazeLayout;

}(typeof window !== 'undefined' ? window : this));
