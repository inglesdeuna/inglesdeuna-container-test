<?php
/**
 * reading_comprehension/viewer.php
 * Robust vanilla-JS viewer/editor for Reading Comprehension.
 * Pattern used by the repo: render_activity_viewer($title, $icon, $content).
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unitId     = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';
$mode       = isset($_GET['mode']) ? trim((string) $_GET['mode']) : 'view';
$source     = isset($_GET['source']) ? trim((string) $_GET['source']) : '';

$savedData = [];
$savedTitle = 'Reading Comprehension';
$activityLoaded = false;

function rc_has_column(PDO $pdo, string $col): bool
{
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='activities' AND column_name=:c LIMIT 1");
        $st->execute(['c' => $col]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

$titleSel = "'' AS title";
if (rc_has_column($pdo, 'title')) {
    $titleSel = 'title AS title';
} elseif (rc_has_column($pdo, 'name')) {
    $titleSel = 'name AS title';
}

try {
    if ($activityId !== '') {
        $st = $pdo->prepare("SELECT data, {$titleSel} FROM activities WHERE id=? AND type='reading_comprehension' LIMIT 1");
        $st->execute([$activityId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } elseif ($unitId !== '') {
        $st = $pdo->prepare("SELECT data, {$titleSel} FROM activities WHERE unit_id=? AND type='reading_comprehension' ORDER BY id ASC LIMIT 1");
        $st->execute([$unitId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } else {
        $row = false;
    }

    if ($row) {
        $activityLoaded = true;
        $decoded = json_decode((string)($row['data'] ?? ''), true);
        $savedData = is_array($decoded) ? $decoded : [];
        $rowTitle = trim((string)($row['title'] ?? ''));
        if ($rowTitle !== '') {
            $savedTitle = $rowTitle;
        }
    }
} catch (Throwable $e) {
    error_log('[reading_comprehension] load error: ' . $e->getMessage());
}

$hasEditorSession = isset($_SESSION['academic_id']) || isset($_SESSION['admin_id']);
$isCreatorSource = in_array(strtolower($source), ['creator', 'create', 'editor', 'teacher'], true);
$isEditor = ($mode === 'edit' || $isCreatorSource) && ($hasEditorSession || $isCreatorSource);
$allowEditor = $isEditor ? 'true' : 'false';
$viewerTitle = $savedTitle !== '' ? $savedTitle : 'Reading Comprehension';

error_log('[reading_comprehension] id=' . $activityId . ' unit=' . $unitId . ' loaded=' . ($activityLoaded ? 'yes' : 'no') . ' mode=' . $mode . ' source=' . $source . ' editor=' . ($isEditor ? 'yes' : 'no'));

ob_start();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;600&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">

<style>
  #rc-root { height: 100%; min-height: 0; display: flex; flex-direction: column; background: #F8F7FF; }
  .rc-app { height: 100%; min-height: 0; display: flex; flex-direction: column; font-family: 'Nunito', system-ui, sans-serif; color: #3D3560; background: #F8F7FF; }
  .rc-top { height: 52px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; position: relative; background: #fff; border-bottom: 1px solid #F0EEF8; }
  .rc-title { color: #F97316; font-family: 'Fredoka', sans-serif; font-weight: 600; font-size: 20px; }
  .rc-edit-badge { position: absolute; right: 16px; top: 10px; border: 1.5px solid #DCD7FF; background: #F5F3FF; color: #5B51C8; border-radius: 999px; padding: 5px 18px; font-weight: 900; font-size: 13px; }
  .rc-body { flex: 1; min-height: 0; overflow-y: auto; padding: 22px; }
  .rc-wrap { max-width: 1120px; margin: 0 auto; }
  .rc-card { background: #fff; border: 1.5px solid #EDE9FA; border-radius: 22px; overflow: hidden; margin-bottom: 18px; box-shadow: 0 3px 14px rgba(127,119,221,.05); }
  .rc-card-head { padding: 16px 22px; border-bottom: 1px solid #F0EEF8; display: flex; align-items: center; gap: 12px; }
  .rc-icon { width: 34px; height: 34px; border-radius: 12px; display: grid; place-items: center; background: #FFF0E6; color: #C2580A; font-weight: 900; }
  .rc-card-title { font-family: 'Fredoka', sans-serif; color: #F97316; font-size: 21px; font-weight: 600; }
  .rc-card-body { padding: 18px 22px; }
  .rc-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .rc-grid-3 { display: grid; grid-template-columns: 1fr 1fr 180px; gap: 14px; }
  .rc-label { display: block; margin: 0 0 6px; color: #9B8FCC; font-weight: 900; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; }
  .rc-input, .rc-textarea { width: 100%; border: 1.5px solid #DCD7FF; border-radius: 11px; background: #FBFAFF; color: #221A3F; font: 800 16px 'Nunito', sans-serif; padding: 10px 14px; outline: none; box-sizing: border-box; }
  .rc-textarea { min-height: 130px; resize: vertical; line-height: 1.5; }
  .rc-mode { text-align: left; min-height: 150px; padding: 20px; border-radius: 18px; border: 2px solid #EDE9FA; background: #F6F4FD; cursor: pointer; color: #3D3560; }
  .rc-mode.active-orange { border-color: #F97316; background: #FFF5EE; }
  .rc-mode.active-purple { border-color: #7F77DD; background: #F5F3FF; }
  .rc-mode h3 { margin: 0 0 6px; font-family: 'Fredoka', sans-serif; font-size: 20px; color: #F97316; }
  .rc-mode.purple h3 { color: #5B51C8; }
  .rc-mode p { margin: 0; color: #9B8FCC; font-size: 14px; font-weight: 800; }
  .rc-selected { margin-top: 10px; color: #F97316; font-weight: 900; font-size: 13px; }
  .rc-note { background: #F5F3FF; border: 1px solid #EDE9FA; color: #5B51C8; border-radius: 12px; padding: 12px 16px; font-weight: 900; margin-bottom: 14px; }
  .rc-preview { background: #FFF9F4; border: 1.5px solid #FCDDBF; border-radius: 14px; padding: 14px 18px; margin-bottom: 16px; line-height: 1.75; font-weight: 800; }
  .rc-hl { color: #C2580A; font-weight: 900; border-bottom: 2px solid #F97316; background: #FFF0E6; border-radius: 3px; padding: 0 2px; }
  .rc-pill { margin-left: auto; background: #F5F3FF; color: #7F77DD; border-radius: 8px; padding: 3px 12px; font-weight: 900; font-size: 13px; }
  .rc-item { border: 1.5px solid #EDE9FA; border-radius: 18px; padding: 16px; margin-bottom: 14px; }
  .rc-item-title { color: #F97316; font-family: 'Fredoka', sans-serif; font-size: 19px; font-weight: 600; margin-bottom: 10px; }
  .rc-remove { float: right; border: 1.5px solid #D85A30; color: #D85A30; background: #fff; border-radius: 9px; padding: 5px 10px; font-weight: 900; cursor: pointer; }
  .rc-add { width: 100%; border: 1.5px solid #DCD7FF; border-radius: 13px; background: #fff; color: #3D3560; padding: 13px 16px; font-weight: 900; font-size: 16px; cursor: pointer; text-align: left; }
  .rc-savebar { position: sticky; bottom: 0; display: grid; grid-template-columns: auto 1fr auto; gap: 14px; align-items: center; background: #F8F7FF; padding: 18px 0 0; }
  .rc-btn { border: 1.5px solid #DCD7FF; border-radius: 13px; background: #fff; color: #3D3560; padding: 12px 24px; font-weight: 900; font-size: 16px; cursor: pointer; font-family: 'Nunito', sans-serif; }
  .rc-primary { background: #F97316; color: #fff; border-color: #F97316; }
  .rc-status { text-align: center; color: #7F77DD; font-weight: 900; }
  .rc-player { flex: 1; min-height: 0; display: grid; grid-template-columns: 48% 52%; background: #F8F7FF; }
  .rc-passage { overflow: auto; padding: 30px 34px 36px; background: #fff; border-right: 1px solid #F0EEF8; line-height: 1.75; box-sizing: border-box; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; }
  .rc-quiz { overflow-y: auto; padding: 22px; }
  .rc-question { background: #fff; border: 1.5px solid #EDE9FA; border-radius: 20px; padding: 22px; box-shadow: 0 4px 20px rgba(127,119,221,.10); }
  .rc-option { width: 100%; text-align: left; border: 1.5px solid #DCD7FF; border-radius: 12px; background: #FBFAFF; color: #3D3560; padding: 12px 14px; margin-bottom: 10px; font-weight: 800; cursor: pointer; }
  .rc-option.correct { background: #E1F5EE; border-color: #1D9E75; color: #085041; }
  .rc-option.wrong { background: #FAECE7; border-color: #D85A30; color: #4A1B0C; }
  @media (max-width: 850px) { .rc-grid-2, .rc-grid-3, .rc-player { grid-template-columns: 1fr; } .rc-savebar { grid-template-columns: 1fr; } .rc-passage { padding: 26px 22px 32px; border-right: 0; border-bottom: 1px solid #F0EEF8; } }
  @media (max-width: 520px) { .rc-passage { padding: 22px 18px 28px; } }
  .rc-zoom-bar { display: flex; align-items: center; gap: 4px; padding: 0 0 14px; }
  .rc-zoom-btn { width: 30px; height: 30px; border-radius: 50%; border: 2px solid #7F77DD; background: #fff; color: #7F77DD; font-size: 17px; font-weight: 900; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1; transition: background .12s, color .12s; padding: 0; }
  .rc-zoom-btn:hover { background: #7F77DD; color: #fff; }
  .rc-zoom-label { font-size: 11px; font-weight: 700; color: #9B94BE; min-width: 32px; text-align: center; font-family: 'Nunito', sans-serif; }
  .rc-passage-inner { display: block; width: 100%; max-width: 100%; min-width: 0; box-sizing: border-box; transform-origin: top left; white-space: normal; overflow-wrap: anywhere; word-break: normal; }
</style>

<div id="rc-root"></div>

<script>
window.RC_ACTIVITY_ID  = <?= json_encode($activityId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RC_UNIT_ID      = <?= json_encode($unitId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RC_RETURN_TO    = <?= json_encode($returnTo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RC_ALLOW_EDITOR = <?= $allowEditor ?>;
window.RC_SAVED_TITLE  = <?= json_encode($savedTitle, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RC_SAVED_DATA   = <?= json_encode($savedData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

(function () {
  const root = document.getElementById('rc-root');
  let state = normalizeDataset(window.RC_SAVED_DATA || {});
  let preview = false;
  let status = '';
  let saving = false;
  let answerIndex = -1;
  let checked = false;
  let qIndex = 0;
  let zoomScale = 1;
  const ZOOM_STEP = 0.2, ZOOM_MIN = 0.6, ZOOM_MAX = 3.0;

  function uid(prefix) { return prefix + '_' + Math.random().toString(36).slice(2, 9) + '_' + Date.now(); }
  function h(v) { return String(v ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch])); }
  function wordsCount(t) { return String(t || '').trim().split(/\s+/).filter(Boolean).length; }
  function reEsc(v) { return String(v || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
  function normalizeWord(x) { x = x || {}; const d = Array.isArray(x.distractors) ? x.distractors : []; return { id: String(x.id || uid('w')), word: String(x.word || ''), correct: String(x.correct || ''), distractors: [String(d[0] || ''), String(d[1] || '')] }; }
  function normalizeQuestion(x) { x = x || {}; const opts = Array.isArray(x.options) ? x.options : []; const c = Number(x.correct); return { id: String(x.id || uid('q')), stem: String(x.stem || ''), options: [String(opts[0] || ''), String(opts[1] || ''), String(opts[2] || ''), String(opts[3] || '')], correct: Number.isInteger(c) ? Math.max(0, Math.min(3, c)) : 0, feedback: String(x.feedback || '') }; }
  function normalizeText(x) { x = x || {}; const body = String(x.body || ''); const wc = Number(x.wordCount); return { id: String(x.id || uid('t')), mode: String(x.mode || 'vocab').toLowerCase() === 'comp' ? 'comp' : 'vocab', title: String(x.title || window.RC_SAVED_TITLE || 'Reading Comprehension'), genre: String(x.genre || 'Informative text'), wordCount: Number.isFinite(wc) && wc > 0 ? wc : wordsCount(body), body, words: (Array.isArray(x.words) ? x.words : []).map(normalizeWord), questions: (Array.isArray(x.questions) ? x.questions : []).map(normalizeQuestion) }; }
  function normalizeDataset(raw) { if (raw && Array.isArray(raw.texts) && raw.texts.length) return { title: String(raw.title || window.RC_SAVED_TITLE || 'Reading Comprehension'), texts: raw.texts.map(normalizeText) }; return { title: String(window.RC_SAVED_TITLE || 'Reading Comprehension'), texts: [normalizeText(raw || {})] }; }
  function text() { if (!state.texts[0]) state.texts[0] = normalizeText({}); return state.texts[0]; }
  function patchText(patch, shouldRender) { state.texts[0] = Object.assign({}, text(), patch); if (shouldRender !== false) render(); }
  function refreshLivePreview() { const box = root ? root.querySelector('.rc-preview') : null; if (!box) return; const t = text(); box.innerHTML = highlight(t.body, t.words); }
  function highlight(body, wordList) { let out = h(body || 'Type passage text above to see highlights.').replace(/\n/g, '<br>'); const terms = (wordList || []).map(w => String(w.word || '').trim()).filter(Boolean).sort((a,b) => b.length - a.length); terms.forEach(term => { const pat = /\s/.test(term) ? '(' + reEsc(term) + ')' : '\\b(' + reEsc(term) + ')\\b'; out = out.replace(new RegExp(pat, 'gi'), '<span class="rc-hl">$1</span>'); }); return out; }
  function optionsForWord(w, idx) { return [{ text: w.correct || '', ok: true }, { text: (w.distractors || [])[0] || '', ok: false }, { text: (w.distractors || [])[1] || '', ok: false }].filter(o => o.text.trim()).map((o, i) => Object.assign(o, { sort: ((idx + 3) * (i + 7) * 17) % 97 })).sort((a,b) => a.sort - b.sort); }
  function topBar(edit) { return '<div class="rc-top"><div class="rc-title">Reading Comprehension</div>' + (edit ? '<div class="rc-edit-badge">✎ Edit mode</div>' : '') + '</div>'; }
  function applyZoom() { const inner = root ? root.querySelector('.rc-passage-inner') : null; if (!inner) return; inner.style.zoom = zoomScale === 1 ? '' : String(zoomScale); const label = root ? root.querySelector('.rc-zoom-label') : null; if (label) label.textContent = Math.round(zoomScale * 100) + '%'; }
  function setupPinch() { const passage = root ? root.querySelector('.rc-passage') : null; if (!passage) return; let pinchActive = false, pinchStartDist = 0, pinchStartScale = 1; function pinchDist(e) { const dx = e.touches[0].clientX - e.touches[1].clientX, dy = e.touches[0].clientY - e.touches[1].clientY; return Math.sqrt(dx * dx + dy * dy); } passage.addEventListener('touchstart', function(e) { if (e.touches.length === 2) { pinchActive = true; pinchStartDist = pinchDist(e); pinchStartScale = zoomScale; e.preventDefault(); } }, { passive: false }); passage.addEventListener('touchmove', function(e) { if (!pinchActive || e.touches.length !== 2) return; zoomScale = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, parseFloat((pinchStartScale * pinchDist(e) / pinchStartDist).toFixed(2)))); applyZoom(); e.preventDefault(); }, { passive: false }); passage.addEventListener('touchend', function(e) { if (e.touches.length < 2) pinchActive = false; }, { passive: true }); }

  function editorHtml() {
    const t = text();
    return '<div class="rc-app">' + topBar(true) + '<div class="rc-body"><div class="rc-wrap">' +
      '<section class="rc-card"><div class="rc-card-head"><div class="rc-icon">⚙</div><div class="rc-card-title">General settings</div></div><div class="rc-card-body"><div class="rc-grid-2"><div><label class="rc-label">Activity title</label><input class="rc-input" data-field="title" value="' + h(t.title) + '"></div><div><label class="rc-label">Level</label><input class="rc-input" data-field="level" value="' + h(t.level || 'B1') + '"></div></div><label class="rc-label" style="margin-top:16px">Activity mode — choose one</label><div class="rc-grid-2"><button class="rc-mode ' + (t.mode === 'vocab' ? 'active-orange' : '') + '" data-action="mode" data-mode="vocab"><h3>🔤 Vocabulary meaning</h3><p>Students read the passage and choose the correct meaning for each highlighted word.</p>' + (t.mode === 'vocab' ? '<div class="rc-selected">✓ Selected</div>' : '') + '</button><button class="rc-mode purple ' + (t.mode === 'comp' ? 'active-purple' : '') + '" data-action="mode" data-mode="comp"><h3>📖 Reading comprehension</h3><p>Students answer questions about the passage to demonstrate understanding.</p>' + (t.mode === 'comp' ? '<div class="rc-selected" style="color:#7F77DD">✓ Selected</div>' : '') + '</button></div></div></section>' +
      '<section class="rc-card"><div class="rc-card-head"><div class="rc-icon">📄</div><div class="rc-card-title">Passage</div></div><div class="rc-card-body"><div class="rc-grid-3"><div><label class="rc-label">Title</label><input class="rc-input" data-field="title" value="' + h(t.title) + '"></div><div><label class="rc-label">Genre</label><input class="rc-input" data-field="genre" value="' + h(t.genre) + '"></div><div><label class="rc-label">Word count</label><input class="rc-input" type="number" data-field="wordCount" value="' + h(t.wordCount || wordsCount(t.body)) + '"></div></div><label class="rc-label" style="margin-top:14px">Passage body</label><textarea class="rc-textarea" data-field="body">' + h(t.body) + '</textarea></div></section>' +
      '<section class="rc-card"><div class="rc-card-head"><div class="rc-icon">🖊</div><div class="rc-card-title">Highlighted vocabulary words</div><div class="rc-pill">' + t.words.length + ' words</div></div><div class="rc-card-body"><div class="rc-note">📌 Add each word that appears in the passage. It will be highlighted in orange for students.</div><label class="rc-label">Live preview — highlighted words as students see them</label><div class="rc-preview">' + highlight(t.body, t.words) + '</div>' +
      t.words.map((w, i) => '<div class="rc-item"><button class="rc-remove" data-action="remove-word" data-index="' + i + '">Remove</button><div class="rc-item-title">' + (i+1) + '. ' + h(w.word || 'Word card') + '</div><div class="rc-grid-2"><div><label class="rc-label">Word as it appears in text</label><input class="rc-input" data-word="' + i + '" data-prop="word" value="' + h(w.word) + '"></div><div><label class="rc-label">Correct meaning</label><input class="rc-input" data-word="' + i + '" data-prop="correct" value="' + h(w.correct) + '"></div></div><div class="rc-grid-2" style="margin-top:12px"><div><label class="rc-label">Wrong option 1</label><input class="rc-input" data-word="' + i + '" data-prop="d0" value="' + h(w.distractors[0]) + '"></div><div><label class="rc-label">Wrong option 2</label><input class="rc-input" data-word="' + i + '" data-prop="d1" value="' + h(w.distractors[1]) + '"></div></div></div>').join('') + '<button class="rc-add" data-action="add-word">＋ Add vocabulary word</button></div></section>' +
      (t.mode === 'comp' ? '<section class="rc-card"><div class="rc-card-head"><div class="rc-icon">?</div><div class="rc-card-title">Comprehension questions</div><div class="rc-pill">' + t.questions.length + ' questions</div></div><div class="rc-card-body">' + t.questions.map((q, qi) => '<div class="rc-item"><button class="rc-remove" data-action="remove-question" data-index="' + qi + '">Remove</button><div class="rc-item-title">Question ' + (qi+1) + '</div><label class="rc-label">Question</label><input class="rc-input" data-question="' + qi + '" data-prop="stem" value="' + h(q.stem) + '">' + q.options.map((op, oi) => '<div style="display:grid;grid-template-columns:42px 1fr;gap:8px;margin-top:10px"><button class="rc-btn" data-action="correct" data-question="' + qi + '" data-option="' + oi + '">' + ['A','B','C','D'][oi] + '</button><input class="rc-input" data-question="' + qi + '" data-prop="option" data-option="' + oi + '" value="' + h(op) + '"></div>').join('') + '<label class="rc-label" style="margin-top:10px">Feedback</label><input class="rc-input" data-question="' + qi + '" data-prop="feedback" value="' + h(q.feedback) + '"></div>').join('') + '<button class="rc-add" data-action="add-question">＋ Add comprehension question</button></div></section>' : '') +
      '<div class="rc-savebar"><button class="rc-btn" data-action="preview">👁 Preview as student</button><div class="rc-status">' + h(status) + '</div><button class="rc-btn rc-primary" data-action="save" ' + (saving ? 'disabled' : '') + '>' + (saving ? 'Saving...' : '💾 Save activity') + '</button></div></div></div></div>';
  }

  function playerHtml() {
    const t = text(); const isComp = t.mode === 'comp';
    const questions = isComp ? t.questions.filter(q => q.options.some(o => String(o).trim())) : t.words.filter(w => w.word.trim()).map((w, i) => ({ word: w.word, options: optionsForWord(w, i) })).filter(q => q.options.length >= 2);
    const current = questions[qIndex] || null;
    return '<div class="rc-app">' + topBar(false) + '<div class="rc-player"><div class="rc-passage"><div class="rc-zoom-bar"><button class="rc-zoom-btn" data-action="zoom-out">−</button><span class="rc-zoom-label">100%</span><button class="rc-zoom-btn" data-action="zoom-in">+</button></div><div class="rc-passage-inner"><h2 style="font-family:Fredoka,sans-serif;color:#F97316;margin-top:0">' + h(t.title || 'Untitled') + '</h2><div style="color:#9B8FCC;font-weight:900;margin-bottom:12px">' + h(t.genre) + ' · ' + h(t.wordCount || wordsCount(t.body)) + ' words</div><div>' + highlight(t.body || 'No passage text yet.', t.words) + '</div></div></div><div class="rc-quiz">' + (!current ? '<div class="rc-question">This activity is not configured yet.</div>' : '<div class="rc-question"><h2>' + (isComp ? h(current.stem) : 'What does <span style="color:#F97316">' + h(current.word) + '</span> mean?') + '</h2>' + (isComp ? current.options : current.options.map(o => o.text)).map((op, oi) => '<button class="rc-option" data-action="answer" data-index="' + oi + '">' + h(op) + '</button>').join('') + '</div>') + '</div></div></div>';
  }

  function render() { if (!root) return; root.innerHTML = preview ? playerHtml() : (window.RC_ALLOW_EDITOR ? editorHtml() : playerHtml()); if (!window.RC_ALLOW_EDITOR || preview) { setupPinch(); applyZoom(); setupPanelFullscreenRC(); } }
  function setupPanelFullscreenRC() { if (!window.initPanelFullscreen) return; var passage = root.querySelector('.rc-passage'); var quiz = root.querySelector('.rc-quiz'); if (passage) window.initPanelFullscreen(passage, { label: 'Texto en pantalla completa' }); if (quiz) window.initPanelFullscreen(quiz, { label: 'Preguntas en pantalla completa' }); }

  root.addEventListener('input', function(e) {
    const el = e.target;
    if (el.dataset.field !== undefined) {
      const field = el.dataset.field;
      const val = field === 'wordCount' ? Number(el.value) : el.value;
      Object.assign(text(), { [field]: val });
      if (field === 'title') { root.querySelectorAll('[data-field="title"]').forEach(inp => { if (inp !== el) inp.value = el.value; }); }
      if (field === 'body') refreshLivePreview();
      return;
    }
    if (el.dataset.word !== undefined) {
      const w = text().words[Number(el.dataset.word)];
      if (!w) return;
      const prop = el.dataset.prop;
      if (prop === 'd0') w.distractors[0] = el.value;
      else if (prop === 'd1') w.distractors[1] = el.value;
      else w[prop] = el.value;
      if (prop === 'word') refreshLivePreview();
      return;
    }
    if (el.dataset.question !== undefined) {
      const q = text().questions[Number(el.dataset.question)];
      if (!q) return;
      const prop = el.dataset.prop;
      if (prop === 'option') q.options[Number(el.dataset.option)] = el.value;
      else q[prop] = el.value;
      return;
    }
  });

  root.addEventListener('click', async function(e) {
    const btn = e.target.closest('[data-action]'); if (!btn) return; const action = btn.dataset.action;
    if (action === 'zoom-in') { zoomScale = Math.min(ZOOM_MAX, zoomScale + ZOOM_STEP); applyZoom(); }
    if (action === 'zoom-out') { zoomScale = Math.max(ZOOM_MIN, zoomScale - ZOOM_STEP); applyZoom(); }
    if (action === 'preview') { preview = true; render(); }
    if (action === 'mode') { patchText({ mode: btn.dataset.mode }); }
    if (action === 'add-word') { text().words.push(normalizeWord({})); render(); }
    if (action === 'remove-word') { text().words.splice(Number(btn.dataset.index), 1); render(); }
    if (action === 'add-question') { text().questions.push(normalizeQuestion({})); render(); }
    if (action === 'remove-question') { text().questions.splice(Number(btn.dataset.index), 1); render(); }
    if (action === 'correct') {
      const q = text().questions[Number(btn.dataset.question)];
      if (q) { q.correct = Number(btn.dataset.option); render(); }
    }
    if (action === 'save') {
      if (!window.RC_ACTIVITY_ID) { status = 'No activity ID - cannot save.'; render(); return; }
      if (saving) return;
      saving = true; status = 'Saving...'; render();
      try {
        const resp = await fetch('save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ id: window.RC_ACTIVITY_ID, title: state.title, texts: state.texts })
        });
        const json = await resp.json().catch(() => ({}));
        if (!resp.ok || !json.ok) throw new Error(json.error || ('HTTP ' + resp.status));
        status = 'Saved successfully';
      } catch (err) {
        status = 'Could not save: ' + err.message;
      } finally {
        saving = false; render();
      }
    }
  });
  try { render(); } catch (err) { console.error(err); }
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '📖', $content);
