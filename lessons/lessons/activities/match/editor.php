<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id'])         ? trim((string) $_GET['id'])         : '';
$unit       = isset($_GET['unit'])       ? trim((string) $_GET['unit'])       : '';
$source     = isset($_GET['source'])     ? trim((string) $_GET['source'])     : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

function activities_columns(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) return $cache;
    $cache = array();
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='activities'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) $cache[] = (string) $row['column_name'];
    }
    return $cache;
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $columns = activities_columns($pdo);
    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id=:id LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['unit_id'])) return (string) $row['unit_id'];
    }
    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT unit FROM activities WHERE id=:id LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['unit'])) return (string) $row['unit'];
    }
    return '';
}

function default_match_title(): string { return 'Match'; }

function normalize_match_mode(string $mode): string
{
    $mode = trim(strtolower($mode));
    if (in_array($mode, array('text_text', 'image_image', 'image_text'), true)) {
        return $mode;
    }
    return 'image_text';
}

function normalize_match_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_match_title();
}

function normalize_match_payload(mixed $rawData): array
{
    $default = array('title' => default_match_title(), 'mode' => 'image_text', 'pairs' => array());
    if ($rawData === null || $rawData === '') return $default;
    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) return $default;

    $title = isset($decoded['title']) ? trim((string) $decoded['title']) : '';
    $mode = isset($decoded['mode']) ? normalize_match_mode((string) $decoded['mode']) : 'image_text';
    $pairsSource = $decoded;
    if (isset($decoded['pairs']) && is_array($decoded['pairs'])) $pairsSource = $decoded['pairs'];
    elseif (isset($decoded['items']) && is_array($decoded['items'])) $pairsSource = $decoded['items'];
    elseif (isset($decoded['data']) && is_array($decoded['data'])) $pairsSource = $decoded['data'];

    $pairs = array();
    if (is_array($pairsSource)) {
        foreach ($pairsSource as $item) {
            if (!is_array($item)) continue;
            $legacyText  = isset($item['text'])  ? trim((string) $item['text'])  : (isset($item['word'])  ? trim((string) $item['word'])  : '');
            $legacyImage = isset($item['image']) ? trim((string) $item['image']) : (isset($item['img'])   ? trim((string) $item['img'])   : '');
            $pairs[] = array(
                'id'          => isset($item['id']) && trim((string) $item['id']) !== '' ? trim((string) $item['id']) : uniqid('match_'),
                'left_text'   => isset($item['left_text'])  ? trim((string) $item['left_text'])  : '',
                'left_image'  => isset($item['left_image']) ? trim((string) $item['left_image']) : $legacyImage,
                'right_text'  => isset($item['right_text']) ? trim((string) $item['right_text']) : $legacyText,
                'right_image' => isset($item['right_image'])? trim((string) $item['right_image']): '',
            );
        }
    }
    return array('title' => normalize_match_title($title), 'mode' => $mode, 'pairs' => $pairs);
}

function encode_match_payload(array $payload): string
{
    return json_encode(array(
        'title' => normalize_match_title(isset($payload['title']) ? (string) $payload['title'] : ''),
        'mode' => normalize_match_mode(isset($payload['mode']) ? (string) $payload['mode'] : 'image_text'),
        'pairs' => isset($payload['pairs']) && is_array($payload['pairs']) ? array_values($payload['pairs']) : array(),
    ), JSON_UNESCAPED_UNICODE);
}

