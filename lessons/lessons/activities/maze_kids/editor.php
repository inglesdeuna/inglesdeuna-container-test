<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';
$source     = isset($_GET['source']) ? trim((string)$_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string)$_GET['assignment']) : '';

function mzk_ed_default(): array {
    return [
        'title' => 'Vocabulary Maze',
        'theme' => '',
        'difficulty' => 'medium',
        'vocabulary_bank' => [],
        'path_sequence' => [],
        'distractor_branches' => [],
        'layout_positions' => [],
        'audio_urls' => [],
    ];
}

function mzk_ed_clean_positions($raw): array {
    $positions = [];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) return $positions;
    foreach ($raw as $key => $pos) {
        $key = (string)$key;
        if (!preg_match('/^(path|branch)_\d+$/', $key)) continue;
        if (!is_array($pos)) continue;
        $x = isset($pos['x']) ? (float)$pos['x'] : null;
        $y = isset($pos['y']) ? (float)$pos['y'] : null;
        if ($x === null || $y === null || !is_finite($x) || !is_finite($y)) continue;
        $positions[$key] = [
            'x' => max(-2000, min(3000, round($x, 2))),
            'y' => max(-2000, min(3000, round($y, 2))),
        ];
    }
    return $positions;
}

function mzk_ed_norm($raw): array {
    $df = mzk_ed_default();
    if ($raw === null || $raw === '') return $df;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $df;

    $bank = [];
    foreach (($d['vocabulary_bank'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $word = trim((string)($item['word'] ?? ''));
        $img  = trim((string)($item['image_url'] ?? ''));
        if ($word === '' && $img === '') continue;
        $bank[] = [
            'id' => trim((string)($item['id'] ?? uniqid('mzk_'))) ?: uniqid('mzk_'),
            'image_url' => $img,
            'word' => $word,
        ];
    }
    $bankIds = array_column($bank, 'id');

    $pathSequence = [];
    foreach (($d['path_sequence'] ?? []) as $vid) {
        $vid = trim((string)$vid);
        if ($vid !== '' && in_array($vid, $bankIds, true)) $pathSequence[] = $vid;
    }
    if (count($pathSequence) === 0 && count($bankIds) > 0) $pathSequence = $bankIds;

    $branches = [];
    foreach (($d['distractor_branches'] ?? []) as $br) {
        if (!is_array($br)) continue;
        $vid = trim((string)($br['vocabulary_id'] ?? ''));
        if ($vid === '' || !in_array($vid, $bankIds, true)) continue;
        $after = (int)($br['attach_after_index'] ?? 0);
        $after = max(0, min(max(0, count($pathSequence) - 1), $after));
        $branches[] = ['attach_after_index' => $after, 'vocabulary_id' => $vid];
    }

    $audioUrls = [];
    if (is_array($d['audio_urls'] ?? null)) {
        foreach ($d['audio_urls'] as $vid => $url) {
            $vid = trim((string)$vid);
            $url = trim((string)$url);
            if ($vid !== '' && $url !== '') $audioUrls[$vid] = $url;
        }
    }

    $difficulty = trim((string)($d['difficulty'] ?? 'medium'));
    if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) $difficulty = 'medium';

    return [
        'title' => trim((string)($d['title'] ?? '')) ?: 'Vocabulary Maze',
        'theme' => trim((string)($d['theme'] ?? '')),
        'difficulty' => $difficulty,
        'vocabulary_bank' => $bank,
        'path_sequence' => $pathSequence,
        'distractor_branches' => $branches,
        'layout_positions' => mzk_ed_clean_positions($d['layout_positions'] ?? []),
        'audio_urls' => $audioUrls,
    ];
}

function mzk_ed_enc(array $p): string {
    return json_encode([
        'title' => trim((string)($p['title'] ?? '')) ?: 'Vocabulary Maze',
        'theme' => trim((string)($p['theme'] ?? '')),
        'difficulty' => in_array($p['difficulty'] ?? 'medium', ['easy', 'medium', 'hard'], true) ? $p['difficulty'] : 'medium',
        'vocabulary_bank' => array_values($p['vocabulary_bank'] ?? []),
        'path_sequence' => array_values($p['path_sequence'] ?? []),
        'distractor_branches' => array_values($p['distractor_branches'] ?? []),
        'layout_positions' => (object)mzk_ed_clean_positions($p['layout_positions'] ?? []),
        'audio_urls' => (object)($p['audio_urls'] ?? []),
    ], JSON_UNESCAPED_UNICODE);
}

function mzk_ed_resolve_unit(PDO $pdo, string $id): string {
    if ($id === '') return '';
    $st = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $st->execute(['id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r && isset($r['unit_id']) ? (string)$r['unit_id'] : '';
}

function mzk_ed_load(PDO $pdo, string $unit, string $id): array {
    $fb = array_merge(['id' => ''], mzk_ed_default());
    $row = null;
    if ($id !== '') {
        $st = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'maze_kids' LIMIT 1");
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $st = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'maze_kids' ORDER BY id ASC LIMIT 1");
        $st->execute(['unit' => $unit]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fb;
    return array_merge(['id' => (string)($row['id'] ?? '')], mzk_ed_norm($row['data'] ?? null));
}

function mzk_ed_save(PDO $pdo, string $unit, string $id, array $payload): string {
    $json = mzk_ed_enc($payload);
    $tid = $id;
    if ($tid === '') {
        $st = $pdo->prepare("SELECT id FROM activities WHERE unit_id = :unit AND type = 'maze_kids' ORDER BY id ASC LIMIT 1");
        $st->execute(['unit' => $unit]);
        $tid = trim((string)$st->fetchColumn());
    }
    if ($tid !== '') {
        $st = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'maze_kids'");
        $st->execute(['data' => $json, 'id' => $tid]);
        return $tid;
    }
    $st = $pdo->prepare("INSERT INTO activities(unit_id, type, data, position, created_at) VALUES(:unit, 'maze_kids', :data, (SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id = :unit2), CURRENT_TIMESTAMP) RETURNING id");
    $st->execute(['unit' => $unit, 'unit2' => $unit, 'data' => $json]);
    return (string)$st->fetchColumn();
}

if ($unit === '' && $activityId !== '') $unit = mzk_ed_resolve_unit($pdo, $activityId);
if ($unit === '') die('Unit not specified');

$activity = mzk_ed_load($pdo, $unit, $activityId);
if ($activityId === '' && !empty($activity['id'])) $activityId = $activity['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = trim((string)($_POST['activity_title'] ?? ''));
    $postedTheme = trim((string)($_POST['theme'] ?? ''));
    $postedDifficulty = trim((string)($_POST['difficulty'] ?? 'medium'));
    if (!in_array($postedDifficulty, ['easy', 'medium', 'hard'], true)) $postedDifficulty = 'medium';

    $bankIds = is_array($_POST['bank_id'] ?? null) ? $_POST['bank_id'] : [];
    $bankWords = is_array($_POST['bank_word'] ?? null) ? $_POST['bank_word'] : [];
    $bankImgExist = is_array($_POST['bank_image_existing'] ?? null) ? $_POST['bank_image_existing'] : [];
    $bankImgUpload = isset($_FILES['bank_image_upload']) ? $_FILES['bank_image_upload'] : ['tmp_name' => [], 'error' => []];
    $bankAudio = is_array($_POST['bank_audio'] ?? null) ? $_POST['bank_audio'] : [];

    $bank = [];
    foreach ($bankWords as $i => $rw) {
        $word = trim((string)$rw);
        $imgUrl = trim((string)($bankImgExist[$i] ?? ''));
        $tmpName = $bankImgUpload['tmp_name'][$i] ?? '';
        $imgErr = $bankImgUpload['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($tmpName !== '' && $imgErr === UPLOAD_ERR_OK) {
            $uploaded = upload_to_cloudinary($tmpName);
            if ($uploaded) $imgUrl = $uploaded;
        }
        if ($word === '' && $imgUrl === '') continue;
        $id = trim((string)($bankIds[$i] ?? uniqid('mzk_'))) ?: uniqid('mzk_');
        $bank[] = ['id' => $id, 'image_url' => $imgUrl, 'word' => $word];
    }
    $bankIdSet = array_column($bank, 'id');

    $audioUrls = [];
    foreach ($bankAudio as $i => $url) {
        $url = trim((string)$url);
        $id = trim((string)($bankIds[$i] ?? ''));
        if ($id !== '' && $url !== '' && in_array($id, $bankIdSet, true)) $audioUrls[$id] = $url;
    }

    $pathSequence = [];
    $pathIds = is_array($_POST['path_vocabulary_id'] ?? null) ? $_POST['path_vocabulary_id'] : [];
    foreach ($pathIds as $vid) {
        $vid = trim((string)$vid);
        if ($vid !== '' && in_array($vid, $bankIdSet, true)) $pathSequence[] = $vid;
    }

    $branches = [];
    $branchAfter = is_array($_POST['branch_after_index'] ?? null) ? $_POST['branch_after_index'] : [];
    $branchVocab = is_array($_POST['branch_vocabulary_id'] ?? null) ? $_POST['branch_vocabulary_id'] : [];
    foreach ($branchVocab as $i => $vid) {
        $vid = trim((string)$vid);
        if ($vid === '' || !in_array($vid, $bankIdSet, true)) continue;
        $after = (int)($branchAfter[$i] ?? 0);
        $after = max(0, min(max(0, count($pathSequence) - 1), $after));
        $branches[] = ['attach_after_index' => $after, 'vocabulary_id' => $vid];
    }

    $payload = [
        'title' => $postedTitle,
        'theme' => $postedTheme,
        'difficulty' => $postedDifficulty,
        'vocabulary_bank' => $bank,
        'path_sequence' => $pathSequence,
        'distractor_branches' => $branches,
        'layout_positions' => mzk_ed_clean_positions($_POST['layout_positions_json'] ?? ''),
        'audio_urls' => $audioUrls,
    ];

    $sid = mzk_ed_save($pdo, $unit, $activityId, $payload);
    $pr = ['unit=' . urlencode($unit), 'saved=1'];
    if ($sid !== '') $pr[] = 'id=' . urlencode($sid);
    if ($assignment !== '') $pr[] = 'assignment=' . urlencode($assignment);
    if ($source !== '') $pr[] = 'source=' . urlencode($source);
    header('Location: editor.php?' . implode('&', $pr));
    exit;
}

$activityTitle = $activity['title'];
$activityTheme = $activity['theme'];
$activityDifficulty = $activity['difficulty'];
$vocabularyBank = $activity['vocabulary_bank'];
$pathSequence = $activity['path_sequence'];
$distractorBranches = $activity['distractor_branches'];
$layoutPositions = $activity['layout_positions'];
$audioUrls = $activity['audio_urls'];

ob_start();
if (isset($_GET['saved'])) echo '<p style="color:#16a34a;font-weight:800;margin-bottom:15px">Saved successfully</p>';
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<style>
.mzke-wrap{max-width:1120px;margin:0 auto;font-family:'Nunito',sans-serif}
.mzke-section{background:#f9fafb;padding:18px;margin-bottom:16px;border-radius:12px;border:1px solid #e5e7eb}
.mzke-section h3{margin:0 0 12px;font-family:'Fredoka',sans-serif;font-size:16px;color:#7F77DD}
.mzke-section label{display:block;font-weight:800;margin-bottom:6px;font-size:13px;color:#374151}
.mzke-section input[type=text],.mzke-section select{width:100%;padding:9px 12px;border-radius:8px;border:1px solid #d1d5db;box-sizing:border-box;margin-bottom:12px;font-size:14px;font-family:inherit}
.mzke-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}.mzke-help{margin:-6px 0 10px;color:#6b7280;font-size:12px;font-weight:700}
.mzke-count-row{display:flex;gap:16px;flex-wrap:wrap;align-items:end;margin-bottom:6px}.mzke-count-field{min-width:190px}.mzke-count-field input{margin-bottom:0}.mzke-btn-update{background:#7F77DD;color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:900;font-size:13px}
.mzke-bank-grid{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:10px}.mzke-bank-card{background:#fff;border:2px solid #e5e7eb;border-radius:10px;padding:10px;width:150px;position:relative}.mzke-bank-card.path{border-color:#CDC7F3}.mzke-bank-card.branch{border-color:#FCA5A5}.mzke-bank-card.empty{border-style:dashed}
.mzke-slot-remove{position:absolute;top:6px;right:6px;background:#9ca3af;color:#fff;border:none;border-radius:999px;width:20px;height:20px;line-height:20px;text-align:center;font-size:12px;font-weight:900;cursor:pointer;padding:0}.mzke-slot-remove:hover{background:#ef4444}
.mzke-slot-tag{position:absolute;top:6px;left:6px;background:#7F77DD;color:#fff;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:900;font-family:'Fredoka',sans-serif}.mzke-bank-card.branch .mzke-slot-tag{background:#ef4444}
.mzke-bank-thumb{width:100%;height:90px;border-radius:8px;overflow:hidden;background:#f3f4f6;display:flex;align-items:center;justify-content:center;margin-bottom:8px;margin-top:14px}.mzke-bank-thumb img{width:100%;height:100%;object-fit:contain;display:block}
.mzke-bank-card input[type=text]{margin-bottom:6px;font-size:13px}.mzke-bank-card input[type=file]{font-size:11px;margin-bottom:6px}
.mzke-btn-tts-small{background:#F97316;color:#fff;border:none;padding:6px 8px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:800;width:100%}.mzke-btn-save{background:linear-gradient(180deg,#7c3aed,#6d28d9);color:#fff;padding:10px 24px;border:none;border-radius:10px;cursor:pointer;font-weight:900;font-size:15px}.mzke-toolbar{display:flex;gap:10px;justify-content:center;margin-top:8px;flex-wrap:wrap}
.mzke-section-sub{margin:0 0 14px;color:#374151;font-size:13px;font-weight:700}.mzke-section-sub b{color:#7F77DD}
#mzkePreviewWrap{overflow:auto;background:#F8F7FF;border-radius:16px;padding:16px;border:1px solid #EDE9FA;min-height:280px}#mzkePreviewWrap svg{display:block;margin:0 auto;max-width:100%;height:auto}.mzke-preview-note{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px;color:#6b7280;font-size:12px;font-weight:800}
@media(max-width:760px){.mzke-row2{grid-template-columns:1fr}.mzke-count-row{flex-direction:column;align-items:stretch}}
</style>

<form method="post" enctype="multipart/form-data" class="mzke-wrap" id="mzkeForm">
<div class="mzke-section"><h3>1. Activity details</h3><label>Activity title</label><input type="text" name="activity_title" value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" required><div class="mzke-row2"><div><label>Theme</label><input type="text" name="theme" value="<?= htmlspecialchars($activityTheme, ENT_QUOTES, 'UTF-8') ?>"></div><div><label>Difficulty</label><select id="mzke_difficulty" name="difficulty"><option value="easy"<?= $activityDifficulty==='easy'?' selected':'' ?>>Easy</option><option value="medium"<?= $activityDifficulty==='medium'?' selected':'' ?>>Medium</option><option value="hard"<?= $activityDifficulty==='hard'?' selected':'' ?>>Hard</option></select></div></div></div>

<div class="mzke-section"><h3>2. Upload your pictures</h3><p class="mzke-section-sub">Add one picture for every word in the correct path, plus (optionally) a few "wall" pictures for wrong turns. <b>The grid always matches the number of pictures you add — there are never empty spaces.</b> The maze automatically draws a <b>start arrow</b> and a <b>home (house)</b> icon at the two ends of the path; those icons are not pictures you upload.</p><div class="mzke-bank-grid" id="mzkeBankGrid"></div><div class="mzke-toolbar" style="justify-content:flex-start"><button type="button" class="mzke-btn-update" onclick="mzkeAddSlot('path')">+ Add picture</button><button type="button" class="mzke-btn-update" style="background:#ef4444" onclick="mzkeAddSlot('branch')">+ Add wall</button></div></div>

<div class="mzke-section"><h3>3. Live preview</h3><div class="mzke-preview-note"><span>This is exactly how the maze will look to the student: real corridors, turns and dead ends carved out of the walls. It updates automatically as you fill in pictures above.</span></div><div id="mzkePreviewWrap"></div></div>
<div class="mzke-toolbar"><button type="submit" class="mzke-btn-save">Save</button></div>
<input type="hidden" name="layout_positions_json" id="mzkeLayoutPositionsInput">
<div id="mzkePathInputs"></div><div id="mzkeBranchInputs"></div>
</form>

<script src="maze_layout.js"></script>
<script>
let mzkePathSlots = [];
let mzkeBranchSlots = [];

function mzkeUid(prefix){return prefix+'_'+Date.now()+Math.floor(Math.random()*100000);}
function mzkeEscape(s){return String(s||'').replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));}

function mzkeInitSlotsFromServer(bank, path, branches, audioUrls){
  const byId = {};
  bank.forEach(item=>{byId[item.id]=item;});
  mzkePathSlots = path.map(vid=>{
    const b = byId[vid] || {word:'',image_url:''};
    return {id:vid, word:b.word||'', image_url:b.image_url||'', audio:(audioUrls&&audioUrls[vid])||''};
  });
  mzkeBranchSlots = branches.map(br=>{
    const b = byId[br.vocabulary_id] || {word:'',image_url:''};
    const id = br.vocabulary_id || mzkeUid('branch');
    return {id:id, word:b.word||'', image_url:b.image_url||'', audio:(audioUrls&&audioUrls[id])||''};
  });
}

function mzkeResizeSlots(arr, newLen, prefix){
  newLen = Math.max(0, newLen|0);
  if (arr.length > newLen){
    const removed = arr.slice(newLen);
    const hasData = removed.some(s=>s.word||s.image_url);
    if (hasData && !confirm('Some pictures already added in the removed spaces will be lost. Continue?')) return null;
    arr = arr.slice(0, newLen);
  }
  while (arr.length < newLen) arr.push({id:mzkeUid(prefix), word:'', image_url:''});
  return arr;
}

function mzkeAddSlot(kind){
  mzkeReadSlotsFromDom();
  if (kind === 'branch') mzkeBranchSlots.push({id:mzkeUid('branch'), word:'', image_url:''});
  else mzkePathSlots.push({id:mzkeUid('path'), word:'', image_url:''});
  mzkeRenderAll();
}

function mzkeRemoveSlot(id, kind){
  mzkeReadSlotsFromDom();
  const arr = kind === 'branch' ? mzkeBranchSlots : mzkePathSlots;
  const slot = arr.find(s=>s.id===id);
  if (slot && (slot.word || slot.image_url) && !confirm('This picture has content. Remove it anyway?')) return;
  const idx = arr.findIndex(s=>s.id===id);
  if (idx > -1) arr.splice(idx, 1);
  mzkeRenderAll();
}

function mzkeReadSlotsFromDom(){
  document.querySelectorAll('#mzkeBankGrid .mzke-bank-card').forEach(card=>{
    const id = card.getAttribute('data-vocab-id');
    const kind = card.getAttribute('data-kind');
    const word = card.querySelector('input[name^="bank_word"]').value.trim();
    const img = card.querySelector('.mzke-img-existing').value.trim();
    const arr = kind === 'branch' ? mzkeBranchSlots : mzkePathSlots;
    const slot = arr.find(s=>s.id===id);
    if (slot){ slot.word = word; slot.image_url = img; }
  });
}

function mzkeSlotCard(slot, kind, label){
  const div = document.createElement('div');
  div.className = 'mzke-bank-card ' + kind + (!slot.word && !slot.image_url ? ' empty' : '');
  div.setAttribute('data-vocab-id', slot.id);
  div.setAttribute('data-kind', kind);
  div.innerHTML = `<span class="mzke-slot-tag">${mzkeEscape(label)}</span>
<button type="button" class="mzke-slot-remove" title="Remove" onclick="mzkeRemoveSlot('${mzkeEscape(slot.id)}','${kind}')">&times;</button>
<input type="hidden" name="${kind==='branch'?'branch_vocabulary_id[]':'path_vocabulary_id[]'}" value="${mzkeEscape(slot.id)}">
<input type="hidden" name="bank_id[]" value="${mzkeEscape(slot.id)}">
<input type="hidden" name="bank_image_existing[]" class="mzke-img-existing" value="${mzkeEscape(slot.image_url)}">
<div class="mzke-bank-thumb">${slot.image_url?`<img src="${mzkeEscape(slot.image_url)}" alt="">`:''}</div>
<input type="file" name="bank_image_upload[]" accept="image/*" onchange="mzkePrevImg(this)">
<input type="text" name="bank_word[]" value="${mzkeEscape(slot.word)}" placeholder="word" onchange="mzkeOnWordChange(this)">
<input type="hidden" name="bank_audio[]" class="mzke-audio-hidden" value="${mzkeEscape(slot.audio||'')}">`;
  return div;
}

function mzkePrevImg(fileInput){
  const card = fileInput.closest('.mzke-bank-card');
  const thumb = card.querySelector('.mzke-bank-thumb');
  const hidden = card.querySelector('.mzke-img-existing');
  const file = fileInput.files[0];
  if (file){
    const reader = new FileReader();
    reader.onload = e => {
      thumb.innerHTML = `<img src="${e.target.result}" alt="">`;
      hidden.value = e.target.result;
      card.classList.remove('empty');
      mzkeReadSlotsFromDom();
      mzkeRenderPreview();
    };
    reader.readAsDataURL(file);
  }
}

function mzkeOnWordChange(input){
  const card = input.closest('.mzke-bank-card');
  if (input.value.trim()) card.classList.remove('empty');
  mzkeReadSlotsFromDom();
  mzkeRenderPreview();
}

function mzkeRenderBankGrid(){
  const grid = document.getElementById('mzkeBankGrid');
  grid.innerHTML = '';
  mzkePathSlots.forEach((slot, idx)=>{
    grid.appendChild(mzkeSlotCard(slot, 'path', String(idx + 1)));
  });
  mzkeBranchSlots.forEach((slot, idx)=>{
    grid.appendChild(mzkeSlotCard(slot, 'branch', 'Wall ' + (idx + 1)));
  });
  const inputs = document.getElementById('mzkePathInputs');
  inputs.innerHTML = '';
  const binputs = document.getElementById('mzkeBranchInputs');
  binputs.innerHTML = '';
  const pathCount = mzkePathSlots.length;
  const branchCount = mzkeBranchSlots.length;
  mzkeBranchSlots.forEach((slot, idx)=>{
    const attachAfter = branchCount > 0
      ? Math.min(pathCount - 1, Math.max(0, Math.floor((idx + 1) * pathCount / (branchCount + 1))))
      : 0;
    const a = document.createElement('input');
    a.type = 'hidden'; a.name = 'branch_after_index[]'; a.value = attachAfter;
    binputs.appendChild(a);
  });
}

function mzkeRenderPreview(){
  const wrap = document.getElementById('mzkePreviewWrap');
  wrap.innerHTML = '';
  document.getElementById('mzkeLayoutPositionsInput').value = '{}';
  if (!mzkePathSlots.length){ wrap.textContent = 'Add pictures above to see a preview.'; return; }
  const byId = {};
  mzkePathSlots.forEach(s=>{byId[s.id]=s;});
  mzkeBranchSlots.forEach(s=>{byId[s.id]=s;});
  const pathIds = mzkePathSlots.map(s=>s.id);
  const pathCount = pathIds.length;
  const branchCount = mzkeBranchSlots.length;
  const branches = mzkeBranchSlots.map((s, idx)=>({
    vocabulary_id: s.id,
    attach_after_index: branchCount > 0
      ? Math.min(pathCount - 1, Math.max(0, Math.floor((idx + 1) * pathCount / (branchCount + 1))))
      : 0,
  }));
  const layout = generateMazeLayout(pathIds, branches);
  const NS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '0 0 ' + layout.width + ' ' + layout.height);
  svg.setAttribute('width', Math.min(layout.width, 900));
  svg.setAttribute('height', layout.height * (Math.min(layout.width, 900) / layout.width));
  mzkRenderMazeBase(NS, svg, layout, {wallColor:'#CDC7F3', floorColor:'#ffffff', dotColor:'rgba(83,74,183,.10)'});
  const R = Math.round(layout.cellSize * 0.32);
  layout.nodes.forEach(node=>{
    const isEndpoint = node.kind === 'start' || node.kind === 'home';
    const b = isEndpoint ? {word:'',image_url:''} : (byId[node.vocabularyId] || {word:'',image_url:''});
    const g = document.createElementNS(NS, 'g');
    g.setAttribute('transform', 'translate(' + node.x + ',' + node.y + ')');
    const c = document.createElementNS(NS, 'circle');
    c.setAttribute('r', R);
    c.setAttribute('fill', '#fff');
    c.setAttribute('stroke', node.kind === 'branch' ? '#FCA5A5' : (node.kind === 'start' ? '#F97316' : (node.kind === 'home' ? '#16a34a' : '#7F77DD')));
    c.setAttribute('stroke-width', '3');
    g.appendChild(c);
    if (isEndpoint){
      g.appendChild(mzkRenderEndpointIcon(NS, node.kind));
    } else if (b.image_url){
      const img = document.createElementNS(NS, 'image');
      img.setAttributeNS('http://www.w3.org/1999/xlink', 'href', b.image_url);
      img.setAttribute('href', b.image_url);
      img.setAttribute('x', -R + 5); img.setAttribute('y', -R + 5);
      img.setAttribute('width', (R - 5) * 2); img.setAttribute('height', (R - 5) * 2);
      img.setAttribute('clip-path', 'circle(' + (R - 5) + 'px)');
      img.setAttribute('preserveAspectRatio', 'xMidYMid slice');
      g.appendChild(img);
    }
    if (!isEndpoint){
      const num = document.createElementNS(NS, 'text');
      num.setAttribute('x', R - 7); num.setAttribute('y', -R + 11); num.setAttribute('text-anchor', 'middle');
      num.setAttribute('font-size', '13'); num.setAttribute('font-family', 'Fredoka, sans-serif'); num.setAttribute('font-weight', '700');
      num.setAttribute('fill', '#7F77DD');
      num.textContent = node.kind === 'path' ? String(node.index + 1) : 'x';
      g.appendChild(num);
      if (b.word){
        const label = document.createElementNS(NS, 'text');
        label.setAttribute('x', 0); label.setAttribute('y', R + 14); label.setAttribute('text-anchor', 'middle');
        label.setAttribute('font-size', '10'); label.setAttribute('font-family', 'Nunito, sans-serif'); label.setAttribute('font-weight', '800');
        label.setAttribute('fill', '#534AB7');
        label.textContent = b.word;
        g.appendChild(label);
      }
    }
    if (isEndpoint){
      const flag = document.createElementNS(NS, 'text');
      flag.setAttribute('x', 0); flag.setAttribute('y', -R - 11); flag.setAttribute('text-anchor', 'middle');
      flag.setAttribute('font-size', '11'); flag.setAttribute('font-family', 'Nunito, sans-serif'); flag.setAttribute('font-weight', '900');
      flag.setAttribute('fill', node.kind === 'start' ? '#F97316' : '#16a34a');
      flag.textContent = node.kind === 'start' ? 'START' : 'HOME';
      g.appendChild(flag);
    }
    svg.appendChild(g);
  });
  wrap.appendChild(svg);
}

function mzkeRenderAll(){ mzkeRenderBankGrid(); mzkeRenderPreview(); }

document.addEventListener('DOMContentLoaded', ()=>{
  mzkeInitSlotsFromServer(
    <?php echo json_encode($vocabularyBank, JSON_UNESCAPED_UNICODE); ?>,
    <?php echo json_encode($pathSequence, JSON_UNESCAPED_UNICODE); ?>,
    <?php echo json_encode($distractorBranches, JSON_UNESCAPED_UNICODE); ?>,
    <?php echo json_encode($audioUrls, JSON_UNESCAPED_UNICODE); ?>
  );
  if (!mzkePathSlots.length){
    mzkePathSlots = mzkeResizeSlots(mzkePathSlots, 2, 'path') || [];
  }
  mzkeRenderAll();
});

document.getElementById('mzkeForm').addEventListener('submit', e=>{
  document.getElementById('mzkeLayoutPositionsInput').value = '{}';
  mzkeReadSlotsFromDom();
  if (!mzkePathSlots.length){
    e.preventDefault();
    alert('Set at least 2 pictures in the path before saving.');
    return;
  }
  const incomplete = mzkePathSlots.concat(mzkeBranchSlots).some(s=>!s.word && !s.image_url);
  if (incomplete && !confirm('Some spaces are still missing a picture or word. Save anyway?')) {
    e.preventDefault();
  }
});
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Maze (Kids) Editor', 'fa-solid fa-route', $content);
