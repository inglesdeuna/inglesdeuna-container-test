<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../core/_activity_editor_template.php";

// Block student access to editor
if (!empty($_SESSION['student_logged'])) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

// Accept admin OR teacher session
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET["id"])         ? trim((string) $_GET["id"])         : "";
$unit       = isset($_GET["unit"])       ? trim((string) $_GET["unit"])       : "";
$source     = isset($_GET["source"])     ? trim((string) $_GET["source"])     : "";
$assignment = isset($_GET["assignment"]) ? trim((string) $_GET["assignment"]) : "";

/* ---------------------------------------------------------------
   HELPERS
--------------------------------------------------------------- */
function resolve_unit_from_activity_cw(PDO $pdo, string $activityId): string
{
    if ($activityId === "") return "";
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(["id" => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)($row["unit_id"] ?? "") : "";
}

function default_cw_title(): string { return "Crossword Puzzle"; }

function normalize_cw_payload($raw): array
{
    $default = ["title" => default_cw_title(), "words" => []];
    if ($raw === null || $raw === "") return $default;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $default;

    $title = trim((string)($d["title"] ?? default_cw_title()));
    if ($title === "") $title = default_cw_title();

    $words = [];
    foreach ((array)($d["words"] ?? []) as $w) {
        if (!is_array($w)) continue;
        $word = strtoupper(trim((string)($w["word"] ?? "")));
        $word = preg_replace('/[^A-Z0-9]/', '', $word);
        if ($word === "") continue;
        $words[] = [
            "id"        => trim((string)($w["id"]        ?? uniqid("cw_"))),
            "word"      => $word,
            "clue"      => trim((string)($w["clue"]      ?? "")),
        ];
    }
    return ["title" => $title, "words" => $words];
}

function load_cw_activity(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = ["id" => "", "title" => default_cw_title(), "words" => []];
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
    if (!$row) return $fallback;
    $payload = normalize_cw_payload($row["data"] ?? null);
    return ["id" => (string)($row["id"] ?? ""), "title" => $payload["title"], "words" => $payload["words"]];
}

function save_cw_activity(PDO $pdo, string $unit, string $activityId, string $title, array $words): string
{
    $json = json_encode(["title" => $title, "words" => array_values($words)], JSON_UNESCAPED_UNICODE);
    $targetId = $activityId;

    if ($targetId === "") {
        $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id = :unit AND type = 'crossword' ORDER BY id ASC LIMIT 1");
        $stmt->execute(["unit" => $unit]);
        $targetId = trim((string)$stmt->fetchColumn());
    }

    if ($targetId !== "") {
        $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'crossword'");
        $stmt->execute(["data" => $json, "id" => $targetId]);
        return $targetId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data, position, created_at)
        VALUES (:unit_id, 'crossword', :data,
            (SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id = :unit_id2),
            CURRENT_TIMESTAMP)
        RETURNING id
    ");
    $stmt->execute(["unit_id" => $unit, "unit_id2" => $unit, "data" => $json]);
    return (string)$stmt->fetchColumn();
}

/* ---------------------------------------------------------------
   BOOT
--------------------------------------------------------------- */
if ($unit === "" && $activityId !== "") {
    $unit = resolve_unit_from_activity_cw($pdo, $activityId);
}
if ($unit === "") die("Unit not specified");

$activity   = load_cw_activity($pdo, $unit, $activityId);
$actTitle   = (string)($activity["title"] ?? default_cw_title());
$words      = is_array($activity["words"]) ? $activity["words"] : [];
if ($activityId === "" && !empty($activity["id"])) $activityId = $activity["id"];

/* ---------------------------------------------------------------
   SAVE
--------------------------------------------------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedTitle = trim((string)($_POST["activity_title"] ?? ""));
    if ($postedTitle === "") $postedTitle = default_cw_title();

    $ids        = (array)($_POST["word_id"]        ?? []);
    $rawWords   = (array)($_POST["word"]            ?? []);
    $clues      = (array)($_POST["clue"]            ?? []);

    $sanitized = [];
    foreach ($rawWords as $i => $w) {
        $word = strtoupper(trim((string)$w));
        $word = preg_replace('/[^A-Z0-9]/', '', $word);
        if ($word === "") continue;
        $sanitized[] = [
            "id"        => trim((string)($ids[$i] ?? uniqid("cw_"))),
            "word"      => $word,
            "clue"      => trim((string)($clues[$i] ?? "")),
        ];
    }

    $savedId = save_cw_activity($pdo, $unit, $activityId, $postedTitle, $sanitized);

    $params = ["unit=" . urlencode($unit), "saved=1"];
    if ($savedId !== "")   $params[] = "id="         . urlencode($savedId);
    if ($assignment !== "") $params[] = "assignment=" . urlencode($assignment);
    if ($source !== "")    $params[] = "source="     . urlencode($source);

    header("Location: editor.php?" . implode("&", $params));
    exit;
}

/* ---------------------------------------------------------------
   RENDER
--------------------------------------------------------------- */
ob_start();
?>

<style>
.cw-form { max-width:960px; margin:0 auto; text-align:left; }

.cw-title-box,
.cw-word-item {
    background:#f9fafb;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:16px;
    margin-bottom:14px;
}

.cw-word-item { position:relative; }

.cw-title-box label,
.cw-word-item label {
    display:block;
    font-weight:700;
    font-size:13px;
    color:#374151;
    margin-bottom:5px;
    margin-top:10px;
}
.cw-title-box label:first-child,
.cw-word-item label:first-child { margin-top:0; }

.cw-title-box input,
.cw-word-item input[type=text],
.cw-word-item input[type=number],
.cw-word-item textarea {
    width:100%;
    padding:9px 12px;
    border:1px solid #d1d5db;
    border-radius:8px;
    font-size:14px;
    box-sizing:border-box;
}
.cw-word-item textarea { min-height:72px; resize:vertical; }

.word-num-badge {
    position:absolute; top:14px; left:14px;
    width:28px; height:28px; border-radius:50%;
    background:#6d28d9; color:#fff;
    font-size:12px; font-weight:800;
    display:flex; align-items:center; justify-content:center;
}
.cw-word-item { padding-left:54px; }

.toolbar-row {
    display:flex; gap:10px; flex-wrap:wrap;
    justify-content:center; margin-top:12px;
}
.btn-add { background:#7c3aed; color:#fff; padding:10px 16px; border:none; border-radius:8px; cursor:pointer; font-weight:700; }
.btn-remove { background:#ef4444; color:#fff; border:none; padding:7px 12px; border-radius:8px; cursor:pointer; font-weight:700; font-size:13px; }

/* live preview */
.cw-preview-wrap {
    background:#fff;
    border:1px solid #ddd6fe;
    border-radius:16px;
    padding:20px;
    margin-bottom:20px;
    overflow-x:auto;
}
.cw-preview-title {
    font-size:14px; font-weight:700; color:#6d28d9; margin-bottom:14px;
}
#cwPreviewGrid { display:inline-block; }
#cwPreviewGrid table {
    border-collapse:collapse;
}
#cwPreviewGrid td {
    width:36px; height:36px;
    border:2px solid #c4b5fd;
    text-align:center; vertical-align:middle;
    font-size:13px; font-weight:700;
    background:#fff; color:#1e1b4b;
    position:relative;
    cursor:default;
}
#cwPreviewGrid td.blocked {
    background:#1e1b4b;
    border-color:#1e1b4b;
}
#cwPreviewGrid td .cell-num {
    position:absolute; top:1px; left:2px;
    font-size:8px; font-weight:800; color:#7c3aed;
    line-height:1;
}
</style>