function load_match_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = activities_columns($pdo);
    $selectFields = array('id');
    if (in_array('data', $columns, true))         $selectFields[] = 'data';
    if (in_array('content_json', $columns, true)) $selectFields[] = 'content_json';
    if (in_array('title', $columns, true))        $selectFields[] = 'title';
    if (in_array('name', $columns, true))         $selectFields[] = 'name';

    $fallback = array('id' => '', 'title' => default_match_title(), 'mode' => 'image_text', 'pairs' => array());
    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id=:id AND type='match' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id=:unit AND type='match' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit=:unit AND type='match' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;

    $rawData = isset($row['data']) ? $row['data'] : (isset($row['content_json']) ? $row['content_json'] : null);
    $payload = normalize_match_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '')      $columnTitle = trim((string) $row['title']);
    elseif (isset($row['name']) && trim((string) $row['name']) !== '')    $columnTitle = trim((string) $row['name']);
    if ($columnTitle !== '') $payload['title'] = $columnTitle;

    return array(
        'id'    => isset($row['id']) ? (string) $row['id'] : '',
        'title' => normalize_match_title((string) $payload['title']),
        'mode'  => normalize_match_mode((string) ($payload['mode'] ?? 'image_text')),
        'pairs' => isset($payload['pairs']) && is_array($payload['pairs']) ? $payload['pairs'] : array(),
    );
}

function save_match_activity(PDO $pdo, string $unit, string $activityId, string $title, string $mode, array $pairs): string
{
    $columns = activities_columns($pdo);
    $title   = normalize_match_title($title);
    $json    = encode_match_payload(array('title' => $title, 'mode' => $mode, 'pairs' => $pairs));

    $hasUnitId      = in_array('unit_id',      $columns, true);
    $hasUnit        = in_array('unit',          $columns, true);
    $hasData        = in_array('data',          $columns, true);
    $hasContentJson = in_array('content_json',  $columns, true);
    $hasId          = in_array('id',            $columns, true);
    $hasTitle       = in_array('title',         $columns, true);
    $hasName        = in_array('name',          $columns, true);

    $targetId = $activityId;
    if ($targetId === '') {
        if ($hasUnitId) {
            $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id=:unit AND type='match' ORDER BY id ASC LIMIT 1");
            $stmt->execute(array('unit' => $unit));
            $targetId = trim((string) $stmt->fetchColumn());
        }
        if ($targetId === '' && $hasUnit) {
            $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit=:unit AND type='match' ORDER BY id ASC LIMIT 1");
            $stmt->execute(array('unit' => $unit));
            $targetId = trim((string) $stmt->fetchColumn());
        }
    }

    if ($targetId !== '') {
        $setParts = array();
        $params   = array('id' => $targetId);
        if ($hasData)        { $setParts[] = 'data=:data';                   $params['data']         = $json; }
        if ($hasContentJson) { $setParts[] = 'content_json=:content_json';   $params['content_json'] = $json; }
        if ($hasTitle)       { $setParts[] = 'title=:title';                 $params['title']        = $title; }
        if ($hasName)        { $setParts[] = 'name=:name';                   $params['name']         = $title; }
        if (!empty($setParts)) {
            $stmt = $pdo->prepare("UPDATE activities SET " . implode(', ', $setParts) . " WHERE id=:id AND type='match'");
            $stmt->execute($params);
        }
        return $targetId;
    }

    $insertColumns = array(); $insertValues = array(); $params = array();
    $newId = '';
    if ($hasId)     { $newId = md5(random_bytes(16)); $insertColumns[] = 'id';    $insertValues[] = ':id';    $params['id'] = $newId; }
    if ($hasUnitId) { $insertColumns[] = 'unit_id'; $insertValues[] = ':unit_id'; $params['unit_id'] = $unit; }
    elseif ($hasUnit) { $insertColumns[] = 'unit';  $insertValues[] = ':unit';    $params['unit']    = $unit; }
    $insertColumns[] = 'type'; $insertValues[] = "'match'";
    if ($hasData)        { $insertColumns[] = 'data';         $insertValues[] = ':data';         $params['data']         = $json; }
    if ($hasContentJson) { $insertColumns[] = 'content_json'; $insertValues[] = ':content_json'; $params['content_json'] = $json; }
    if ($hasTitle)       { $insertColumns[] = 'title';        $insertValues[] = ':title';        $params['title']        = $title; }
    if ($hasName)        { $insertColumns[] = 'name';         $insertValues[] = ':name';         $params['name']         = $title; }

    $stmt = $pdo->prepare("INSERT INTO activities (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")");
    $stmt->execute($params);
    return $newId;
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}
if ($unit === '') die('Unit not specified');

