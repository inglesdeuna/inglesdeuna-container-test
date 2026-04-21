<?php // TEST SYNC 2026-04-21 ?>
<?php
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../core/_activity_viewer_template.php";

$unit       = isset($_GET['unit']) ? trim((string)$_GET['unit']) : "";
$activityId = isset($_GET['id'])   ? trim((string)$_GET['id'])   : "";
$returnTo   = isset($_GET['return_to']) ? trim((string)$_GET['return_to']) : "";

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

// Limitar tamaño máximo de la cuadrícula
$MAX_GRID_SIZE = 18; // puedes ajustar este valor
$gridRows = min($maxRow + 1, $MAX_GRID_SIZE);
$gridCols = min($maxCol + 1, $MAX_GRID_SIZE);

// Si la cuadrícula es más grande, mostrar advertencia
$showGridLimitWarning = ($maxRow + 1 > $MAX_GRID_SIZE || $maxCol + 1 > $MAX_GRID_SIZE);

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

function cw_compute_cell_sizes(int $rows, int $cols): array
{
    $largestSide = max($rows, $cols);

    if ($largestSide >= 22) {
        return ['desktop' => 36, 'compact' => 32, 'mobile' => 28];
    }
    if ($largestSide >= 18) {
        return ['desktop' => 42, 'compact' => 38, 'mobile' => 32];
    }
    if ($largestSide >= 15) {
        return ['desktop' => 48, 'compact' => 44, 'mobile' => 36];
    }
    if ($largestSide >= 12) {
        return ['desktop' => 54, 'compact' => 48, 'mobile' => 40];
    }

    return ['desktop' => 60, 'compact' => 54, 'mobile' => 44];
}

$cellSizes = cw_compute_cell_sizes($gridRows, $gridCols);

// Pass data to JS
$jsWords = json_encode(array_values($words), JSON_UNESCAPED_UNICODE);
$jsCellMap = json_encode($cellMap, JSON_UNESCAPED_UNICODE);
$jsWordNumbers = json_encode($wordNumber, JSON_UNESCAPED_UNICODE);

ob_start();
?>
<style>
:root {
    --purple: #7c3aed;
    --purple-soft: #ede9fe;
    --purple-mid: #a78bfa;
    --purple-dark: #5b21b6;
    --green: #16a34a;
    --red: #dc2626;
    --text: #1e1b4b;
    --cell-size: <?= (int)$cellSizes['desktop'] ?>px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }

.cw-viewer {
    max-width: 1020px;
    margin: 0 auto;
}

.cw-card {
    background: transparent;
    border-radius: 0;
    padding: 0;
    box-shadow: none;
}

