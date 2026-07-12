<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

if (!empty($_SESSION['student_logged'])) { header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied'); exit; }
if (empty($_SESSION['academic_logged']) && empty($_SESSION['admin_logged'])) { header('Location: /lessons/lessons/academic/login.php'); exit; }

$activityId = trim((string)($_GET['id'] ?? ''));
$unit = trim((string)($_GET['unit'] ?? ''));
$source = trim((string)($_GET['source'] ?? ''));
$assignment = trim((string)($_GET['assignment'] ?? ''));

function fc_columns(PDO $pdo): array {
    static $cache = null;
    if (is_array($cache)) return $cache;
    $cache = [];
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='activities'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) if (isset($row['column_name'])) $cache[] = (string)$row['column_name'];
    return $cache;
}
function fc_title(string $title): string { $title = trim($title); return $title !== '' ? $title : 'Flashcards'; }
function fc_resolve_unit(PDO $pdo, string $activityId): string {
    if ($activityId === '') return '';
    $cols = fc_columns($pdo);
    foreach (['unit_id','unit'] as $col) {
        if (!in_array($col, $cols, true)) continue;
        $stmt = $pdo->prepare("SELECT {$col} FROM activities WHERE id=:id LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $v = $stmt->fetchColumn();
        if ($v !== false && trim((string)$v) !== '') return (string)$v;
    }
    return '';
}
function fc_normalize($raw): array {
    $d = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
    if (!is_array($d)) $d = [];
    $src = isset($d['cards']) && is_array($d['cards']) ? $d['cards'] : (array_is_list($d) ? $d : []);
    $cards = [];
    foreach ($src as $item) {
        if (!is_array($item)) continue;
        $text = trim((string)($item['text'] ?? $item['word'] ?? $item['english_text'] ?? ''));
        $cards[] = [
            'id' => trim((string)($item['id'] ?? uniqid('flashcard_'))),
            'text' => $text,
            'word' => $text,
            'image' => trim((string)($item['image'] ?? $item['img'] ?? '')),
            'back_image' => trim((string)($item['back_image'] ?? '')),
            'example' => trim((string)($item['example'] ?? '')),
            'ipa' => trim((string)($item['ipa'] ?? '')),
            'meaning' => trim((string)($item['meaning'] ?? '')),
            'voice_id' => trim((string)($item['voice_id'] ?? 'nzFihrBIvB34imQBuxub')),
            'audio' => trim((string)($item['audio'] ?? '')),
        ];
    }
    return ['title' => fc_title((string)($d['title'] ?? '')), 'cards' => $cards];
}
function fc_encode(string $title, array $cards): string { return json_encode(['title' => fc_title($title), 'cards' => array_values($cards)], JSON_UNESCAPED_UNICODE); }
function fc_load(PDO $pdo, string $unit, string $activityId): array {
    $cols = fc_columns($pdo);
    $fields = ['id'];
    foreach (['data','content_json','title','name'] as $c) if (in_array($c, $cols, true)) $fields[] = $c;
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare('SELECT '.implode(',', $fields)." FROM activities WHERE id=:id AND type='flashcards' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    foreach (['unit_id','unit'] as $col) {
        if ($row || $unit === '' || !in_array($col, $cols, true)) continue;
        $stmt = $pdo->prepare('SELECT '.implode(',', $fields)." FROM activities WHERE {$col}=:unit AND type='flashcards' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return ['id'=>'', 'title'=>'Flashcards', 'cards'=>[]];
    $p = fc_normalize($row['data'] ?? ($row['content_json'] ?? ''));
    $dbTitle = trim((string)($row['title'] ?? $row['name'] ?? ''));
    if ($dbTitle !== '') $p['title'] = $dbTitle;
    $p['id'] = (string)($row['id'] ?? '');
    return $p;
}
function fc_save(PDO $pdo, string $unit, string $activityId, string $title, array $cards): string {
    $cols = fc_columns($pdo);
    $json = fc_encode($title, $cards);
    $targetId = $activityId;
    if ($targetId === '') {
        foreach (['unit_id','unit'] as $col) {
            if ($targetId !== '' || !in_array($col, $cols, true)) continue;
            $stmt = $pdo->prepare("SELECT id FROM activities WHERE {$col}=:unit AND type='flashcards' ORDER BY id ASC LIMIT 1");
            $stmt->execute(['unit' => $unit]);
            $targetId = trim((string)$stmt->fetchColumn());
        }
    }
    if ($targetId !== '') {
        $sets = [];
        $params = ['id' => $targetId];
        if (in_array('data', $cols, true)) { $sets[] = 'data=:data'; $params['data'] = $json; }
        if (in_array('content_json', $cols, true)) { $sets[] = 'content_json=:content_json'; $params['content_json'] = $json; }
        if (in_array('title', $cols, true)) { $sets[] = 'title=:title'; $params['title'] = fc_title($title); }
        if (in_array('name', $cols, true)) { $sets[] = 'name=:name'; $params['name'] = fc_title($title); }
        if ($sets) {
            $stmt = $pdo->prepare('UPDATE activities SET '.implode(',', $sets)." WHERE id=:id AND type='flashcards'");
            $stmt->execute($params);
        }
        return $targetId;
    }
    $newId = md5(random_bytes(16));
    $ic = []; $iv = []; $p = [];
    if (in_array('id', $cols, true)) { $ic[] = 'id'; $iv[] = ':id'; $p['id'] = $newId; }
    if (in_array('unit_id', $cols, true)) { $ic[] = 'unit_id'; $iv[] = ':unit'; $p['unit'] = $unit; }
    elseif (in_array('unit', $cols, true)) { $ic[] = 'unit'; $iv[] = ':unit'; $p['unit'] = $unit; }
    $ic[] = 'type'; $iv[] = "'flashcards'";
    if (in_array('data', $cols, true)) { $ic[] = 'data'; $iv[] = ':data'; $p['data'] = $json; }
    if (in_array('content_json', $cols, true)) { $ic[] = 'content_json'; $iv[] = ':content_json'; $p['content_json'] = $json; }
    if (in_array('title', $cols, true)) { $ic[] = 'title'; $iv[] = ':title'; $p['title'] = fc_title($title); }
    if (in_array('name', $cols, true)) { $ic[] = 'name'; $iv[] = ':name'; $p['name'] = fc_title($title); }
    $stmt = $pdo->prepare('INSERT INTO activities ('.implode(',', $ic).') VALUES ('.implode(',', $iv).')');
    $stmt->execute($p);
    return $newId;
}

if ($unit === '' && $activityId !== '') $unit = fc_resolve_unit($pdo, $activityId);
if ($unit === '') die('Unit not specified');
$activity = fc_load($pdo, $unit, $activityId);
$cards = is_array($activity['cards'] ?? null) ? $activity['cards'] : [];
$activityTitle = (string)($activity['title'] ?? 'Flashcards');
if ($activityId === '' && !empty($activity['id'])) $activityId = (string)$activity['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string)($_POST['activity_title'] ?? ''));
    $texts = is_array($_POST['text'] ?? null) ? $_POST['text'] : [];
    $examples = is_array($_POST['example'] ?? null) ? $_POST['example'] : [];
    $ipas = is_array($_POST['ipa'] ?? null) ? $_POST['ipa'] : [];
    $meanings = is_array($_POST['meaning'] ?? null) ? $_POST['meaning'] : [];
    $images = is_array($_POST['image_existing'] ?? null) ? $_POST['image_existing'] : [];
    $backImages = is_array($_POST['back_image_existing'] ?? null) ? $_POST['back_image_existing'] : [];
    $audios = is_array($_POST['audio'] ?? null) ? $_POST['audio'] : [];
    $voiceIds = is_array($_POST['voice_id'] ?? null) ? $_POST['voice_id'] : [];
    $ids = is_array($_POST['card_id'] ?? null) ? $_POST['card_id'] : [];
    $imageFiles = $_FILES['image_file'] ?? null;
    $backImageFiles = $_FILES['back_image_file'] ?? null;
    $out = [];
    foreach ($texts as $i => $raw) {
        $text = trim((string)$raw);
        $image = trim((string)($images[$i] ?? ''));
        $backImage = trim((string)($backImages[$i] ?? ''));
        if ($imageFiles && !empty($imageFiles['tmp_name'][$i])) { $up = upload_to_cloudinary($imageFiles['tmp_name'][$i]); if ($up) $image = $up; }
        if ($backImageFiles && !empty($backImageFiles['tmp_name'][$i])) { $up = upload_to_cloudinary($backImageFiles['tmp_name'][$i]); if ($up) $backImage = $up; }
        $example = trim((string)($examples[$i] ?? ''));
        $ipa = trim((string)($ipas[$i] ?? ''));
        $meaning = trim((string)($meanings[$i] ?? ''));
        if ($text === '' && $image === '' && $backImage === '' && $ipa === '' && $meaning === '' && $example === '') continue;
        $out[] = [
            'id' => trim((string)($ids[$i] ?? '')) ?: uniqid('flashcard_'),
            'text' => $text,
            'word' => $text,
            'image' => $image,
            'back_image' => $backImage,
            'example' => $example,
            'ipa' => $ipa,
            'meaning' => $meaning,
            'voice_id' => trim((string)($voiceIds[$i] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub',
            'audio' => trim((string)($audios[$i] ?? '')),
        ];
    }
    $savedId = fc_save($pdo, $unit, $activityId, $title, $out);
    $params = ['unit='.urlencode($unit), 'saved=1', 'id='.urlencode($savedId)];
    if ($assignment !== '') $params[] = 'assignment='.urlencode($assignment);
    if ($source !== '') $params[] = 'source='.urlencode($source);
    header('Location: editor.php?'.implode('&', $params));
    exit;
}

ob_start();
?>
<style>.flashcards-form{max-width:940px;margin:0 auto;text-align:left;font-family:Nunito,Arial,sans-serif}.title-box,.card-item{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;margin-bottom:14px}.title-box input,.card-item input[type=text],.card-item input[type=file],.card-item select{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box}label{display:block;font-weight:800;margin:8px 0 6px}.ipa-meaning-row{display:grid;grid-template-columns:1fr 2fr auto;gap:10px;align-items:end;margin:8px 0 12px}.autofill-btn,.btn-autofill-all{background:#7F77DD;color:#fff;border:0;border-radius:999px;padding:10px 14px;font-weight:900;cursor:pointer}.btn-autofill-all{background:#F97316}.toolbar-row{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}.btn-add,.save-btn,.btn-remove{border:0;border-radius:8px;padding:10px 14px;font-weight:800;cursor:pointer;color:#fff}.btn-add{background:#16a34a}.save-btn{background:#0d9488}.btn-remove{background:#ef4444;margin-top:12px}.small-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.image-preview{display:block;max-width:120px;max-height:120px;object-fit:contain;border:1px solid #d1d5db;border-radius:10px;margin-bottom:10px}.status{font-size:12px;font-weight:800;color:#7F77DD;min-height:18px}@media(max-width:760px){.ipa-meaning-row,.small-grid{grid-template-columns:1fr}}</style>
<?php if (isset($_GET['saved'])) { ?><p style="color:green;font-weight:bold">Saved successfully</p><?php } ?>
<form class="flashcards-form" id="flashcardsForm" method="post" enctype="multipart/form-data">
<div class="title-box"><label>Activity title</label><input type="text" name="activity_title" value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" required></div>
<div class="toolbar-row" style="margin-bottom:14px"><button type="button" class="btn-autofill-all" id="autofillAll">Auto-fill all words</button></div>
<div id="cardsContainer">
<?php foreach ($cards as $card) { ?>
<div class="card-item word-row">
<input type="hidden" name="card_id[]" value="<?= htmlspecialchars($card['id'] ?? uniqid('flashcard_'), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="image_existing[]" value="<?= htmlspecialchars($card['image'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="back_image_existing[]" value="<?= htmlspecialchars($card['back_image'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="audio[]" value="<?= htmlspecialchars($card['audio'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
<label>Word / text</label><input class="word-input" type="text" name="text[]" value="<?= htmlspecialchars($card['text'] ?? $card['word'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="coral reef">
<label>Example sentence (optional)</label><input class="example-input" type="text" name="example[]" value="<?= htmlspecialchars($card['example'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="The coral reef was full of colorful fish.">
<div class="ipa-meaning-row"><input class="ipa-input" type="text" name="ipa[]" value="<?= htmlspecialchars($card['ipa'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="/ipa/"><input class="meaning-input" type="text" name="meaning[]" value="<?= htmlspecialchars($card['meaning'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Short meaning in English"><button type="button" class="autofill-btn">Auto-fill pronunciation &amp; meaning</button></div>
<div class="status"></div>
<label>Voice</label><select name="voice_id[]"><option value="nzFihrBIvB34imQBuxub"<?= ($card['voice_id'] ?? 'nzFihrBIvB34imQBuxub') === 'nzFihrBIvB34imQBuxub' ? ' selected' : '' ?>>Adult Male (Josh)</option><option value="NoOVOzCQFLOvtsMoNcdT"<?= ($card['voice_id'] ?? '') === 'NoOVOzCQFLOvtsMoNcdT' ? ' selected' : '' ?>>Adult Female (Lily)</option><option value="Nggzl2QAXh3OijoXD116"<?= ($card['voice_id'] ?? '') === 'Nggzl2QAXh3OijoXD116' ? ' selected' : '' ?>>Child (Candy)</option></select>
<div class="small-grid"><div><label>Image (optional)</label><?php if (!empty($card['image'])) { ?><img src="<?= htmlspecialchars($card['image'], ENT_QUOTES, 'UTF-8') ?>" class="image-preview" alt=""><?php } ?><input type="file" name="image_file[]" accept="image/*"></div><div><label>Back image (optional)</label><?php if (!empty($card['back_image'])) { ?><img src="<?= htmlspecialchars($card['back_image'], ENT_QUOTES, 'UTF-8') ?>" class="image-preview" alt=""><?php } ?><input type="file" name="back_image_file[]" accept="image/*"></div></div>
<button type="button" class="btn-remove" onclick="removeCard(this)">Remove</button>
</div>
<?php } ?>
</div>
<div class="toolbar-row"><button type="button" class="btn-add" onclick="addCard()">Add Card</button><button type="submit" class="save-btn">Save</button></div>
</form>
<script>
let formChanged=false,formSubmitted=false;function markChanged(){formChanged=true}function removeCard(btn){const row=btn.closest('.card-item');if(row){row.remove();markChanged()}}function cardHtml(){return '<div class="card-item word-row"><input type="hidden" name="card_id[]" value="flashcard_'+Date.now()+'"><input type="hidden" name="image_existing[]" value=""><input type="hidden" name="back_image_existing[]" value=""><input type="hidden" name="audio[]" value=""><label>Word / text</label><input class="word-input" type="text" name="text[]" placeholder="coral reef"><label>Example sentence (optional)</label><input class="example-input" type="text" name="example[]" placeholder="The coral reef was full of colorful fish."><div class="ipa-meaning-row"><input class="ipa-input" type="text" name="ipa[]" placeholder="/ipa/"><input class="meaning-input" type="text" name="meaning[]" placeholder="Short meaning in English"><button type="button" class="autofill-btn">Auto-fill pronunciation &amp; meaning</button></div><div class="status"></div><label>Voice</label><select name="voice_id[]"><option value="nzFihrBIvB34imQBuxub" selected>Adult Male (Josh)</option><option value="NoOVOzCQFLOvtsMoNcdT">Adult Female (Lily)</option><option value="Nggzl2QAXh3OijoXD116">Child (Candy)</option></select><div class="small-grid"><div><label>Image (optional)</label><input type="file" name="image_file[]" accept="image/*"></div><div><label>Back image (optional)</label><input type="file" name="back_image_file[]" accept="image/*"></div></div><button type="button" class="btn-remove" onclick="removeCard(this)">Remove</button></div>'}function addCard(){const c=document.getElementById('cardsContainer');c.insertAdjacentHTML('beforeend',cardHtml());bindChangeTracking(c.lastElementChild);markChanged()}function bindChangeTracking(scope){scope.querySelectorAll('input,select,textarea').forEach(el=>{el.addEventListener('input',markChanged);el.addEventListener('change',markChanged)})}function sleep(ms){return new Promise(r=>setTimeout(r,ms))}async function autofillRow(row){const word=row.querySelector('.word-input')?.value.trim()||'';const context=row.querySelector('.example-input')?.value.trim()||'';const btn=row.querySelector('.autofill-btn');const status=row.querySelector('.status');if(!word){if(status)status.textContent='Enter a word first.';return false}if(btn){btn.disabled=true;btn.textContent='Filling...'}try{const body='word='+encodeURIComponent(word)+'&context='+encodeURIComponent(context);const res=await fetch('/lessons/lessons/api/autofill_word.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body,credentials:'same-origin'});const data=await res.json();if(!res.ok||data.error)throw new Error(data.error||'Auto-fill failed');if(data.ipa)row.querySelector('.ipa-input').value=data.ipa;if(data.meaning)row.querySelector('.meaning-input').value=data.meaning;if(status)status.textContent='Auto-fill complete. Review before saving.';markChanged();return true}catch(e){if(status)status.textContent='Auto-fill failed. Type manually.';alert('Auto-fill failed. You can type it manually.');return false}finally{if(btn){btn.disabled=false;btn.textContent='Auto-fill pronunciation & meaning'}}}document.addEventListener('DOMContentLoaded',()=>{bindChangeTracking(document);document.getElementById('cardsContainer').addEventListener('click',e=>{const b=e.target.closest('.autofill-btn');if(b)autofillRow(b.closest('.word-row'))});document.getElementById('autofillAll').addEventListener('click',async()=>{const rows=[...document.querySelectorAll('.word-row')];const all=document.getElementById('autofillAll');all.disabled=true;all.textContent='Filling all...';for(const row of rows){await autofillRow(row);await sleep(300)}all.disabled=false;all.textContent='Auto-fill all words'});document.getElementById('flashcardsForm').addEventListener('submit',()=>{formSubmitted=true;formChanged=false})});window.addEventListener('beforeunload',e=>{if(formChanged&&!formSubmitted){e.preventDefault();e.returnValue=''}});
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Flashcards Editor', 'Cards', $content);
