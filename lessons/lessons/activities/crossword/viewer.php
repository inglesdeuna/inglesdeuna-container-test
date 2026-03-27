<?php
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../core/_activity_viewer_template.php";

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
    $word = preg_replace('/[^A-Z0-9]/', '', $word);
    if ($word === "" || strlen($word) < 2) continue;
    $words[] = [
        "word"      => $word,
        "clue"      => htmlspecialchars(trim((string)($w["clue"] ?? "")), ENT_QUOTES, "UTF-8"),
        "raw_clue"  => trim((string)($w["clue"] ?? "")),
    ];
}

function cw_key(int $r, int $c): string {
    return $r . ',' . $c;
}

function cw_can_place_word(array $grid, string $word, int $row, int $col, string $direction): array
{
    $len = strlen($word);
    $overlaps = 0;

    $dr = $direction === 'across' ? 0 : 1;
    $dc = $direction === 'across' ? 1 : 0;

    $beforeKey = cw_key($row - $dr, $col - $dc);
    $afterKey = cw_key($row + $dr * $len, $col + $dc * $len);
    if (isset($grid[$beforeKey]) || isset($grid[$afterKey])) {
        return [false, 0];
    }

    for ($i = 0; $i < $len; $i++) {
        $r = $row + $dr * $i;
        $c = $col + $dc * $i;
        $key = cw_key($r, $c);
        $ch = $word[$i];

        if (isset($grid[$key])) {
            if (($grid[$key]['letter'] ?? '') !== $ch) {
                return [false, 0];
            }
            $overlaps++;
            continue;
        }

        if ($direction === 'across') {
            if (isset($grid[cw_key($r - 1, $c)]) || isset($grid[cw_key($r + 1, $c)])) {
                return [false, 0];
            }
        } else {
            if (isset($grid[cw_key($r, $c - 1)]) || isset($grid[cw_key($r, $c + 1)])) {
                return [false, 0];
            }
        }
    }

    return [true, $overlaps];
}

function cw_place_word(array &$grid, array $placedWord): void
{
    $word = $placedWord['word'];
    $len = strlen($word);
    $row = (int)$placedWord['row'];
    $col = (int)$placedWord['col'];
    $dir = $placedWord['direction'];

    for ($i = 0; $i < $len; $i++) {
        $r = $dir === 'across' ? $row : $row + $i;
        $c = $dir === 'across' ? $col + $i : $col;
        $key = cw_key($r, $c);
        if (!isset($grid[$key])) {
            $grid[$key] = ['letter' => $word[$i], 'wordIdxs' => []];
        }
        $grid[$key]['wordIdxs'][] = $placedWord['idx'];
    }
}

