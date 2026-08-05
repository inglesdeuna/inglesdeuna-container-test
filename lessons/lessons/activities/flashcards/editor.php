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
        // Migrate legacy fields: front text from text/word/english_text, back text from back_text/meaning
        $frontText = trim((string)($item['front_text'] ?? $item['text'] ?? $item['word'] ?? $item['english_text'] ?? ''));
        $backText  = trim((string)($item['back_text']  ?? $item['meaning'] ?? ''));
        // Visibility flags — legacy cards default to showing whatever content they have
        $frontImg  = trim((string)($item['front_image'] ?? $item['image'] ?? $item['img'] ?? ''));
        $backImg   = trim((string)($item['back_image'] ?? ''));
        $frontAud  = trim((string)($item['front_audio'] ?? $item['audio'] ?? ''));
        $backAud   = trim((string)($item['back_audio'] ?? ''));
        $frontAudText = trim((string)($item['front_audio_text'] ?? ''));
        $backAudText  = trim((string)($item['back_audio_text']  ?? ''));
        // Migrate legacy cards: populate audio text from the matching side's text if not set
        if ($frontAudText === '') $frontAudText = $frontText;
        if ($backAudText  === '') $backAudText  = $backText;
        $hasFlag = array_key_exists('front_show_image', $item);
        $cards[] = [
            'id'               => trim((string)($item['id'] ?? uniqid('flashcard_'))),
            'front_text'       => $frontText,
            'front_image'      => $frontImg,
            'front_audio'      => $frontAud,
            'front_audio_text' => $frontAudText,
            'back_text'        => $backText,
            'back_image'       => $backImg,
            'back_audio'       => $backAud,
            'back_audio_text'  => $backAudText,
            'voice_id'         => trim((string)($item['voice_id'] ?? 'nzFihrBIvB34imQBuxub')),
            // Visibility flags (default true for legacy cards so existing content still shows)
            'front_show_image' => array_key_exists('front_show_image', $item) ? (bool)$item['front_show_image'] : ($hasFlag ? false : $frontImg !== ''),
            'front_show_listen'=> array_key_exists('front_show_listen', $item) ? (bool)$item['front_show_listen'] : ($hasFlag ? false : ($frontAud !== '' || $frontText !== '')),
            'front_show_text'  => array_key_exists('front_show_text', $item)  ? (bool)$item['front_show_text']  : ($hasFlag ? false : $frontText !== ''),
            'back_show_image'  => array_key_exists('back_show_image', $item)  ? (bool)$item['back_show_image']  : ($hasFlag ? false : $backImg !== ''),
            'back_show_listen' => array_key_exists('back_show_listen', $item)  ? (bool)$item['back_show_listen']  : ($hasFlag ? false : ($backAud !== '' || $backText !== '')),
            'back_show_text'   => array_key_exists('back_show_text', $item)   ? (bool)$item['back_show_text']   : ($hasFlag ? false : $backText !== ''),
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
    $title       = trim((string)($_POST['activity_title'] ?? ''));
    $frontTexts  = is_array($_POST['front_text']           ?? null) ? $_POST['front_text']           : [];
    $backTexts   = is_array($_POST['back_text']            ?? null) ? $_POST['back_text']             : [];
    $frontImages = is_array($_POST['front_image_existing'] ?? null) ? $_POST['front_image_existing']  : [];
    $backImages  = is_array($_POST['back_image_existing']  ?? null) ? $_POST['back_image_existing']   : [];
    $frontAudios    = is_array($_POST['front_audio']          ?? null) ? $_POST['front_audio']           : [];
    $backAudios     = is_array($_POST['back_audio']           ?? null) ? $_POST['back_audio']            : [];
    $frontAudTexts  = is_array($_POST['front_audio_text']     ?? null) ? $_POST['front_audio_text']      : [];
    $backAudTexts   = is_array($_POST['back_audio_text']      ?? null) ? $_POST['back_audio_text']       : [];
    $voiceIds    = is_array($_POST['voice_id']             ?? null) ? $_POST['voice_id']              : [];
    $ids         = is_array($_POST['card_id']              ?? null) ? $_POST['card_id']               : [];
    // Visibility checkboxes (present in POST only when checked)
    $frontShowImages  = is_array($_POST['front_show_image']  ?? null) ? $_POST['front_show_image']  : [];
    $frontShowListens = is_array($_POST['front_show_listen'] ?? null) ? $_POST['front_show_listen'] : [];
    $frontShowTexts   = is_array($_POST['front_show_text']   ?? null) ? $_POST['front_show_text']   : [];
    $backShowImages   = is_array($_POST['back_show_image']   ?? null) ? $_POST['back_show_image']   : [];
    $backShowListens  = is_array($_POST['back_show_listen']  ?? null) ? $_POST['back_show_listen']  : [];
    $backShowTexts    = is_array($_POST['back_show_text']    ?? null) ? $_POST['back_show_text']    : [];
    $frontImageFiles = $_FILES['front_image_file'] ?? null;
    $backImageFiles  = $_FILES['back_image_file']  ?? null;
    $count = max(count($frontTexts), count($backTexts), count($ids));
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $frontText  = trim((string)($frontTexts[$i]  ?? ''));
        $backText   = trim((string)($backTexts[$i]   ?? ''));
        $frontImage = trim((string)($frontImages[$i] ?? ''));
        $backImage  = trim((string)($backImages[$i]  ?? ''));
        if ($frontImageFiles && !empty($frontImageFiles['tmp_name'][$i])) { $up = upload_to_cloudinary($frontImageFiles['tmp_name'][$i]); if ($up) $frontImage = $up; }
        if ($backImageFiles  && !empty($backImageFiles['tmp_name'][$i]))  { $up = upload_to_cloudinary($backImageFiles['tmp_name'][$i]);  if ($up) $backImage  = $up; }
        if ($frontText === '' && $backText === '' && $frontImage === '' && $backImage === '') continue;
        $out[] = [
            'id'               => trim((string)($ids[$i] ?? '')) ?: uniqid('flashcard_'),
            'front_text'       => $frontText,
            'front_image'      => $frontImage,
            'front_audio'      => trim((string)($frontAudios[$i] ?? '')),
            'front_audio_text' => trim((string)($frontAudTexts[$i] ?? '')),
            'back_text'        => $backText,
            'back_image'       => $backImage,
            'back_audio'       => trim((string)($backAudios[$i] ?? '')),
            'back_audio_text'  => trim((string)($backAudTexts[$i] ?? '')),
            'voice_id'         => trim((string)($voiceIds[$i] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub',
            'front_show_image' => !empty($frontShowImages[$i]),
            'front_show_listen'=> !empty($frontShowListens[$i]),
            'front_show_text'  => !empty($frontShowTexts[$i]),
            'back_show_image'  => !empty($backShowImages[$i]),
            'back_show_listen' => !empty($backShowListens[$i]),
            'back_show_text'   => !empty($backShowTexts[$i]),
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
<style>
.flashcards-form{max-width:940px;margin:0 auto;text-align:left;font-family:Nunito,Arial,sans-serif}
.title-box,.card-item{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px;margin-bottom:14px}
.title-box input,.card-item input[type=text],.card-item input[type=file],.card-item select{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box}
label{display:block;font-weight:800;margin:8px 0 6px}
.sides-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:12px}
.side-box{background:#f9f8ff;border:1px solid #EDE9FA;border-radius:10px;padding:12px}
.side-box h4{margin:0 0 10px;font-family:Fredoka,sans-serif;font-size:15px;color:#7F77DD}
.side-box h4.back{color:#F97316}
.image-preview{display:block;max-width:120px;max-height:120px;object-fit:contain;border:1px solid #d1d5db;border-radius:10px;margin-bottom:8px}
.toolbar-row{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.btn-add,.save-btn,.btn-remove{border:0;border-radius:8px;padding:10px 14px;font-weight:800;cursor:pointer;color:#fff}
.btn-add{background:#16a34a}.save-btn{background:#0d9488}.btn-remove{background:#ef4444;margin-top:12px}
.card-voice-row{margin-bottom:10px}
.show-opts{display:flex;gap:14px;flex-wrap:wrap;margin:0 0 10px;padding:8px 10px;background:#f0eeff;border-radius:8px}
.show-opts label{display:inline-flex;align-items:center;gap:5px;font-weight:700;font-size:13px;margin:0;cursor:pointer}
.show-opts input[type=checkbox]{width:auto;padding:0;accent-color:#7F77DD}
.field-group{margin-bottom:8px}
.field-group.hidden-field{display:none}
@media(max-width:640px){.sides-grid{grid-template-columns:1fr}}
</style>
<?php if (isset($_GET['saved'])) { ?><p style="color:green;font-weight:bold">Saved successfully</p><?php } ?>
<form class="flashcards-form" id="flashcardsForm" method="post" enctype="multipart/form-data">
<div class="title-box"><label>Activity title</label><input type="text" name="activity_title" value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" required></div>
<div id="cardsContainer">
<?php foreach ($cards as $i => $card) {
    $vid = $card['voice_id'] ?? 'nzFihrBIvB34imQBuxub';
    $fsi = !empty($card['front_show_image']);
    $fsl = !empty($card['front_show_listen']);
    $fst = !empty($card['front_show_text']);
    $bsi = !empty($card['back_show_image']);
    $bsl = !empty($card['back_show_listen']);
    $bst = !empty($card['back_show_text']);
?>
<div class="card-item word-row">
<input type="hidden" name="card_id[]" value="<?= htmlspecialchars($card['id'] ?? uniqid('flashcard_'), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="front_image_existing[]" value="<?= htmlspecialchars($card['front_image'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="back_image_existing[]"  value="<?= htmlspecialchars($card['back_image']  ?? '', ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="front_audio[]" value="<?= htmlspecialchars($card['front_audio'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="back_audio[]"  value="<?= htmlspecialchars($card['back_audio']  ?? '', ENT_QUOTES, 'UTF-8') ?>">
<div class="card-voice-row"><label>Voice (used for audio on both sides)</label><select name="voice_id[]"><option value="nzFihrBIvB34imQBuxub"<?= $vid==='nzFihrBIvB34imQBuxub'?' selected':''?>>Jose</option><option value="NoOVOzCQFLOvtsMoNcdT"<?= $vid==='NoOVOzCQFLOvtsMoNcdT'?' selected':''?>>Lily</option><option value="Nggzl2QAXh3OijoXD116"<?= $vid==='Nggzl2QAXh3OijoXD116'?' selected':''?>>Candy</option></select></div>
<div class="sides-grid">
<div class="side-box"><h4>▶ Front</h4>
<div class="show-opts">
  <label><input type="checkbox" name="front_show_image[<?= $i ?>]" value="1" onchange="syncField(this,'front-image-<?= $i ?>')" <?= $fsi?'checked':''?>> Show image</label>
  <label><input type="checkbox" name="front_show_listen[<?= $i ?>]" value="1" onchange="syncField(this,'front-audtext-<?= $i ?>')" <?= $fsl?'checked':''?>> Show listen</label>
  <label><input type="checkbox" name="front_show_text[<?= $i ?>]" value="1" onchange="syncField(this,'front-text-<?= $i ?>')" <?= $fst?'checked':''?>> Show text</label>
</div>
<div class="field-group<?= $fst?'':' hidden-field' ?>" id="front-text-<?= $i ?>">
<label>Text</label><input type="text" name="front_text[]" value="<?= htmlspecialchars($card['front_text'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. coral reef">
</div>
<div class="field-group<?= $fsl?'':' hidden-field' ?>" id="front-audtext-<?= $i ?>">
<label>Audio text <small style="font-weight:400;color:#6b7280">(what the Listen button reads aloud)</small></label><input type="text" name="front_audio_text[]" value="<?= htmlspecialchars($card['front_audio_text'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Head in hands">
</div>
<div class="field-group<?= $fsi?'':' hidden-field' ?>" id="front-image-<?= $i ?>">
<label>Image</label><?php if (!empty($card['front_image'])) { ?><img src="<?= htmlspecialchars($card['front_image'], ENT_QUOTES, 'UTF-8') ?>" class="image-preview" alt=""><?php } ?><input type="file" name="front_image_file[]" accept="image/*">
</div>
</div>
<div class="side-box"><h4 class="back">◀ Back</h4>
<div class="show-opts">
  <label><input type="checkbox" name="back_show_image[<?= $i ?>]" value="1" onchange="syncField(this,'back-image-<?= $i ?>')" <?= $bsi?'checked':''?>> Show image</label>
  <label><input type="checkbox" name="back_show_listen[<?= $i ?>]" value="1" onchange="syncField(this,'back-audtext-<?= $i ?>')" <?= $bsl?'checked':''?>> Show listen</label>
  <label><input type="checkbox" name="back_show_text[<?= $i ?>]" value="1" onchange="syncField(this,'back-text-<?= $i ?>')" <?= $bst?'checked':''?>> Show text</label>
</div>
<div class="field-group<?= $bst?'':' hidden-field' ?>" id="back-text-<?= $i ?>">
<label>Text</label><input type="text" name="back_text[]" value="<?= htmlspecialchars($card['back_text'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. arrecife de coral">
</div>
<div class="field-group<?= $bsl?'':' hidden-field' ?>" id="back-audtext-<?= $i ?>">
<label>Audio text <small style="font-weight:400;color:#6b7280">(what the Listen button reads aloud)</small></label><input type="text" name="back_audio_text[]" value="<?= htmlspecialchars($card['back_audio_text'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Feeling distressed or embarrassed">
</div>
<div class="field-group<?= $bsi?'':' hidden-field' ?>" id="back-image-<?= $i ?>">
<label>Image</label><?php if (!empty($card['back_image'])) { ?><img src="<?= htmlspecialchars($card['back_image'], ENT_QUOTES, 'UTF-8') ?>" class="image-preview" alt=""><?php } ?><input type="file" name="back_image_file[]" accept="image/*">
</div>
</div>
</div>
<button type="button" class="btn-remove" onclick="this.closest('.card-item').remove();markChanged()">Remove</button>
</div>
<?php } ?>
</div>
<div class="toolbar-row"><button type="button" class="btn-add" onclick="addCard()">Add Card</button><button type="submit" class="save-btn">Save</button></div>
</form>
<script>
let formChanged=false,formSubmitted=false;
function markChanged(){formChanged=true}
function syncField(cb, fieldId){
  var el=document.getElementById(fieldId);
  if(el) el.classList.toggle('hidden-field',!cb.checked);
}
let cardCounter=<?= count($cards) ?>;
function cardHtml(){
  const i=cardCounter++;
  const id='flashcard_'+Date.now();
  return '<div class="card-item word-row">'
    +'<input type="hidden" name="card_id[]" value="'+id+'">'
    +'<input type="hidden" name="front_image_existing[]" value="">'
    +'<input type="hidden" name="back_image_existing[]" value="">'
    +'<input type="hidden" name="front_audio[]" value="">'
    +'<input type="hidden" name="back_audio[]" value="">'
    +'<div class="card-voice-row"><label>Voice (used for audio on both sides)</label><select name="voice_id[]"><option value="nzFihrBIvB34imQBuxub" selected>Jose</option><option value="NoOVOzCQFLOvtsMoNcdT">Lily</option><option value="Nggzl2QAXh3OijoXD116">Candy</option></select></div>'
    +'<div class="sides-grid">'
    +'<div class="side-box"><h4>▶ Front</h4>'
    +'<div class="show-opts">'
    +'<label><input type="checkbox" name="front_show_image['+i+']" value="1" onchange="syncField(this,\'front-image-'+i+'\')"> Show image</label>'
    +'<label><input type="checkbox" name="front_show_listen['+i+']" value="1" onchange="syncField(this,\'front-audtext-'+i+'\')"> Show listen</label>'
    +'<label><input type="checkbox" name="front_show_text['+i+']" value="1" onchange="syncField(this,\'front-text-'+i+'\')" checked> Show text</label>'
    +'</div>'
    +'<div class="field-group" id="front-text-'+i+'">'
    +'<label>Text</label><input type="text" name="front_text[]" placeholder="e.g. coral reef">'
    +'</div>'
    +'<div class="field-group hidden-field" id="front-audtext-'+i+'">'
    +'<label>Audio text <small style="font-weight:400;color:#6b7280">(what the Listen button reads aloud)</small></label><input type="text" name="front_audio_text[]" placeholder="e.g. Head in hands">'
    +'</div>'
    +'<div class="field-group hidden-field" id="front-image-'+i+'">'
    +'<label>Image</label><input type="file" name="front_image_file[]" accept="image/*">'
    +'</div>'
    +'</div>'
    +'<div class="side-box"><h4 class="back">◀ Back</h4>'
    +'<div class="show-opts">'
    +'<label><input type="checkbox" name="back_show_image['+i+']" value="1" onchange="syncField(this,\'back-image-'+i+'\')"> Show image</label>'
    +'<label><input type="checkbox" name="back_show_listen['+i+']" value="1" onchange="syncField(this,\'back-audtext-'+i+'\')"> Show listen</label>'
    +'<label><input type="checkbox" name="back_show_text['+i+']" value="1" onchange="syncField(this,\'back-text-'+i+'\')" checked> Show text</label>'
    +'</div>'
    +'<div class="field-group" id="back-text-'+i+'">'
    +'<label>Text</label><input type="text" name="back_text[]" placeholder="e.g. arrecife de coral">'
    +'</div>'
    +'<div class="field-group hidden-field" id="back-audtext-'+i+'">'
    +'<label>Audio text <small style="font-weight:400;color:#6b7280">(what the Listen button reads aloud)</small></label><input type="text" name="back_audio_text[]" placeholder="e.g. Feeling distressed or embarrassed">'
    +'</div>'
    +'<div class="field-group hidden-field" id="back-image-'+i+'">'
    +'<label>Image</label><input type="file" name="back_image_file[]" accept="image/*">'
    +'</div>'
    +'</div>'
    +'</div>'
    +'<button type="button" class="btn-remove" onclick="this.closest(\'.card-item\').remove();markChanged()">Remove</button>'
    +'</div>';
}
function addCard(){
  const c=document.getElementById('cardsContainer');
  c.insertAdjacentHTML('beforeend',cardHtml());
  c.lastElementChild.querySelectorAll('input,select').forEach(el=>el.addEventListener('input',markChanged));
  markChanged();
}
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('.flashcards-form input,.flashcards-form select').forEach(el=>el.addEventListener('input',markChanged));
  document.getElementById('flashcardsForm').addEventListener('submit',()=>{formSubmitted=true;formChanged=false});
});
window.addEventListener('beforeunload',e=>{if(formChanged&&!formSubmitted){e.preventDefault();e.returnValue=''}});
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Flashcards Editor', 'Cards', $content);