.cw-layout {
    display: flex;
    flex-direction: row;
    gap: 24px;
    align-items: flex-start;
    width: 100%;
}
.cw-grid-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 0;
    flex: 1 1 auto;
}
.cw-clues-col {
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: 260px;
    flex-shrink: 0;
    max-height: 80vh;
    overflow-y: auto;
    position: sticky;
    top: 20px;
    scrollbar-width: thin;
    scrollbar-color: #c4b5fd transparent;
}
.cw-clues-col::-webkit-scrollbar { width: 5px; }
.cw-clues-col::-webkit-scrollbar-track { background: transparent; }
.cw-clues-col::-webkit-scrollbar-thumb { background: #c4b5fd; border-radius: 99px; }

/* ---- GRID ---- */
.cw-grid-wrap {
    overflow-x: auto;
    padding: 12px 0 8px;
    width: 100%;
    display: flex;
    justify-content: center;
}
.cw-grid-wrap::-webkit-scrollbar {
    height: 10px;
}
.cw-grid-wrap::-webkit-scrollbar-thumb {
    background: #a78bfa;
    border-radius: 8px;
}
.cw-grid-wrap::-webkit-scrollbar-track {
    background: #ede9fe;
    border-radius: 8px;
}
.cw-grid {
    display: grid;
    grid-template-columns: repeat(<?= $gridCols ?>, var(--cell-size));
    grid-template-rows:    repeat(<?= $gridRows ?>, var(--cell-size));
    gap: 3px;
}

/* Active (word) cells */
.cw-cell {
    width: var(--cell-size);
    height: var(--cell-size);
    position: relative;
    background: #fff;
    border: 2.5px solid #c4b5fd;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: background .15s, border-color .15s, box-shadow .15s;
    box-shadow: 0 3px 8px rgba(124,58,237,.10);
}

/* Blocked cells: fully invisible */
.cw-cell.blocked {
    background: transparent;
    border-color: transparent;
    box-shadow: none;
    cursor: default;
    pointer-events: none;
}

.cw-cell .num {
    position: absolute;
    top: 3px; left: 4px;
    font-size: 11px; font-weight: 900;
    color: var(--purple);
    line-height: 1;
    pointer-events: auto;
    user-select: none;
    cursor: pointer;
    z-index: 3;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    padding: 2px 1px;
}
.cw-cell .num:hover { color: var(--purple-dark); text-decoration: underline; }

.cw-cell input {
    width: 100%; height: 100%;
    border: none;
    background: transparent;
    text-align: center;
    font-size: calc(var(--cell-size) * 0.46);
    font-weight: 900;
    font-family: 'Fredoka', 'Nunito', 'Segoe UI', sans-serif;
    color: var(--text);
    text-transform: uppercase;
    caret-color: var(--purple);
    outline: none;
    padding: 0;
    padding-top: 6px;
    letter-spacing: 0;
}

.cw-cell:hover:not(.blocked) { background: #f3f0ff; border-color: var(--purple-mid); }
.cw-cell.selected {
    background: linear-gradient(135deg, #ede9fe, #ddd6fe);
    border-color: var(--purple);
    box-shadow: 0 0 0 3px rgba(124,58,237,.25);
    z-index: 2;
}
.cw-cell.word-hl  { background: #f5f3ff; border-color: #c4b5fd; }
.cw-cell.correct  { background: linear-gradient(135deg,#dcfce7,#bbf7d0); border-color: #4ade80; }
.cw-cell.correct input { color: var(--green); }
.cw-cell.wrong    { background: linear-gradient(135deg,#fee2e2,#fecaca); border-color: #f87171; }
.cw-cell.wrong input { color: var(--red); }
.cw-cell.revealed { background: linear-gradient(135deg,#fef9c3,#fef08a); border-color: #facc15; }
.cw-cell.revealed input { color: #92400e; }

/* ---- TOOLBAR ---- */
.cw-toolbar {
    display: flex; gap: 10px; flex-wrap: wrap;
    justify-content: center;
    margin-top: 16px;
    width: 100%;
}
.cw-toolbar button {
    padding: 12px 22px;
    border: none; border-radius: 999px;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-weight: 900; font-size: 15px;
    min-width: 148px;
    cursor: pointer;
    color: #fff;
    box-shadow: 0 8px 20px rgba(15, 23, 42, .16);
    transition: transform .15s ease, filter .15s ease;
    letter-spacing: .2px;
}
.cw-toolbar button:hover { transform: translateY(-2px); filter: brightness(1.06); }
.btn-check      { background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%); }
.btn-reveal     { background: linear-gradient(180deg, #fbbf24 0%, #f59e0b 100%); color: #1c1917; }
.btn-reveal-all { background: linear-gradient(180deg, #f9a8d4 0%, #ec4899 100%); }
.btn-clear      { background: linear-gradient(180deg, #94a3b8 0%, #64748b 100%); }

/* ---- RESULT BANNER ---- */
#cw-result {
    margin-top: 10px;
    font-size: 16px; font-weight: 800;
    min-height: 24px; text-align: center;
    font-family: 'Fredoka', 'Nunito', sans-serif;
}

/* ---- CLUE PANELS ---- */
.clue-panel {
    background: #fff;
    border-radius: 20px;
    border: 2px solid #ede9fe;
    padding: 16px 18px;
    box-shadow: 0 8px 24px rgba(124, 58, 237, .09);
    width: 100%;
}
.clue-panel h3 {
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: 17px;
    color: var(--purple-dark);
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 2px solid #ede9fe;
    display: flex; align-items: center; gap: 6px;
}
.clue-list { list-style: none; }
.clue-list li {
    padding: 6px 4px;
    font-size: 14px;
    border-bottom: 1px solid #f3f4f6;
    cursor: pointer;
    transition: background .12s, color .12s;
    display: flex; gap: 8px;
    line-height: 1.45;
    border-radius: 8px;
}
.clue-list li:last-child { border-bottom: none; }
.clue-list li:hover { background: #f5f3ff; color: var(--purple); }
.clue-list li.active {
    background: #ede9fe;
    color: var(--purple-dark);
    font-weight: 900;
    border-radius: 8px;
}
.clue-num {
    font-weight: 900; color: var(--purple);
    min-width: 22px; text-align: right;
    font-size: 13px;
    flex-shrink: 0;
}

/* ---- CLUE TOOLTIP (shown on number click) ---- */
#cw-clue-tooltip {
    position: fixed;
    z-index: 9999;
    background: #fff;
    border: 2.5px solid var(--purple);
    border-radius: 16px;
    padding: 10px 14px;
    box-shadow: 0 8px 28px rgba(124,58,237,.22);
    font-family: 'Nunito', 'Fredoka', sans-serif;
    font-size: 14px;
    font-weight: 700;
    color: var(--text);
    max-width: 240px;
    line-height: 1.4;
    pointer-events: none;
    display: none;
    transition: opacity .15s;
}
#cw-clue-tooltip .tt-dir {
    font-size: 11px;
    font-weight: 900;
    color: var(--purple);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 4px;
    display: block;
}
#cw-clue-tooltip .tt-num {
    color: var(--purple-dark);
}

/* ---- PROGRESS BAR ---- */
.cw-progress-wrap {
    width: 100%;
    margin-top: 18px;
}
.cw-progress-label {
    font-size: 14px; color: #5b516f;
    margin-bottom: 6px; text-align: center;
    font-weight: 800;
    font-family: 'Nunito', sans-serif;
}
.cw-progress-bar-bg {
    background: #ede9fe; border-radius: 999px; height: 12px; overflow: hidden;
}
.cw-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #8b5cf6, var(--purple-mid));
    border-radius: 50px;
    transition: width .4s;
    width: 0%;
}

/* ---- Completed screen ---- */
.completed-screen {
    display: none;
    text-align: center;
    max-width: 600px;
    margin: 0 auto;
    padding: 40px 20px 24px;
}
.completed-screen.active { display: block; }

.cw-card.is-completed #cwGameLayout { display: none; }
.cw-card.is-completed {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 420px;
}

.completed-icon { font-size: 80px; margin-bottom: 20px; }
.completed-title {
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: 36px; font-weight: 700;
    color: #6d28d9; margin: 0 0 16px; line-height: 1.2;
}
.completed-text { font-size: 16px; color: #5b516f; line-height: 1.6; margin: 0 0 32px; }
.completed-button {
    display: inline-block; padding: 12px 28px;
    border: none; border-radius: 999px;
    background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%);
    color: #fff; font-weight: 800; font-size: 17px;
    cursor: pointer;
    box-shadow: 0 10px 24px rgba(0,0,0,.15);
    transition: transform .18s ease, filter .18s ease;
    font-family: 'Fredoka','Nunito',sans-serif;
}
.completed-button:hover { transform: scale(1.05); filter: brightness(1.07); }

@media (max-width: 820px) {
    .cw-layout { flex-direction: column; align-items: center; }
    .cw-clues-col { flex-direction: row; flex-wrap: wrap; width: 100%; max-height: none; position: static; overflow-y: visible; }
    .clue-panel { width: min(100%, 360px); }
}
@media (max-width: 640px) {
    :root { --cell-size: <?= (int)$cellSizes['mobile'] ?>px; }
    .cw-clues-col { flex-direction: column; align-items: stretch; }
    .clue-panel { width: 100%; }
    .clue-list li { font-size: 13px; }
    .cw-toolbar { flex-wrap: wrap; }
    .cw-toolbar button { width: 100%; min-width: 0; max-width: 300px; }
}

@media (max-height: 900px) and (min-width: 641px) {
    :root { --cell-size: <?= (int)$cellSizes['compact'] ?>px; }
    .cw-toolbar button { padding: 10px 18px; min-width: 136px; font-size: 14px; }
}

/* Ensure any images inside the crossword viewer scale responsively and never overflow */
.cw-viewer img {
    max-width: 100%;
    height: auto;
    display: block;
    margin: 0 auto;
}
</style>

<div class="cw-viewer" id="cwViewer">
    <?php if ($showGridLimitWarning): ?>
    <div style="background:#fef3c7;color:#92400e;padding:10px 18px;border-radius:12px;margin-bottom:18px;font-weight:700;font-size:15px;text-align:center;">
        This crossword is too large (max <?= $MAX_GRID_SIZE ?>x<?= $MAX_GRID_SIZE ?>). Only the first <?= $MAX_GRID_SIZE ?> rows and columns are shown. Please reduce the number or length of words for best display.
    </div>
    <?php endif; ?>
    <div class="cw-card" id="cwGame">
        <div class="cw-layout" id="cwGameLayout">
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
                                    <span class="num" data-num="<?= $cell['numLabel'] ?>" onclick="cwNumClick(event, <?= $r ?>, <?= $c ?>)"><?= $cell["numLabel"] ?></span>
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
        <div id="cw-completed" class="completed-screen">
            <div class="completed-icon">✅</div>
            <h2 class="completed-title" id="cw-completed-title"></h2>
            <p class="completed-text" id="cw-completed-text"></p>
            <p class="completed-text" id="cw-score-text" style="font-weight:700;font-size:18px;color:#6d28d9;"></p>
            <button type="button" class="completed-button" onclick="restartCrossword()">Restart</button>
        </div>
    </div>
</div>

<!-- Clue tooltip -->
<div id="cw-clue-tooltip"><span class="tt-dir" id="tt-dir"></span><span id="tt-body"></span></div>

<script>
/* ===== DATA from PHP ===== */
const WORDS = <?= $jsWords ?>;
const WORD_NUMBERS = <?= $jsWordNumbers ?>;  // idx→number
const GRID_ROWS = <?= $gridRows ?>;
const GRID_COLS = <?= $gridCols ?>;
const TOTAL_LETTERS = <?= $activeCellCount ?>;
const CW_RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const CW_ACTIVITY_ID = <?= json_encode((string) ($row['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;

/* ===== STATE ===== */
let selectedCell = null;     // {r, c}
let currentWordIdx = -1;     // which word is active
let currentDir = 'across';   // 'across' | 'down'
const assistedCells = new Set();
let cwScorePersisted = false;

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

/* ===== CLUE TOOLTIP ===== */
const cwTooltip    = document.getElementById('cw-clue-tooltip');
const ttDir        = document.getElementById('tt-dir');
const ttBody       = document.getElementById('tt-body');
let tooltipTimer   = null;

function cwNumClick(event, r, c) {
    event.stopPropagation();

    // Collect all words that start at this cell
    const el = cellEl(r, c);
    if (!el) return;
    const idxsRaw = el.dataset.wordIdxs || '';
    const idxs = idxsRaw.split(',').map(Number).filter(n => !isNaN(n));

    // Only show words whose start cell is r,c
    const starters = idxs.filter(i => WORDS[i] && WORDS[i].row === r && WORDS[i].col === c);
    if (!starters.length) return;

    const lines = starters.map(i => {
        const w = WORDS[i];
        const num = WORD_NUMBERS[i] || '';
        const dir = w.direction === 'across' ? '→ Across' : '↓ Down';
        const clueText = w.clue || '—';
        return `<span class="tt-dir">${num}. ${dir}</span>${clueText}`;
    });

    ttDir.innerHTML  = '';
    ttBody.innerHTML = lines.join('<hr style="border:none;border-top:1px solid #ede9fe;margin:6px 0">');

    // Position near the number, but keep inside viewport
    const rect = event.target.getBoundingClientRect();
    cwTooltip.style.display = 'block';
    cwTooltip.style.opacity = '0';

    // measure then place
    requestAnimationFrame(() => {
        const tw = cwTooltip.offsetWidth;
        const th = cwTooltip.offsetHeight;
        let left = rect.left + window.scrollX;
        let top  = rect.bottom + window.scrollY + 6;

        if (left + tw > window.innerWidth - 12) left = window.innerWidth - tw - 12;
        if (top + th > window.innerHeight + window.scrollY - 12) top = rect.top + window.scrollY - th - 8;

        cwTooltip.style.left = left + 'px';
        cwTooltip.style.top  = top + 'px';
        cwTooltip.style.opacity = '1';
    });

    clearTimeout(tooltipTimer);
    tooltipTimer = setTimeout(cwHideTooltip, 3200);
}

function cwHideTooltip() {
    if (cwTooltip) { cwTooltip.style.display = 'none'; }
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('#cw-clue-tooltip') && !e.target.classList.contains('num')) {
        cwHideTooltip();
    }
});

/* ===== CLICK CELL ===== */
document.getElementById('cwGrid').addEventListener('click', function(e) {
    if (e.target.classList.contains('num')) return; // handled by cwNumClick
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

function persistScoreSilently(targetUrl) {
    if (!targetUrl) {
        return Promise.resolve(false);
    }

    return fetch(targetUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
    }).then(function (response) {
        return !!(response && response.ok);
    }).catch(function () {
        return false;
    });
}

function navigateToReturn(targetUrl) {
    if (!targetUrl) {
        return;
    }

    try {
        if (window.top && window.top !== window.self) {
            window.top.location.href = targetUrl;
            return;
        }
    } catch (e) {}

    window.location.href = targetUrl;
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

async function checkComplete() {
    const cells = document.querySelectorAll('.cw-cell:not(.blocked)');
    let allDone = true;
    cells.forEach(cell => {
        const inp = cell.querySelector('input');
        if (!inp || inp.value.trim() === '' || !cell.classList.contains('correct')) allDone = false;
    });
    if (allDone) {
        const gameCard = document.getElementById('cwGame');
        const completed = document.getElementById('cw-completed');
        const scoreEl = document.getElementById('cw-score-text');
        const earned = Math.max(0, TOTAL_LETTERS - assistedCells.size);
        const percent = TOTAL_LETTERS > 0 ? Math.round((earned / TOTAL_LETTERS) * 100) : 0;
        const errors = Math.max(0, TOTAL_LETTERS - earned);
        if (gameCard) gameCard.classList.add('is-completed');
        if (completed) {
            completed.classList.add('active');
            setTimeout(() => completed.scrollIntoView({ behavior: 'smooth', block: 'center' }), 100);
        }
        if (scoreEl) {
            scoreEl.textContent = 'Score: ' + earned + ' / ' + TOTAL_LETTERS + ' (' + percent + '%)';
        }

        if (!cwScorePersisted && CW_ACTIVITY_ID && CW_RETURN_TO) {
            const joiner = CW_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
            const saveUrl = CW_RETURN_TO
                + joiner + 'activity_percent=' + percent
                + '&activity_errors=' + errors
                + '&activity_total=' + TOTAL_LETTERS
                + '&activity_id=' + encodeURIComponent(CW_ACTIVITY_ID)
                + '&activity_type=crossword';

            const ok = await persistScoreSilently(saveUrl);
            if (ok) {
                cwScorePersisted = true;
            } else {
                navigateToReturn(saveUrl);
            }
        }
    }
}

function restartCrossword() {
    clearAll();
    const gameCard = document.getElementById('cwGame');
    const completed = document.getElementById('cw-completed');
    if (gameCard) gameCard.classList.remove('is-completed');
    if (completed) completed.classList.remove('active');
}

/* ===== REVEAL ===== */
function revealSelected() {
    if (currentWordIdx === -1) return;
    wordCells(currentWordIdx).forEach(({ el, letter }) => {
        assistedCells.add(el.dataset.r + ',' + el.dataset.c);
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
        assistedCells.add(cell.dataset.r + ',' + cell.dataset.c);
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
    assistedCells.clear();
    cwScorePersisted = false;
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
    const completed = document.getElementById('cw-completed');
    const gameCard = document.getElementById('cwGame');
    if (completed) completed.classList.remove('active');
    if (gameCard) gameCard.classList.remove('is-completed');
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