function cw_generate_layout(array $words): array
{
    if (empty($words)) {
        return [[], []];
    }

    $indexed = [];
    foreach ($words as $idx => $w) {
        $indexed[] = ['idx' => $idx, 'word' => $w['word']];
    }

    usort($indexed, function ($a, $b) {
        $lenCmp = strlen($b['word']) <=> strlen($a['word']);
        if ($lenCmp !== 0) return $lenCmp;
        return $a['idx'] <=> $b['idx'];
    });

    $grid = [];
    $placed = [];

    $first = $indexed[0];
    $firstPlaced = [
        'idx' => $first['idx'],
        'word' => $first['word'],
        'row' => 0,
        'col' => 0,
        'direction' => 'across',
    ];
    $placed[] = $firstPlaced;
    cw_place_word($grid, $firstPlaced);

    for ($p = 1; $p < count($indexed); $p++) {
        $candidateWord = $indexed[$p];
        $word = $candidateWord['word'];
        $len = strlen($word);

        $best = null;
        $bestScore = -1000000;

        foreach ($grid as $key => $cell) {
            $parts = explode(',', $key);
            $r0 = (int)$parts[0];
            $c0 = (int)$parts[1];
            $gridCh = $cell['letter'];

            for ($i = 0; $i < $len; $i++) {
                if ($word[$i] !== $gridCh) continue;

                foreach (['across', 'down'] as $dir) {
                    $startRow = $dir === 'across' ? $r0 : $r0 - $i;
                    $startCol = $dir === 'across' ? $c0 - $i : $c0;
                    [$ok, $overlaps] = cw_can_place_word($grid, $word, $startRow, $startCol, $dir);
                    if (!$ok || $overlaps < 1) continue;

                    $minR = $startRow;
                    $maxR = $dir === 'down' ? $startRow + $len - 1 : $startRow;
                    $minC = $startCol;
                    $maxC = $dir === 'across' ? $startCol + $len - 1 : $startCol;
                    $areaPenalty = ($maxR - $minR + 1) * ($maxC - $minC + 1);
                    $score = ($overlaps * 1000) - $areaPenalty;

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = [
                            'idx' => $candidateWord['idx'],
                            'word' => $word,
                            'row' => $startRow,
                            'col' => $startCol,
                            'direction' => $dir,
                        ];
                    }
                }
            }
        }

        if ($best === null) {
            $bounds = ['minR' => 0, 'maxR' => 0, 'minC' => 0, 'maxC' => 0];
            $firstBound = true;
            foreach ($grid as $key => $_cell) {
                $parts = explode(',', $key);
                $r = (int)$parts[0];
                $c = (int)$parts[1];
                if ($firstBound) {
                    $bounds = ['minR' => $r, 'maxR' => $r, 'minC' => $c, 'maxC' => $c];
                    $firstBound = false;
                } else {
                    $bounds['minR'] = min($bounds['minR'], $r);
                    $bounds['maxR'] = max($bounds['maxR'], $r);
                    $bounds['minC'] = min($bounds['minC'], $c);
                    $bounds['maxC'] = max($bounds['maxC'], $c);
                }
            }

            $preferDown = ($p % 2) === 1;
            if ($preferDown) {
                $fallback = [
                    'idx' => $candidateWord['idx'],
                    'word' => $word,
                    'row' => $bounds['maxR'] + 2,
                    'col' => $bounds['minC'],
                    'direction' => 'down',
                ];
                [$okFallback] = cw_can_place_word($grid, $word, $fallback['row'], $fallback['col'], $fallback['direction']);
                if (!$okFallback) {
                    $fallback['direction'] = 'across';
                    $fallback['row'] = $bounds['maxR'] + 2;
                    $fallback['col'] = $bounds['minC'];
                }
                $best = $fallback;
            } else {
                $fallback = [
                    'idx' => $candidateWord['idx'],
                    'word' => $word,
                    'row' => $bounds['maxR'] + 2,
                    'col' => $bounds['minC'],
                    'direction' => 'across',
                ];
                [$okFallback] = cw_can_place_word($grid, $word, $fallback['row'], $fallback['col'], $fallback['direction']);
                if (!$okFallback) {
                    $fallback['direction'] = 'down';
                    $fallback['row'] = $bounds['maxR'] + 2;
                    $fallback['col'] = $bounds['minC'];
                }
                $best = $fallback;
            }
        }

        $placed[] = $best;
        cw_place_word($grid, $best);
    }

    $minRow = 0;
    $minCol = 0;
    $firstCell = true;
    foreach ($grid as $key => $_cell) {
        [$r, $c] = array_map('intval', explode(',', $key));
        if ($firstCell) {
            $minRow = $r;
            $minCol = $c;
            $firstCell = false;
        } else {
            $minRow = min($minRow, $r);
            $minCol = min($minCol, $c);
        }
    }

    if ($minRow !== 0 || $minCol !== 0) {
        foreach ($placed as &$pw) {
            $pw['row'] -= $minRow;
            $pw['col'] -= $minCol;
        }
        unset($pw);
    }

    return [$placed, $grid];
}

[$placedWords, $_generatedGrid] = cw_generate_layout($words);

$wordsByIdx = [];
foreach ($words as $idx => $w) {
    $wordsByIdx[$idx] = $w;
}

$words = [];
foreach ($placedWords as $pw) {
    $idx = (int)$pw['idx'];
    if (!isset($wordsByIdx[$idx])) continue;
    $words[] = [
        'idx' => $idx,
        'word' => $wordsByIdx[$idx]['word'],
        'clue' => $wordsByIdx[$idx]['clue'],
        'raw_clue' => $wordsByIdx[$idx]['raw_clue'],
        'direction' => $pw['direction'],
        'row' => (int)$pw['row'],
        'col' => (int)$pw['col'],
    ];
}

if (empty($words)) {
    die('Crossword has no valid words. Please add at least one word with 2+ letters.');
}