<?php if (isset($_GET["saved"])): ?>
    <p style="color:#16a34a;font-weight:700;margin-bottom:14px;">✔ Saved successfully</p>
<?php endif; ?>

<!-- Live preview -->
<div class="cw-preview-wrap">
    <div class="cw-preview-title">📐 Auto Grid Preview (updates as you type)</div>
    <div id="cwPreviewGrid"></div>
</div>

<form class="cw-form" id="cwForm" method="post">
    <!-- Title -->
    <div class="cw-title-box">
        <label for="activity_title">Activity title</label>
        <input id="activity_title" type="text" name="activity_title"
               value="<?= htmlspecialchars($actTitle, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="e.g. Classroom Commands" required>
    </div>

    <div id="wordsContainer">
    <?php foreach ($words as $idx => $w): $n = $idx + 1; ?>
        <div class="cw-word-item" data-idx="<?= $n ?>">
            <div class="word-num-badge"><?= $n ?></div>

            <input type="hidden" name="word_id[]" value="<?= htmlspecialchars((string)($w["id"] ?? uniqid("cw_")), ENT_QUOTES, 'UTF-8') ?>">

            <label>Word (letters only, no spaces)</label>
            <input type="text" name="word[]"
                   value="<?= htmlspecialchars((string)($w["word"] ?? ""), ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="e.g. LISTEN" required>

            <label>Clue / Hint</label>
            <textarea name="clue[]" placeholder="e.g. Pay attention with your ears."><?= htmlspecialchars((string)($w["clue"] ?? ""), ENT_QUOTES, 'UTF-8') ?></textarea>

            <p style="margin-top:10px;color:#6b7280;font-size:12px;">Placement is automatic in the preview and player.</p>

            <div style="margin-top:12px;">
                <button type="button" class="btn-remove" onclick="removeWord(this)">✖ Remove</button>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="toolbar-row">
        <button type="button" class="btn-add" onclick="addWord()">+ Add Word</button>
        <button type="submit" class="save-btn">💾 Save Crossword</button>
    </div>