$activity      = load_match_activity($pdo, $unit, $activityId);
$pairs         = isset($activity['pairs']) && is_array($activity['pairs']) ? $activity['pairs'] : array();
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_match_title();
$matchMode     = normalize_match_mode((string) ($activity['mode'] ?? 'image_text'));
if ($activityId === '' && !empty($activity['id'])) $activityId = (string) $activity['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle      = trim((string) ($_POST['activity_title']       ?? ''));
    $postedMode       = normalize_match_mode((string) ($_POST['match_mode'] ?? 'image_text'));
    $leftTexts        = (array) ($_POST['left_text']                  ?? []);
    $rightTexts       = (array) ($_POST['right_text']                 ?? []);
    $leftImages       = (array) ($_POST['left_image_existing']        ?? []);
    $rightImages      = (array) ($_POST['right_image_existing']       ?? []);
    $leftRemoveFlags  = (array) ($_POST['left_remove_image']          ?? []);
    $rightRemoveFlags = (array) ($_POST['right_remove_image']         ?? []);
    $ids              = (array) ($_POST['pair_id']                    ?? []);
    $leftImageFiles   = $_FILES['left_image_file']  ?? null;
    $rightImageFiles  = $_FILES['right_image_file'] ?? null;

    $sanitized  = array();
    $totalPairs = max(count($leftTexts), count($rightTexts), count($leftImages), count($rightImages), count($ids));

    for ($i = 0; $i < $totalPairs; $i++) {
        $leftText    = isset($leftTexts[$i])       ? trim((string) $leftTexts[$i])  : '';
        $rightText   = isset($rightTexts[$i])      ? trim((string) $rightTexts[$i]) : $leftText;
        $leftImage   = isset($leftImages[$i])      ? trim((string) $leftImages[$i]) : '';
        $rightImage  = isset($rightImages[$i])     ? trim((string) $rightImages[$i]): '';
        $pairId      = isset($ids[$i]) && trim((string) $ids[$i]) !== '' ? trim((string) $ids[$i]) : uniqid('match_');

        if (isset($leftRemoveFlags[$i])  && (string) $leftRemoveFlags[$i]  === '1') $leftImage  = '';
        if (isset($rightRemoveFlags[$i]) && (string) $rightRemoveFlags[$i] === '1') $rightImage = '';

        if ($leftImageFiles && isset($leftImageFiles['name'][$i]) && $leftImageFiles['name'][$i] !== '' && isset($leftImageFiles['tmp_name'][$i]) && $leftImageFiles['tmp_name'][$i] !== '') {
            $uploaded = upload_to_cloudinary($leftImageFiles['tmp_name'][$i]);
            if ($uploaded) $leftImage = $uploaded;
        }
        if ($rightImageFiles && isset($rightImageFiles['name'][$i]) && $rightImageFiles['name'][$i] !== '' && isset($rightImageFiles['tmp_name'][$i]) && $rightImageFiles['tmp_name'][$i] !== '') {
            $uploaded = upload_to_cloudinary($rightImageFiles['tmp_name'][$i]);
            if ($uploaded) $rightImage = $uploaded;
        }

        if ($leftText === '' && $leftImage === '' && $rightText === '' && $rightImage === '') continue;

        if ($postedMode === 'text_text') {
            if ($leftText === '' || $rightText === '') continue;
        } elseif ($postedMode === 'image_image') {
            if ($leftImage === '' || $rightImage === '') continue;
        } else {
            if ($leftText === '' || $rightImage === '') continue;
        }

        $sanitized[] = array('id' => $pairId, 'left_text' => $leftText, 'left_image' => $leftImage, 'right_text' => $rightText, 'right_image' => $rightImage);
    }

    $savedId = save_match_activity($pdo, $unit, $activityId, $postedTitle, $postedMode, $sanitized);
    $params  = array('unit=' . urlencode($unit), 'saved=1');
    if ($savedId !== '')    $params[] = 'id='         . urlencode($savedId);
    elseif ($activityId !== '') $params[] = 'id='    . urlencode($activityId);
    if ($assignment !== '') $params[] = 'assignment=' . urlencode($assignment);
    if ($source !== '')     $params[] = 'source='     . urlencode($source);
    header('Location: editor.php?' . implode('&', $params));
    exit;
}

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<style>
.me-form{max-width:960px;margin:0 auto;}
.me-title-wrap{background:#fff;border:1px solid #EDE9FA;border-radius:16px;padding:16px 20px;margin-bottom:20px;box-shadow:0 2px 10px rgba(127,119,221,.07);}
.me-title-wrap label{display:block;font-weight:900;font-size:13px;color:#534AB7;margin-bottom:8px;text-transform:uppercase;letter-spacing:.06em;}
.me-title-wrap input{width:100%;padding:10px 14px;border:1.5px solid #EDE9FA;border-radius:10px;font-size:15px;font-family:'Fredoka',sans-serif;font-weight:500;box-sizing:border-box;color:#271B5D;outline:none;}
.me-title-wrap input:focus{border-color:#7F77DD;}
.me-mode-wrap{background:#fff;border:1px solid #EDE9FA;border-radius:16px;padding:14px 20px;margin-bottom:16px;box-shadow:0 2px 10px rgba(127,119,221,.07);}
.me-mode-wrap label{display:block;font-weight:900;font-size:13px;color:#534AB7;margin-bottom:8px;text-transform:uppercase;letter-spacing:.06em;}
.me-mode-toggle{display:flex;gap:8px;flex-wrap:wrap;}
.me-mode-toggle input{display:none;}
.me-mode-pill{display:inline-flex;align-items:center;justify-content:center;padding:8px 14px;border:1.5px solid #EDE9FA;border-radius:999px;font-size:12px;font-weight:900;color:#534AB7;background:#fff;cursor:pointer;transition:.15s border-color,.15s background,.15s color;}
.me-mode-toggle input:checked + .me-mode-pill{border-color:#7F77DD;background:#EEEDFE;color:#534AB7;}
.me-pairs-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;}
.me-pair{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:10px;background:#fff;border:1px solid #EDE9FA;border-radius:14px;padding:10px 12px;box-shadow:0 2px 8px rgba(127,119,221,.07);}
.me-num{width:28px;height:28px;flex-shrink:0;border-radius:50%;background:#F97316;color:#fff;font-size:12px;font-weight:900;display:flex;align-items:center;justify-content:center;font-family:'Nunito',sans-serif;}
.me-pair-sides{display:grid;grid-template-columns:1fr 1fr;gap:10px;min-width:0;}
.me-side{display:grid;grid-template-columns:minmax(0,1fr) 52px;gap:8px;align-items:center;}
.me-word-input{width:100%;min-width:0;padding:8px 12px;border:1.5px solid #EDE9FA;border-radius:10px;font-size:14px;font-family:'Fredoka',sans-serif;font-weight:500;color:#271B5D;outline:none;box-sizing:border-box;}
.me-word-input:focus{border-color:#7F77DD;}
.me-img-box{width:52px;height:52px;flex-shrink:0;border:2px dashed #C4BFEE;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#FAFAFD;transition:border-color .15s;}
.me-img-box:hover{border-color:#7F77DD;}
.me-img-box img,.me-img-box video{width:100%;height:100%;object-fit:cover;display:block;}
.me-del{width:28px;height:28px;flex-shrink:0;background:transparent;border:1.5px solid #EDE9FA;border-radius:50%;color:#9B94BE;font-size:16px;font-weight:900;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:border-color .15s,color .15s;padding:0;line-height:1;}
.me-del:hover{border-color:#ef4444;color:#ef4444;}
.me-pair.is-invalid{border-color:#fca5a5;box-shadow:0 0 0 2px rgba(239,68,68,.14);}
.me-pair.is-invalid .me-word-input,
.me-pair.is-invalid .me-img-box{border-color:#ef4444!important;}
.me-pair.me-mode-text_text .me-side-left .me-img-box,
.me-pair.me-mode-text_text .me-side-right .me-img-box,
.me-pair.me-mode-image_text .me-side-left .me-img-box,
.me-pair.me-mode-image_text .me-side-right .me-word-input,
.me-pair.me-mode-image_image .me-side-left .me-word-input,
.me-pair.me-mode-image_image .me-side-right .me-word-input{display:none;}
.me-add-btn{width:100%;padding:14px;border:2px dashed #C4BFEE;border-radius:14px;background:transparent;color:#7F77DD;font-family:'Nunito',sans-serif;font-size:14px;font-weight:900;cursor:pointer;transition:border-color .15s,background .15s;margin-bottom:20px;}
.me-add-btn:hover{border-color:#7F77DD;background:#FAFAFD;}
.me-error{display:none;margin:-10px 0 14px;padding:10px 12px;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:10px;font-weight:800;font-size:13px;}
.me-error.show{display:block;}
.me-toolbar{display:flex;justify-content:center;margin-top:4px;}
.me-save-btn{background:linear-gradient(180deg,#7F77DD,#534AB7);color:#fff;padding:12px 28px;border:none;border-radius:999px;cursor:pointer;font-weight:900;font-family:'Nunito',sans-serif;font-size:14px;box-shadow:0 4px 14px rgba(127,119,221,.3);transition:filter .15s,transform .15s;}
.me-save-btn:hover{filter:brightness(1.07);transform:translateY(-1px);}
.me-saved{color:#16a34a;font-weight:900;text-align:center;margin-bottom:14px;}
@media(max-width:880px){.me-pairs-grid{grid-template-columns:1fr;}}
@media(max-width:640px){.me-pair-sides{grid-template-columns:1fr;}}
</style>

<?php if (isset($_GET['saved'])): ?>
<p class="me-saved">✔ Saved successfully</p>
<?php endif; ?>

<form class="me-form" id="meForm" method="post" enctype="multipart/form-data">

    <div class="me-title-wrap">
        <label for="me-title">Activity title</label>
        <input id="me-title" type="text" name="activity_title"
               value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="e.g. Match the animals" required>
    </div>

    <div class="me-mode-wrap">
        <label>Pair mode</label>
        <div class="me-mode-toggle" id="meModeToggle">
            <label>
                <input type="radio" name="match_mode" value="text_text" <?= $matchMode === 'text_text' ? 'checked' : '' ?>>
                <span class="me-mode-pill">Text + Text</span>
            </label>
            <label>
                <input type="radio" name="match_mode" value="image_image" <?= $matchMode === 'image_image' ? 'checked' : '' ?>>
                <span class="me-mode-pill">Image + Image</span>
            </label>
            <label>
                <input type="radio" name="match_mode" value="image_text" <?= $matchMode === 'image_text' ? 'checked' : '' ?>>
                <span class="me-mode-pill">Image + Text</span>
            </label>
        </div>
    </div>

    <div class="me-pairs-grid" id="mePairs">
        <?php foreach ($pairs as $i => $pair):
            $pId    = htmlspecialchars(isset($pair['id'])          ? $pair['id']          : uniqid('match_'), ENT_QUOTES, 'UTF-8');
            $wVal   = htmlspecialchars(isset($pair['left_text'])   ? $pair['left_text']   : '', ENT_QUOTES, 'UTF-8');
            $rtVal  = htmlspecialchars(isset($pair['right_text'])  ? $pair['right_text']  : $pair['left_text'] ?? '', ENT_QUOTES, 'UTF-8');
            $liVal  = htmlspecialchars(isset($pair['left_image'])  ? $pair['left_image']  : '', ENT_QUOTES, 'UTF-8');
            $riRaw  = isset($pair['right_image']) ? trim((string) $pair['right_image']) : '';
            $riVal  = htmlspecialchars($riRaw, ENT_QUOTES, 'UTF-8');
            $isVid  = (bool) preg_match('/\.(mp4|webm|ogg|mov|m4v)$/i', $riRaw);
        ?>
        <div class="me-pair me-mode-<?= htmlspecialchars($matchMode, ENT_QUOTES, 'UTF-8') ?>">
            <div class="me-num"><?= $i + 1 ?></div>
            <input type="hidden" name="pair_id[]"             value="<?= $pId ?>">
            <input type="hidden" name="left_image_existing[]" value="<?= $liVal ?>">
            <input type="hidden" name="left_remove_image[]"   value="0">
            <input type="hidden" name="right_image_existing[]" class="me-ri-existing" value="<?= $riVal ?>">
            <input type="hidden" name="right_remove_image[]"  class="me-ri-remove"   value="0">
            <div class="me-pair-sides">
                <div class="me-side me-side-left">
                    <input type="text" name="left_text[]" class="me-word-input" value="<?= $wVal ?>" placeholder="Left text…">
                    <div class="me-img-box" onclick="meOpenFile(this)">
                        <?php if (trim((string) $liVal) !== ''): ?>
                            <img src="<?= $liVal ?>" alt="">
                        <?php else: ?>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#C4BFEE" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        <?php endif; ?>
                    </div>
                    <input type="file" name="left_image_file[]" accept="image/*,video/*" class="me-file-in me-file-left" style="display:none">
                </div>

                <div class="me-side me-side-right">
                    <input type="text" name="right_text[]" class="me-word-input me-rt" value="<?= $rtVal ?>" placeholder="Right text…">
                    <div class="me-img-box" onclick="meOpenFile(this)">
                        <?php if ($riRaw !== ''): ?>
                            <?php if ($isVid): ?>
                                <video src="<?= $riVal ?>" autoplay muted loop playsinline></video>
                            <?php else: ?>
                                <img src="<?= $riVal ?>" alt="">
                            <?php endif; ?>
                        <?php else: ?>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#C4BFEE" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        <?php endif; ?>
                    </div>
                    <input type="file" name="right_image_file[]" accept="image/*,video/*" class="me-file-in me-file-right" style="display:none">
                </div>
            </div>
            <button type="button" class="me-del" onclick="meDel(this)" title="Remove">×</button>
        </div>
        <?php endforeach; ?>
    </div>

    <button type="button" class="me-add-btn" onclick="meAdd()">+ Add pair</button>

    <div id="meError" class="me-error" role="alert" aria-live="polite"></div>

    <div class="me-toolbar">
        <button type="submit" class="me-save-btn">💾 Save</button>
    </div>

</form>

<script>
let meChanged = false, meSubmitted = false;
const meMark = () => meChanged = true;

function meShowError(message) {
    const box = document.getElementById('meError');
    if (!box) return;
    box.textContent = message || '';
    box.classList.toggle('show', !!message);
}

function meClearValidationUI() {
    document.querySelectorAll('#mePairs .me-pair.is-invalid').forEach((pair) => {
        pair.classList.remove('is-invalid');
    });
    meShowError('');
}

function meCurrentMode() {
    const checked = document.querySelector('input[name="match_mode"]:checked');
    return checked ? checked.value : 'image_text';
}

function meApplyModeToPair(pair, mode) {
    pair.classList.remove('me-mode-text_text', 'me-mode-image_image', 'me-mode-image_text');
    pair.classList.add('me-mode-' + mode);
}

function meApplyModeToAll() {
    const mode = meCurrentMode();
    document.querySelectorAll('#mePairs .me-pair').forEach((pair) => meApplyModeToPair(pair, mode));
    meClearValidationUI();
}

function mePairHasImage(pair, side) {
    const existing = pair.querySelector(side === 'left' ? 'input[name="left_image_existing[]"]' : 'input[name="right_image_existing[]"]');
    const remove = pair.querySelector(side === 'left' ? 'input[name="left_remove_image[]"]' : 'input[name="right_remove_image[]"]');
    const file = pair.querySelector(side === 'left' ? '.me-file-left' : '.me-file-right');

    const existingVal = existing ? String(existing.value || '').trim() : '';
    const removeVal = remove ? String(remove.value || '').trim() : '0';
    const hasFile = !!(file && file.files && file.files.length > 0);

    if (hasFile) return true;
    return existingVal !== '' && removeVal !== '1';
}

function meValidateBeforeSubmit() {
    const mode = meCurrentMode();
    const rows = Array.from(document.querySelectorAll('#mePairs .me-pair'));
    let invalidCount = 0;
    let validCount = 0;

    meClearValidationUI();

    rows.forEach((pair) => {
        const leftTextEl = pair.querySelector('input[name="left_text[]"]');
        const rightTextEl = pair.querySelector('input[name="right_text[]"]');
        const leftText = leftTextEl ? String(leftTextEl.value || '').trim() : '';
        const rightText = rightTextEl ? String(rightTextEl.value || '').trim() : '';
        const hasLeftImage = mePairHasImage(pair, 'left');
        const hasRightImage = mePairHasImage(pair, 'right');

        const emptyRow = leftText === '' && rightText === '' && !hasLeftImage && !hasRightImage;
        if (emptyRow) return;

        let rowValid = false;
        if (mode === 'text_text') {
            rowValid = leftText !== '' && rightText !== '';
        } else if (mode === 'image_image') {
            rowValid = hasLeftImage && hasRightImage;
        } else {
            rowValid = leftText !== '' && hasRightImage;
        }

        if (!rowValid) {
            invalidCount += 1;
            pair.classList.add('is-invalid');
        } else {
            validCount += 1;
        }
    });

    if (invalidCount > 0) {
        meShowError('Fix ' + invalidCount + ' invalid pair(s) before saving.');
        return false;
    }

    if (validCount === 0) {
        meShowError('Add at least 1 valid pair before saving.');
        return false;
    }

    return true;
}

function meOpenFile(box) {
    const input = box.parentElement.querySelector('input[type="file"]');
    if (input) input.click();
}

function meDel(btn) {
    btn.closest('.me-pair').remove();
    meRenumber();
    meClearValidationUI();
    meMark();
}

function meRenumber() {
    document.querySelectorAll('#mePairs .me-pair').forEach((p, i) => {
        const badge = p.querySelector('.me-num');
        if (badge) badge.textContent = i + 1;
    });
}

function meAdd() {
    const grid = document.getElementById('mePairs');
    const n    = grid.querySelectorAll('.me-pair').length + 1;
    const mode = meCurrentMode();
    const id   = 'match_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    const div  = document.createElement('div');
    div.className = 'me-pair me-mode-' + mode;
    div.innerHTML = `
        <div class="me-num">${n}</div>
        <input type="hidden" name="pair_id[]" value="${id}">
        <input type="hidden" name="left_image_existing[]" value="">
        <input type="hidden" name="left_remove_image[]" value="0">
        <input type="hidden" name="right_image_existing[]" class="me-ri-existing" value="">
        <input type="hidden" name="right_remove_image[]" class="me-ri-remove" value="0">
        <div class="me-pair-sides">
            <div class="me-side me-side-left">
                <input type="text" name="left_text[]" class="me-word-input" placeholder="Left text…">
                <div class="me-img-box" onclick="meOpenFile(this)">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#C4BFEE" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </div>
                <input type="file" name="left_image_file[]" accept="image/*,video/*" class="me-file-in me-file-left" style="display:none">
            </div>
            <div class="me-side me-side-right">
                <input type="text" name="right_text[]" class="me-word-input me-rt" placeholder="Right text…">
                <div class="me-img-box" onclick="meOpenFile(this)">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#C4BFEE" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </div>
                <input type="file" name="right_image_file[]" accept="image/*,video/*" class="me-file-in me-file-right" style="display:none">
            </div>
        </div>
        <button type="button" class="me-del" onclick="meDel(this)" title="Remove">×</button>
    `;
    grid.appendChild(div);
    meBindPair(div);
    meClearValidationUI();
    meMark();
}

function meBindPair(pair) {
    const leftFileIn  = pair.querySelector('.me-file-left');
    const rightFileIn = pair.querySelector('.me-file-right');
    const leftWordIn  = pair.querySelector('input[name="left_text[]"]');
    const rightWordIn = pair.querySelector('input[name="right_text[]"]');
    const leftImgBox  = pair.querySelector('.me-side-left .me-img-box');
    const rightImgBox = pair.querySelector('.me-side-right .me-img-box');
    const liExist     = pair.querySelector('input[name="left_image_existing[]"]');
    const liRem       = pair.querySelector('input[name="left_remove_image[]"]');
    const riExist = pair.querySelector('.me-ri-existing');
    const riRem   = pair.querySelector('.me-ri-remove');

    if (leftWordIn) {
        leftWordIn.addEventListener('input', meMark);
    }
    if (rightWordIn) {
        rightWordIn.addEventListener('input', meMark);
    }

    if (leftFileIn && leftImgBox) {
        leftFileIn.addEventListener('change', () => {
            const file = leftFileIn.files && leftFileIn.files[0];
            if (!file) return;
            const url  = URL.createObjectURL(file);
            const isVid = file.type.startsWith('video/');
            leftImgBox.innerHTML = isVid
                ? `<video src="${url}" autoplay muted loop playsinline></video>`
                : `<img src="${url}" alt="">`;
            if (liExist) liExist.value = '';
            if (liRem)   liRem.value   = '0';
            meMark();
        });
    }

    if (rightFileIn && rightImgBox) {
        rightFileIn.addEventListener('change', () => {
            const file = rightFileIn.files && rightFileIn.files[0];
            if (!file) return;
            const url  = URL.createObjectURL(file);
            const isVid = file.type.startsWith('video/');
            rightImgBox.innerHTML = isVid
                ? `<video src="${url}" autoplay muted loop playsinline></video>`
                : `<img src="${url}" alt="">`;
            if (riExist) riExist.value = '';
            if (riRem)   riRem.value   = '0';
            meMark();
        });
    }

    pair.querySelectorAll('input[type=text],input[type=hidden]').forEach(el => {
        el.addEventListener('input', () => {
            meClearValidationUI();
            meMark();
        });
        el.addEventListener('change', () => {
            meClearValidationUI();
            meMark();
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('#mePairs .me-pair').forEach(meBindPair);
    document.querySelectorAll('input[name="match_mode"]').forEach((input) => {
        input.addEventListener('change', () => {
            meApplyModeToAll();
            meMark();
        });
    });
    meApplyModeToAll();
    const form = document.getElementById('meForm');
    if (form) {
        form.addEventListener('submit', (e) => {
            if (!meValidateBeforeSubmit()) {
                e.preventDefault();
                return;
            }
            meSubmitted = true;
            meChanged = false;
        });
    }
});

window.addEventListener('beforeunload', e => {
    if (meChanged && !meSubmitted) { e.preventDefault(); e.returnValue = ''; }
});
</script>

<?php
$content = ob_get_clean();
render_activity_editor('🧩 Match Editor', '🧩', $content);
