<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db.php';
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

function wpe_columns(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) return $cache;
    $cache = array();
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'activities'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) $cache[] = (string) $row['column_name'];
    }
    return $cache;
}

function wpe_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $cols = wpe_columns($pdo);
    foreach (array('unit_id','unit') as $col) {
        if (in_array($col, $cols, true)) {
            $stmt = $pdo->prepare("SELECT $col FROM activities WHERE id = :id LIMIT 1");
            $stmt->execute(array('id' => $activityId));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row[$col])) return (string) $row[$col];
        }
    }
    return '';
}

function wpe_default_title(): string { return 'Writing Practice'; }

const WPE_GRAMMAR_OPTIONS = array(
    'present_tense'               => 'Present Tense',
    'present_continuous'         => 'Present Continuous',
    'past_tense'                  => 'Past Tense',
    'future_tense'                => 'Future Tense',
    'present_perfect'             => 'Present Perfect',
    'present_perfect_continuous' => 'Present Perfect Continuous',
    'past_perfect'                => 'Past Perfect',
    'past_perfect_continuous'    => 'Past Perfect Continuous',
    'future_perfect'              => 'Future Perfect',
    'future_perfect_continuous'  => 'Future Perfect Continuous',
    'conditionals'                => 'Conditionals',
    'passive_voice'               => 'Passive Voice',
    'clauses'                     => 'Clauses',
);

function wpe_list_from_lines($raw): array
{
    $raw = (string) $raw;
    $parts = preg_split('/[\r\n,]+/', $raw);
    $out = array();
    foreach ($parts as $p) {
        $p = trim((string) $p);
        if ($p !== '') $out[] = $p;
    }
    return $out;
}

function wpe_normalize_payload($rawData): array
{
    $default = array('title' => wpe_default_title(), 'items' => array());
    if ($rawData === null || $rawData === '') return $default;
    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) return $default;

    $title = isset($decoded['title']) ? trim((string)$decoded['title']) : '';
    $src   = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : $decoded;
    $items = array();
    foreach ($src as $item) {
        if (!is_array($item)) continue;
        $mode = isset($item['mode']) ? trim((string)$item['mode']) : 'exact';
        if ($mode !== 'essay') $mode = 'exact';

        $grammar = array();
        if (isset($item['grammar_checklist']) && is_array($item['grammar_checklist'])) {
            foreach ($item['grammar_checklist'] as $g) {
                $g = trim((string)$g);
                if (isset(WPE_GRAMMAR_OPTIONS[$g])) $grammar[] = $g;
            }
        }

        $items[] = array(
            'id'                => isset($item['id'])          ? trim((string)$item['id'])          : uniqid('wp_'),
            'instruction'       => isset($item['instruction']) ? trim((string)$item['instruction']) : '',
            'prompt_text'       => isset($item['prompt_text']) ? trim((string)$item['prompt_text']) : '',
            'answer'            => isset($item['answer'])      ? trim((string)$item['answer'])      : '',
            'mode'              => $mode,
            'min_words'         => isset($item['min_words'])      ? max(0, (int)$item['min_words'])      : 0,
            'max_words'         => isset($item['max_words'])      ? max(0, (int)$item['max_words'])      : 0,
            'min_sentences'     => isset($item['min_sentences'])  ? max(0, (int)$item['min_sentences'])  : 0,
            'max_sentences'     => isset($item['max_sentences'])  ? max(0, (int)$item['max_sentences'])  : 0,
            'required_any'      => isset($item['required_any']) && is_array($item['required_any']) ? array_values(array_filter(array_map('trim', $item['required_any']))) : array(),
            'vocab_bank'        => isset($item['vocab_bank']) && is_array($item['vocab_bank']) ? array_values(array_filter(array_map('trim', $item['vocab_bank']))) : array(),
            'vocab_min'         => isset($item['vocab_min'])       ? max(0, (int)$item['vocab_min'])      : 0,
            'grammar_checklist' => $grammar,
            'sentence_guide'    => isset($item['sentence_guide']) && is_array($item['sentence_guide']) ? array_values(array_filter(array_map('trim', $item['sentence_guide']))) : array(),
        );
    }
    return array('title' => $title !== '' ? $title : wpe_default_title(), 'items' => $items);
}