</form>

<script>
let formChanged = false, formSubmitted = false;
function markChanged(){ formChanged = true; renderPreview(); }

function removeWord(btn){
    btn.closest('.cw-word-item').remove();
    renumberBadges();
    markChanged();
}

function renumberBadges(){
    document.querySelectorAll('.cw-word-item').forEach(function(el, i){
        const badge = el.querySelector('.word-num-badge');
        if(badge) badge.textContent = (i+1);
        el.dataset.idx = (i+1);
    });
}

function addWord(){
    const container = document.getElementById('wordsContainer');
    const n = container.querySelectorAll('.cw-word-item').length + 1;
    const id = 'cw_' + Date.now() + '_' + Math.floor(Math.random()*1000);
    const div = document.createElement('div');
    div.className = 'cw-word-item';
    div.dataset.idx = n;
    div.innerHTML = `
        <div class="word-num-badge">${n}</div>
        <input type="hidden" name="word_id[]" value="${id}">
        <label>Word (letters and numbers only)</label>
        <input type="text" name="word[]" placeholder="e.g. LISTEN" required>
        <label>Clue / Hint</label>
        <textarea name="clue[]" placeholder="e.g. Pay attention with your ears."></textarea>
        <p style="margin-top:10px;color:#6b7280;font-size:12px;">Placement is automatic in the preview and player.</p>
        <div style="margin-top:12px;">
            <button type="button" class="btn-remove" onclick="removeWord(this)">✖ Remove</button>
        </div>
    `;
    container.appendChild(div);
    div.querySelectorAll('input,textarea,select').forEach(function(el){
        el.addEventListener('input', markChanged);
        el.addEventListener('change', markChanged);
    });
    markChanged();
}

function bindAll(scope){
    scope.querySelectorAll('input,textarea,select').forEach(function(el){
        el.addEventListener('input', markChanged);
        el.addEventListener('change', markChanged);
    });
}

/* ----  Live preview ---- */
function collectWords(){
    const items = document.querySelectorAll('.cw-word-item');
    const result = [];
    items.forEach(function(item){
        const word  = (item.querySelector('input[name="word[]"]')      || {}).value || '';
        const cleaned = word.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
        if(cleaned.length >= 2) result.push({ word: cleaned });
    });
    return result;
}

