<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) { header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied'); exit; }
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) { header('Location: /lessons/lessons/academic/login.php'); exit; }

$activityId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';
$source     = isset($_GET['source']) ? trim((string)$_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string)$_GET['assignment']) : '';

function mzk_ed_resolve_unit(PDO $pdo, string $id): string {
    if ($id === '') return '';
    $st = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $st->execute(['id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r && isset($r['unit_id']) ? (string)$r['unit_id'] : '';
}

function mzk_ed_default(): array {
    return ['title'=>'Vocabulary Maze','theme'=>'','difficulty'=>'medium','vocabulary_bank'=>[],'path_sequence'=>[],'distractor_branches'=>[],'audio_urls'=>[]];
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
        $bank[] = ['id'=>trim((string)($item['id'] ?? uniqid('mzk_'))) ?: uniqid('mzk_'),'image_url'=>$img,'word'=>$word];
    }
    $bankIds = array_column($bank, 'id');

    $pathSequence = [];
    foreach (($d['path_sequence'] ?? []) as $vid) {
        $vid = trim((string)$vid);
        if ($vid !== '' && in_array($vid, $bankIds, true)) $pathSequence[] = $vid;
    }

    $branches = [];
    foreach (($d['distractor_branches'] ?? []) as $br) {
        if (!is_array($br)) continue;
        $vid = trim((string)($br['vocabulary_id'] ?? ''));
        if ($vid === '' || !in_array($vid, $bankIds, true)) continue;
        $after = (int)($br['attach_after_index'] ?? 0);
        $after = max(0, min(count($pathSequence) - 1, $after));
        $branches[] = ['attach_after_index'=>$after,'vocabulary_id'=>$vid];
    }

    $audioUrls = [];
    if (is_array($d['audio_urls'] ?? null)) {
        foreach ($d['audio_urls'] as $vid => $url) {
            $vid = trim((string)$vid); $url = trim((string)$url);
            if ($vid !== '' && $url !== '') $audioUrls[$vid] = $url;
        }
    }

    $title      = trim((string)($d['title'] ?? ''));
    $theme      = trim((string)($d['theme'] ?? ''));
    $difficulty = trim((string)($d['difficulty'] ?? 'medium'));
    if (!in_array($difficulty, ['easy','medium','hard'], true)) $difficulty = 'medium';

    return [
        'title'=>$title !== '' ? $title : 'Vocabulary Maze',
        'theme'=>$theme,
        'difficulty'=>$difficulty,
        'vocabulary_bank'=>$bank,
        'path_sequence'=>$pathSequence,
        'distractor_branches'=>$branches,
        'audio_urls'=>$audioUrls,
    ];
}

function mzk_ed_enc(array $p): string {
    return json_encode([
        'title'=>trim((string)($p['title'] ?? '')) ?: 'Vocabulary Maze',
        'theme'=>trim((string)($p['theme'] ?? '')),
        'difficulty'=>in_array($p['difficulty'] ?? 'medium', ['easy','medium','hard'], true) ? $p['difficulty'] : 'medium',
        'vocabulary_bank'=>array_values($p['vocabulary_bank'] ?? []),
        'path_sequence'=>array_values($p['path_sequence'] ?? []),
        'distractor_branches'=>array_values($p['distractor_branches'] ?? []),
        'audio_urls'=>(object)($p['audio_urls'] ?? []),
    ], JSON_UNESCAPED_UNICODE);
}

function mzk_ed_load(PDO $pdo, string $unit, string $id): array {
    $fb = array_merge(['id'=>''], mzk_ed_default());
    $row = null;
    if ($id !== '') { $st = $pdo->prepare("SELECT id,data FROM activities WHERE id=:id AND type='maze_kids' LIMIT 1"); $st->execute(['id'=>$id]); $row=$st->fetch(PDO::FETCH_ASSOC); }
    if (!$row && $unit !== '') { $st = $pdo->prepare("SELECT id,data FROM activities WHERE unit_id=:u AND type='maze_kids' ORDER BY id ASC LIMIT 1"); $st->execute(['u'=>$unit]); $row=$st->fetch(PDO::FETCH_ASSOC); }
    if (!$row) return $fb;
    $p = mzk_ed_norm($row['data'] ?? null);
    return array_merge(['id'=>(string)($row['id'] ?? '')], $p);
}

function mzk_ed_save(PDO $pdo, string $unit, string $id, array $payload): string {
    $json = mzk_ed_enc($payload);
    $tid = $id;
    if ($tid === '') { $st=$pdo->prepare("SELECT id FROM activities WHERE unit_id=:u AND type='maze_kids' ORDER BY id ASC LIMIT 1"); $st->execute(['u'=>$unit]); $tid=trim((string)$st->fetchColumn()); }
    if ($tid !== '') { $st=$pdo->prepare("UPDATE activities SET data=:data WHERE id=:id AND type='maze_kids'"); $st->execute(['data'=>$json,'id'=>$tid]); return $tid; }
    $st=$pdo->prepare("INSERT INTO activities(unit_id,type,data,position,created_at) VALUES(:u,'maze_kids',:d,(SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id=:u2),CURRENT_TIMESTAMP) RETURNING id");
    $st->execute(['u'=>$unit,'u2'=>$unit,'d'=>$json]);
    return (string)$st->fetchColumn();
}

if ($unit==='' && $activityId!=='') { $unit=mzk_ed_resolve_unit($pdo,$activityId); }
if ($unit==='') die('Unit not specified');

$activity   = mzk_ed_load($pdo,$unit,$activityId);
if ($activityId==='' && !empty($activity['id'])) $activityId=$activity['id'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $postedTitle      = trim((string)($_POST['activity_title'] ?? ''));
    $postedTheme      = trim((string)($_POST['theme'] ?? ''));
    $postedDifficulty = trim((string)($_POST['difficulty'] ?? 'medium'));
    if (!in_array($postedDifficulty, ['easy','medium','hard'], true)) $postedDifficulty = 'medium';

    /* ── vocabulary bank ── */
    $bankIds       = is_array($_POST['bank_id'] ?? null) ? $_POST['bank_id'] : [];
    $bankWords     = is_array($_POST['bank_word'] ?? null) ? $_POST['bank_word'] : [];
    $bankImgExist  = is_array($_POST['bank_image_existing'] ?? null) ? $_POST['bank_image_existing'] : [];
    $bankImgUpload = isset($_FILES['bank_image_upload']) ? $_FILES['bank_image_upload'] : ['tmp_name'=>[],'error'=>[]];
    $bankAudio     = is_array($_POST['bank_audio'] ?? null) ? $_POST['bank_audio'] : [];

    $bank = [];
    foreach ($bankWords as $i => $rw) {
        $w = trim((string)$rw);
        $imgUrl = trim((string)($bankImgExist[$i] ?? ''));
        $tmpName = $bankImgUpload['tmp_name'][$i] ?? '';
        $imgErr  = $bankImgUpload['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($tmpName !== '' && $imgErr === UPLOAD_ERR_OK) { $uploaded = upload_to_cloudinary($tmpName); if ($uploaded) $imgUrl = $uploaded; }
        if ($w === '' && $imgUrl === '') continue;
        $id = trim((string)($bankIds[$i] ?? uniqid('mzk_'))) ?: uniqid('mzk_');
        $bank[] = ['id'=>$id,'image_url'=>$imgUrl,'word'=>$w];
    }
    $bankIdSet = array_column($bank, 'id');

    /* audio urls keyed by vocab id */
    $audioUrls = [];
    foreach ($bankAudio as $i => $url) {
        $url = trim((string)$url);
        $id = trim((string)($bankIds[$i] ?? ''));
        if ($id !== '' && $url !== '' && in_array($id, $bankIdSet, true)) $audioUrls[$id] = $url;
    }

    /* ── path sequence ── */
    $pathIds = is_array($_POST['path_vocabulary_id'] ?? null) ? $_POST['path_vocabulary_id'] : [];
    $pathSequence = [];
    foreach ($pathIds as $vid) {
        $vid = trim((string)$vid);
        if ($vid !== '' && in_array($vid, $bankIdSet, true)) $pathSequence[] = $vid;
    }

    /* ── distractor branches ── */
    $branchAfter = is_array($_POST['branch_after_index'] ?? null) ? $_POST['branch_after_index'] : [];
    $branchVocab = is_array($_POST['branch_vocabulary_id'] ?? null) ? $_POST['branch_vocabulary_id'] : [];
    $branches = [];
    foreach ($branchVocab as $i => $vid) {
        $vid = trim((string)$vid);
        if ($vid === '' || !in_array($vid, $bankIdSet, true)) continue;
        $after = (int)($branchAfter[$i] ?? 0);
        $after = max(0, min(count($pathSequence) - 1, $after));
        $branches[] = ['attach_after_index'=>$after,'vocabulary_id'=>$vid];
    }

    $payload = [
        'title'=>$postedTitle,
        'theme'=>$postedTheme,
        'difficulty'=>$postedDifficulty,
        'vocabulary_bank'=>$bank,
        'path_sequence'=>$pathSequence,
        'distractor_branches'=>$branches,
        'audio_urls'=>$audioUrls,
    ];

    $sid = mzk_ed_save($pdo,$unit,$activityId,$payload);
    $pr = ['unit='.urlencode($unit),'saved=1'];
    if ($sid!=='') $pr[]='id='.urlencode($sid);
    if ($assignment!=='') $pr[]='assignment='.urlencode($assignment);
    if ($source!=='') $pr[]='source='.urlencode($source);
    header('Location: editor.php?'.implode('&',$pr)); exit;
}

$activityTitle = $activity['title'];
$activityTheme = $activity['theme'];
$activityDifficulty = $activity['difficulty'];
$vocabularyBank = $activity['vocabulary_bank'];
$pathSequence = $activity['path_sequence'];
$distractorBranches = $activity['distractor_branches'];
$audioUrls = $activity['audio_urls'];

ob_start();
if (isset($_GET['saved'])) echo '<p style="color:#16a34a;font-weight:700;margin-bottom:15px">✔ Saved successfully</p>';
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@700;800" rel="stylesheet">
<style>
.mzke-wrap{max-width:1080px;margin:0 auto;font-family:'Nunito',sans-serif}
.mzke-section{background:#f9fafb;padding:18px;margin-bottom:16px;border-radius:12px;border:1px solid #e5e7eb}
.mzke-section h3{margin:0 0 12px;font-family:'Fredoka',sans-serif;font-size:16px;color:#7F77DD}
.mzke-section label{display:block;font-weight:700;margin-bottom:6px;font-size:13px;color:#374151}
.mzke-section input[type=text],.mzke-section select,.mzke-section textarea{width:100%;padding:9px 12px;border-radius:8px;border:1px solid #d1d5db;box-sizing:border-box;margin-bottom:12px;font-size:14px;font-family:inherit}
.mzke-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.mzke-help{margin:-8px 0 10px;color:#6b7280;font-size:12px}

.mzke-bank-grid{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:10px}
.mzke-bank-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px;width:150px;position:relative}
.mzke-bank-thumb{width:100%;height:90px;border-radius:8px;overflow:hidden;background:#f3f4f6;display:flex;align-items:center;justify-content:center;margin-bottom:8px}
.mzke-bank-thumb img{width:100%;height:100%;object-fit:contain;display:block}
.mzke-bank-card input[type=text]{margin-bottom:6px;font-size:13px}
.mzke-bank-card input[type=file]{font-size:11px;margin-bottom:6px}
.mzke-btn-remove-bank{position:absolute;top:6px;right:6px;background:#ef4444;color:#fff;border:none;border-radius:6px;width:20px;height:20px;line-height:1;cursor:pointer;font-size:12px}
.mzke-btn-tts-small{background:#F97316;color:#fff;border:none;padding:5px 8px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:700;width:100%}

.mzke-btn-add{background:#16a34a;color:#fff;padding:10px 14px;border:none;border-radius:8px;cursor:pointer;font-weight:700}
.mzke-btn-save{background:linear-gradient(180deg,#7c3aed,#6d28d9);color:#fff;padding:10px 24px;border:none;border-radius:10px;cursor:pointer;font-weight:800;font-size:15px}
.mzke-toolbar{display:flex;gap:10px;justify-content:center;margin-top:8px}

.mzke-seq-list{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;min-height:60px;padding:10px;border:2px dashed #CDC7F3;border-radius:10px}
.mzke-seq-item{background:#F8F7FF;border:1px solid #7F77DD;border-radius:8px;padding:6px 10px;font-size:12px;display:flex;align-items:center;gap:6px}
.mzke-seq-item .num{font-family:'Fredoka',sans-serif;font-weight:700;color:#7F77DD}
.mzke-seq-item .mzke-node-thumb{width:28px;height:28px;border-radius:6px;overflow:hidden;background:#fff;flex:none;display:flex;align-items:center;justify-content:center}
.mzke-seq-item .mzke-node-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.mzke-seq-item button{background:none;border:none;color:#ef4444;cursor:pointer;font-weight:700}
.mzke-bank-picker{display:flex;flex-wrap:wrap;gap:8px}
.mzke-bank-picker-item{background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:6px 10px;font-size:12px;cursor:pointer;display:flex;align-items:center;gap:6px}
.mzke-bank-picker-item:hover{border-color:#7F77DD}
.mzke-bank-picker-item .mzke-node-thumb{width:24px;height:24px;border-radius:6px;overflow:hidden;background:#f3f4f6;flex:none;display:flex;align-items:center;justify-content:center}
.mzke-bank-picker-item .mzke-node-thumb img{width:100%;height:100%;object-fit:cover;display:block}

.mzke-branch-item{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin-bottom:8px;display:flex;gap:10px;align-items:end;flex-wrap:wrap}
.mzke-branch-item select{margin-bottom:0}
.mzke-branch-item button{background:#ef4444;color:#fff;border:none;padding:8px 10px;border-radius:8px;cursor:pointer;font-weight:700}

#mzkePreviewWrap{overflow-x:auto;background:#F8F7FF;border-radius:16px;padding:16px}
#mzkePreviewWrap svg{display:block;margin:0 auto}
</style>

<form method="post" enctype="multipart/form-data" class="mzke-wrap" id="mzkeForm">

<div class="mzke-section">
<h3>1. Activity details</h3>
<label for="mzke_title">Activity title</label>
<input id="mzke_title" type="text" name="activity_title" value="<?= htmlspecialchars($activityTitle,ENT_QUOTES,'UTF-8') ?>" placeholder="e.g. Laberinto: Objetos del Día" required>
<div class="mzke-row2">
<div><label for="mzke_theme">Theme (free text, optional)</label><input id="mzke_theme" type="text" name="theme" value="<?= htmlspecialchars($activityTheme,ENT_QUOTES,'UTF-8') ?>" placeholder="e.g. Vida diaria"></div>
<div><label for="mzke_difficulty">Difficulty (suggests # of dead ends)</label>
<select id="mzke_difficulty" name="difficulty">
<option value="easy"<?= $activityDifficulty==='easy'?' selected':'' ?>>Easy (0 dead ends)</option>
<option value="medium"<?= $activityDifficulty==='medium'?' selected':'' ?>>Medium (2 dead ends)</option>
<option value="hard"<?= $activityDifficulty==='hard'?' selected':'' ?>>Hard (4 dead ends)</option>
</select></div>
</div>
<button type="button" class="mzke-btn-tts-small" style="width:auto;padding:8px 14px" onclick="mzkeAutoSuggestBranches()">✨ Auto-suggest dead ends for this difficulty</button>
</div>

<div class="mzke-section">
<h3>2. Vocabulary bank</h3>
<p class="mzke-help">Upload any images and their words — any topic works, there's no fixed set.</p>
<div class="mzke-bank-grid" id="mzkeBankGrid">
<?php foreach ($vocabularyBank as $item): ?>
<div class="mzke-bank-card" data-vocab-id="<?= htmlspecialchars($item['id'],ENT_QUOTES,'UTF-8') ?>">
<button type="button" class="mzke-btn-remove-bank" onclick="mzkeRemoveBank(this)">✖</button>
<input type="hidden" name="bank_id[]" value="<?= htmlspecialchars($item['id'],ENT_QUOTES,'UTF-8') ?>">
<input type="hidden" name="bank_image_existing[]" class="mzke-img-existing" value="<?= htmlspecialchars($item['image_url'],ENT_QUOTES,'UTF-8') ?>">
<div class="mzke-bank-thumb"><?php if (!empty($item['image_url'])): ?><img src="<?= htmlspecialchars($item['image_url'],ENT_QUOTES,'UTF-8') ?>" alt=""><?php endif; ?></div>
<input type="file" name="bank_image_upload[]" accept="image/*" onchange="mzkePrevImg(this)">
<input type="text" name="bank_word[]" value="<?= htmlspecialchars($item['word'],ENT_QUOTES,'UTF-8') ?>" placeholder="word" onchange="mzkeSyncPicker()">
<input type="hidden" name="bank_audio[]" class="mzke-audio-hidden" value="<?= htmlspecialchars($audioUrls[$item['id']] ?? '',ENT_QUOTES,'UTF-8') ?>">
<button type="button" class="mzke-btn-tts-small" onclick="mzkeGenAudio(this)">🔊 Audio</button>
</div>
<?php endforeach; ?>
</div>
<div class="mzke-toolbar"><button type="button" class="mzke-btn-add" onclick="mzkeAddBank()">+ Add Image/Word</button></div>
</div>

<div class="mzke-section">
<h3>3. Path sequence (correct order through the maze)</h3>
<p class="mzke-help">Click images from the bank below to add them, in order, to the path.</p>
<div class="mzke-seq-list" id="mzkeSeqList"></div>
<div class="mzke-bank-picker" id="mzkeSeqPicker"></div>
</div>

<div class="mzke-section">
<h3>4. Distractor branches (dead ends)</h3>
<p class="mzke-help">Choose after which path node a dead end appears, and which bank image it uses.</p>
<div id="mzkeBranchList"></div>
<div class="mzke-toolbar"><button type="button" class="mzke-btn-add" onclick="mzkeAddBranch()">+ Add Dead End</button></div>
</div>

<div class="mzke-section">
<h3>5. Live preview</h3>
<div id="mzkePreviewWrap"></div>
</div>

<div class="mzke-toolbar"><button type="submit" class="mzke-btn-save">💾 Save</button></div>

<input type="hidden" name="path_sequence_hidden" id="mzkePathHidden">
<div id="mzkePathInputs"></div>
<div id="mzkeBranchInputs"></div>
</form>

<script src="maze_layout.js"></script>
<script>
let mzkeBank = <?php echo json_encode($vocabularyBank, JSON_UNESCAPED_UNICODE); ?>;
let mzkePath = <?php echo json_encode($pathSequence, JSON_UNESCAPED_UNICODE); ?>;
let mzkeBranches = <?php echo json_encode($distractorBranches, JSON_UNESCAPED_UNICODE); ?>;

function mzkeReadBankFromDom(){
    /* Read current bank state (words/images) straight from the DOM cards so the
       picker/sequence/preview always reflect unsaved edits too. */
    const cards = document.querySelectorAll('#mzkeBankGrid .mzke-bank-card');
    const bank = [];
    cards.forEach(card => {
        const id = card.getAttribute('data-vocab-id');
        const word = card.querySelector('input[name="bank_word[]"]').value.trim();
        const img = card.querySelector('.mzke-img-existing').value.trim();
        bank.push({ id, word, image_url: img });
    });
    mzkeBank = bank;
    return bank;
}

function mzkeBankById(id){ return mzkeBank.find(b => b.id === id) || { word:'', image_url:'' }; }

function mzkeAddBank(){
    const id = 'mzk_' + Date.now() + Math.floor(Math.random()*1000);
    const div = document.createElement('div');
    div.className = 'mzke-bank-card';
    div.setAttribute('data-vocab-id', id);
    div.innerHTML = `<button type="button" class="mzke-btn-remove-bank" onclick="mzkeRemoveBank(this)">✖</button>
<input type="hidden" name="bank_id[]" value="${id}">
<input type="hidden" name="bank_image_existing[]" class="mzke-img-existing" value="">
<div class="mzke-bank-thumb"></div>
<input type="file" name="bank_image_upload[]" accept="image/*" onchange="mzkePrevImg(this)">
<input type="text" name="bank_word[]" value="" placeholder="word" onchange="mzkeSyncPicker()">
<input type="hidden" name="bank_audio[]" class="mzke-audio-hidden" value="">
<button type="button" class="mzke-btn-tts-small" onclick="mzkeGenAudio(this)">🔊 Audio</button>`;
    document.getElementById('mzkeBankGrid').appendChild(div);
    mzkeSyncPicker();
}

function mzkeRemoveBank(btn){
    const card = btn.closest('.mzke-bank-card');
    const id = card.getAttribute('data-vocab-id');
    card.remove();
    mzkePath = mzkePath.filter(v => v !== id);
    mzkeBranches = mzkeBranches.filter(b => b.vocabulary_id !== id);
    mzkeSyncAll();
}

function mzkePrevImg(fileInput){
    const card = fileInput.closest('.mzke-bank-card');
    const thumb = card.querySelector('.mzke-bank-thumb');
    const file = fileInput.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = e => { thumb.innerHTML = `<img src="${e.target.result}" alt="">`; };
        reader.readAsDataURL(file);
    }
    mzkeSyncPicker();
}

function mzkeGenAudio(btn){
    const card = btn.closest('.mzke-bank-card');
    const word = card.querySelector('input[name="bank_word[]"]').value.trim().toLowerCase();
    const audioHidden = card.querySelector('.mzke-audio-hidden');
    if (!word) { alert('Enter a word first'); return; }
    btn.disabled = true; const orig = btn.textContent; btn.textContent = '⏳';
    const fd = new FormData();
    fd.append('text', word);
    fd.append('voice_id', 'Nggzl2QAXh3OijoXD116');
    fetch('tts.php', { method:'POST', body:fd, credentials:'same-origin' })
        .then(r => { if (!r.ok) throw new Error('TTS ' + r.status); return r.blob(); })
        .then(blob => {
            const cf = new FormData();
            cf.append('file', blob, word + '.mp3');
            cf.append('upload_preset', 'ml_default');
            return fetch('https://api.cloudinary.com/v1_1/YOUR_CLOUD_NAME/auto/upload', { method:'POST', body:cf });
        })
        .then(r => r.json())
        .then(d => { const u = d.secure_url || ''; if (!u) throw new Error('No URL'); audioHidden.value = u; })
        .catch(e => { alert('Audio error: ' + e.message); })
        .finally(() => { btn.disabled = false; btn.textContent = orig; });
}

/* ── sequence builder ── */
function mzkeSyncPicker(){
    mzkeReadBankFromDom();
    const picker = document.getElementById('mzkeSeqPicker');
    picker.innerHTML = '';
    mzkeBank.forEach(item => {
        if (!item.word && !item.image_url) return;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'mzke-bank-picker-item';
        const thumb = document.createElement('span');
        thumb.className = 'mzke-node-thumb';
        if (item.image_url) thumb.innerHTML = `<img src="${item.image_url}" alt="">`;
        btn.appendChild(thumb);
        const label = document.createElement('span');
        label.textContent = (item.word || '(no word)');
        btn.appendChild(label);
        btn.onclick = () => { mzkePath.push(item.id); mzkeSyncAll(); };
        picker.appendChild(btn);
    });
    mzkeRenderSeqList();
    mzkeRenderBranches();
    mzkeRenderPreview();
}

function mzkeRenderSeqList(){
    const list = document.getElementById('mzkeSeqList');
    list.innerHTML = '';
    mzkePath.forEach((vid, idx) => {
        const b = mzkeBankById(vid);
        const el = document.createElement('div');
        el.className = 'mzke-seq-item';
        const thumbHtml = b.image_url ? `<span class="mzke-node-thumb"><img src="${b.image_url}" alt=""></span>` : '';
        el.innerHTML = `<span class="num">${idx+1}.</span>${thumbHtml}<span>${b.word || vid}</span><button type="button">✖</button>`;
        el.querySelector('button').onclick = () => { mzkePath.splice(idx, 1); mzkeSyncAll(); };
        list.appendChild(el);
    });

    /* hidden inputs for submission */
    const inputsWrap = document.getElementById('mzkePathInputs');
    inputsWrap.innerHTML = '';
    mzkePath.forEach(vid => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'path_vocabulary_id[]'; inp.value = vid;
        inputsWrap.appendChild(inp);
    });
}

/* ── auto-suggest dead ends based on difficulty (does not block manual edits) ── */
function mzkeAutoSuggestBranches(){
    const difficulty = document.getElementById('mzke_difficulty').value;
    const suggested = { easy: 0, medium: 2, hard: 4 }[difficulty] ?? 0;
    mzkeReadBankFromDom();

    const usedInPath = new Set(mzkePath);
    const usedInBranches = new Set(mzkeBranches.map(b => b.vocabulary_id));
    const available = mzkeBank.filter(item => (item.word || item.image_url) && !usedInPath.has(item.id) && !usedInBranches.has(item.id));

    let added = 0;
    while (mzkeBranches.length < suggested && available.length > 0) {
        const item = available.shift();
        const afterIndex = mzkePath.length > 0 ? Math.min(mzkePath.length - 1, mzkeBranches.length % mzkePath.length) : 0;
        mzkeBranches.push({ attach_after_index: afterIndex, vocabulary_id: item.id });
        added++;
    }
    if (added === 0 && mzkeBranches.length < suggested) {
        alert('Add more images to the vocabulary bank to create more dead ends.');
    }
    mzkeRenderBranches();
    mzkeRenderPreview();
}

/* ── distractor branch builder ── */
function mzkeAddBranch(){
    if (!mzkePath.length) {
        alert('Primero agrega al menos un nodo a la secuencia del camino (paso 3) antes de crear un callejón sin salida.');
        return;
    }
    mzkeBranches.push({ attach_after_index: 0, vocabulary_id: '' });
    mzkeRenderBranches();
    mzkeRenderPreview();
}

function mzkeRenderBranches(){
    const wrap = document.getElementById('mzkeBranchList');
    wrap.innerHTML = '';
    if (mzkeBranches.length && !mzkePath.length) {
        const hint = document.createElement('p');
        hint.className = 'mzke-help';
        hint.style.color = '#ef4444';
        hint.textContent = '⚠ Todavía no hay nodos en la secuencia del camino (paso 3). Agrega nodos allí para poder elegir después de cuál nodo va cada callejón sin salida.';
        wrap.appendChild(hint);
    }
    mzkeBranches.forEach((br, idx) => {
        const row = document.createElement('div');
        row.className = 'mzke-branch-item';

        const afterSelect = document.createElement('select');
        if (!mzkePath.length) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = '(sin nodos en el camino todavía)';
            afterSelect.appendChild(opt);
            afterSelect.disabled = true;
        }
        mzkePath.forEach((vid, i) => {
            const b = mzkeBankById(vid);
            const opt = document.createElement('option');
            opt.value = i;
            opt.textContent = 'After node ' + (i+1) + ' (' + (b.word || vid) + ')';
            if (i === br.attach_after_index) opt.selected = true;
            afterSelect.appendChild(opt);
        });
        afterSelect.onchange = () => { br.attach_after_index = parseInt(afterSelect.value, 10) || 0; mzkeRenderPreview(); };

        const vocabSelect = document.createElement('select');
        mzkeBank.forEach(item => {
            if (!item.word && !item.image_url) return;
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.word || item.id;
            if (item.id === br.vocabulary_id) opt.selected = true;
            vocabSelect.appendChild(opt);
        });
        vocabSelect.onchange = () => { br.vocabulary_id = vocabSelect.value; mzkeRenderPreview(); };

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.textContent = '✖ Remove';
        removeBtn.onclick = () => { mzkeBranches.splice(idx, 1); mzkeRenderBranches(); mzkeRenderPreview(); };

        row.appendChild(afterSelect);
        row.appendChild(vocabSelect);
        row.appendChild(removeBtn);
        wrap.appendChild(row);
    });

    /* hidden inputs for submission */
    const inputsWrap = document.getElementById('mzkeBranchInputs');
    inputsWrap.innerHTML = '';
    mzkeBranches.forEach(br => {
        const inp1 = document.createElement('input');
        inp1.type = 'hidden'; inp1.name = 'branch_after_index[]'; inp1.value = br.attach_after_index;
        const inp2 = document.createElement('input');
        inp2.type = 'hidden'; inp2.name = 'branch_vocabulary_id[]'; inp2.value = br.vocabulary_id;
        inputsWrap.appendChild(inp1);
        inputsWrap.appendChild(inp2);
    });
}

/* ── live preview (reuses generateMazeLayout, same as the viewer) ── */
function mzkeRenderPreview(){
    const wrap = document.getElementById('mzkePreviewWrap');
    wrap.innerHTML = '';
    if (!mzkePath.length) { wrap.textContent = 'Add nodes to the path sequence to see a preview.'; return; }

    const layout = generateMazeLayout(mzkePath, mzkeBranches);
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + layout.width + ' ' + layout.height);
    svg.setAttribute('width', Math.min(layout.width, 760));
    svg.setAttribute('height', layout.height * (Math.min(layout.width, 760) / layout.width));

    const wallPath = document.createElementNS(NS, 'path');
    wallPath.setAttribute('d', layout.wallPathD);
    wallPath.setAttribute('fill', 'none');
    wallPath.setAttribute('stroke', '#CDC7F3');
    wallPath.setAttribute('stroke-width', '34');
    wallPath.setAttribute('stroke-linecap', 'round');
    wallPath.setAttribute('stroke-linejoin', 'round');
    wallPath.setAttribute('opacity', '0.35');
    svg.appendChild(wallPath);

    const corridorPath = document.createElementNS(NS, 'path');
    corridorPath.setAttribute('d', layout.corridorPathD);
    corridorPath.setAttribute('fill', 'none');
    corridorPath.setAttribute('stroke', '#7F77DD');
    corridorPath.setAttribute('stroke-width', '3');
    corridorPath.setAttribute('stroke-dasharray', '2 10');
    corridorPath.setAttribute('stroke-linecap', 'round');
    corridorPath.setAttribute('opacity', '0.55');
    svg.appendChild(corridorPath);

    const R = 30;
    layout.nodes.forEach(node => {
        const b = mzkeBankById(node.vocabularyId);
        const isStart = node.kind === 'path' && node.index === 0;
        const isEnd   = node.kind === 'path' && node.index === mzkePath.length - 1;
        const g = document.createElementNS(NS, 'g');
        g.setAttribute('transform', 'translate(' + node.x + ',' + node.y + ')');

        const circle = document.createElementNS(NS, 'circle');
        circle.setAttribute('r', R);
        circle.setAttribute('fill', '#fff');
        circle.setAttribute('stroke', node.kind === 'branch' ? '#FCA5A5' : (isStart ? '#F97316' : (isEnd ? '#16a34a' : '#7F77DD')));
        circle.setAttribute('stroke-width', '3');
        g.appendChild(circle);

        if (b.image_url) {
            const img = document.createElementNS(NS, 'image');
            img.setAttributeNS('http://www.w3.org/1999/xlink', 'href', b.image_url);
            img.setAttribute('href', b.image_url);
            img.setAttribute('x', -R + 5); img.setAttribute('y', -R + 5);
            img.setAttribute('width', (R-5)*2); img.setAttribute('height', (R-5)*2);
            img.setAttribute('clip-path', 'circle(' + (R-5) + 'px)');
            img.setAttribute('preserveAspectRatio', 'xMidYMid slice');
            g.appendChild(img);
        }

        if (b.word) {
            const label = document.createElementNS(NS, 'text');
            label.setAttribute('x', 0); label.setAttribute('y', R + 14);
            label.setAttribute('text-anchor', 'middle');
            label.setAttribute('font-size', '10');
            label.setAttribute('font-family', 'Nunito, sans-serif');
            label.setAttribute('font-weight', '800');
            label.setAttribute('fill', '#534AB7');
            label.textContent = b.word;
            g.appendChild(label);
        }

        svg.appendChild(g);
    });

    wrap.appendChild(svg);
}

function mzkeSyncAll(){
    mzkeSyncPicker();
}

document.addEventListener('DOMContentLoaded', () => { mzkeSyncAll(); });

document.getElementById('mzkeForm').addEventListener('submit', (e) => {
    if (!mzkePath.length) {
        e.preventDefault();
        alert('Agrega al menos un nodo a la secuencia del camino (paso 3, "Path sequence") antes de guardar. Sin eso, la vista previa y el visor del estudiante no funcionarán.');
        document.getElementById('mzkeSeqPicker').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>
<?php
$content = ob_get_clean();
render_activity_editor('🧩 Maze (Kids) Editor', 'fa-solid fa-route', $content);
