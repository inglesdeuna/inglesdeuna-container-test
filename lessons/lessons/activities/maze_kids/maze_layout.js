/**
 * maze_layout.js
 * Shared by maze_kids/editor.php and maze_kids/viewer.php.
 * generateMazeLayout() is the single source of truth for the maze grid
 * (cells, corridors and dead ends), used identically by the editor live
 * preview and the student viewer. mzkRenderMazeBase() draws the shared
 * "real maze" background (walls + carved floor corridors) so both places
 * render the exact same labyrinth instead of a simple line/diagram.
 */
(function (global) {
  'use strict';

  var CELL = 140;
  var PAD = 96;
  var MIN_CANVAS_WIDTH = 760;
  var MAX_STRAIGHT_RUN = 2;

  var THEME_ICONS = {
    plants: ['🌵', '🌿', '🍀', '🌸', '🌼', '🍃', '🌻', '🌾'],
    buildings: ['🏢', '🏬', '🏭', '🏛️', '🏦', '🏪', '🏗️', '🧱'],
    park: ['🌳', '🌲', '⛲', '🌷', '🦋', '🐝', '🍂', '⚽'],
    home: ['🛋️', '🪟', '🚪', '🖼️', '🕯️', '🪴', '📺', '🛏️'],
    clothes: ['👕', '👖', '👗', '🧥', '👟', '🧢', '🧦', '🧤'],
    animals: ['🐶', '🐱', '🐰', '🐸', '🐵', '🦊', '🐻', '🐼'],
    shapes: ['🔴', '🔵', '🟡', '🟢', '🟣', '⭐', '🔺', '⬛']
  };
  var DEFAULT_THEME = 'plants';

  function normalizeTheme(theme) {
    theme = (theme || '').toString().trim().toLowerCase();
    return THEME_ICONS[theme] ? theme : DEFAULT_THEME;
  }

  var DIRS = [
    { x: 1, y: 0 },
    { x: -1, y: 0 },
    { x: 0, y: 1 },
    { x: 0, y: -1 }
  ];

  function cellKey(x, y) { return x + ',' + y; }

  function mzkHash(str) {
    var h = 0;
    for (var i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) | 0;
    return Math.abs(h);
  }

  function seedFromPath(pathSequence, distractorBranches) {
    var basis = (pathSequence || []).join('|') + '::' + (distractorBranches || []).map(function (b) {
      return (b && b.vocabulary_id || '') + '@' + (b && b.attach_after_index);
    }).join('|');
    return basis ? (mzkHash(basis) || 1) : 1;
  }

  function makeRng(seed) {
    var s = (seed >>> 0) || 1;
    return function () {
      s |= 0; s = (s + 0x6D2B79F5) | 0;
      var t = Math.imul(s ^ (s >>> 15), 1 | s);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  function shuffle(arr, rng) {
    var a = arr.slice();
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(rng() * (i + 1));
      var tmp = a[i]; a[i] = a[j]; a[j] = tmp;
    }
    return a;
  }

  function buildMainPath(count, rng) {
    var path = [{ x: 0, y: 0 }];
    var visited = {}; visited[cellKey(0, 0)] = true;
    var guard = 0;
    var guardLimit = Math.max(4000, count * 400);

    function straightRun() {
      if (path.length < 3) return 0;
      var run = 1;
      for (var i = path.length - 1; i >= 2; i--) {
        var a = path[i], b = path[i - 1], c = path[i - 2];
        if ((a.x - b.x) === (b.x - c.x) && (a.y - b.y) === (b.y - c.y)) run++; else break;
      }
      return run;
    }

    function attempt() {
      if (path.length === count) return true;
      guard++;
      if (guard > guardLimit) return false;
      var cur = path[path.length - 1];
      var prev = path.length > 1 ? path[path.length - 2] : null;
      var prevDir = prev ? { x: cur.x - prev.x, y: cur.y - prev.y } : null;
      var run = straightRun();
      var candidates = shuffle(DIRS, rng);
      if (prevDir && run >= MAX_STRAIGHT_RUN) {
        candidates = candidates.filter(function (d) { return !(d.x === prevDir.x && d.y === prevDir.y); });
      }
      for (var k = 0; k < candidates.length; k++) {
        var d = candidates[k];
        var nx = cur.x + d.x, ny = cur.y + d.y, key = cellKey(nx, ny);
        if (visited[key]) continue;
        path.push({ x: nx, y: ny });
        visited[key] = true;
        if (attempt()) return true;
        path.pop();
        delete visited[key];
        if (guard > guardLimit) return false;
      }
      return false;
    }

    if (count > 0) attempt();
    return path;
  }

  function walk(from, dir, maxSteps, used) {
    var cells = [];
    var cur = from;
    for (var i = 0; i < maxSteps; i++) {
      var next = { x: cur.x + dir.x, y: cur.y + dir.y };
      var key = cellKey(next.x, next.y);
      if (used[key]) break;
      used[key] = true;
      cells.push(next);
      cur = next;
    }
    return cells;
  }

  function buildBranch(attachCell, mainDir, used, rng) {
    var perpOptions;
    if (mainDir && mainDir.x !== 0) perpOptions = [{ x: 0, y: 1 }, { x: 0, y: -1 }];
    else if (mainDir && mainDir.y !== 0) perpOptions = [{ x: 1, y: 0 }, { x: -1, y: 0 }];
    else perpOptions = DIRS.slice();
    perpOptions = shuffle(perpOptions, rng);

    var chain = [];
    var entryDir = null;
    for (var i = 0; i < perpOptions.length; i++) {
      var len1 = 1 + Math.floor(rng() * 2);
      var seg = walk(attachCell, perpOptions[i], len1, used);
      if (seg.length) { entryDir = perpOptions[i]; chain = chain.concat(seg); break; }
    }
    if (!chain.length) {
      var fallbackDirs = shuffle(DIRS, rng);
      for (var f = 0; f < fallbackDirs.length; f++) {
        var seg2 = walk(attachCell, fallbackDirs[f], 1, used);
        if (seg2.length) { entryDir = fallbackDirs[f]; chain = seg2; break; }
      }
    }
    if (!chain.length) return null;

    var shape = rng() < 0.5 ? 'L' : 'U';
    var cur = chain[chain.length - 1];
    var turnOptions = shuffle([{ x: entryDir.y, y: entryDir.x }, { x: -entryDir.y, y: -entryDir.x }], rng);
    var turnedDir = null;
    for (var t = 0; t < turnOptions.length; t++) {
      var len2 = 1 + Math.floor(rng() * 2);
      var seg3 = walk(cur, turnOptions[t], len2, used);
      if (seg3.length) { turnedDir = turnOptions[t]; chain = chain.concat(seg3); cur = chain[chain.length - 1]; break; }
    }
    if (shape === 'U' && turnedDir) {
      var backDir = { x: -entryDir.x, y: -entryDir.y };
      var seg4 = walk(cur, backDir, 1, used);
      if (seg4.length) chain = chain.concat(seg4);
    }

    return { entry: chain[0], end: chain[chain.length - 1], cells: chain };
  }

  function localMainDir(mainPoints, idx) {
    var cur = mainPoints[idx];
    var ref = mainPoints[idx + 1] || mainPoints[idx - 1];
    if (!ref) return null;
    return { x: ref.x - cur.x, y: ref.y - cur.y };
  }

  function pickEndpointCell(anchor, preferDir, used, rng) {
    var candidates = [];
    if (preferDir) candidates.push(preferDir);
    shuffle(DIRS, rng).forEach(function (d) {
      if (!preferDir || d.x !== preferDir.x || d.y !== preferDir.y) candidates.push(d);
    });
    for (var i = 0; i < candidates.length; i++) {
      var d = candidates[i];
      var cand = { x: anchor.x + d.x, y: anchor.y + d.y };
      var key = cellKey(cand.x, cand.y);
      if (!used[key]) { used[key] = true; return cand; }
    }
    return null;
  }

  function generateMazeLayout(pathSequence, distractorBranches) {
    pathSequence = Array.isArray(pathSequence) ? pathSequence : [];
    distractorBranches = Array.isArray(distractorBranches) ? distractorBranches : [];

    var seed = seedFromPath(pathSequence, distractorBranches);
    var rng = makeRng(seed);
    var mainCells = buildMainPath(pathSequence.length, rng);

    var used = {};
    mainCells.forEach(function (c) { used[cellKey(c.x, c.y)] = true; });

    var nodes = [];
    var allCells = mainCells.map(function (c) { return { x: c.x, y: c.y }; });
    var connectors = [];
    var fillerCells = [];

    for (var i = 0; i < mainCells.length; i++) {
      nodes.push({ id: 'path_' + i, index: i, gx: mainCells[i].x, gy: mainCells[i].y, kind: 'path', vocabularyId: pathSequence[i], attachAfterIndex: null });
      if (i > 0) connectors.push([mainCells[i - 1], mainCells[i]]);
    }

    // Reserve START and HOME before dead-end branches are carved. This keeps
    // the exit as its own free maze cell and prevents HOME from falling back
    // onto a vocabulary picture when branches use the available neighbour.
    if (mainCells.length) {
      var startDir = mainCells.length > 1
        ? { x: mainCells[0].x - mainCells[1].x, y: mainCells[0].y - mainCells[1].y }
        : null;
      var startCell = pickEndpointCell(mainCells[0], startDir, used, rng);
      if (startCell) {
        allCells.push({ x: startCell.x, y: startCell.y });
        connectors.push([startCell, mainCells[0]]);
        nodes.unshift({ id: 'start', index: -1, gx: startCell.x, gy: startCell.y, kind: 'start', vocabularyId: '', attachAfterIndex: null });
      }

      var lastIdx = mainCells.length - 1;
      var homeDir = mainCells.length > 1
        ? { x: mainCells[lastIdx].x - mainCells[lastIdx - 1].x, y: mainCells[lastIdx].y - mainCells[lastIdx - 1].y }
        : null;
      var homeCell = pickEndpointCell(mainCells[lastIdx], homeDir, used, rng);
      if (homeCell) {
        allCells.push({ x: homeCell.x, y: homeCell.y });
        connectors.push([mainCells[lastIdx], homeCell]);
        nodes.push({ id: 'home', index: pathSequence.length + distractorBranches.length, gx: homeCell.x, gy: homeCell.y, kind: 'home', vocabularyId: '', attachAfterIndex: null });
      }
    }

    for (var b = 0; b < distractorBranches.length; b++) {
      var branch = distractorBranches[b] || {};
      var attachIndex = Math.max(0, Math.min(mainCells.length - 1, parseInt(branch.attach_after_index, 10) || 0));
      var attachCell = mainCells[attachIndex] || { x: 0, y: 0 };
      var mainDir = localMainDir(mainCells, attachIndex);
      var built = buildBranch(attachCell, mainDir, used, rng);
      if (!built) continue;

      built.cells.forEach(function (c, idx) {
        allCells.push({ x: c.x, y: c.y });
        if (idx < built.cells.length - 1) fillerCells.push({ x: c.x, y: c.y });
      });
      connectors.push([attachCell, built.entry]);
      for (var s = 1; s < built.cells.length; s++) connectors.push([built.cells[s - 1], built.cells[s]]);

      nodes.push({
        id: 'branch_' + b,
        index: pathSequence.length + b,
        gx: built.end.x,
        gy: built.end.y,
        kind: 'branch',
        vocabularyId: branch.vocabulary_id || '',
        attachAfterIndex: attachIndex,
        entryGx: built.entry.x,
        entryGy: built.entry.y
      });
    }

    var xs = allCells.map(function (c) { return c.x; });
    var ys = allCells.map(function (c) { return c.y; });
    if (!xs.length) { xs = [0]; ys = [0]; }
    var minGX = Math.min.apply(null, xs), maxGX = Math.max.apply(null, xs);
    var minGY = Math.min.apply(null, ys), maxGY = Math.max.apply(null, ys);

    var rawWidth = (maxGX - minGX) * CELL + PAD * 2;
    var rawHeight = (maxGY - minGY) * CELL + PAD * 2;
    var finalWidth = Math.max(rawWidth, MIN_CANVAS_WIDTH);
    var offsetX = PAD - minGX * CELL + (finalWidth - rawWidth) / 2;
    var offsetY = PAD - minGY * CELL;

    function toPx(c) { return { x: c.x * CELL + offsetX, y: c.y * CELL + offsetY }; }

    nodes.forEach(function (n) {
      var p = toPx({ x: n.gx, y: n.gy });
      n.x = p.x; n.y = p.y;
      if (n.kind === 'branch') {
        var ep = toPx({ x: n.entryGx, y: n.entryGy });
        n.entryX = ep.x; n.entryY = ep.y;
      }
    });

    return {
      nodes: nodes,
      cells: allCells.map(toPx),
      connectors: connectors.map(function (pair) { return [toPx(pair[0]), toPx(pair[1])]; }),
      fillerCells: fillerCells.map(toPx),
      cellSize: CELL,
      width: Math.round(finalWidth),
      height: Math.round(rawHeight),
      offsetX: offsetX,
      offsetY: offsetY
    };
  }

  function renderMazeBase(NS, svg, layout, opts) {
    opts = opts || {};
    var wallColor = opts.wallColor || '#CDC7F3';
    var floorColor = opts.floorColor || '#FFFFFF';
    var dotColor = opts.dotColor || 'rgba(255,255,255,.35)';
    var cell = layout.cellSize;
    var floorSize = cell * 0.78;
    var corridorThickness = cell * 0.56;
    var patternId = 'mzkWallDots' + Math.floor(Math.random() * 1e6);

    var defs = document.createElementNS(NS, 'defs');
    var pattern = document.createElementNS(NS, 'pattern');
    pattern.setAttribute('id', patternId);
    pattern.setAttribute('width', 22);
    pattern.setAttribute('height', 22);
    pattern.setAttribute('patternUnits', 'userSpaceOnUse');
    var dot = document.createElementNS(NS, 'circle');
    dot.setAttribute('cx', 4); dot.setAttribute('cy', 4); dot.setAttribute('r', 2.1);
    dot.setAttribute('fill', dotColor);
    pattern.appendChild(dot);
    defs.appendChild(pattern);
    svg.appendChild(defs);

    var bg = document.createElementNS(NS, 'rect');
    bg.setAttribute('x', 0); bg.setAttribute('y', 0);
    bg.setAttribute('width', layout.width); bg.setAttribute('height', layout.height);
    bg.setAttribute('rx', 26);
    bg.setAttribute('fill', wallColor);
    svg.appendChild(bg);

    var texture = document.createElementNS(NS, 'rect');
    texture.setAttribute('x', 0); texture.setAttribute('y', 0);
    texture.setAttribute('width', layout.width); texture.setAttribute('height', layout.height);
    texture.setAttribute('rx', 26);
    texture.setAttribute('fill', 'url(#' + patternId + ')');
    svg.appendChild(texture);

    var corridorGroup = document.createElementNS(NS, 'g');
    corridorGroup.setAttribute('class', 'mzk-corridor');
    layout.connectors.forEach(function (pair) {
      var a = pair[0], b = pair[1];
      var rect = document.createElementNS(NS, 'rect');
      if (a.y === b.y) {
        var x = Math.min(a.x, b.x) - floorSize / 2;
        rect.setAttribute('x', x);
        rect.setAttribute('y', a.y - corridorThickness / 2);
        rect.setAttribute('width', Math.abs(a.x - b.x) + floorSize);
        rect.setAttribute('height', corridorThickness);
      } else {
        var y = Math.min(a.y, b.y) - floorSize / 2;
        rect.setAttribute('x', a.x - corridorThickness / 2);
        rect.setAttribute('y', y);
        rect.setAttribute('width', corridorThickness);
        rect.setAttribute('height', Math.abs(a.y - b.y) + floorSize);
      }
      rect.setAttribute('rx', corridorThickness * 0.28);
      rect.setAttribute('fill', floorColor);
      corridorGroup.appendChild(rect);
    });
    svg.appendChild(corridorGroup);

    var cellGroup = document.createElementNS(NS, 'g');
    cellGroup.setAttribute('class', 'mzk-floor-cells');
    layout.cells.forEach(function (c) {
      var rect = document.createElementNS(NS, 'rect');
      rect.setAttribute('x', c.x - floorSize / 2);
      rect.setAttribute('y', c.y - floorSize / 2);
      rect.setAttribute('width', floorSize);
      rect.setAttribute('height', floorSize);
      rect.setAttribute('rx', 16);
      rect.setAttribute('fill', floorColor);
      cellGroup.appendChild(rect);
    });
    svg.appendChild(cellGroup);
  }

  function renderEndpointIcon(NS, kind) {
    var g = document.createElementNS(NS, 'g');
    if (kind === 'start') {
      var tri = document.createElementNS(NS, 'path');
      tri.setAttribute('d', 'M -7,-11 L 11,0 L -7,11 Z');
      tri.setAttribute('fill', '#F97316');
      g.appendChild(tri);
    } else if (kind === 'home') {
      var roof = document.createElementNS(NS, 'path');
      roof.setAttribute('d', 'M -13,9 L -13,-2 L 0,-15 L 13,-2 L 13,9 Z');
      roof.setAttribute('fill', '#16a34a');
      g.appendChild(roof);
      var door = document.createElementNS(NS, 'rect');
      door.setAttribute('x', -4); door.setAttribute('y', -3);
      door.setAttribute('width', 8); door.setAttribute('height', 12);
      door.setAttribute('rx', 1.5);
      door.setAttribute('fill', '#fff');
      g.appendChild(door);
    }
    return g;
  }

  function renderFillerIcon(NS, theme, seed, cellSize) {
    var icons = THEME_ICONS[normalizeTheme(theme)];
    var icon = icons[Math.abs(seed || 0) % icons.length];
    var g = document.createElementNS(NS, 'g');
    g.setAttribute('class', 'mzk-filler');
    g.setAttribute('style', 'pointer-events:none');
    var text = document.createElementNS(NS, 'text');
    text.setAttribute('x', 0);
    text.setAttribute('y', 0);
    text.setAttribute('text-anchor', 'middle');
    text.setAttribute('dominant-baseline', 'central');
    text.setAttribute('font-size', Math.round((cellSize || CELL) * 0.34));
    text.textContent = icon;
    g.appendChild(text);
    return g;
  }

  function loadArrowMode() {
    if (!global.document) return;
    document.addEventListener('DOMContentLoaded', function () {
      if (!document.getElementById('mzkMazeWrap')) return;
      var s = document.createElement('script');
      s.src = 'maze_arrow_mode.js?v=6';
      document.body.appendChild(s);
    });
  }

  global.generateMazeLayout = generateMazeLayout;
  global.mzkRenderMazeBase = renderMazeBase;
  global.mzkRenderEndpointIcon = renderEndpointIcon;
  global.mzkRenderFillerIcon = renderFillerIcon;
  global.mzkThemeIcons = THEME_ICONS;
  global.mzkNormalizeTheme = normalizeTheme;
  global.mzkShuffleArray = function (arr) { return shuffle(arr, Math.random); };
  loadArrowMode();
}(typeof window !== 'undefined' ? window : this));