function keyRC(r, c){ return r + ',' + c; }

function canPlaceWord(grid, word, row, col, direction){
    const len = word.length;
    let overlaps = 0;
    const dr = direction === 'across' ? 0 : 1;
    const dc = direction === 'across' ? 1 : 0;

    const before = keyRC(row - dr, col - dc);
    const after = keyRC(row + dr * len, col + dc * len);
    if(grid[before] || grid[after]) return { ok:false, overlaps:0 };

    for(let i=0;i<len;i++){
        const r = row + dr * i;
        const c = col + dc * i;
        const key = keyRC(r,c);
        const ch = word[i];

        if(grid[key]){
            if((grid[key].letter || '') !== ch) return { ok:false, overlaps:0 };
            overlaps++;
            continue;
        }

        if(direction === 'across'){
            if(grid[keyRC(r-1,c)] || grid[keyRC(r+1,c)]) return { ok:false, overlaps:0 };
        } else {
            if(grid[keyRC(r,c-1)] || grid[keyRC(r,c+1)]) return { ok:false, overlaps:0 };
        }
    }

    return { ok:true, overlaps };
}

function placeWord(grid, placed){
    const { word, row, col, direction, idx } = placed;
    for(let i=0;i<word.length;i++){
        const r = direction === 'across' ? row : row + i;
        const c = direction === 'across' ? col + i : col;
        const key = keyRC(r,c);
        if(!grid[key]) grid[key] = { letter: word[i], wordIdxs: [] };
        grid[key].wordIdxs.push(idx);
    }
}

function generateLayout(words){
    if(!words.length) return [];

    const indexed = words.map((w, idx) => ({ idx, word: w.word }))
        .sort((a,b) => (b.word.length - a.word.length) || (a.idx - b.idx));

    const grid = {};
    const placed = [];

    const first = indexed[0];
    const firstPlaced = { idx:first.idx, word:first.word, row:0, col:0, direction:'across' };
    placed.push(firstPlaced);
    placeWord(grid, firstPlaced);

    for(let p=1;p<indexed.length;p++){
        const candidate = indexed[p];
        const word = candidate.word;
        let best = null;
        let bestScore = -1000000;

        Object.keys(grid).forEach(function(key){
            const parts = key.split(',');
            const r0 = parseInt(parts[0], 10);
            const c0 = parseInt(parts[1], 10);
            const gridCh = grid[key].letter;

            for(let i=0;i<word.length;i++){
                if(word[i] !== gridCh) continue;
                ['across','down'].forEach(function(dir){
                    const startRow = dir === 'across' ? r0 : r0 - i;
                    const startCol = dir === 'across' ? c0 - i : c0;
                    const check = canPlaceWord(grid, word, startRow, startCol, dir);
                    if(!check.ok || check.overlaps < 1) return;

                    const minR = startRow;
                    const maxR = dir === 'down' ? startRow + word.length - 1 : startRow;
                    const minC = startCol;
                    const maxC = dir === 'across' ? startCol + word.length - 1 : startCol;
                    const areaPenalty = (maxR - minR + 1) * (maxC - minC + 1);
                    const score = (check.overlaps * 1000) - areaPenalty;

                    if(score > bestScore){
                        bestScore = score;
                        best = { idx:candidate.idx, word, row:startRow, col:startCol, direction:dir };
                    }
                });
            }
        });

        if(!best){
            let minC = 0;
            let maxR = 0;
            let firstBound = true;
            Object.keys(grid).forEach(function(key){
                const parts = key.split(',');
                const r = parseInt(parts[0], 10);
                const c = parseInt(parts[1], 10);
                if(firstBound){ minC = c; maxR = r; firstBound = false; }
                else { minC = Math.min(minC, c); maxR = Math.max(maxR, r); }
            });

            const preferDown = (p % 2) === 1;
            best = {
                idx:candidate.idx,
                word,
                row:maxR + 2,
                col:minC,
                direction: preferDown ? 'down' : 'across'
            };

            const fallbackCheck = canPlaceWord(grid, best.word, best.row, best.col, best.direction);
            if(!fallbackCheck.ok){
                best.direction = best.direction === 'across' ? 'down' : 'across';
            }
        }

        placed.push(best);
        placeWord(grid, best);
    }

    let minRow = 0;
    let minCol = 0;
    let firstCell = true;
    Object.keys(grid).forEach(function(key){
        const parts = key.split(',');
        const r = parseInt(parts[0], 10);
        const c = parseInt(parts[1], 10);
        if(firstCell){ minRow = r; minCol = c; firstCell = false; }
        else { minRow = Math.min(minRow, r); minCol = Math.min(minCol, c); }
    });

    if(minRow !== 0 || minCol !== 0){
        placed.forEach(function(pw){
            pw.row -= minRow;
            pw.col -= minCol;
        });
    }

    return placed;
}

