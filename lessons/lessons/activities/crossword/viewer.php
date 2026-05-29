<?php
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../core/_activity_viewer_template.php";

$unit = isset($_GET['unit']) ? trim((string)$_GET['unit']) : "";
$activityId = isset($_GET['id']) ? trim((string)$_GET['id']) : "";
$returnTo = isset($_GET['return_to']) ? trim((string)$_GET['return_to']) : "";

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

$activityId = isset($row['id']) ? (string)$row['id'] : $activityId;

$raw = json_decode($row["data"] ?? "{}", true);
if (!is_array($raw)) $raw = [];

$title = trim((string)($raw["title"] ?? "Crossword Puzzle"));
if ($title === "") $title = "Crossword Puzzle";

$rawWords = is_array($raw["words"] ?? null) ? $raw["words"] : [];
$sourceWords = [];

foreach ($rawWords as $w) {
    if (!is_array($w)) continue;

    $word = strtoupper(trim((string)($w["word"] ?? "")));
    $word = preg_replace('/[^A-Z0-9]/', '', $word);

    if ($word === "" || strlen($word) < 2) continue;

    $image = "";
    foreach (["image", "clue_image", "img", "picture"] as $key) {
        if (isset($w[$key]) && trim((string)$w[$key]) !== "") {
            $image = trim((string)$w[$key]);
            break;
        }
    }

    $sourceWords[] = [
        "word" => $word,
        "clue" => trim((string)($w["clue"] ?? "")),
        "image" => $image,
    ];
}

if (empty($sourceWords)) {
    die("Crossword has no valid words. Please add at least one word with 2+ letters.");
}

function cw_key(int $r, int $c): string {
    return $r . "," . $c;
}

function cw_can_place_word(array $grid, string $word, int $row, int $col, string $direction): array {
    $len = strlen($word);
    $overlaps = 0;

    $dr = $direction === "across" ? 0 : 1;
    $dc = $direction === "across" ? 1 : 0;

    if (isset($grid[cw_key($row - $dr, $col - $dc)])) return [false, 0];
    if (isset($grid[cw_key($row + $dr * $len, $col + $dc * $len)])) return [false, 0];

    for ($i = 0; $i < $len; $i++) {
        $r = $row + $dr * $i;
        $c = $col + $dc * $i;
        $key = cw_key($r, $c);
        $ch = $word[$i];

        if (isset($grid[$key])) {
            if (($grid[$key]["letter"] ?? "") !== $ch) return [false, 0];
            $overlaps++;
            continue;
        }

        if ($direction === "across") {
            if (isset($grid[cw_key($r - 1, $c)]) || isset($grid[cw_key($r + 1, $c)])) return [false, 0];
        } else {
            if (isset($grid[cw_key($r, $c - 1)]) || isset($grid[cw_key($r, $c + 1)])) return [false, 0];
        }
    }

    return [true, $overlaps];
}

function cw_place_word(array &$grid, array $placed): void {
    $word = $placed["word"];
    $len = strlen($word);
    $row = (int)$placed["row"];
    $col = (int)$placed["col"];
    $dir = $placed["direction"];

    for ($i = 0; $i < $len; $i++) {
        $r = $dir === "across" ? $row : $row + $i;
        $c = $dir === "across" ? $col + $i : $col;
        $key = cw_key($r, $c);

        if (!isset($grid[$key])) {
            $grid[$key] = ["letter" => $word[$i], "wordIdxs" => []];
        }

        $grid[$key]["wordIdxs"][] = $placed["idx"];
    }
}

