<?php
require_once __DIR__ . "/../../config/db.php";

$unit       = isset($_GET['unit']) ? trim((string)$_GET['unit']) : "";
$activityId = isset($_GET['id'])   ? trim((string)$_GET['id'])   : "";

// Load activity row
$row = null;
if ($activityId !== "") {
    $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'crossword' LIMIT 1");
    $stmt->execute(["id" => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$row && $unit !== "") {
    $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'crossword' ORDER BY id ASC LIMIT 1");
    $stmt->execute(["unit" => $unit]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$row) die("Activity not found.");

$raw = json_decode($row["data"] ?? "{}", true);
if (!is_array($raw)) $raw = [];

$title = trim((string)($raw["title"] ?? "Crossword Puzzle"));
if ($title === "") $title = "Crossword Puzzle";

$rawWords = is_array($raw["words"] ?? null) ? $raw["words"] : [];
$words = [];
foreach ($rawWords as $w) {
    if (!is_array($w)) continue;
    $word = strtoupper(trim((string)($w["word"] ?? "")));
    if ($word === "" || strlen($word) < 1) continue;
    $dir = in_array(($w["direction"] ?? ""), ["across","down"], true) ? $w["direction"] : "across";
    $words[] = [
        "word"      => $word,
        "clue"      => htmlspecialchars(trim((string)($w["clue"] ?? "")), ENT_QUOTES, "UTF-8"),
        "raw_clue"  => trim((string)($w["clue"] ?? "")),
        "direction" => $dir,
        "row"       => max(0, (int)($w["row"] ?? 0)),
        "col"       => max(0, (int)($w["col"] ?? 0)),
    ];
}

// Compute grid dimensions
$maxRow = 0; $maxCol = 0;
foreach ($words as $w) {
    if ($w["direction"] === "across") {
        $maxRow = max($maxRow, $w["row"]);
        $maxCol = max($maxCol, $w["col"] + strlen($w["word"]) - 1);
    } else {
        $maxRow = max($maxRow, $w["row"] + strlen($w["word"]) - 1);
        $maxCol = max($maxCol, $w["col"]);
    }
}
$gridRows = $maxRow + 1;
$gridCols = $maxCol + 1;

// Build cell map: [r][c] = list of word references
$cellMap = [];
for ($r = 0; $r < $gridRows; $r++) {
    for ($c = 0; $c < $gridCols; $c++) {
        $cellMap[$r][$c] = ["active" => false, "letter" => "", "wordIdxs" => [], "numLabel" => 0];
    }
}

// Assign numbers — sort by row then col, then across before down at same cell
$indexed = [];
foreach ($words as $idx => $w) {
    $indexed[] = array_merge($w, ["idx" => $idx]);
}
usort($indexed, function($a, $b) {
    if ($a["row"] !== $b["row"]) return $a["row"] - $b["row"];
    if ($a["col"] !== $b["col"]) return $a["col"] - $b["col"];
    return strcmp($a["direction"], $b["direction"]); // "across" < "down"
});

$cellNumberMap = []; // "$r,$c" => number
$wordNumber = [];    // $idx => clue_number
$nextNum = 1;
foreach ($indexed as $w) {
    $key = $w["row"] . "," . $w["col"];
    if (!isset($cellNumberMap[$key])) {
        $cellNumberMap[$key] = $nextNum++;
    }
    $wordNumber[$w["idx"]] = $cellNumberMap[$key];
}

foreach ($words as $idx => $w) {
    $len = strlen($w["word"]);
    for ($i = 0; $i < $len; $i++) {
        $r = $w["direction"] === "across" ? $w["row"] : $w["row"] + $i;
        $c = $w["direction"] === "across" ? $w["col"] + $i : $w["col"];
        if ($r < $gridRows && $c < $gridCols) {
            $cellMap[$r][$c]["active"]   = true;
            $cellMap[$r][$c]["letter"]   = $w["word"][$i];
            $cellMap[$r][$c]["wordIdxs"][] = $idx;
        }
    }
    $cellMap[$w["row"]][$w["col"]]["numLabel"] = $wordNumber[$idx];
}

// Build across and down lists
$acrossWords = [];
$downWords   = [];
foreach ($words as $idx => $w) {
    $entry = ["idx" => $idx, "num" => $wordNumber[$idx], "clue" => $w["clue"], "word" => $w["word"], "row" => $w["row"], "col" => $w["col"], "dir" => $w["direction"]];
    if ($w["direction"] === "across") $acrossWords[] = $entry;
    else $downWords[] = $entry;
}
usort($acrossWords, fn($a,$b) => $a["num"] - $b["num"]);
usort($downWords,   fn($a,$b) => $a["num"] - $b["num"]);

// Pass data to JS
$jsWords = json_encode(array_values($words), JSON_UNESCAPED_UNICODE);
$jsCellMap = json_encode($cellMap, JSON_UNESCAPED_UNICODE);
$jsWordNumbers = json_encode($wordNumber, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
    --bg1: #fdf4ff;
    --bg2: #fff8f0;
    --purple: #7c3aed;
    --purple-soft: #ddd6fe;
    --purple-mid: #a78bfa;
    --purple-dark: #5b21b6;
    --orange: #f97316;
    --orange-soft: #fff7ed;
    --green: #16a34a;
    --red: #dc2626;
    --text: #1e1b4b;
    --muted: #6b7280;
    --cell-size: 42px;
    --cell-border: 2px solid #c4b5fd;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Nunito', sans-serif;
    background: linear-gradient(135deg, var(--bg1) 0%, var(--bg2) 100%);
    min-height: 100vh;
    padding: 18px 12px 40px;
    color: var(--text);
}

/* ---- TOP BAR ---- */
.cw-topbar {
    text-align: center;
    margin-bottom: 22px;
}
.cw-topbar h1 {
    font-family: 'Fredoka One', cursive;
    font-size: clamp(1.5rem, 4vw, 2.2rem);
    color: var(--purple-dark);
    letter-spacing: .5px;
}
.cw-topbar .subtitle {
    font-size: 13px;
    color: var(--muted);
    margin-top: 4px;
}

/* ---- LAYOUT ---- */
.cw-layout {
    display: flex;
    gap: 24px;
    max-width: 1060px;
    margin: 0 auto;
    align-items: flex-start;
    flex-wrap: wrap;
}
.cw-grid-col {
    flex: 1 1 420px;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.cw-clues-col {
    flex: 0 0 280px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

/* ---- GRID ---- */
.cw-grid-wrap {
    overflow-x: auto;
    padding-bottom: 4px;
}
.cw-grid {
    display: grid;
    grid-template-columns: repeat(<?= $gridCols ?>, var(--cell-size));
    grid-template-rows:    repeat(<?= $gridRows ?>, var(--cell-size));
    gap: 2px;
}
.cw-cell {
    width: var(--cell-size);
    height: var(--cell-size);
    position: relative;
    background: var(--purple-soft);
    border: var(--cell-border);
    border-radius: 4px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: background .15s;
}
.cw-cell.blocked {
    background: #1e1b4b;
    border-color: #1e1b4b;
    cursor: default;
    pointer-events: none;
    border-radius: 4px;
}
.cw-cell .num {
    position: absolute;
    top: 2px; left: 3px;
    font-size: 9px; font-weight: 800;
    color: var(--purple-dark);
    line-height: 1;
    pointer-events: none;
    user-select: none;
}
.cw-cell input {
    width: 100%; height: 100%;
    border: none;
    background: transparent;
    text-align: center;
    font-size: 17px;
    font-weight: 800;
    font-family: 'Nunito', sans-serif;
    color: var(--text);
    text-transform: uppercase;
    caret-color: var(--purple);
    outline: none;
    padding: 0;
}
.cw-cell.selected { background: #e0d7ff; border-color: var(--purple); }
.cw-cell.word-hl  { background: #f3effb; }
.cw-cell.correct  { background: #dcfce7; border-color: #86efac; }
.cw-cell.correct input { color: var(--green); }
.cw-cell.wrong    { background: #fee2e2; border-color: #fca5a5; }
.cw-cell.wrong input { color: var(--red); }

/* when revealed */
.cw-cell.revealed { background: #fef9c3; border-color: #fde047; }
.cw-cell.revealed input { color: #92400e; }

/* ---- TOOLBAR ---- */
.cw-toolbar {
    display: flex; gap: 8px; flex-wrap: wrap;
    justify-content: center;
    margin-top: 14px;
}
.cw-toolbar button {
    padding: 9px 15px;
    border: none; border-radius: 50px;
    font-family: 'Nunito', sans-serif;
    font-weight: 800; font-size: 13px;
    cursor: pointer;
    transition: transform .1s, box-shadow .1s;
}
.cw-toolbar button:active { transform: scale(.96); }
.btn-check   { background: var(--purple);      color: #fff; box-shadow: 0 3px 10px rgba(124,58,237,.3); }
.btn-reveal  { background: var(--orange);      color: #fff; box-shadow: 0 3px 10px rgba(249,115,22,.3); }
.btn-reveal-all { background: #f59e0b; color: #fff; box-shadow: 0 3px 10px rgba(245,158,11,.3); }
.btn-clear   { background: #e5e7eb; color: #374151; }

/* ---- RESULT BANNER ---- */
#cw-result {
    margin-top: 12px;
    font-size: 15px; font-weight: 800;
    min-height: 22px; text-align: center;
}

/* ---- CLUE PANELS ---- */
.clue-panel {
    background: #fff;
    border-radius: 16px;
    border: 1px solid var(--purple-soft);
    padding: 14px 16px;
    box-shadow: 0 4px 16px rgba(124,58,237,.08);
}
.clue-panel h3 {
    font-family: 'Fredoka One', cursive;
    font-size: 1rem;
    color: var(--purple-dark);
    margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
}
.clue-list { list-style: none; }
.clue-list li {
    padding: 5px 0;
    font-size: 12.5px;
    border-bottom: 1px solid #f3f4f6;
    cursor: pointer;
    transition: color .15s;
    display: flex; gap: 6px;
    line-height: 1.4;
}
.clue-list li:last-child { border-bottom: none; }
.clue-list li:hover { color: var(--purple); }
.clue-list li.active { color: var(--purple-dark); font-weight: 800; }
.clue-num {
    font-weight: 900; color: var(--purple);
    min-width: 20px; text-align: right;
    font-size: 12px;
}

/* ---- PROGRESS BAR ---- */
.cw-progress-wrap {
    width: 100%;
    margin-top: 16px;
}
.cw-progress-label {
    font-size: 12px; color: var(--muted);
    margin-bottom: 4px; text-align: center;
}
.cw-progress-bar-bg {
    background: #e9d5ff; border-radius: 50px; height: 10px; overflow: hidden;
}
.cw-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--purple), var(--purple-mid));
    border-radius: 50px;
    transition: width .4s;
    width: 0%;
}

/* ---- CONFETTI success overlay ---- */
.cw-complete {
    display: none;
    position: fixed; inset: 0;
    background: rgba(30,27,75,.6);
    z-index: 200;
    align-items: center;
    justify-content: center;
}
.cw-complete.show { display: flex; }
.cw-complete-card {
    background: #fff;
    border-radius: 24px;
    padding: 40px 30px;
    text-align: center;
    max-width: 360px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0,0,0,.2);
    animation: pop .35s ease;
}
@keyframes pop { from { transform: scale(.7); opacity:0; } to { transform: scale(1); opacity:1; } }
.cw-complete-card .big-emoji { font-size: 4rem; }
.cw-complete-card h2 {
    font-family: 'Fredoka One', cursive;
    font-size: 2rem; color: var(--purple-dark);
    margin: 12px 0 6px;
}
.cw-complete-card p { color: var(--muted); font-size: 15px; }
.btn-play-again {
    margin-top: 20px;
    display: inline-block;
    padding: 12px 28px;
    background: var(--purple);
    color: #fff; border-radius: 50px;
    font-weight: 800; font-size: 15px;
    cursor: pointer; border: none;
    font-family: 'Nunito', sans-serif;
}

@media (max-width: 640px) {
    :root { --cell-size: 34px; }
    .cw-clues-col { flex: 0 0 100%; }
    .clue-list li { font-size: 12px; }
}
</style>
</head>
<body>

<div class="cw-topbar">
    <h1>📝 <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="subtitle">Fill in the blanks using the clues. Click a cell to start!</p>
</div>

<div class="cw-layout">
    <!-- GRID COLUMN -->
    <div class="cw-grid-col">
        <div class="cw-grid-wrap">
            <div class="cw-grid" id="cwGrid">
                <?php for ($r = 0; $r < $gridRows; $r++): ?>
                    <?php for ($c = 0; $c < $gridCols; $c++): $cell = $cellMap[$r][$c]; ?>
                        <?php if (!$cell["active"]): ?>
                            <div class="cw-cell blocked" data-r="<?= $r ?>" data-c="<?= $c ?>"></div>
                        <?php else: ?>
                            <div class="cw-cell" data-r="<?= $r ?>" data-c="<?= $c ?>"
                                 data-word-idxs="<?= htmlspecialchars(implode(',', $cell["wordIdxs"]), ENT_QUOTES, 'UTF-8') ?>"
                                 data-answer="<?= htmlspecialchars($cell["letter"], ENT_QUOTES, 'UTF-8') ?>">
                                <?php if ($cell["numLabel"] > 0): ?>
                                    <span class="num"><?= $cell["numLabel"] ?></span>
                                <?php endif; ?>
                                <input type="text" maxlength="1" autocomplete="off"
                                       autocorrect="off" autocapitalize="characters" spellcheck="false">
                            </div>
                        <?php endif; ?>
                    <?php endfor; ?>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="cw-toolbar">
            <button class="btn-check"      onclick="checkAll()">✔ Check</button>
            <button class="btn-reveal"     onclick="revealSelected()">💡 Reveal Word</button>
            <button class="btn-reveal-all" onclick="revealAll()">🔓 Reveal All</button>
            <button class="btn-clear"      onclick="clearAll()">🗑 Clear</button>
        </div>

        <div id="cw-result"></div>

        <!-- Progress -->
        <div class="cw-progress-wrap">
            <div class="cw-progress-label" id="progressLabel">0 / <?= array_sum(array_map(fn($w) => strlen($w["word"]), $words)) ?> letters</div>
            <div class="cw-progress-bar-bg">
                <div class="cw-progress-bar" id="progressBar"></div>
            </div>
        </div>
    </div>

    <!-- CLUES COLUMN -->
    <div class="cw-clues-col">
        <?php if (!empty($acrossWords)): ?>
        <div class="clue-panel">
            <h3>→ Across</h3>
            <ul class="clue-list" id="acrossList">
                <?php foreach ($acrossWords as $aw): ?>
                <li data-idx="<?= $aw['idx'] ?>" data-dir="across" onclick="jumpToClue(<?= $aw['idx'] ?>)">
                    <span class="clue-num"><?= $aw['num'] ?>.</span>
                    <span><?= $aw['clue'] !== '' ? $aw['clue'] : '<em style="color:#9ca3af">No clue</em>' ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($downWords)): ?>
        <div class="clue-panel">
            <h3>↓ Down</h3>
            <ul class="clue-list" id="downList">
                <?php foreach ($downWords as $dw): ?>
                <li data-idx="<?= $dw['idx'] ?>" data-dir="down" onclick="jumpToClue(<?= $dw['idx'] ?>)">
                    <span class="clue-num"><?= $dw['num'] ?>.</span>
                    <span><?= $dw['clue'] !== '' ? $dw['clue'] : '<em style="color:#9ca3af">No clue</em>' ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Complete overlay -->
<div class="cw-complete" id="cwComplete">
    <div class="cw-complete-card">
        <div class="big-emoji">🎉</div>
        <h2>Excellent!</h2>
        <p>You completed the crossword puzzle!</p>
        <button class="btn-play-again" onclick="clearAll(); document.getElementById('cwComplete').classList.remove('show')">Play Again</button>
    </div>
</div>

<script>
/* ===== DATA from PHP ===== */
const WORDS = <?= $jsWords ?>;
const WORD_NUMBERS = <?= $jsWordNumbers ?>;  // idx→number
const GRID_ROWS = <?= $gridRows ?>;
const GRID_COLS = <?= $gridCols ?>;
const TOTAL_LETTERS = <?= array_sum(array_map(fn($w) => strlen($w["word"]), $words)) ?>;

/* ===== STATE ===== */
let selectedCell = null;     // {r, c}
let currentWordIdx = -1;     // which word is active
let currentDir = 'across';   // 'across' | 'down'

/* ===== CELL LOOKUP ===== */
function cellEl(r, c) {
    return document.querySelector(`.cw-cell[data-r="${r}"][data-c="${c}"]`);
}
function cellInput(r, c) {
    const el = cellEl(r, c);
    return el ? el.querySelector('input') : null;
}

/* ===== WORD CELLS ===== */
function wordCells(idx) {
    const w = WORDS[idx];
    if (!w) return [];
    const cells = [];
    for (let i = 0; i < w.word.length; i++) {
        const r = w.direction === 'across' ? w.row : w.row + i;
        const c = w.direction === 'across' ? w.col + i : w.col;
        const el = cellEl(r, c);
        if (el) cells.push({ el, r, c, letter: w.word[i] });
    }
    return cells;
}

/* ===== HIGHLIGHT ===== */
function clearHighlights() {
    document.querySelectorAll('.cw-cell.selected, .cw-cell.word-hl').forEach(el => {
        el.classList.remove('selected', 'word-hl');
    });
    document.querySelectorAll('.clue-list li.active').forEach(el => el.classList.remove('active'));
}

function highlightWord(idx) {
    wordCells(idx).forEach(({ el, r, c }) => {
        if (selectedCell && selectedCell.r === r && selectedCell.c === c) {
            el.classList.add('selected');
        } else {
            el.classList.add('word-hl');
        }
    });
    // highlight clue
    document.querySelectorAll(`.clue-list li[data-idx="${idx}"]`).forEach(li => li.classList.add('active'));
    // scroll clue into view
    const activeLi = document.querySelector(`.clue-list li[data-idx="${idx}"].active`);
    if (activeLi) activeLi.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

/* ===== SELECT WORD for a cell ===== */
function selectWordForCell(r, c, preferDir) {
    const el = cellEl(r, c);
    if (!el || el.classList.contains('blocked')) return;
    const idxsRaw = el.dataset.wordIdxs || '';
    const idxs = idxsRaw.split(',').map(Number).filter(n => !isNaN(n));
    if (!idxs.length) return;

    let chosen = idxs[0];
    // try preferred dir
    const preferred = idxs.find(i => WORDS[i] && WORDS[i].direction === preferDir);
    if (preferred !== undefined) chosen = preferred;
    // if already on this cell+word, toggle direction
    if (currentWordIdx !== -1 && idxs.includes(currentWordIdx) && selectedCell &&
        selectedCell.r === r && selectedCell.c === c) {
        const other = idxs.find(i => i !== currentWordIdx);
        if (other !== undefined) chosen = other;
    }

    currentWordIdx = chosen;
    currentDir     = WORDS[chosen] ? WORDS[chosen].direction : 'across';
    selectedCell   = { r, c };

    clearHighlights();
    el.classList.add('selected');
    highlightWord(chosen);
}

/* ===== CLICK CELL ===== */
document.getElementById('cwGrid').addEventListener('click', function(e) {
    const cell = e.target.closest('.cw-cell:not(.blocked)');
    if (!cell) return;
    const r = parseInt(cell.dataset.r);
    const c = parseInt(cell.dataset.c);
    selectWordForCell(r, c, currentDir);
    const inp = cell.querySelector('input');
    if (inp) inp.focus();
});

/* ===== KEYBOARD INPUT ===== */
document.getElementById('cwGrid').addEventListener('keydown', function(e) {
    if (!selectedCell) return;
    const { r, c } = selectedCell;

    if (e.key === 'Backspace') {
        e.preventDefault();
        const inp = cellInput(r, c);
        if (inp && inp.value !== '') {
            inp.value = '';
            const cell = cellEl(r, c);
            if (cell) { cell.classList.remove('correct','wrong','revealed'); }
            updateProgress();
        } else {
            // move backward
            moveFocus(r, c, -1);
        }
        return;
    }

    if (e.key === 'Tab') {
        e.preventDefault();
        nextWord(e.shiftKey ? -1 : 1);
        return;
    }

    if (e.key === 'ArrowRight') { e.preventDefault(); selectWordForCell(r, c, 'across'); cellInput(r,c)?.focus(); return; }
    if (e.key === 'ArrowDown')  { e.preventDefault(); selectWordForCell(r, c, 'down');   cellInput(r,c)?.focus(); return; }

    if (e.key.length === 1 && /[a-zA-Z]/.test(e.key)) {
        e.preventDefault();
        const inp = cellInput(r, c);
        if (inp) {
            const cell = cellEl(r, c);
            if (cell) { cell.classList.remove('correct','wrong','revealed'); }
            inp.value = e.key.toUpperCase();
            updateProgress();
            moveFocus(r, c, 1);
        }
    }
});

function moveFocus(r, c, delta) {
    const word = WORDS[currentWordIdx];
    if (!word) return;
    const cells = wordCells(currentWordIdx);
    const curPos = cells.findIndex(cell => cell.r === r && cell.c === c);
    const nextPos = curPos + delta;
    if (nextPos >= 0 && nextPos < cells.length) {
        const next = cells[nextPos];
        selectedCell = { r: next.r, c: next.c };
        clearHighlights();
        next.el.classList.add('selected');
        highlightWord(currentWordIdx);
        const inp = next.el.querySelector('input');
        if (inp) inp.focus();
    } else if (delta === 1 && nextPos >= cells.length) {
        nextWord(1);
    }
}

function nextWord(delta) {
    const total = WORDS.length;
    if (total === 0) return;
    let next = ((currentWordIdx + delta) % total + total) % total;
    const w = WORDS[next];
    if (!w) return;
    selectedCell = { r: w.row, c: w.col };
    currentWordIdx = next;
    currentDir = w.direction;
    clearHighlights();
    const firstCell = cellEl(w.row, w.col);
    if (firstCell) { firstCell.classList.add('selected'); firstCell.querySelector('input')?.focus(); }
    highlightWord(next);
}

/* ===== JUMP TO CLUE (from sidebar) ===== */
function jumpToClue(idx) {
    const w = WORDS[idx];
    if (!w) return;
    selectedCell = { r: w.row, c: w.col };
    currentWordIdx = idx;
    currentDir = w.direction;
    clearHighlights();
    const cell = cellEl(w.row, w.col);
    if (cell) { cell.classList.add('selected'); cell.querySelector('input')?.focus(); }
    highlightWord(idx);
}

/* ===== PROGRESS ===== */
function updateProgress() {
    let filled = 0;
    document.querySelectorAll('.cw-cell:not(.blocked) input').forEach(inp => {
        if (inp.value.trim() !== '') filled++;
    });
    const pct = TOTAL_LETTERS > 0 ? Math.round(filled / TOTAL_LETTERS * 100) : 0;
    document.getElementById('progressBar').style.width = pct + '%';
    document.getElementById('progressLabel').textContent = filled + ' / ' + TOTAL_LETTERS + ' letters';
}

/* ===== CHECK ===== */
function checkAll() {
    let wrong = 0, correct = 0;
    document.querySelectorAll('.cw-cell:not(.blocked)').forEach(cell => {
        const inp = cell.querySelector('input');
        if (!inp || inp.value.trim() === '') return;
        const answer = (cell.dataset.answer || '').toUpperCase();
        cell.classList.remove('correct','wrong','revealed');
        if (inp.value.toUpperCase() === answer) { cell.classList.add('correct'); correct++; }
        else { cell.classList.add('wrong'); wrong++; }
    });
    const result = document.getElementById('cw-result');
    if (wrong === 0 && correct > 0) {
        result.innerHTML = '<span style="color:#16a34a">✅ All correct! Well done!</span>';
        checkComplete();
    } else if (wrong > 0) {
        result.innerHTML = `<span style="color:#dc2626">❌ ${wrong} mistake${wrong>1?'s':''} found. Keep going!</span>`;
    } else {
        result.innerHTML = '';
    }
}

function checkComplete() {
    const cells = document.querySelectorAll('.cw-cell:not(.blocked)');
    let allDone = true;
    cells.forEach(cell => {
        const inp = cell.querySelector('input');
        if (!inp || inp.value.trim() === '' || !cell.classList.contains('correct')) allDone = false;
    });
    if (allDone) {
        setTimeout(() => { document.getElementById('cwComplete').classList.add('show'); }, 400);
    }
}

/* ===== REVEAL ===== */
function revealSelected() {
    if (currentWordIdx === -1) return;
    wordCells(currentWordIdx).forEach(({ el, letter }) => {
        const inp = el.querySelector('input');
        if (inp) inp.value = letter;
        el.classList.remove('correct','wrong');
        el.classList.add('revealed');
    });
    updateProgress();
    document.getElementById('cw-result').innerHTML = '<span style="color:#92400e">💡 Word revealed.</span>';
}

function revealAll() {
    document.querySelectorAll('.cw-cell:not(.blocked)').forEach(cell => {
        const inp = cell.querySelector('input');
        const answer = cell.dataset.answer || '';
        if (inp) inp.value = answer;
        cell.classList.remove('correct','wrong');
        cell.classList.add('revealed');
    });
    updateProgress();
    document.getElementById('cw-result').innerHTML = '<span style="color:#92400e">🔓 All revealed.</span>';
}

/* ===== CLEAR ===== */
function clearAll() {
    document.querySelectorAll('.cw-cell:not(.blocked)').forEach(cell => {
        const inp = cell.querySelector('input');
        if (inp) inp.value = '';
        cell.classList.remove('correct','wrong','revealed');
    });
    clearHighlights();
    selectedCell = null;
    currentWordIdx = -1;
    updateProgress();
    document.getElementById('cw-result').innerHTML = '';
}

/* ===== INIT ===== */
function init() {
    updateProgress();
    // auto-select first word
    if (WORDS.length > 0) {
        setTimeout(() => { jumpToClue(0); }, 100);
    }
}
init();

// prevent accidental page leave
window.addEventListener('beforeunload', function(e) {
    const hasInput = [...document.querySelectorAll('.cw-cell:not(.blocked) input')]
        .some(inp => inp.value !== '');
    if (hasInput) { e.preventDefault(); e.returnValue = ''; }
});
</script>
</body>
</html>