function renderPreview(){
    const words = collectWords();
    const grid = document.getElementById('cwPreviewGrid');
    if(!words.length){ grid.innerHTML = '<span style="color:#9ca3af;font-size:13px;">Add words to see the grid preview.</span>'; return; }

    const placed = generateLayout(words);
    if(!placed.length){
        grid.innerHTML = '<span style="color:#9ca3af;font-size:13px;">Unable to build crossword from current words.</span>';
        return;
    }

    let maxR = 0, maxC = 0;
    placed.forEach(function(w){
        if(w.direction === 'across'){
            maxR = Math.max(maxR, w.row);
            maxC = Math.max(maxC, w.col + w.word.length - 1);
        } else {
            maxR = Math.max(maxR, w.row + w.word.length - 1);
            maxC = Math.max(maxC, w.col);
        }
    });
    const rows = maxR + 1, cols = maxC + 1;
    const cells = [];
    for(let r=0;r<rows;r++){ cells.push([]); for(let c=0;c<cols;c++) cells[r].push({letter:'',num:'',blocked:true}); }

    placed.forEach(function(w){
        for(let i=0;i<w.word.length;i++){
            const r = w.direction==='across' ? w.row : w.row+i;
            const c = w.direction==='across' ? w.col+i : w.col;
            if(r<rows && c<cols){ cells[r][c].letter = w.word[i]; cells[r][c].blocked = false; }
        }
    });

    const startNum = {};
    let next = 1;
    for(let r=0;r<rows;r++){
        for(let c=0;c<cols;c++){
            if(cells[r][c].blocked) continue;
            const startsAcross = (c===0 || cells[r][c-1].blocked) && (c+1<cols && !cells[r][c+1].blocked);
            const startsDown = (r===0 || cells[r-1][c].blocked) && (r+1<rows && !cells[r+1][c].blocked);
            if(startsAcross || startsDown){
                startNum[keyRC(r,c)] = next++;
            }
        }
    }
    placed.forEach(function(w){
        const key = keyRC(w.row, w.col);
        if(cells[w.row] && cells[w.row][w.col]) cells[w.row][w.col].num = startNum[key] || '';
    });

    let html = '<table>';
    for(let r=0;r<rows;r++){
        html += '<tr>';
        for(let c=0;c<cols;c++){
            const cell = cells[r][c];
            if(cell.blocked){ html += '<td class="blocked"></td>'; }
            else {
                const numSpan = cell.num ? `<span class="cell-num">${cell.num}</span>` : '';
                html += `<td>${numSpan}${cell.letter}</td>`;
            }
        }
        html += '</tr>';
    }
    html += '</table>';
    grid.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function(){
    bindAll(document);
    renderPreview();
    const form = document.getElementById('cwForm');
    if(form){ form.addEventListener('submit', function(){ formSubmitted=true; formChanged=false; }); }
});
window.addEventListener('beforeunload', function(e){ if(formChanged && !formSubmitted){ e.preventDefault(); e.returnValue=''; } });
</script>

<?php
$content = ob_get_clean();
render_activity_editor("🔤 Crossword Editor", "fas fa-th", $content);