function cw_generate_layout(array $words): array {
    $indexed = [];
    foreach ($words as $idx => $w) {
        $indexed[] = ["idx" => $idx, "word" => $w["word"]];
    }
    usort($indexed, function ($a, $b) {
        $lenCmp = strlen($b["word"]) <=> strlen($a["word"]);
        return $lenCmp !== 0 ? $lenCmp : ($a["idx"] <=> $b["idx"]);
    });
    $grid = [];
    $placed = [];
    $first = $indexed[0];
    $firstPlaced = [
        "idx" => $first["idx"],
        "word" => $first["word"],
        "row" => 0,
        "col" => 0,
        "direction" => "across",
    ];
    $placed[] = $firstPlaced;
    cw_place_word($grid, $firstPlaced);
    for ($p = 1; $p < count($indexed); $p++) {
        $candidate = $indexed[$p];
        $word = $candidate["word"];
        $len = strlen($word);
        $best = null;
        $bestScore = -1000000;
        foreach ($grid as $key => $cell) {
            [$r0, $c0] = array_map("intval", explode(",", $key));
            $gridCh = $cell["letter"];
            for ($i = 0; $i < $len; $i++) {
                if ($word[$i] !== $gridCh) continue;
                foreach (["across", "down"] as $dir) {
                    $startRow = $dir === "across" ? $r0 : $r0 - $i;
                    $startCol = $dir === "across" ? $c0 - $i : $c0;
                    [$ok, $overlaps] = cw_can_place_word($grid, $word, $startRow, $startCol, $dir);
                    if (!$ok || $overlaps < 1) continue;
                    // --- PARCHE LOCALIZADO SOLO PARA ACTIVIDAD 550 ---
                    if (isset($_GET['id']) && $_GET['id'] === '550') {
                        // Calcula área ocupada igual que en el editor JS
                        $minR = $startRow;
                        $maxR = $dir === 'down' ? $startRow + $len - 1 : $startRow;
                        $minC = $startCol;
                        $maxC = $dir === 'across' ? $startCol + $len - 1 : $startCol;
                        $areaPenalty = ($maxR - $minR + 1) * ($maxC - $minC + 1);
                        $score = ($overlaps * 1000) - $areaPenalty;
                    } else {
                        $score = ($overlaps * 1000) - abs($startRow) - abs($startCol);
                    }
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = [
                            "idx" => $candidate["idx"],
                            "word" => $word,
                            "row" => $startRow,
                            "col" => $startCol,
                            "direction" => $dir,
                        ];
                    }
                }
            }
        }
        if ($best === null) {
            // Keep crossword as a connected graph: skip words that cannot intersect.
            continue;
        }
        $placed[] = $best;
        cw_place_word($grid, $best);
    }
    $minRow = 0;
    $minCol = 0;
    $firstCell = true;
    foreach ($grid as $key => $_cell) {
        [$r, $c] = array_map("intval", explode(",", $key));
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
            $pw["row"] -= $minRow;
            $pw["col"] -= $minCol;
        }
        unset($pw);
    }
    return $placed;
    // --- DEBUG LOG SOLO PARA ACTIVIDAD 550 ---
    if ($activityId === "550") {
        echo '<div style="background:#222;color:#fff;padding:12px 18px;margin:18px 0 0 0;z-index:9999;position:relative;font-size:13px;max-width:900px;overflow-x:auto;">';
        echo '<b>DEBUG: sourceWords</b><br><pre>' . htmlspecialchars(json_encode($sourceWords, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '</pre>';
        $debugPlaced = cw_generate_layout($sourceWords);
        echo '<b>DEBUG: placedWords</b><br><pre>' . htmlspecialchars(json_encode($debugPlaced, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '</pre>';
        echo '</div>';
    }

    $placedWords = cw_generate_layout($sourceWords);

$wordsByIdx = [];
foreach ($sourceWords as $idx => $w) {
    $wordsByIdx[$idx] = $w;
}

$placed = [];

foreach ($placedWords as $pw) {
    $idx = (int)$pw["idx"];

    if (!isset($wordsByIdx[$idx])) continue;

    $placed[] = [
        "idx" => $idx,
        "word" => $wordsByIdx[$idx]["word"],
        "clue" => $wordsByIdx[$idx]["clue"],
        "image" => $wordsByIdx[$idx]["image"],
        "direction" => $pw["direction"],
        "row" => (int)$pw["row"],
        "col" => (int)$pw["col"],
    ];
}

$maxRow = 0;
$maxCol = 0;

foreach ($placed as $w) {
    if ($w["direction"] === "across") {
        $maxRow = max($maxRow, $w["row"]);
        $maxCol = max($maxCol, $w["col"] + strlen($w["word"]) - 1);
    } else {
        $maxRow = max($maxRow, $w["row"] + strlen($w["word"]) - 1);
        $maxCol = max($maxCol, $w["col"]);
    }
}

$MAX_GRID_SIZE = 18;

$gridRows = min($maxRow + 1, $MAX_GRID_SIZE);
$gridCols = min($maxCol + 1, $MAX_GRID_SIZE);

$cellMap = [];

for ($r = 0; $r < $gridRows; $r++) {
    for ($c = 0; $c < $gridCols; $c++) {
        $cellMap[$r][$c] = [
            "active" => false,
            "letter" => "",
            "wordIdxs" => [],
            "numLabel" => 0,
        ];
    }
}

foreach ($placed as $idx => $w) {
    $len = strlen($w["word"]);

    for ($i = 0; $i < $len; $i++) {
        $r = $w["direction"] === "across" ? $w["row"] : $w["row"] + $i;
        $c = $w["direction"] === "across" ? $w["col"] + $i : $w["col"];

        if ($r < $gridRows && $c < $gridCols) {
            $cellMap[$r][$c]["active"] = true;
            $cellMap[$r][$c]["letter"] = $w["word"][$i];
            $cellMap[$r][$c]["wordIdxs"][] = $idx;
        }
    }
}

$startNumberByCell = [];
$nextNum = 1;

for ($r = 0; $r < $gridRows; $r++) {
    for ($c = 0; $c < $gridCols; $c++) {

        if (!$cellMap[$r][$c]["active"]) continue;

        $startsAcross =
            ($c === 0 || !$cellMap[$r][$c - 1]["active"]) &&
            ($c + 1 < $gridCols && $cellMap[$r][$c + 1]["active"]);

        $startsDown =
            ($r === 0 || !$cellMap[$r - 1][$c]["active"]) &&
            ($r + 1 < $gridRows && $cellMap[$r + 1][$c]["active"]);

        if ($startsAcross || $startsDown) {
            $startNumberByCell[$r . "," . $c] = $nextNum++;
        }
    }
}

$wordNumber = [];

foreach ($placed as $idx => $w) {

    $key = $w["row"] . "," . $w["col"];

    $wordNumber[$idx] =
        $startNumberByCell[$key] ?? ($idx + 1);

    if (isset($cellMap[$w["row"]][$w["col"]])) {
        $cellMap[$w["row"]][$w["col"]]["numLabel"] =
            $wordNumber[$idx];
    }

    $placed[$idx]["num"] = $wordNumber[$idx];
}

$gridGap = ($gridCols >= 15 || $gridRows >= 15) ? 3 : 4;

$desktopGridMaxW = 860;
$desktopGridMaxH = 640;

$cellByDesktopW = (int) floor(($desktopGridMaxW - (($gridCols - 1) * $gridGap)) / max(1, $gridCols));
$cellByDesktopH = (int) floor(($desktopGridMaxH - (($gridRows - 1) * $gridGap)) / max(1, $gridRows));

$cellSize = max(28, min(62, $cellByDesktopW, $cellByDesktopH));

$mobileGridMaxW = 360;
$mobileGridMaxH = 420;

$cellByMobileW = (int) floor(($mobileGridMaxW - (($gridCols - 1) * $gridGap)) / max(1, $gridCols));
$cellByMobileH = (int) floor(($mobileGridMaxH - (($gridRows - 1) * $gridGap)) / max(1, $gridRows));

$mobileCellSize = max(22, min($cellSize, $cellByMobileW, $cellByMobileH));

ob_start();
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>

:root{
    --cw-orange:#F97316;
    --cw-orange-dark:#C2580A;
    --cw-orange-soft:#FFF0E6;

    --cw-purple:#7F77DD;
    --cw-purple-dark:#534AB7;
    --cw-purple-soft:#EEEDFE;

    --cw-muted:#9B94BE;
    --cw-border:#F0EEF8;
    --cw-track:#F4F2FD;

    --cw-green:#16a34a;
    --cw-red:#dc2626;

    --cw-cell:<?= (int)$cellSize ?>px;
    --cw-gap:<?= (int)$gridGap ?>px;
}

html,
body{
    width:100%;
    min-height:100%;
}

body{
    margin:0!important;
    padding:0!important;
    background:#fff!important;
    font-family:'Nunito','Segoe UI',sans-serif!important;
}

.activity-wrapper{
    max-width:100%!important;
    margin:0!important;
    padding:0!important;
    min-height:0;
    display:flex!important;
    flex-direction:column!important;
    background:transparent!important;
}

.top-row,
.activity-header,
.activity-title,
.activity-subtitle{
    display:none!important;
}

.viewer-content{
    flex:1!important;
    display:flex!important;
    flex-direction:column!important;
    min-height:0!important;
    padding:0!important;
    margin:0!important;
    background:transparent!important;
    border:none!important;
    box-shadow:none!important;
    border-radius:0!important;
}

.cw-page{
    width:100%;
    flex:1;
    min-height:0;
    overflow-y:auto;
    padding:clamp(14px,2.5vw,34px);
    display:flex;
    align-items:flex-start;
    justify-content:center;
    background:#fff;
    box-sizing:border-box;
}

.cw-app{
    width:min(1180px,100%);
    margin:0 auto;
}

.cw-hero{
    text-align:center;
    margin-bottom:clamp(14px,2vw,22px);
}

.cw-kicker{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:7px 14px;
    border-radius:999px;
    background:var(--cw-orange-soft);
    border:1px solid #FCDDBF;
    color:var(--cw-orange-dark);
    font-size:12px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    margin-bottom:10px;
}

.cw-hero h1{
    font-family:'Fredoka',sans-serif;
    font-size:clamp(30px,5.5vw,58px);
    font-weight:700;
    color:var(--cw-orange);
    margin:0;
    line-height:1.03;
}

.cw-hero p{
    font-size:clamp(13px,1.8vw,17px);
    font-weight:800;
    color:var(--cw-muted);
    margin:8px 0 0;
}

.cw-stage{
    background:#fff;
    border:1px solid var(--cw-border);
    border-radius:34px;
    padding:clamp(16px,2.6vw,26px);
    box-shadow:0 8px 40px rgba(127,119,221,.13);
    box-sizing:border-box;
}

.cw-layout{
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(240px,300px);
    gap:clamp(16px,2.4vw,24px);
    align-items:start;
}

.cw-grid-card,
.cw-clue-card{
    background:#fff;
    border:1px solid #EDE9FA;
    border-radius:30px;
    box-shadow:0 8px 24px rgba(127,119,221,.09);
    padding:clamp(14px,2vw,20px);
}.cw-status{
    display:grid;
    grid-template-columns:1fr auto;
    gap:10px;
    align-items:center;
    margin-bottom:14px;
}

.cw-track{
    height:12px;
    background:var(--cw-track);
    border:1px solid #E4E1F8;
    border-radius:999px;
    overflow:hidden;
}

.cw-fill{
    height:100%;
    width:0;
    border-radius:999px;
    background:linear-gradient(90deg,var(--cw-orange),var(--cw-purple));
    transition:width .35s ease;
}

.cw-count{
    min-width:74px;
    text-align:center;
    padding:7px 11px;
    border-radius:999px;
    background:var(--cw-purple);
    color:#fff;
    font-size:12px;
    font-weight:900;
}

.cw-grid-wrap{
    width:100%;
    overflow:auto;
    padding:6px 2px 10px;
    display:flex;
    justify-content:center;
}

.cw-grid{
    display:grid;
    grid-template-columns:repeat(<?= $gridCols ?>,var(--cw-cell));
    grid-template-rows:repeat(<?= $gridRows ?>,var(--cw-cell));
    gap:var(--cw-gap);
}

.cw-cell{
    width:var(--cw-cell);
    height:var(--cw-cell);
    position:relative;
    background:#fff;
    border:1.5px solid #DCD8F8;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 4px 12px rgba(127,119,221,.10);
    transition:.15s;
}

.cw-cell.blocked{
    background:transparent !important;
    border:none !important;
    box-shadow:none !important;
    pointer-events:none;
}

.cw-cell .num{
    position:absolute;
    top:3px;
    left:5px;
    color:var(--cw-purple);
    font-size:10px;
    font-weight:900;
    line-height:1;
}

.cw-cell input{
    width:100%;
    height:100%;
    border:0;
    background:transparent;
    text-align:center;
    text-transform:uppercase;
    outline:0;
    color:var(--cw-purple-dark);
    font-family:'Fredoka','Nunito',sans-serif;
    font-size:calc(var(--cw-cell) * .44);
    font-weight:700;
    padding-top:5px;
}

.cw-cell.selected,
.cw-cell.word-hl{
    background:var(--cw-purple-soft);
    border-color:var(--cw-purple);
}

.cw-cell.correct{
    background:#f0fdf4;
    border-color:#86efac;
}

.cw-cell.correct input{
    color:var(--cw-green);
}

.cw-cell.wrong{
    background:#fff0e6;
    border-color:#fdba74;
}

.cw-cell.wrong input{
    color:var(--cw-orange-dark);
}

.cw-cell.revealed{
    background:#FFF0E6;
    border-color:#F97316;
}

.cw-cell.revealed input{
    color:var(--cw-orange-dark);
}

.btn-row{
    display:flex;
    gap:10px;
    justify-content:center;
    margin-top:16px;
    padding-top:16px;
    border-top:1px solid var(--cw-border);
    flex-wrap:wrap;
}

.btn-purple{
    background:#7F77DD;
    color:#fff;
    border:none;
    border-radius:999px;
    padding:13px clamp(20px,3vw,32px);
    font-family:'Nunito',sans-serif;
    font-weight:900;
    font-size:clamp(13px,1.8vw,15px);
    cursor:pointer;
    min-width:clamp(104px,16vw,146px);
    box-shadow:0 6px 18px rgba(127,119,221,.18);
    transition:filter .15s,transform .15s;
}

.btn-orange{
    background:#F97316;
    color:#fff;
    border:none;
    border-radius:999px;
    padding:13px clamp(20px,3vw,32px);
    font-family:'Nunito',sans-serif;
    font-weight:900;
    font-size:clamp(13px,1.8vw,15px);
    cursor:pointer;
    min-width:clamp(104px,16vw,146px);
    box-shadow:0 6px 18px rgba(249,115,22,.22);
    transition:filter .15s,transform .15s;
}

.btn-purple:hover,.btn-orange:hover{
    filter:brightness(1.07);
    transform:translateY(-1px);
}


.cw-clue-title{
    font-family:'Fredoka',sans-serif;
    color:var(--cw-orange);
    font-size:clamp(22px,3vw,30px);
    line-height:1;
    margin:0 0 6px;
}

.cw-clue-sub{
    color:var(--cw-muted);
    font-weight:800;
    font-size:13px;
    margin:0 0 14px;
}

.cw-visual-list{
    display:grid;
    grid-template-columns:1fr;
    gap:8px;
    max-height:620px;
    overflow:auto;
    padding-right:2px;
}

.cw-visual-clue{
    width:100%;
    border:1px solid #EDE9FA;
    border-radius:16px;
    background:#fff;
    padding:8px;
    display:grid;
    grid-template-columns:64px minmax(0,1fr);
    gap:8px;
    align-items:center;
    text-align:left;
    cursor:pointer;
    box-shadow:0 5px 14px rgba(127,119,221,.08);
    transition:.15s;
}

.cw-visual-clue:hover,
.cw-visual-clue.active{
    background:#FAFAFE;
    border-color:var(--cw-purple);
    transform:translateY(-1px);
}

.cw-thumb{
    width:64px;
    height:56px;
    border-radius:12px;
    background:#FAFAFD;
    border:1px solid #EDE9FA;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    color:#D5D0F0;
    font-size:28px;
    font-weight:900;
}

.cw-thumb img{
    width:100%;
    height:100%;
    object-fit:contain;
    display:block;
}

.cw-clue-meta{
    min-width:0;
}

.cw-clue-number{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:30px;
    height:24px;
    border-radius:999px;
    background:var(--cw-purple-soft);
    color:var(--cw-purple-dark);
    font-size:12px;
    font-weight:900;
    margin-bottom:6px;
}

.cw-clue-dir{
    color:var(--cw-muted);
    font-size:11px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.08em;
}

.cw-clue-text{
    margin-top:4px;
    color:#111111;
    font-size:11px;
    font-weight:800;
    line-height:1.35;
    white-space:normal;
    overflow:visible;
}

.cw-completed{
    display:none;
    align-items:stretch;
    flex-direction:column;
    padding:0;
}

.cw-completed.active{
    display:flex;
}

.cw-completed-icon{
    width:72px;
    height:72px;
    border-radius:999px;
    background:var(--cw-purple-soft);
    color:var(--cw-purple);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:34px;
    font-weight:900;
    margin-bottom:16px;
}

.cw-completed-title{
    font-family:'Fredoka',sans-serif;
    font-size:clamp(30px,5.5vw,58px);
    color:var(--cw-orange);
    margin:0;
    line-height:1.03;
}

.cw-completed-text{
    color:var(--cw-muted);
    font-size:clamp(13px,1.8vw,17px);
    font-weight:800;
    line-height:1.5;
    margin:10px 0 18px;
}

.cw-game.hide{
    display:none;
}

@media(max-width:860px){

    :root{
        --cw-cell:<?= (int)$mobileCellSize ?>px;
    }

    .cw-page{
        padding:12px;
    }

    .cw-stage{
        border-radius:26px;
        padding:14px;
    }

    .cw-layout{
        grid-template-columns:1fr;
    }

    .cw-clue-card{
        order:-1;
    }

    .cw-visual-list{
        grid-template-columns:1fr 1fr;
        max-height:none;
    }

    .cw-visual-clue{
        grid-template-columns:56px 1fr;
    }

    .cw-thumb{
        width:56px;
        height:50px;
    }

    .btn-row{
        flex-direction:column;
        align-items:stretch;
    }

    .btn-purple,.btn-orange{
        width:100%;
    }
}

@media(max-width:560px){

    .cw-visual-list{
        grid-template-columns:1fr;
    }

    .cw-cell{
        border-radius:9px;
    }

    .cw-cell .num{
        font-size:9px;
        top:2px;
        left:3px;
    }
}

.cw-dir-tabs{
    display:flex;
    gap:8px;
    margin-bottom:12px;
}

.cw-tab{
    flex:1;
    padding:8px 12px;
    border-radius:999px;
    border:1.5px solid var(--cw-purple);
    background:transparent;
    color:var(--cw-purple);
    font-family:'Nunito',sans-serif;
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.06em;
    cursor:pointer;
    transition:.15s;
}

.cw-tab.active{
    background:var(--cw-purple);
    color:#fff;
}

.cw-cell.selected{
    border-color:var(--cw-orange);
    border-width:2px;
}

/* ── Unified unscored completed screen ── */
.af-unscored__card{background:#fff;border:1.5px solid #EDE9FA;border-radius:14px;padding:28px 32px;width:100%;max-width:100%;box-sizing:border-box;font-family:'Nunito','Segoe UI',sans-serif;}
.af-unscored__prog-label{font-size:11px;color:#9B8FCC;font-weight:700;letter-spacing:.06em;text-align:center;margin-bottom:6px;text-transform:uppercase;}
.af-unscored__prog-track{background:#EDE9FA;border-radius:99px;height:9px;overflow:hidden;margin-bottom:4px;}
.af-unscored__prog-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#F97316,#7F77DD);transition:width .4s ease;}
.af-unscored__prog-nums{display:flex;justify-content:space-between;font-size:11px;color:#9B8FCC;margin-bottom:16px;}
.af-unscored__prog-nums strong{color:#7F77DD;}
.af-unscored__icon{width:48px;height:48px;border-radius:50%;background:#EDE9FA;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;}
.af-unscored__title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:20px;font-weight:600;color:#7F77DD;text-align:center;margin:0 0 3px;}
.af-unscored__sub{font-size:13px;color:#9B8FCC;font-weight:600;text-align:center;margin:0 0 16px;}
.af-unscored__chips{display:grid;gap:8px;margin-bottom:16px;}
.af-unscored__chips--2{grid-template-columns:1fr 1fr;}
.af-unscored__chips--3{grid-template-columns:1fr 1fr 1fr;}
.af-unscored__chip{background:#F9F8FF;border:1.5px solid #EDE9FA;border-radius:12px;padding:10px 6px;text-align:center;}
.af-unscored__chip-val{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:24px;color:#7F77DD;line-height:1;}
.af-unscored__chip-val--orange{color:#F97316;}
.af-unscored__chip-lbl{font-size:10px;color:#9B8FCC;font-weight:700;letter-spacing:.05em;margin-top:2px;text-transform:uppercase;}
.af-unscored__banner{border-radius:12px;padding:9px 14px;display:flex;align-items:center;gap:10px;margin-bottom:16px;}
.af-unscored__banner--orange{background:#FFF0E6;}
.af-unscored__banner--purple{background:#F5F3FF;}
.af-unscored__banner--green{background:#F0FDF4;}
.af-unscored__banner-icon{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.af-unscored__banner-icon--orange{background:#F97316;}
.af-unscored__banner-icon--purple{background:#7F77DD;}
.af-unscored__banner-icon--green{background:#22c55e;}
.af-unscored__banner-text{font-size:12px;font-weight:600;}
.af-unscored__banner-text--orange{color:#b85a10;}
.af-unscored__banner-text--purple{color:#5046a6;}
.af-unscored__banner-text--green{color:#166534;}
.af-unscored__banner-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:15px;display:block;}
.af-unscored__btns{display:flex;gap:8px;}
.af-unscored__btn-primary{flex:1;background:#F97316;color:#fff;border:none;border-radius:10px;padding:11px 0;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}
.af-unscored__btn-secondary{flex:1;background:#fff;color:#7F77DD;border:1.5px solid #EDE9FA;border-radius:10px;padding:11px 0;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}

</style><div class="cw-page">
    <div class="cw-app">

        <div class="cw-hero">
            <div class="cw-kicker">
                Activity
            </div>

            <h1>
                <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
            </h1>

            <p>
                Use the visual clues. Fill the crossword.
            </p>
        </div>

        <section class="cw-stage">

            <div class="cw-game" id="cw-game">

                <div class="cw-layout">

                    <div class="cw-grid-card">

                        <div class="cw-status">

                            <div class="cw-track">
                                <div class="cw-fill" id="cw-progress"></div>
                            </div>

                            <div class="cw-count" id="cw-count">
                                0%
                            </div>

                        </div>

                        <div class="cw-grid-wrap">

                            <div class="cw-grid" id="cw-grid">

                                <?php for ($r = 0; $r < $gridRows; $r++): ?>
                                    <?php for ($c = 0; $c < $gridCols; $c++): ?>

                                        <?php $cell = $cellMap[$r][$c]; ?>

                                        <div
                                            class="cw-cell<?= $cell['active'] ? '' : ' blocked' ?>"
                                            data-r="<?= $r ?>"
                                            data-c="<?= $c ?>"
                                            data-letter="<?= htmlspecialchars($cell['letter'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-word-idxs="<?= htmlspecialchars(implode(',', $cell['wordIdxs']), ENT_QUOTES, 'UTF-8') ?>"
                                        >

                                            <?php if ($cell['active']): ?>

                                                <?php if ((int)$cell['numLabel'] > 0): ?>
                                                    <span class="num">
                                                        <?= (int)$cell['numLabel'] ?>
                                                    </span>
                                                <?php endif; ?>

                                                <input
                                                    maxlength="1"
                                                    autocomplete="off"
                                                    inputmode="text"
                                                    aria-label="Crossword cell"
                                                >

                                            <?php endif; ?>

                                        </div>

                                    <?php endfor; ?>
                                <?php endfor; ?>

                            </div>

                        </div>

                        <div class="btn-row">
                            <button type="button" id="btn-check"       class="btn-purple">Check</button>
                            <button type="button" id="btn-show-answer" class="btn-purple">Show Answer</button>
                            <button type="button" id="btn-next"        class="btn-orange">Next →</button>
                        </div>


                    </div>

                    <aside class="cw-clue-card">

                        <h2 class="cw-clue-title">
                            Visual Clues
                        </h2>

                        <p class="cw-clue-sub">
                            Tap a clue to highlight its word.
                        </p>

                        <div class="cw-dir-tabs">
                            <button type="button" class="cw-tab active" data-dir="across">Across</button>
                            <button type="button" class="cw-tab" data-dir="down">Down</button>
                        </div>

                        <div class="cw-visual-list" id="cw-clues">

                            <?php foreach ($placed as $idx => $w): ?>

                                <button
                                    type="button"
                                    class="cw-visual-clue"
                                    data-word-idx="<?= $idx ?>"
                                    data-direction="<?= htmlspecialchars($w['direction'], ENT_QUOTES, 'UTF-8') ?>"
                                >

                                    <span class="cw-thumb">

                                        <?php if (trim((string)$w['image']) !== ''): ?>

                                            <img
                                                src="<?= htmlspecialchars($w['image'], ENT_QUOTES, 'UTF-8') ?>"
                                                alt="visual clue <?= (int)$w['num'] ?>"
                                            >

                                        <?php else: ?>

                                            <?= (int)$w['num'] ?>

                                        <?php endif; ?>

                                    </span>

                                    <span class="cw-clue-meta">

                                        <span class="cw-clue-number">
                                            <?= (int)$w['num'] ?>
                                        </span>

                                        <?php if (trim((string)$w['clue']) !== ''): ?>

                                            <span class="cw-clue-text">
                                                <?= htmlspecialchars($w['clue'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>

                                        <?php endif; ?>

                                    </span>

                                </button>

                            <?php endforeach; ?>

                        </div>

                    </aside>

                </div>

            </div>

            <div class="cw-completed" id="cw-completed">
                <div class="af-unscored__card">
                  <div class="af-unscored__prog-label">WORDS FOUND</div>
                  <div class="af-unscored__prog-track">
                    <div class="af-unscored__prog-fill" id="af-prog-fill" style="width:0%"></div>
                  </div>
                  <div class="af-unscored__prog-nums">
                    <span>0</span>
                    <strong id="af-prog-text">0 / 0</strong>
                  </div>
                  <div class="af-unscored__icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#7F77DD" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                  </div>
                  <p class="af-unscored__title">Crossword complete!</p>
                  <p class="af-unscored__sub">You found all the words.</p>
                  <div class="af-unscored__chips af-unscored__chips--2">
                    <div class="af-unscored__chip">
                      <div class="af-unscored__chip-val" id="af-stat1-val">0</div>
                      <div class="af-unscored__chip-lbl">WORDS FOUND</div>
                    </div>
                    <div class="af-unscored__chip">
                      <div class="af-unscored__chip-val" id="af-stat2-val">0</div>
                      <div class="af-unscored__chip-lbl">ROUNDS</div>
                    </div>
                  </div>
                  <div class="af-unscored__banner af-unscored__banner--purple">
                    <div class="af-unscored__banner-icon af-unscored__banner-icon--purple">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </div>
                    <div class="af-unscored__banner-text af-unscored__banner-text--purple">
                      <span class="af-unscored__banner-title">Word master!</span>
                      Ready for the next challenge?
                    </div>
                  </div>
                  <div class="af-unscored__btns">
                    <button class="af-unscored__btn-secondary" id="af-btn-retry">↺ Try again</button>
                    <button class="af-unscored__btn-primary" id="af-btn-next">Next →</button>
                  </div>
                </div>
            </div>

        </section>

    </div>
</div>

<audio
    id="cw-win"
    src="../../hangman/assets/win.mp3"
    preload="auto"
></audio>

<audio
    id="cw-lose"
    src="../../hangman/assets/lose.mp3"
    preload="auto"
></audio>

<script>

const CW_WORDS =
<?= json_encode(array_values($placed), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const CW_ACTIVITY_ID =
<?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;

const CW_RETURN_TO =
<?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;

let cwSelectedWord = null;
let cwActiveTab = 'across';
let cwRounds = 0;

const cwProgress =
document.getElementById('cw-progress');

const cwCount =
document.getElementById('cw-count');

const cwWin =
document.getElementById('cw-win');

const cwLose =
document.getElementById('cw-lose');

function cwCells(){
    return Array.from(
        document.querySelectorAll('.cw-cell:not(.blocked)')
    );
}

function cwPlay(audio){
    try{
        audio.pause();
        audio.currentTime = 0;
        audio.play();
    }catch(e){}
}

function cwCellFor(r,c){
    return document.querySelector(
        '.cw-cell[data-r="' + r + '"][data-c="' + c + '"]'
    );
}function cwWordCells(idx){
    const word = CW_WORDS[idx];

    if(!word) return [];

    let cells = [];

    for(let i = 0; i < word.word.length; i++){

        let r =
            word.direction === 'across'
                ? word.row
                : word.row + i;

        let c =
            word.direction === 'across'
                ? word.col + i
                : word.col;

        let cell = cwCellFor(r,c);

        if(cell) cells.push(cell);
    }

    return cells;
}

function cwSwitchTab(dir){
    cwActiveTab = dir;

    document.querySelectorAll('.cw-tab').forEach(function(btn){
        btn.classList.toggle('active', btn.dataset.dir === dir);
    });

    document.querySelectorAll('.cw-visual-clue').forEach(function(clue){
        clue.style.display =
            (!clue.dataset.direction || clue.dataset.direction === dir)
                ? ''
                : 'none';
    });
}

function cwSelectWord(idx, activeCell){

    cwSelectedWord = Number(idx);

    document
    .querySelectorAll('.cw-cell')
    .forEach(function(cell){
        cell.classList.remove('word-hl','selected');
    });

    document
    .querySelectorAll('.cw-visual-clue')
    .forEach(function(clue){
        clue.classList.toggle(
            'active',
            Number(clue.dataset.wordIdx) === cwSelectedWord
        );
    });

    const cells = cwWordCells(cwSelectedWord);
    let target = activeCell || null;

    if(!target && cells.length){
        target = cells.find(function(c){
            const input = c.querySelector('input');
            return input && !String(input.value || '').trim();
        }) || cells[0];
    }

    cells.forEach(function(cell){
        cell.classList.add(cell === target ? 'selected' : 'word-hl');
    });

    if(target){
        const input = target.querySelector('input');
        if(input) input.focus();
    }

    const activeClue = document.querySelector('.cw-visual-clue.active');
    if(activeClue) activeClue.scrollIntoView({ behavior:'smooth', block:'nearest' });

    const word = CW_WORDS[cwSelectedWord];
    if(word) cwSwitchTab(word.direction);
}

function cwUpdateProgress(){

    const cells = cwCells();

    const filled = cells.filter(function(cell){
        const input = cell.querySelector('input');
        return input && input.value.trim() !== '';
    }).length;

    const pct =
        cells.length
            ? Math.round((filled / cells.length) * 100)
            : 0;

    cwProgress.style.width = pct + '%';
    cwCount.textContent = pct + '%';
}

function cwCheck(){

    let allCorrect = true;
    let filled = 0;

    cwCells().forEach(function(cell){

        const input = cell.querySelector('input');
        const expected = cell.dataset.letter;
        const value = (input.value || '').toUpperCase();

        cell.classList.remove('correct','wrong','revealed');

        if(value){

            filled++;

            if(value === expected){
                cell.classList.add('correct');
            }else{
                cell.classList.add('wrong');
                allCorrect = false;
            }

        }else{
            allCorrect = false;
        }
    });

    cwUpdateProgress();

    if(allCorrect){

        cwPlay(cwWin);

        setTimeout(cwFinish,450);

    }else{

        if(filled) cwPlay(cwLose);
    }
}

function cwRevealSelected(){

    if(cwSelectedWord === null){
        return;
    }

    cwWordCells(cwSelectedWord).forEach(function(cell){

        const input = cell.querySelector('input');

        input.value = cell.dataset.letter;

        cell.classList.remove('wrong');
        cell.classList.add('revealed');
    });

    cwUpdateProgress();
}

function cwClear(){

    cwCells().forEach(function(cell){

        const input = cell.querySelector('input');

        input.value = '';

        cell.classList.remove(
            'correct',
            'wrong',
            'revealed',
            'word-hl',
            'selected'
        );
    });

    document
    .querySelectorAll('.cw-visual-clue')
    .forEach(function(clue){
        clue.classList.remove('active');
    });

    cwSelectedWord = null;

    cwUpdateProgress();
}

async function cwPersistScore(pct,total,errors){

    if(!CW_ACTIVITY_ID || !CW_RETURN_TO) return;

    const joiner =
        CW_RETURN_TO.indexOf('?') !== -1
            ? '&'
            : '?';

    const url =
        CW_RETURN_TO +
        joiner +
        'activity_percent=' + pct +
        '&activity_errors=' + errors +
        '&activity_total=' + total +
        '&activity_id=' + encodeURIComponent(CW_ACTIVITY_ID) +
        '&activity_type=crossword';

    try{

        const response = await fetch(url,{
            method:'GET',
            credentials:'same-origin',
            cache:'no-store'
        });

        if(!response.ok){
            window.location.href = url;
        }

    }catch(e){
        window.location.href = url;
    }
}

function cwFinish(){

    const cells = cwCells();

    let correct = 0;

    cells.forEach(function(cell){

        const input = cell.querySelector('input');

        if(
            ((input.value || '').toUpperCase()) ===
            cell.dataset.letter
        ){
            correct++;
        }
    });

    const pct =
        cells.length
            ? Math.round((correct / cells.length) * 100)
            : 0;

    document
    .getElementById('cw-game')
    .classList
    .add('hide');

    cwRounds += 1;

    document
    .getElementById('cw-completed')
    .classList
    .add('active');

    cwPlay(cwWin);

    cwPersistScore(
        pct,
        cells.length,
        Math.max(0,cells.length - correct)
    );

    /* Populate unified completed screen stats */
    var totalWords = CW_WORDS.length;
    var fillEl   = document.getElementById('af-prog-fill');
    var textEl   = document.getElementById('af-prog-text');
    var stat1El  = document.getElementById('af-stat1-val');
    var stat2El  = document.getElementById('af-stat2-val');
    var retryBtn = document.getElementById('af-btn-retry');
    var nextBtn  = document.getElementById('af-btn-next');

    if (fillEl)  { setTimeout(function(){ fillEl.style.width = '100%'; }, 120); }
    if (textEl)  textEl.textContent  = totalWords + ' / ' + totalWords;
    if (stat1El) stat1El.textContent = String(totalWords);
    if (stat2El) stat2El.textContent = String(cwRounds);

    if (retryBtn) retryBtn.addEventListener('click', cwRestart);
    if (nextBtn) {
        if (CW_RETURN_TO) {
            nextBtn.addEventListener('click', function () {
                try {
                    if (window.top && window.top !== window.self) { window.top.location.href = CW_RETURN_TO; return; }
                } catch(e) {}
                window.location.href = CW_RETURN_TO;
            });
        } else {
            nextBtn.style.display = 'none';
        }
    }
}

function cwRestart(){

    document
    .getElementById('cw-game')
    .classList
    .remove('hide');

    document
    .getElementById('cw-completed')
    .classList
    .remove('active');

    var fillEl = document.getElementById('af-prog-fill');
    if (fillEl) fillEl.style.width = '0%';

    cwClear();
}

document
.querySelectorAll('.cw-visual-clue')
.forEach(function(btn){

    btn.addEventListener('click',function(){
        cwSelectWord(btn.dataset.wordIdx);
    });
});

cwCells().forEach(function(cell){

    const input = cell.querySelector('input');

    cell.addEventListener('click',function(){

        const ids =
            (cell.dataset.wordIdxs || '')
            .split(',')
            .filter(Boolean);

        if(!ids.length) return;

        if(ids.length === 1){
            cwSelectWord(ids[0], cell);
        } else {
            // Toggle between the two words on repeated taps
            const next =
                (String(cwSelectedWord) === ids[0])
                    ? ids[1]
                    : ids[0];
            cwSelectWord(next, cell);
        }
    });

    input.addEventListener('input',function(){

        input.value =
            (input.value || '')
            .toUpperCase()
            .replace(/[^A-Z0-9]/g,'')
            .slice(0,1);

        cell.classList.remove(
            'correct',
            'wrong',
            'revealed'
        );

        cwUpdateProgress();

        if(input.value){

            const ids =
                (cell.dataset.wordIdxs || '')
                .split(',')
                .filter(Boolean);

            const active =
                (cwSelectedWord !== null && ids.includes(String(cwSelectedWord)))
                    ? cwSelectedWord
                    : (ids.length ? Number(ids[0]) : null);

            const cells =
                active !== null
                    ? cwWordCells(active)
                    : [];

            const pos = cells.indexOf(cell);
            let next = null;

            for(let i = pos + 1; i < cells.length; i++){
                const cand = cells[i];
                const candInput = cand ? cand.querySelector('input') : null;
                if(candInput && !String(candInput.value || '').trim()){
                    next = cand;
                    break;
                }
            }

            if(!next){
                next = cells[pos + 1] || null;
            }

            if(next){
                const nextInput =
                    next.querySelector('input');

                if(nextInput) nextInput.focus();
            }
        }
    });

    input.addEventListener('keydown',function(e){

        if(e.key === 'Backspace' && !input.value){

            const ids =
                (cell.dataset.wordIdxs || '')
                .split(',')
                .filter(Boolean);

            const active =
                (cwSelectedWord !== null && ids.includes(String(cwSelectedWord)))
                    ? cwSelectedWord
                    : (ids.length ? Number(ids[0]) : null);

            const cells =
                active !== null
                    ? cwWordCells(active)
                    : [];

            const pos = cells.indexOf(cell);
            const prev = cells[pos - 1];

            if(prev){
                const prevInput =
                    prev.querySelector('input');

                if(prevInput) prevInput.focus();
            }
        }
    });
});

cwUpdateProgress();

document
.querySelectorAll('.cw-tab')
.forEach(function(btn){
    btn.addEventListener('click',function(){
        cwSwitchTab(btn.dataset.dir);
    });
});

cwSwitchTab('across');

function cwShowAllAnswers(){
    cwCells().forEach(function(cell){
        const input = cell.querySelector('input');
        input.value = cell.dataset.letter;
        cell.classList.remove('wrong','selected','word-hl');
        cell.classList.add('correct');
    });
    cwUpdateProgress();
}

document.getElementById('btn-check').onclick       = cwCheck;
document.getElementById('btn-show-answer').onclick  = cwShowAllAnswers;
document.getElementById('btn-next').onclick         = cwFinish;

</script>

<?php
$content = ob_get_clean();
render_activity_viewer($title, 'crossword', $content);
?>