function wpe_load(PDO $pdo, string $unit, string $activityId): array
{
    $cols = wpe_columns($pdo);
    $fields = array('id');
    foreach (array('data','content_json','title','name') as $f) {
        if (in_array($f, $cols, true)) $fields[] = $f;
    }

    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(',', $fields) . " FROM activities WHERE id = :id AND type = 'writing_practice' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit_id', $cols, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(',', $fields) . " FROM activities WHERE unit_id = :u AND type = 'writing_practice' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('u' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit', $cols, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(',', $fields) . " FROM activities WHERE unit = :u AND type = 'writing_practice' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('u' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return array('id' => '', 'title' => wpe_default_title(), 'items' => array());

    $raw = $row['data'] ?? ($row['content_json'] ?? null);
    $payload = wpe_normalize_payload($raw);

    $colTitle = '';
    if (!empty($row['title'])) $colTitle = trim((string)$row['title']);
    elseif (!empty($row['name'])) $colTitle = trim((string)$row['name']);
    if ($colTitle !== '') $payload['title'] = $colTitle;

    return array('id' => (string)($row['id'] ?? ''), 'title' => $payload['title'], 'items' => $payload['items']);
}

function wpe_save(PDO $pdo, string $unit, string $activityId, string $title, array $items): string
{
    $cols   = wpe_columns($pdo);
    $title  = trim($title) !== '' ? trim($title) : wpe_default_title();
    $json   = json_encode(array('title' => $title, 'items' => array_values($items)), JSON_UNESCAPED_UNICODE);

    $hasUnitId = in_array('unit_id', $cols, true);
    $hasUnit   = in_array('unit',    $cols, true);
    $hasData   = in_array('data',    $cols, true);
    $hasCJ     = in_array('content_json', $cols, true);
    $hasTitle  = in_array('title',   $cols, true);
    $hasName   = in_array('name',    $cols, true);

    $targetId = $activityId;
    if ($targetId === '') {
        foreach (array('unit_id' => 'unit_id', 'unit' => 'unit') as $col => $param) {
            if (in_array($col, $cols, true)) {
                $stmt = $pdo->prepare("SELECT id FROM activities WHERE $col = :u AND type = 'writing_practice' ORDER BY id ASC LIMIT 1");
                $stmt->execute(array('u' => $unit));
                $targetId = (string)$stmt->fetchColumn();
                if ($targetId !== '') break;
            }
        }
    }

    if ($targetId !== '') {
        $set = array(); $params = array('id' => $targetId);
        if ($hasData)  { $set[] = 'data = :data';               $params['data'] = $json; }
        if ($hasCJ)    { $set[] = 'content_json = :cj';         $params['cj']   = $json; }
        if ($hasTitle) { $set[] = 'title = :title';             $params['title']= $title; }
        if ($hasName)  { $set[] = 'name = :name';               $params['name'] = $title; }
        if (!empty($set)) {
            $stmt = $pdo->prepare("UPDATE activities SET " . implode(', ', $set) . " WHERE id = :id AND type = 'writing_practice'");
            $stmt->execute($params);
        }
        return $targetId;
    }

    $iCols = array(); $iVals = array(); $params = array();
    $newId = md5(random_bytes(16));
    if (in_array('id', $cols, true)) { $iCols[] = 'id'; $iVals[] = ':id'; $params['id'] = $newId; }
    if ($hasUnitId) { $iCols[] = 'unit_id'; $iVals[] = ':u'; $params['u'] = $unit; }
    elseif ($hasUnit) { $iCols[] = 'unit'; $iVals[] = ':u'; $params['u'] = $unit; }
    $iCols[] = 'type'; $iVals[] = "'writing_practice'";
    if ($hasData)  { $iCols[] = 'data';         $iVals[] = ':data';  $params['data']  = $json; }
    if ($hasCJ)    { $iCols[] = 'content_json'; $iVals[] = ':cj';    $params['cj']    = $json; }
    if ($hasTitle) { $iCols[] = 'title';        $iVals[] = ':title'; $params['title'] = $title; }
    if ($hasName)  { $iCols[] = 'name';         $iVals[] = ':name';  $params['name']  = $title; }
    $stmt = $pdo->prepare("INSERT INTO activities (" . implode(',', $iCols) . ") VALUES (" . implode(',', $iVals) . ")");
    $stmt->execute($params);
    return $newId;
}

if ($unit === '' && $activityId !== '') $unit = wpe_resolve_unit($pdo, $activityId);
if ($unit === '') die('Unit not specified');

$activity    = wpe_load($pdo, $unit, $activityId);
$items       = $activity['items'];
$activityTitle = $activity['title'];
if ($activityId === '' && !empty($activity['id'])) $activityId = $activity['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = isset($_POST['activity_title']) ? trim((string)$_POST['activity_title']) : '';
    $instructions = isset($_POST['instruction'])  && is_array($_POST['instruction'])  ? $_POST['instruction']  : array();
    $prompts      = isset($_POST['prompt_text'])  && is_array($_POST['prompt_text'])  ? $_POST['prompt_text']  : array();
    $answers      = isset($_POST['answer'])       && is_array($_POST['answer'])       ? $_POST['answer']       : array();
    $ids          = isset($_POST['item_id'])      && is_array($_POST['item_id'])      ? $_POST['item_id']      : array();
    $modes        = isset($_POST['mode'])         && is_array($_POST['mode'])         ? $_POST['mode']         : array();
    $minWords     = isset($_POST['min_words'])    && is_array($_POST['min_words'])    ? $_POST['min_words']    : array();
    $maxWords     = isset($_POST['max_words'])    && is_array($_POST['max_words'])    ? $_POST['max_words']    : array();
    $minSent      = isset($_POST['min_sentences']) && is_array($_POST['min_sentences']) ? $_POST['min_sentences'] : array();
    $maxSent      = isset($_POST['max_sentences']) && is_array($_POST['max_sentences']) ? $_POST['max_sentences'] : array();
    $requiredAny  = isset($_POST['required_any']) && is_array($_POST['required_any']) ? $_POST['required_any'] : array();
    $vocabBank    = isset($_POST['vocab_bank'])   && is_array($_POST['vocab_bank'])   ? $_POST['vocab_bank']   : array();
    $vocabMin     = isset($_POST['vocab_min'])    && is_array($_POST['vocab_min'])    ? $_POST['vocab_min']    : array();
    $grammarSel   = isset($_POST['grammar_checklist']) && is_array($_POST['grammar_checklist']) ? $_POST['grammar_checklist'] : array();
    $sentGuide    = isset($_POST['sentence_guide']) && is_array($_POST['sentence_guide']) ? $_POST['sentence_guide'] : array();

    $sanitized = array();
    foreach ($prompts as $i => $promptRaw) {
        $instruction = trim((string)($instructions[$i] ?? ''));
        $prompt      = trim((string)$promptRaw);
        $answer      = trim((string)($answers[$i]  ?? ''));
        $itemId      = trim((string)($ids[$i]      ?? '')) ?: uniqid('wp_');
        if ($prompt === '' && $answer === '') continue;

        $mode = trim((string)($modes[$itemId] ?? 'exact'));
        if ($mode !== 'essay') $mode = 'exact';

        $grammarRaw = isset($grammarSel[$itemId]) && is_array($grammarSel[$itemId]) ? $grammarSel[$itemId] : array();
        $grammar = array();
        foreach ($grammarRaw as $g) {
            $g = trim((string)$g);
            if (isset(WPE_GRAMMAR_OPTIONS[$g])) $grammar[] = $g;
        }

        $sanitized[] = array(
            'id'                => $itemId,
            'instruction'       => $instruction,
            'prompt_text'       => $prompt,
            'answer'            => $answer,
            'mode'              => $mode,
            'min_words'         => max(0, (int)($minWords[$i] ?? 0)),
            'max_words'         => max(0, (int)($maxWords[$i] ?? 0)),
            'min_sentences'     => max(0, (int)($minSent[$i] ?? 0)),
            'max_sentences'     => max(0, (int)($maxSent[$i] ?? 0)),
            'required_any'      => wpe_list_from_lines($requiredAny[$i] ?? ''),
            'vocab_bank'        => wpe_list_from_lines($vocabBank[$i] ?? ''),
            'vocab_min'         => max(0, (int)($vocabMin[$i] ?? 0)),
            'grammar_checklist' => $grammar,
            'sentence_guide'    => wpe_list_from_lines($sentGuide[$i] ?? ''),
        );
    }

    $savedId = wpe_save($pdo, $unit, $activityId, $postedTitle, $sanitized);
    $qs = array('unit=' . urlencode($unit), 'saved=1');
    if ($savedId !== '')    $qs[] = 'id=' . urlencode($savedId);
    elseif ($activityId !== '') $qs[] = 'id=' . urlencode($activityId);
    if ($assignment !== '') $qs[] = 'assignment=' . urlencode($assignment);
    if ($source !== '')     $qs[] = 'source=' . urlencode($source);
    header('Location: editor.php?' . implode('&', $qs));
    exit;
}

ob_start();
?>
<style>
.wp-editor-form{max-width:860px;margin:0 auto;font-family:'Nunito','Segoe UI',sans-serif;}
.wp-editor-title-box{background:#f9fafb;padding:14px;margin-bottom:16px;border-radius:12px;border:1px solid #e5e7eb;}
.wp-editor-title-box label{display:block;font-weight:700;margin-bottom:6px;font-size:14px;}
.wp-editor-title-box input{width:100%;padding:10px 12px;border-radius:8px;border:1px solid #ccc;font-size:15px;font-family:'Nunito',sans-serif;}
.wp-editor-item{background:#f9fafb;padding:16px;margin-bottom:14px;border-radius:12px;border:1px solid #e5e7eb;}
.wp-editor-item-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
.wp-editor-item-num{font-weight:900;font-size:13px;color:#534AB7;background:#EEEDFE;border-radius:999px;padding:3px 12px;}
.wp-editor-label{display:block;font-weight:700;font-size:13px;margin-bottom:5px;color:#271B5D;}
.wp-editor-input{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:'Nunito',sans-serif;margin-bottom:10px;}
.wp-editor-textarea{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:'Nunito',sans-serif;min-height:80px;resize:vertical;margin-bottom:10px;}
.wp-editor-textarea.answer{border-color:#9FE1CB;background:#f0fdf9;}
.wp-editor-hint{font-size:12px;color:#9B94BE;font-weight:700;margin-top:-6px;margin-bottom:10px;}
.wp-toolbar{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:12px;}
.wp-btn-add{background:#7F77DD;color:#fff;padding:10px 16px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-family:'Nunito',sans-serif;}
.wp-btn-save{background:#F97316;color:#fff;padding:10px 22px;border:none;border-radius:10px;cursor:pointer;font-weight:900;font-family:'Nunito',sans-serif;font-size:15px;}
.wp-btn-remove{background:#ef4444;color:#fff;border:none;padding:7px 12px;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;}
.wp-saved-msg{color:#1D9E75;font-weight:900;margin-bottom:14px;}
.wp-mode-toggle{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;}
.wp-mode-opt{flex:1;min-width:180px;position:relative;}
.wp-mode-opt input{position:absolute;opacity:0;}
.wp-mode-opt span{display:block;padding:10px 12px;border-radius:10px;border:1.5px solid #d1d5db;font-weight:800;font-size:13px;text-align:center;cursor:pointer;color:#534AB7;background:#fff;transition:.15s;}
.wp-mode-opt input:checked + span{background:#7F77DD;border-color:#7F77DD;color:#fff;}
.wp-rubric-box{background:#FAFAFE;border:1.5px dashed #EDE9FA;border-radius:12px;padding:14px;margin-bottom:10px;display:none;}
.wp-rubric-box.show{display:block;}
.wp-rubric-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.wp-rubric-grammar{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;}
.wp-rubric-grammar label{display:flex;align-items:center;gap:5px;font-size:13px;font-weight:700;color:#271B5D;background:#fff;border:1px solid #EDE9FA;border-radius:8px;padding:6px 10px;}
</style>

<?php if (isset($_GET['saved'])): ?>
    <p class="wp-saved-msg">&#10004; Saved successfully</p>
<?php endif; ?>

<form class="wp-editor-form" id="wpEditorForm" method="post">
    <div class="wp-editor-title-box">
        <label for="wp_activity_title">Activity title</label>
        <input id="wp_activity_title" type="text" name="activity_title"
               value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="e.g. Translation Practice — Unit 4" required>
    </div>

    <div id="wpItemsContainer">
    <?php foreach ($items as $i => $item): ?>
        <div class="wp-editor-item">
            <div class="wp-editor-item-header">
                <span class="wp-editor-item-num">Prompt <?= $i+1 ?></span>
                <button type="button" class="wp-btn-remove" onclick="removeItem(this)">&#10006; Remove</button>
            </div>
            <input type="hidden" name="item_id[]" value="<?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?>">

            <label class="wp-editor-label">Instruction shown to student</label>
            <input type="text" class="wp-editor-input" name="instruction[]"
                   value="<?= htmlspecialchars($item['instruction'], ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="e.g. Translate to English">

            <label class="wp-editor-label">Text / prompt the student reads</label>
            <textarea class="wp-editor-textarea" name="prompt_text[]"
                      placeholder="Write the source text here (e.g. Spanish paragraph to translate)"><?= htmlspecialchars($item['prompt_text'], ENT_QUOTES, 'UTF-8') ?></textarea>

            <label class="wp-editor-label">Scoring mode</label>
            <div class="wp-mode-toggle">
                <label class="wp-mode-opt">
                    <input type="radio" name="mode[<?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?>]" value="exact" onchange="wpToggleMode(this)" <?= $item['mode'] !== 'essay' ? 'checked' : '' ?>>
                    <span>&#127919; Exact match</span>
                </label>
                <label class="wp-mode-opt">
                    <input type="radio" name="mode[<?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?>]" value="essay" onchange="wpToggleMode(this)" <?= $item['mode'] === 'essay' ? 'checked' : '' ?>>
                    <span>&#128221; Essay / rubric</span>
                </label>
            </div>

            <label class="wp-editor-label" style="color:#0F6E56;">Model answer <span class="wp-editor-hint" style="margin:0;display:inline;">(reference text for teachers)</span></label>
            <p class="wp-editor-hint">In <b>Exact match</b> mode the student's answer is compared word by word against this text and it is shown to the student. In <b>Essay / rubric</b> mode this text is for teacher reference only — it is never shown to the student, who is scored against the rubric below instead.</p>
            <textarea class="wp-editor-textarea answer" name="answer[]"
                      placeholder="Write the correct answer here..."><?= htmlspecialchars($item['answer'], ENT_QUOTES, 'UTF-8') ?></textarea>

            <div class="wp-rubric-box <?= $item['mode'] === 'essay' ? 'show' : '' ?>">
                <label class="wp-editor-label">Word count</label>
                <div class="wp-rubric-row">
                    <input type="number" min="0" class="wp-editor-input" name="min_words[]" placeholder="Min words (e.g. 80)" value="<?= $item['min_words'] ?: '' ?>">
                    <input type="number" min="0" class="wp-editor-input" name="max_words[]" placeholder="Max words (e.g. 100)" value="<?= $item['max_words'] ?: '' ?>">
                </div>

                <label class="wp-editor-label">Sentence count</label>
                <div class="wp-rubric-row">
                    <input type="number" min="0" class="wp-editor-input" name="min_sentences[]" placeholder="Min sentences (e.g. 6)" value="<?= $item['min_sentences'] ?: '' ?>">
                    <input type="number" min="0" class="wp-editor-input" name="max_sentences[]" placeholder="Max sentences (e.g. 8)" value="<?= $item['max_sentences'] ?: '' ?>">
                </div>

                <label class="wp-editor-label">Must include one of these words (e.g. app names)</label>
                <input type="text" class="wp-editor-input" name="required_any[]" placeholder="WhatsApp, YouTube, Instagram, TikTok, Canva, ChatGPT, Spotify" value="<?= htmlspecialchars(implode(', ', $item['required_any']), ENT_QUOTES, 'UTF-8') ?>">

                <label class="wp-editor-label">Vocabulary bank</label>
                <textarea class="wp-editor-textarea" name="vocab_bank[]" placeholder="app, frustrated, message, loading, buffering, update, internet connection, account, notification, error, problem, video call, download, password, restart"><?= htmlspecialchars(implode(', ', $item['vocab_bank']), ENT_QUOTES, 'UTF-8') ?></textarea>
                <label class="wp-editor-label">Minimum vocabulary words required</label>
                <input type="number" min="0" class="wp-editor-input" name="vocab_min[]" placeholder="e.g. 6" value="<?= $item['vocab_min'] ?: '' ?>">

                <label class="wp-editor-label">Grammar checklist</label>
                <div class="wp-rubric-grammar">
                    <?php foreach (WPE_GRAMMAR_OPTIONS as $gKey => $gLabel): ?>
                        <label><input type="checkbox" name="grammar_checklist[<?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?>][]" value="<?= $gKey ?>" <?= in_array($gKey, $item['grammar_checklist'], true) ? 'checked' : '' ?>> <?= htmlspecialchars($gLabel, ENT_QUOTES, 'UTF-8') ?></label>
                    <?php endforeach; ?>
                </div>

                <label class="wp-editor-label">Sentence guide (one instruction per line, shown to the student)</label>
                <textarea class="wp-editor-textarea" name="sentence_guide[]" placeholder="Introduce your favorite app.&#10;Describe the problem.&#10;Explain why you are frustrated.&#10;..."><?= htmlspecialchars(implode("\n", $item['sentence_guide']), ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="wp-toolbar">
        <button type="button" class="wp-btn-add" onclick="addItem()">+ Add Prompt</button>
        <button type="submit" class="wp-btn-save">&#128190; Save Activity</button>
    </div>
</form>

<script>
var wpItemCount = <?= count($items) ?>;
var formChanged = false;
var formSubmitted = false;
var WPE_GRAMMAR_OPTIONS = <?= json_encode(WPE_GRAMMAR_OPTIONS, JSON_UNESCAPED_UNICODE) ?>;

function removeItem(btn){
    btn.closest('.wp-editor-item').remove();
    formChanged = true;
}

function wpToggleMode(radio){
    var box = radio.closest('.wp-editor-item').querySelector('.wp-rubric-box');
    if(box) box.classList.toggle('show', radio.value === 'essay');
    formChanged = true;
}

function addItem(){
    var n = wpItemCount++;
    var itemId = 'wp_' + Date.now() + '_' + Math.floor(Math.random()*1000);
    var c = document.getElementById('wpItemsContainer');
    var d = document.createElement('div');
    d.className = 'wp-editor-item';
    var grammarHtml = '';
    Object.keys(WPE_GRAMMAR_OPTIONS).forEach(function(key){
        grammarHtml += '<label><input type="checkbox" name="grammar_checklist[' + itemId + '][]" value="' + key + '"> ' + WPE_GRAMMAR_OPTIONS[key] + '</label>';
    });
    d.innerHTML =
        '<div class="wp-editor-item-header">' +
            '<span class="wp-editor-item-num">Prompt ' + (n+1) + '</span>' +
            '<button type="button" class="wp-btn-remove" onclick="removeItem(this)">&#10006; Remove</button>' +
        '</div>' +
        '<input type="hidden" name="item_id[]" value="' + itemId + '">' +
        '<label class="wp-editor-label">Instruction shown to student</label>' +
        '<input type="text" class="wp-editor-input" name="instruction[]" placeholder="e.g. Translate to English">' +
        '<label class="wp-editor-label">Text / prompt the student reads</label>' +
        '<textarea class="wp-editor-textarea" name="prompt_text[]" placeholder="Write the source text here"></textarea>' +
        '<label class="wp-editor-label">Scoring mode</label>' +
        '<div class="wp-mode-toggle">' +
            '<label class="wp-mode-opt"><input type="radio" name="mode[' + itemId + ']" value="exact" onchange="wpToggleMode(this)" checked><span>&#127919; Exact match</span></label>' +
            '<label class="wp-mode-opt"><input type="radio" name="mode[' + itemId + ']" value="essay" onchange="wpToggleMode(this)"><span>&#128221; Essay / rubric</span></label>' +
        '</div>' +
        '<label class="wp-editor-label" style="color:#0F6E56;">Model answer</label>' +
        '<p class="wp-editor-hint">In Exact match mode the student\'s answer is compared word by word. In Essay / rubric mode this is only a reference example.</p>' +
        '<textarea class="wp-editor-textarea answer" name="answer[]" placeholder="Write the correct answer here..."></textarea>' +
        '<div class="wp-rubric-box">' +
            '<label class="wp-editor-label">Word count</label>' +
            '<div class="wp-rubric-row">' +
                '<input type="number" min="0" class="wp-editor-input" name="min_words[]" placeholder="Min words (e.g. 80)">' +
                '<input type="number" min="0" class="wp-editor-input" name="max_words[]" placeholder="Max words (e.g. 100)">' +
            '</div>' +
            '<label class="wp-editor-label">Sentence count</label>' +
            '<div class="wp-rubric-row">' +
                '<input type="number" min="0" class="wp-editor-input" name="min_sentences[]" placeholder="Min sentences (e.g. 6)">' +
                '<input type="number" min="0" class="wp-editor-input" name="max_sentences[]" placeholder="Max sentences (e.g. 8)">' +
            '</div>' +
            '<label class="wp-editor-label">Must include one of these words (e.g. app names)</label>' +
            '<input type="text" class="wp-editor-input" name="required_any[]" placeholder="WhatsApp, YouTube, Instagram, TikTok, Canva, ChatGPT, Spotify">' +
            '<label class="wp-editor-label">Vocabulary bank</label>' +
            '<textarea class="wp-editor-textarea" name="vocab_bank[]" placeholder="app, frustrated, message, loading, buffering, update..."></textarea>' +
            '<label class="wp-editor-label">Minimum vocabulary words required</label>' +
            '<input type="number" min="0" class="wp-editor-input" name="vocab_min[]" placeholder="e.g. 6">' +
            '<label class="wp-editor-label">Grammar checklist</label>' +
            '<div class="wp-rubric-grammar">' + grammarHtml + '</div>' +
            '<label class="wp-editor-label">Sentence guide (one instruction per line)</label>' +
            '<textarea class="wp-editor-textarea" name="sentence_guide[]" placeholder="Introduce your favorite app.&#10;Describe the problem."></textarea>' +
        '</div>';
    c.appendChild(d);
    formChanged = true;
}

document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('input,textarea').forEach(function(el){
        el.addEventListener('input', function(){ formChanged = true; });
    });
    document.getElementById('wpEditorForm').addEventListener('submit', function(){
        formSubmitted = true; formChanged = false;
    });
});

window.addEventListener('beforeunload', function(e){
    if(formChanged && !formSubmitted){ e.preventDefault(); e.returnValue=''; }
});
</script>
<?php
$content = ob_get_clean();
render_activity_editor('', '', $content);
