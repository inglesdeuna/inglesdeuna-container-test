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
.mzke-bank-grid{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:10px}.mzke-bank-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px;width:150px;position:relative}
.mzke-bank-thumb{width:100%;height:90px;border-radius:8px;overflow:hidden;background:#f3f4f6;display:flex;align-items:center;justify-content:center;margin-bottom:8px}.mzke-bank-thumb img{width:100%;height:100%;object-fit:contain;display:block}
.mzke-bank-card input[type=text]{margin-bottom:6px;font-size:13px}.mzke-bank-card input[type=file]{font-size:11px;margin-bottom:6px}.mzke-btn-remove-bank{position:absolute;top:6px;right:6px;background:#ef4444;color:#fff;border:none;border-radius:6px;width:20px;height:20px;line-height:1;cursor:pointer;font-size:12px}
.mzke-btn-tts-small{background:#F97316;color:#fff;border:none;padding:6px 8px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:800;width:100%}.mzke-btn-add{background:#16a34a;color:#fff;padding:10px 14px;border:none;border-radius:8px;cursor:pointer;font-weight:800}.mzke-btn-save{background:linear-gradient(180deg,#7c3aed,#6d28d9);color:#fff;padding:10px 24px;border:none;border-radius:10px;cursor:pointer;font-weight:900;font-size:15px}.mzke-toolbar{display:flex;gap:10px;justify-content:center;margin-top:8px;flex-wrap:wrap}
.mzke-seq-list{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;min-height:60px;padding:10px;border:2px dashed #CDC7F3;border-radius:10px}.mzke-seq-item{background:#F8F7FF;border:1px solid #7F77DD;border-radius:8px;padding:6px 10px;font-size:12px;display:flex;align-items:center;gap:6px}.mzke-seq-item .num{font-family:'Fredoka',sans-serif;font-weight:700;color:#7F77DD}.mzke-node-thumb{width:28px;height:28px;border-radius:6px;overflow:hidden;background:#fff;flex:none;display:flex;align-items:center;justify-content:center}.mzke-node-thumb img{width:100%;height:100%;object-fit:cover;display:block}.mzke-seq-item button{background:none;border:none;color:#ef4444;cursor:pointer;font-weight:900}
.mzke-bank-picker{display:flex;flex-wrap:wrap;gap:8px}.mzke-bank-picker-item{background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:6px 10px;font-size:12px;cursor:pointer;display:flex;align-items:center;gap:6px}.mzke-bank-picker-item:hover{border-color:#7F77DD}.mzke-bank-picker-item .mzke-node-thumb{width:24px;height:24px}
.mzke-branch-item{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin-bottom:8px;display:flex;gap:10px;align-items:end;flex-wrap:wrap}.mzke-branch-item select{margin-bottom:0;min-width:220px}.mzke-branch-item button{background:#ef4444;color:#fff;border:none;padding:8px 10px;border-radius:8px;cursor:pointer;font-weight:800}
#mzkePreviewWrap{overflow:auto;background:#F8F7FF;border-radius:16px;padding:16px;border:1px solid #EDE9FA;min-height:280px}#mzkePreviewWrap svg{display:block;margin:0 auto;max-width:100%;height:auto}.mzke-preview-node{cursor:grab}.mzke-preview-node:active{cursor:grabbing}.mzke-preview-note{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px;color:#6b7280;font-size:12px;font-weight:800}.mzke-reset-layout{background:#7F77DD;color:#fff;border:none;border-radius:8px;padding:8px 12px;font-weight:900;cursor:pointer}
@media(max-width:760px){.mzke-row2{grid-template-columns:1fr}.mzke-branch-item select{min-width:100%}}
</style>

<form method="post" enctype="multipart/form-data" class="mzke-wrap" id="mzkeForm">
<div class="mzke-section"><h3>1. Activity details</h3><label>Activity title</label><input type="text" name="activity_title" value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" required><div class="mzke-row2"><div><label>Theme</label><input type="text" name="theme" value="<?= htmlspecialchars($activityTheme, ENT_QUOTES, 'UTF-8') ?>"></div><div><label>Difficulty</label><select id="mzke_difficulty" name="difficulty"><option value="easy"<?= $activityDifficulty==='easy'?' selected':'' ?>>Easy</option><option value="medium"<?= $activityDifficulty==='medium'?' selected':'' ?>>Medium</option><option value="hard"<?= $activityDifficulty==='hard'?' selected':'' ?>>Hard</option></select></div></div><button type="button" class="mzke-btn-tts-small" style="width:auto;padding:8px 14px" onclick="mzkeAutoSuggestBranches()">Auto-suggest dead ends</button><button type="button" class="mzke-btn-tts-small" style="width:auto;padding:8px 14px;margin-left:8px" onclick="mzkeRandomizePathOrder()">🎲 Randomize path order</button></div>

<div class="mzke-section"><h3>2. Vocabulary bank</h3><p class="mzke-help">Upload images and write the word. These become the maze pictures.</p><div class="mzke-bank-grid" id="mzkeBankGrid"><?php foreach ($vocabularyBank as $item): ?><div class="mzke-bank-card" data-vocab-id="<?= htmlspecialchars($item['id'],ENT_QUOTES,'UTF-8') ?>"><button type="button" class="mzke-btn-remove-bank" onclick="mzkeRemoveBank(this)">x</button><input type="hidden" name="bank_id[]" value="<?= htmlspecialchars($item['id'],ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="bank_image_existing[]" class="mzke-img-existing" value="<?= htmlspecialchars($item['image_url'],ENT_QUOTES,'UTF-8') ?>"><div class="mzke-bank-thumb"><?php if (!empty($item['image_url'])): ?><img src="<?= htmlspecialchars($item['image_url'],ENT_QUOTES,'UTF-8') ?>" alt=""><?php endif; ?></div><input type="file" name="bank_image_upload[]" accept="image/*" onchange="mzkePrevImg(this)"><input type="text" name="bank_word[]" value="<?= htmlspecialchars($item['word'],ENT_QUOTES,'UTF-8') ?>" placeholder="word" onchange="mzkeSyncPicker()"><input type="hidden" name="bank_audio[]" class="mzke-audio-hidden" value="<?= htmlspecialchars($audioUrls[$item['id']] ?? '',ENT_QUOTES,'UTF-8') ?>"><button type="button" class="mzke-btn-tts-small" onclick="mzkeGenAudio(this)">Audio</button></div><?php endforeach; ?></div><div class="mzke-toolbar"><button type="button" class="mzke-btn-add" onclick="mzkeAddBank()">+ Add Image/Word</button></div></div>

<div class="mzke-section"><h3>3. Path sequence</h3><p class="mzke-help">Click the images below in the correct order. Children will tap them in this order.</p><div class="mzke-seq-list" id="mzkeSeqList"></div><div class="mzke-bank-picker" id="mzkeSeqPicker"></div></div>
<div class="mzke-section"><h3>4. Distractor branches / wall animals</h3><p class="mzke-help">Choose which picture is a dead end and after which correct node it connects. Each wall now draws two blockages (a mid-wall barrier plus the dead-end picture) so it reads as a real obstacle instead of a single stray tile. Add a few walls spread across different nodes so children have to think at more than one turn.</p><div id="mzkeBranchList"></div><div class="mzke-toolbar"><button type="button" class="mzke-btn-add" onclick="mzkeAddBranch()">+ Add Dead End</button></div></div>
<div class="mzke-section"><h3>5. Live preview - drag the pictures to make the grid</h3><div class="mzke-preview-note"><span>Drag any circle to place the maze horizontally. Click Save to store the custom grid.</span><button type="button" class="mzke-reset-layout" onclick="mzkeResetLayout()">Reset auto layout</button></div><div id="mzkePreviewWrap"></div></div>
<div class="mzke-toolbar"><button type="submit" class="mzke-btn-save">Save</button></div>
<input type="hidden" name="layout_positions_json" id="mzkeLayoutPositionsInput">
<div id="mzkePathInputs"></div><div id="mzkeBranchInputs"></div>
</form>

<script src="maze_layout.js"></script>
<script>
let mzkeBank = <?php echo json_encode($vocabularyBank, JSON_UNESCAPED_UNICODE); ?>;
let mzkePath = <?php echo json_encode($pathSequence, JSON_UNESCAPED_UNICODE); ?>;
let mzkeBranches = <?php echo json_encode($distractorBranches, JSON_UNESCAPED_UNICODE); ?>;
let mzkePositions = <?php echo json_encode((object)$layoutPositions, JSON_UNESCAPED_UNICODE); ?> || {};
let mzkeLastLayout = null;

function mzkeReadBankFromDom(){const cards=document.querySelectorAll('#mzkeBankGrid .mzke-bank-card');const bank=[];cards.forEach(card=>{const id=card.getAttribute('data-vocab-id');const word=card.querySelector('input[name="bank_word[]"]').value.trim();const img=card.querySelector('.mzke-img-existing').value.trim();bank.push({id:id,word:word,image_url:img});});mzkeBank=bank;return bank;}
function mzkeBankById(id){return mzkeBank.find(b=>b.id===id)||{word:'',image_url:''};}
function mzkeEscape(s){return String(s||'').replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));}
function mzkeCleanPositions(){const keep={};mzkePath.forEach((v,i)=>{const k='path_'+i;if(mzkePositions[k])keep[k]=mzkePositions[k];});mzkeBranches.forEach((b,i)=>{const k='branch_'+i;if(mzkePositions[k])keep[k]=mzkePositions[k];});mzkePositions=keep;document.getElementById('mzkeLayoutPositionsInput').value=JSON.stringify(mzkePositions);}
function mzkeResetLayout(){mzkePositions={};mzkeRenderPreview();}

function mzkeAddBank(){const id='mzk_'+Date.now()+Math.floor(Math.random()*1000);const div=document.createElement('div');div.className='mzke-bank-card';div.setAttribute('data-vocab-id',id);div.innerHTML=`<button type="button" class="mzke-btn-remove-bank" onclick="mzkeRemoveBank(this)">x</button><input type="hidden" name="bank_id[]" value="${id}"><input type="hidden" name="bank_image_existing[]" class="mzke-img-existing" value=""><div class="mzke-bank-thumb"></div><input type="file" name="bank_image_upload[]" accept="image/*" onchange="mzkePrevImg(this)"><input type="text" name="bank_word[]" value="" placeholder="word" onchange="mzkeSyncPicker()"><input type="hidden" name="bank_audio[]" class="mzke-audio-hidden" value=""><button type="button" class="mzke-btn-tts-small" onclick="mzkeGenAudio(this)">Audio</button>`;document.getElementById('mzkeBankGrid').appendChild(div);mzkeSyncPicker();}
function mzkeRemoveBank(btn){const card=btn.closest('.mzke-bank-card');const id=card.getAttribute('data-vocab-id');card.remove();mzkePath=mzkePath.filter(v=>v!==id);mzkeBranches=mzkeBranches.filter(b=>b.vocabulary_id!==id);mzkeSyncAll();}
function mzkePrevImg(fileInput){const card=fileInput.closest('.mzke-bank-card');const thumb=card.querySelector('.mzke-bank-thumb');const hidden=card.querySelector('.mzke-img-existing');const file=fileInput.files[0];if(file){const reader=new FileReader();reader.onload=e=>{thumb.innerHTML=`<img src="${e.target.result}" alt="">`;hidden.value=e.target.result;mzkeSyncPicker();};reader.readAsDataURL(file);}else{mzkeSyncPicker();}}
function mzkeGenAudio(btn){alert('Audio generation is unchanged. Save the activity after setting words/images.');}

function mzkeSyncPicker(){mzkeReadBankFromDom();const picker=document.getElementById('mzkeSeqPicker');picker.innerHTML='';mzkeBank.forEach(item=>{if(!item.word&&!item.image_url)return;const btn=document.createElement('button');btn.type='button';btn.className='mzke-bank-picker-item';btn.innerHTML=`<span class="mzke-node-thumb">${item.image_url?`<img src="${mzkeEscape(item.image_url)}" alt="">`:''}</span><span>${mzkeEscape(item.word||'(no word)')}</span>`;btn.onclick=()=>{mzkePath.push(item.id);mzkeSyncAll();};picker.appendChild(btn);});mzkeRenderSeqList();mzkeRenderBranches();mzkeRenderPreview();}
function mzkeRenderSeqList(){const list=document.getElementById('mzkeSeqList');list.innerHTML='';mzkePath.forEach((vid,idx)=>{const b=mzkeBankById(vid);const el=document.createElement('div');el.className='mzke-seq-item';el.innerHTML=`<span class="num">${idx+1}.</span>${b.image_url?`<span class="mzke-node-thumb"><img src="${mzkeEscape(b.image_url)}" alt=""></span>`:''}<span>${mzkeEscape(b.word||vid)}</span><button type="button">x</button>`;el.querySelector('button').onclick=()=>{mzkePath.splice(idx,1);mzkeSyncAll();};list.appendChild(el);});const inputs=document.getElementById('mzkePathInputs');inputs.innerHTML='';mzkePath.forEach(vid=>{const inp=document.createElement('input');inp.type='hidden';inp.name='path_vocabulary_id[]';inp.value=vid;inputs.appendChild(inp);});}
function mzkeRandomizePathOrder(){mzkeReadBankFromDom();if(!mzkePath.length){alert('Add at least one node to the path sequence first.');return;}mzkePath=(window.mzkShuffleArray?window.mzkShuffleArray(mzkePath):mzkePath.slice());mzkeRenderSeqList();mzkeRenderPreview();}
function mzkeAutoSuggestBranches(){const suggested={easy:0,medium:2,hard:4}[document.getElementById('mzke_difficulty').value]||0;mzkeReadBankFromDom();const used=new Set(mzkePath.concat(mzkeBranches.map(b=>b.vocabulary_id)));const available=mzkeBank.filter(item=>(item.word||item.image_url)&&!used.has(item.id));while(mzkeBranches.length<suggested&&available.length){const item=available.shift();mzkeBranches.push({attach_after_index:mzkePath.length?Math.min(mzkePath.length-1,mzkeBranches.length%mzkePath.length):0,vocabulary_id:item.id});}mzkeRenderBranches();mzkeRenderPreview();}
function mzkeAddBranch(){if(!mzkePath.length){alert('Add at least one path node first.');return;}mzkeBranches.push({attach_after_index:0,vocabulary_id:''});mzkeRenderBranches();mzkeRenderPreview();}
function mzkeRenderBranches(){const wrap=document.getElementById('mzkeBranchList');wrap.innerHTML='';mzkeBranches.forEach((br,idx)=>{const row=document.createElement('div');row.className='mzke-branch-item';const after=document.createElement('select');mzkePath.forEach((vid,i)=>{const b=mzkeBankById(vid);const opt=document.createElement('option');opt.value=i;opt.textContent='After node '+(i+1)+' ('+(b.word||vid)+')';if(i===br.attach_after_index)opt.selected=true;after.appendChild(opt);});after.onchange=()=>{br.attach_after_index=parseInt(after.value,10)||0;mzkeRenderPreview();};const vocab=document.createElement('select');mzkeBank.forEach(item=>{if(!item.word&&!item.image_url)return;const opt=document.createElement('option');opt.value=item.id;opt.textContent=item.word||item.id;if(item.id===br.vocabulary_id)opt.selected=true;vocab.appendChild(opt);});vocab.onchange=()=>{br.vocabulary_id=vocab.value;mzkeRenderPreview();};const rem=document.createElement('button');rem.type='button';rem.textContent='Remove';rem.onclick=()=>{mzkeBranches.splice(idx,1);mzkeSyncAll();};row.appendChild(after);row.appendChild(vocab);row.appendChild(rem);wrap.appendChild(row);});const inputs=document.getElementById('mzkeBranchInputs');inputs.innerHTML='';mzkeBranches.forEach(br=>{const a=document.createElement('input');a.type='hidden';a.name='branch_after_index[]';a.value=br.attach_after_index;const v=document.createElement('input');v.type='hidden';v.name='branch_vocabulary_id[]';v.value=br.vocabulary_id;inputs.appendChild(a);inputs.appendChild(v);});}
function mzkeSvgPoint(svg,evt){const pt=svg.createSVGPoint();pt.x=evt.clientX;pt.y=evt.clientY;return pt.matrixTransform(svg.getScreenCTM().inverse());}
function mzkeAttachDrag(svg,g,node){g.classList.add('mzke-preview-node');g.addEventListener('pointerdown',evt=>{evt.preventDefault();g.setPointerCapture(evt.pointerId);const move=e=>{const p=mzkeSvgPoint(svg,e);const rawX=p.x-(mzkeLastLayout.offsetX||0);const rawY=p.y-(mzkeLastLayout.offsetY||0);mzkePositions[node.id]={x:Math.round(rawX/10)*10,y:Math.round(rawY/10)*10};mzkeRenderPreview();};const up=e=>{try{g.releasePointerCapture(evt.pointerId);}catch(err){}g.removeEventListener('pointermove',move);g.removeEventListener('pointerup',up);};g.addEventListener('pointermove',move);g.addEventListener('pointerup',up);});}
function mzkeRenderPreview(){const wrap=document.getElementById('mzkePreviewWrap');wrap.innerHTML='';mzkeReadBankFromDom();mzkeCleanPositions();if(!mzkePath.length){wrap.textContent='Add nodes to the path sequence to see a preview.';return;}const layout=generateMazeLayout(mzkePath,mzkeBranches,mzkePositions);mzkeLastLayout=layout;const NS='http://www.w3.org/2000/svg';const svg=document.createElementNS(NS,'svg');svg.setAttribute('viewBox','0 0 '+layout.width+' '+layout.height);svg.setAttribute('width',Math.min(layout.width,900));svg.setAttribute('height',layout.height*(Math.min(layout.width,900)/layout.width));function path(d,stroke,w,dash,op){const p=document.createElementNS(NS,'path');p.setAttribute('d',d);p.setAttribute('fill','none');p.setAttribute('stroke',stroke);p.setAttribute('stroke-width',w);p.setAttribute('stroke-linecap','round');p.setAttribute('stroke-linejoin','round');if(dash)p.setAttribute('stroke-dasharray',dash);if(op)p.setAttribute('opacity',op);svg.appendChild(p);}path(layout.wallPathD,'#CDC7F3',34,'',0.35);path(layout.corridorPathD,'#7F77DD',3,'2 10',0.55);if(layout.branchPathD){path(layout.branchPathD,'#FCA5A5',16,'',0.22);path(layout.branchPathD,'#FCA5A5',3,'4 6',0.85);}(layout.branchBlockers||[]).forEach(pt=>{const cap=document.createElementNS(NS,'g');cap.setAttribute('transform','translate('+pt.x+','+pt.y+')');const c=document.createElementNS(NS,'circle');c.setAttribute('r',9);c.setAttribute('fill','#fff');c.setAttribute('stroke','#FCA5A5');c.setAttribute('stroke-width','3');c.setAttribute('stroke-dasharray','3 3');cap.appendChild(c);const t=document.createElementNS(NS,'text');t.setAttribute('x',0);t.setAttribute('y',4);t.setAttribute('text-anchor','middle');t.setAttribute('font-size','11');t.setAttribute('font-weight','900');t.setAttribute('fill','#FCA5A5');t.textContent='x';cap.appendChild(t);svg.appendChild(cap);});const R=30;layout.nodes.forEach(node=>{const b=mzkeBankById(node.vocabularyId);const isStart=node.kind==='path'&&node.index===0;const isEnd=node.kind==='path'&&node.index===mzkePath.length-1;const g=document.createElementNS(NS,'g');g.setAttribute('transform','translate('+node.x+','+node.y+')');const c=document.createElementNS(NS,'circle');c.setAttribute('r',R);c.setAttribute('fill','#fff');c.setAttribute('stroke',node.kind==='branch'?'#FCA5A5':(isStart?'#F97316':(isEnd?'#16a34a':'#7F77DD')));c.setAttribute('stroke-width','3');g.appendChild(c);if(b.image_url){const img=document.createElementNS(NS,'image');img.setAttributeNS('http://www.w3.org/1999/xlink','href',b.image_url);img.setAttribute('href',b.image_url);img.setAttribute('x',-R+5);img.setAttribute('y',-R+5);img.setAttribute('width',(R-5)*2);img.setAttribute('height',(R-5)*2);img.setAttribute('clip-path','circle('+(R-5)+'px)');img.setAttribute('preserveAspectRatio','xMidYMid slice');g.appendChild(img);}const num=document.createElementNS(NS,'text');num.setAttribute('x',R-7);num.setAttribute('y',-R+11);num.setAttribute('text-anchor','middle');num.setAttribute('font-size','13');num.setAttribute('font-family','Fredoka, sans-serif');num.setAttribute('font-weight','700');num.setAttribute('fill','#7F77DD');num.textContent=node.kind==='path'?String(node.index+1):'x';g.appendChild(num);if(b.word){const label=document.createElementNS(NS,'text');label.setAttribute('x',0);label.setAttribute('y',R+14);label.setAttribute('text-anchor','middle');label.setAttribute('font-size','10');label.setAttribute('font-family','Nunito, sans-serif');label.setAttribute('font-weight','800');label.setAttribute('fill','#534AB7');label.textContent=b.word;g.appendChild(label);}mzkeAttachDrag(svg,g,node);svg.appendChild(g);});wrap.appendChild(svg);document.getElementById('mzkeLayoutPositionsInput').value=JSON.stringify(mzkePositions);}
function mzkeSyncAll(){mzkeSyncPicker();}
document.addEventListener('DOMContentLoaded',()=>{mzkeSyncAll();});
document.getElementById('mzkeForm').addEventListener('submit',e=>{mzkeCleanPositions();if(!mzkePath.length){e.preventDefault();alert('Add at least one node to the path sequence before saving.');document.getElementById('mzkeSeqPicker').scrollIntoView({behavior:'smooth',block:'center'});}});
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Maze (Kids) Editor', 'fa-solid fa-route', $content);