// Compute grid dimensions from generated placement
$maxRow = 0;
$maxCol = 0;
foreach ($words as $w) {
    if ($w['direction'] === 'across') {
        $maxRow = max($maxRow, $w['row']);
        $maxCol = max($maxCol, $w['col'] + strlen($w['word']) - 1);
    } else {
        $maxRow = max($maxRow, $w['row'] + strlen($w['word']) - 1);
        $maxCol = max($maxCol, $w['col']);
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
}

// Assign clue numbers by crossword starts (scan top-left to bottom-right)
$startNumberByCell = [];
$nextNum = 1;
for ($r = 0; $r < $gridRows; $r++) {
    for ($c = 0; $c < $gridCols; $c++) {
        if (!$cellMap[$r][$c]['active']) continue;
        $startsAcross = ($c === 0 || !$cellMap[$r][$c - 1]['active']) && ($c + 1 < $gridCols && $cellMap[$r][$c + 1]['active']);
        $startsDown   = ($r === 0 || !$cellMap[$r - 1][$c]['active']) && ($r + 1 < $gridRows && $cellMap[$r + 1][$c]['active']);
        if ($startsAcross || $startsDown) {
            $startNumberByCell[$r . ',' . $c] = $nextNum++;
        }
    }
}

$wordNumber = [];
foreach ($words as $idx => $w) {
    $key = $w['row'] . ',' . $w['col'];
    $wordNumber[$idx] = $startNumberByCell[$key] ?? 0;
    $cellMap[$w['row']][$w['col']]['numLabel'] = $wordNumber[$idx];
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

$activeCellCount = 0;
for ($r = 0; $r < $gridRows; $r++) {
    for ($c = 0; $c < $gridCols; $c++) {
        if ($cellMap[$r][$c]['active']) $activeCellCount++;
    }
}

// Pass data to JS
$jsWords = json_encode(array_values($words), JSON_UNESCAPED_UNICODE);
$jsCellMap = json_encode($cellMap, JSON_UNESCAPED_UNICODE);
$jsWordNumbers = json_encode($wordNumber, JSON_UNESCAPED_UNICODE);

ob_start();
?>
<style>
:root {
    --bg1: #dff5ff;
    --bg2: #fff4db;
    --bg3: #f8d9e6;
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
    --cell-size: 40px;
    --cell-border: 2px solid #c4b5fd;
}
* { box-sizing: border-box; margin: 0; padding: 0; }

.cw-viewer {
    max-width: 980px;
    margin: 0 auto;
}

.cw-intro {
    margin-bottom: 12px;
    padding: 18px 20px;
    border-radius: 26px;
    border: 1px solid #e7d8fb;
    background: linear-gradient(135deg, #f7ecff 0%, #fff3eb 48%, #fff9d9 100%);
    box-shadow: 0 16px 34px rgba(15, 23, 42, .09);
    text-align: center;
}

.cw-intro h2 {
    margin: 0 0 8px;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(26px, 2.2vw, 30px);
    line-height: 1.1;
    color: var(--purple-dark);
}

.cw-intro p {
    margin: 0;
    color: #5b516f;
    font-size: 15px;
    line-height: 1.5;
}

.cw-card {
    background: linear-gradient(180deg, #fff7ff 0%, #fffdf4 100%);
    border: 1px solid #f1d7eb;
    border-radius: 24px;
    padding: 16px;
    box-shadow: 0 14px 28px rgba(15, 23, 42, .08);
}

.cw-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 280px;
    gap: 16px;
    align-items: flex-start;
}
.cw-grid-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 0;
}
.cw-clues-col {
    display: flex;
    flex-direction: column;
    gap: 12px;
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
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: background .15s;
}
.cw-cell.blocked {
    background: #312e81;
    border-color: #312e81;
    cursor: default;
    pointer-events: none;
    border-radius: 8px;
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
    font-family: 'Nunito', 'Segoe UI', sans-serif;
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
    display: flex; gap: 10px; flex-wrap: wrap;
    justify-content: center;
    margin-top: 14px;
}
.cw-toolbar button {
    padding: 11px 18px;
    border: none; border-radius: 999px;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-weight: 800; font-size: 14px;
    min-width: 142px;
    cursor: pointer;
    color: #fff;
    box-shadow: 0 10px 22px rgba(15, 23, 42, .14);
    transition: transform .15s ease, filter .15s ease;
}
.cw-toolbar button:hover { transform: translateY(-1px); filter: brightness(1.04); }
.btn-check   { background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%); }
.btn-reveal  { background: linear-gradient(180deg, #f59e0b 0%, #ea580c 100%); }
.btn-reveal-all { background: linear-gradient(180deg, #f9a8d4 0%, #ec4899 100%); }
.btn-clear   { background: linear-gradient(180deg, #94a3b8 0%, #64748b 100%); }

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
    border: 1px solid #ead8ff;
    padding: 14px 16px;
    box-shadow: 0 8px 18px rgba(124, 58, 237, .08);
}
.clue-panel h3 {
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
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
    font-size: 13px; color: #5b516f;
    margin-bottom: 4px; text-align: center;
    font-weight: 700;
}
.cw-progress-bar-bg {
    background: #e9d5ff; border-radius: 999px; height: 10px; overflow: hidden;
}
.cw-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--purple), var(--purple-mid));
    border-radius: 50px;
    transition: width .4s;
    width: 0%;
}

/* ---- Completed screen (shared activity style) ---- */
.completed-screen {
    display: none;
    text-align: center;
    max-width: 600px;
    margin: 0 auto;
    padding: 40px 20px 24px;
}
.completed-screen.active {
    display: block;
}

.completed-icon {
    font-size: 80px;
    margin-bottom: 20px;
}

.completed-title {
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: 36px;
    font-weight: 700;
    color: #6d28d9;
    margin: 0 0 16px;
    line-height: 1.2;
}

.completed-text {
    font-size: 16px;
    color: #5b516f;
    line-height: 1.6;
    margin: 0 0 32px;
}

.completed-button {
    display: inline-block;
    padding: 12px 24px;
    border: none;
    border-radius: 999px;
    background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%);
    color: #fff;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    box-shadow: 0 10px 24px rgba(0, 0, 0, .14);
    transition: transform .18s ease, filter .18s ease;
}

.completed-button:hover {
    transform: scale(1.05);
    filter: brightness(1.07);
}

@media (max-width: 640px) {
    :root { --cell-size: 34px; }
    .cw-intro { padding: 16px 14px; }
    .cw-layout { grid-template-columns: 1fr; }
    .clue-list li { font-size: 12px; }
    .cw-toolbar button { width: 100%; min-width: 0; max-width: 300px; }
}

@media (max-height: 900px) and (min-width: 641px) {
    .cw-intro { padding: 14px 16px; }
    .cw-intro h2 { font-size: clamp(22px, 1.9vw, 26px); }
    .cw-toolbar button {
        padding: 10px 16px;
        min-width: 132px;
        font-size: 13px;
    }
}
</style>

<div class="cw-viewer" id="cwViewer">
    <section class="cw-intro">
        <h2><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
        <p>Fill the crossword using Across and Down clues. Click a square to begin.</p>
    </section>

    <div class="cw-card" id="cwGame">
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
            <div class="cw-progress-label" id="progressLabel">0 / <?= $activeCellCount ?> letters</div>
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
    </div>

    <div id="cw-completed" class="completed-screen">
        <div class="completed-icon">✅</div>
        <h2 class="completed-title" id="cw-completed-title"></h2>
        <p class="completed-text" id="cw-completed-text"></p>
        <button type="button" class="completed-button" onclick="restartCrossword()">Restart</button>
    </div>
</div>

<script>
/* ===== DATA from PHP ===== */
const WORDS = <?= $jsWords ?>;
const WORD_NUMBERS = <?= $jsWordNumbers ?>;  // idx→number
const GRID_ROWS = <?= $gridRows ?>;
const GRID_COLS = <?= $gridCols ?>;
const TOTAL_LETTERS = <?= $activeCellCount ?>;

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
        const completed = document.getElementById('cw-completed');
        if (completed) {
            completed.classList.add('active');
            completed.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
}

function restartCrossword() {
    clearAll();
    const completed = document.getElementById('cw-completed');
    if (completed) completed.classList.remove('active');
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
    const completedTitle = document.getElementById('cw-completed-title');
    const completedText = document.getElementById('cw-completed-text');
    if (completedTitle) completedTitle.textContent = <?= json_encode($title, JSON_UNESCAPED_UNICODE) ?> || 'Crossword Puzzle';
    if (completedText) completedText.textContent = "You've completed " + (<?= json_encode($title, JSON_UNESCAPED_UNICODE) ?> || 'this activity') + '. Great job practicing.';

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

<?php
$content = ob_get_clean();
render_activity_viewer($title, '🧩', $content);
