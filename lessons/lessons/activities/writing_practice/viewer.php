<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function wp_columns(PDO $pdo): array
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

function wp_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $columns = wp_columns($pdo);
    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['unit_id'])) return (string) $row['unit_id'];
    }
    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT unit FROM activities WHERE id = :id LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['unit'])) return (string) $row['unit'];
    }
    return '';
}

function wp_default_title(): string { return 'Writing Practice'; }

function wp_normalize_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : wp_default_title();
}

function wp_normalize_payload($rawData): array
{
    $default = array('title' => wp_default_title(), 'items' => array());
    if ($rawData === null || $rawData === '') return $default;
    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) return $default;

    $title = '';
    $itemsSource = $decoded;
    if (isset($decoded['title'])) $title = trim((string) $decoded['title']);
    if (isset($decoded['items']) && is_array($decoded['items'])) $itemsSource = $decoded['items'];

    $items = array();
    foreach ($itemsSource as $item) {
        if (!is_array($item)) continue;
        $mode = isset($item['mode']) ? trim((string) $item['mode']) : 'exact';
        if ($mode !== 'essay') $mode = 'exact';
        $items[] = array(
            'id'                => isset($item['id'])           ? trim((string) $item['id'])           : uniqid('wp_'),
            'instruction'       => isset($item['instruction'])   ? trim((string) $item['instruction'])   : '',
            'prompt_text'       => isset($item['prompt_text'])   ? trim((string) $item['prompt_text'])   : '',
            'answer'            => isset($item['answer'])        ? trim((string) $item['answer'])        : '',
            'mode'              => $mode,
            'min_words'         => isset($item['min_words'])     ? max(0, (int) $item['min_words'])      : 0,
            'max_words'         => isset($item['max_words'])     ? max(0, (int) $item['max_words'])      : 0,
            'min_sentences'     => isset($item['min_sentences']) ? max(0, (int) $item['min_sentences'])  : 0,
            'max_sentences'     => isset($item['max_sentences']) ? max(0, (int) $item['max_sentences'])  : 0,
            'required_any'      => isset($item['required_any']) && is_array($item['required_any']) ? array_values(array_filter(array_map('trim', $item['required_any']))) : array(),
            'vocab_bank'        => isset($item['vocab_bank']) && is_array($item['vocab_bank']) ? array_values(array_filter(array_map('trim', $item['vocab_bank']))) : array(),
            'vocab_min'         => isset($item['vocab_min'])     ? max(0, (int) $item['vocab_min'])      : 0,
            'grammar_checklist' => isset($item['grammar_checklist']) && is_array($item['grammar_checklist']) ? array_values(array_filter(array_map('trim', $item['grammar_checklist']))) : array(),
            'sentence_guide'    => isset($item['sentence_guide']) && is_array($item['sentence_guide']) ? array_values(array_filter(array_map('trim', $item['sentence_guide']))) : array(),
        );
    }
    return array('title' => wp_normalize_title($title), 'items' => $items);
}

function wp_load_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = wp_columns($pdo);
    $selectFields = array('id');
    if (in_array('data', $columns, true)) $selectFields[] = 'data';
    if (in_array('content_json', $columns, true)) $selectFields[] = 'content_json';
    if (in_array('title', $columns, true)) $selectFields[] = 'title';
    if (in_array('name', $columns, true)) $selectFields[] = 'name';

    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id = :id AND type = 'writing_practice' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id = :unit AND type = 'writing_practice' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit = :unit AND type = 'writing_practice' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return array('title' => wp_default_title(), 'items' => array());

    $rawData = null;
    if (isset($row['data'])) $rawData = $row['data'];
    elseif (isset($row['content_json'])) $rawData = $row['content_json'];

    $payload = wp_normalize_payload($rawData);
    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') $columnTitle = trim((string) $row['title']);
    elseif (isset($row['name']) && trim((string) $row['name']) !== '') $columnTitle = trim((string) $row['name']);
    if ($columnTitle !== '') $payload['title'] = $columnTitle;

    return array(
        'title' => wp_normalize_title((string) $payload['title']),
        'items' => isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : array(),
    );
}

if ($unit === '' && $activityId !== '') $unit = wp_resolve_unit($pdo, $activityId);

$activity = wp_load_activity($pdo, $unit, $activityId);
$items    = isset($activity['items']) && is_array($activity['items']) ? $activity['items'] : array();
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : wp_default_title();

if (count($items) === 0) die('No writing prompts found for this activity');

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
    --wp-orange:#F97316;
    --wp-orange-dark:#C2580A;
    --wp-orange-soft:#FFF0E6;
    --wp-purple:#7F77DD;
    --wp-purple-dark:#534AB7;
    --wp-purple-soft:#EEEDFE;
    --wp-lila:rgba(127,119,221,.13);
    --wp-lila-md:rgba(127,119,221,.18);
    --wp-ink:#271B5D;
    --wp-muted:#9B94BE;
    --wp-green:#1D9E75;
    --wp-green-soft:#E6F9F2;
    --wp-green-border:#9FE1CB;
    --wp-red:#E24B4A;
    --wp-red-soft:#FCEBEB;
    --wp-red-border:#F7C1C1;
}

*{box-sizing:border-box}

.wp-shell{
    width:100%;
    flex:1;
    min-height:0;
    overflow-y:auto;
    padding:clamp(14px,2.5vw,34px);
    display:flex;
    align-items:flex-start;
    justify-content:center;
    font-family:'Nunito','Segoe UI',system-ui,sans-serif;
    background:#ffffff;
    border-radius:16px;
}

.wp-app{
    width:min(860px,100%);
    display:grid;
    grid-template-columns:minmax(0,1fr);
    gap:clamp(12px,2vw,20px);
}

.wp-hero{text-align:center;}

.wp-kicker{
    display:inline-flex;align-items:center;gap:7px;
    margin-bottom:8px;padding:7px 14px;border-radius:999px;
    background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;
    font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;
}

.wp-title{
    margin:0;font-family:'Fredoka',sans-serif;
    font-size:clamp(30px,5.5vw,58px);line-height:1;
    color:#F97316;font-weight:700;
}

.wp-subtitle{
    margin:8px 0 0;color:#9B94BE;
    font-size:clamp(13px,1.8vw,17px);font-weight:800;
}

.wp-board{
    background:#ffffff;border:1px solid #F0EEF8;
    border-radius:28px;padding:clamp(16px,2.6vw,26px);
    box-shadow:0 8px 40px rgba(127,119,221,.13);
    overflow:visible;
}

.wp-progress-row{
    display:grid;grid-template-columns:1fr auto;
    gap:12px;align-items:center;margin-bottom:20px;
}

.wp-progress-track{
    height:10px;background:#F4F2FD;border-radius:999px;
    overflow:hidden;border:1px solid #E4E1F8;
}

.wp-progress-fill{
    height:100%;width:0%;
    background:linear-gradient(90deg,#F97316,#7F77DD);
    border-radius:999px;transition:width .45s cubic-bezier(.2,.9,.2,1);
}

.wp-progress-count{
    min-width:74px;text-align:center;padding:7px 11px;
    border-radius:999px;background:#7F77DD;color:#fff;
    font-size:13px;font-weight:900;
}

.wp-section-label{
    font-size:11px;font-weight:900;letter-spacing:.1em;
    text-transform:uppercase;color:#9B94BE;margin-bottom:8px;
}

.wp-prompt-box{
    background:#FAFAFE;border:1px solid #EDE9FA;
    border-radius:16px;padding:16px 18px;margin-bottom:18px;
}

.wp-instruction-badge{
    display:inline-block;font-size:12px;font-weight:900;
    color:#C2580A;background:#FFF0E6;border:1px solid #FCDDBF;
    border-radius:999px;padding:4px 12px;margin-bottom:10px;
}

.wp-rubric-panel{
    display:none;background:#FAFAFE;border:1.5px solid #EDE9FA;
    border-radius:18px;padding:16px 18px;margin-bottom:14px;
}
.wp-rubric-panel.show{display:block;}
.wp-rubric-title{
    font-family:'Fredoka',sans-serif;font-size:16px;font-weight:600;
    color:#534AB7;margin:0 0 10px;display:flex;align-items:center;gap:8px;
}
.wp-rubric-grid{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
    gap:10px;margin-bottom:14px;
}
.wp-rubric-chip{
    background:#fff;border:1.5px solid #EDE9FA;border-radius:14px;
    padding:10px 12px;display:flex;align-items:center;gap:8px;
    font-size:12px;font-weight:800;color:#271B5D;transition:.2s;
}
.wp-rubric-chip .wp-chip-icon{font-size:16px;line-height:1;}
.wp-rubric-chip.pass{background:#E6F9F2;border-color:#9FE1CB;color:#0F6E56;}
.wp-rubric-chip.fail{background:#FCEBEB;border-color:#F7C1C1;color:#A32D2D;}
.wp-rubric-sub{
    font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;
    color:#9B94BE;margin:12px 0 8px;
}
.wp-rubric-list{
    margin:0;padding-left:20px;font-size:13px;font-weight:700;
    color:#271B5D;line-height:1.9;
}
.wp-checklist{
    display:flex;flex-wrap:wrap;gap:8px;margin-bottom:6px;
}
.wp-checklist-item{
    display:flex;align-items:center;gap:6px;padding:6px 12px;
    border-radius:999px;background:#fff;border:1.5px solid #EDE9FA;
    font-size:12px;font-weight:800;color:#9B94BE;transition:.2s;
}
.wp-checklist-item.done{
    background:#E6F9F2;border-color:#9FE1CB;color:#0F6E56;
}
.wp-checklist-item .wp-ci-dot{
    width:8px;height:8px;border-radius:50%;background:#D8D4F5;flex-shrink:0;
}
.wp-checklist-item.done .wp-ci-dot{background:#1D9E75;}

.wp-prompt-text{
    font-size:15px;font-weight:700;color:#271B5D;line-height:1.7;
}

.wp-panel{
    background:#fff;border:1.5px solid #EDE9FA;
    border-radius:18px;padding:16px 18px;margin-bottom:16px;
}
.wp-panel-head{
    display:flex;align-items:center;justify-content:space-between;
    gap:10px;margin-bottom:14px;
}
.wp-panel-head-left{
    display:flex;align-items:center;gap:8px;
    font-size:12px;font-weight:900;letter-spacing:.08em;
    text-transform:uppercase;color:#534AB7;
}
.wp-panel-head-icon{font-size:15px;line-height:1;}

.wp-req-grid{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
    gap:12px;
}
.wp-req-card{
    background:#FAFAFE;border:1.5px solid #EDE9FA;border-radius:14px;
    padding:14px;text-align:center;
}
.wp-req-num{
    font-family:'Fredoka',sans-serif;font-size:clamp(22px,4vw,28px);
    font-weight:600;color:#7F77DD;line-height:1;
}
.wp-req-lbl{
    font-size:11px;font-weight:900;letter-spacing:.08em;
    text-transform:uppercase;color:#9B94BE;margin-top:6px;
}

.wp-badge-orange{
    display:inline-block;font-size:11px;font-weight:900;
    color:#C2580A;background:#FFF0E6;border:1px solid #FCDDBF;
    border-radius:999px;padding:5px 12px;white-space:nowrap;
}

.wp-chip-row{display:flex;flex-wrap:wrap;gap:10px;}

.wp-app-chip{
    border:1.5px solid #7F77DD;border-radius:10px;
    background:#fff;color:#534AB7;font-weight:900;font-size:13px;
    padding:10px 16px;cursor:pointer;transition:.18s;
    font-family:'Nunito',sans-serif;
}
.wp-app-chip:hover{background:#EEEDFE;}
.wp-app-chip.active{background:#7F77DD;color:#fff;border-color:#7F77DD;}

.wp-vocab-chip{
    border:1.5px solid #E4E1F8;border-radius:10px;
    background:#F4F2FD;color:#534AB7;font-weight:800;font-size:13px;
    padding:9px 14px;transition:.18s;user-select:none;
}
.wp-vocab-chip.used{background:#E6F9F2;border-color:#9FE1CB;color:#0F6E56;}

.wp-grammar-chip{
    border:1.5px solid #FCDDBF;border-radius:999px;
    background:#FFF0E6;color:#C2580A;font-weight:900;font-size:13px;
    padding:8px 16px;
}
.wp-grammar-chip.hit{background:#E6F9F2;border-color:#9FE1CB;color:#0F6E56;}

.wp-guide-toggle{
    background:none;border:0;cursor:pointer;font-size:14px;
    color:#9B94BE;padding:4px;line-height:1;
}
.wp-guide-list{
    list-style:none;margin:0;padding:0;
}
.wp-guide-list.collapsed{display:none;}
.wp-guide-step{
    display:flex;align-items:center;gap:12px;
    padding:11px 0;border-bottom:1px dashed #EDE9FA;
    font-size:14px;font-weight:700;color:#271B5D;
}
.wp-guide-step:last-child{border-bottom:0;}
.wp-guide-num{
    flex-shrink:0;width:26px;height:26px;border-radius:50%;
    background:#7F77DD;color:#fff;display:flex;align-items:center;
    justify-content:center;font-size:12px;font-weight:900;
}

.wp-essay-counts{
    display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;
    font-size:12px;font-weight:900;color:#7F77DD;margin-top:-8px;margin-bottom:16px;
}

.wp-writing-wrap{position:relative;margin-bottom:16px;}

.wp-textarea{
    width:100%;min-height:120px;
    border:1.5px solid #EDE9FA;border-radius:16px;
    padding:14px 16px 32px;
    font-family:'Nunito',sans-serif;font-size:15px;
    font-weight:700;color:#271B5D;resize:vertical;
    outline:none;background:#fff;
    transition:border-color .18s,box-shadow .18s;
}

.wp-textarea:focus{
    border-color:#7F77DD;
    box-shadow:0 0 0 3px rgba(127,119,221,.10);
}

.wp-wordcount{
    position:absolute;bottom:10px;right:14px;
    font-size:11px;font-weight:900;color:#9B94BE;
}

.wp-actions{
    display:flex;justify-content:center;align-items:center;
    gap:clamp(8px,1.4vw,12px);flex-wrap:wrap;
    margin-bottom:20px;padding-bottom:4px;flex-shrink:0;
}

.wp-btn{
    border:0;border-radius:8px;
    min-width:clamp(100px,14vw,136px);
    padding:13px 20px;color:#fff;
    font-family:'Nunito',sans-serif;
    font-size:clamp(13px,1.8vw,15px);font-weight:900;
    cursor:pointer;display:inline-flex;align-items:center;
    justify-content:center;gap:7px;
    transition:transform .18s ease,filter .18s ease;
}

.wp-btn:hover{transform:translateY(-2px);filter:brightness(1.05);}

.wp-btn-orange{background:#F97316;box-shadow:0 6px 18px rgba(249,115,22,.22);}
.wp-btn-purple{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18);}

.wp-btn-outline{
    background:#fff;color:#534AB7;
    border:1.5px solid #EDE9FA;
    box-shadow:0 4px 12px rgba(127,119,221,.10);
}
.wp-btn-outline:hover{background:#EEEDFE;}

.wp-answer-reveal{
    display:none;background:#FAFAFE;
    border:1.5px solid #9FE1CB;border-radius:16px;
    padding:14px 18px;margin-bottom:16px;
}
.wp-answer-reveal.show{display:block;}
.wp-answer-title{
    font-size:11px;font-weight:900;letter-spacing:.1em;
    text-transform:uppercase;color:#1D9E75;margin-bottom:8px;
}
.wp-answer-text{
    font-size:14px;font-weight:700;color:#271B5D;line-height:1.7;
}

.wp-result{display:none;margin-top:4px;}
.wp-result.show{display:block;}

.wp-score-row{
    display:grid;grid-template-columns:repeat(3,1fr);
    gap:12px;margin-bottom:18px;
}

.wp-score-card{
    background:#FAFAFE;border:1px solid #EDE9FA;
    border-radius:16px;padding:14px;text-align:center;
}

.wp-score-num{
    font-family:'Fredoka',sans-serif;
    font-size:clamp(28px,5vw,38px);font-weight:700;line-height:1;
}

.wp-score-num.green{color:#1D9E75;}
.wp-score-num.orange{color:#F97316;}
.wp-score-num.purple{color:#7F77DD;}
.wp-score-lbl{
    font-size:11px;font-weight:900;color:#9B94BE;
    text-transform:uppercase;letter-spacing:.06em;margin-top:4px;
}

.wp-legend{
    display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;
}
.wp-leg{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:900;color:#9B94BE;}
.wp-leg-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.wp-leg-dot.match{background:#1D9E75;}
.wp-leg-dot.miss{background:#E24B4A;}
.wp-leg-dot.extra{background:#F97316;}

.wp-comparison{
    background:#FAFAFE;border:1px solid #EDE9FA;
    border-radius:16px;padding:14px 18px;margin-bottom:14px;
}
.wp-comp-label{
    font-size:11px;font-weight:900;letter-spacing:.08em;
    text-transform:uppercase;margin-bottom:10px;
}
.wp-comp-label.green{color:#1D9E75;}
.wp-comp-label.purple{color:#534AB7;}

.wp-tokens{
    line-height:2;
    font-size:15px;
    font-weight:700;
    color:#271B5D;
}

.wp-token{
    display:inline;
    padding:1px 3px;
    border-radius:4px;
    font-size:inherit;
    font-weight:800;
}
.wp-token.match{
    background:rgba(29,158,117,.18);
    color:#0F6E56;
}
.wp-token.miss{
    background:rgba(226,75,74,.15);
    color:#A32D2D;
    text-decoration:line-through;
}
.wp-token.extra{
    background:rgba(249,115,22,.18);
    color:#C2580A;
}
.wp-token.neutral{
    background:transparent;
    color:#271B5D;
}

.wp-nav-row{
    display:flex;justify-content:space-between;
    align-items:center;margin-top:8px;
    padding-top:16px;border-top:1px solid #F0EEF8;
}
.wp-nav-info{
    font-size:13px;font-weight:900;color:#9B94BE;
}

.wp-confetti{
    position:fixed;width:10px;height:14px;top:-20px;
    z-index:99999;opacity:.95;
    animation:wpFall linear forwards;pointer-events:none;
}

@keyframes wpFall{
    to{transform:translateY(110vh) rotate(720deg);opacity:1}
}

@media(max-width:640px){
    .wp-shell{padding:12px;border-radius:12px;}
    .wp-board{border-radius:22px;padding:14px;}
    .wp-score-row{grid-template-columns:1fr 1fr;}
    .wp-score-row .wp-score-card:last-child{grid-column:span 2;}
    .wp-actions{display:grid;grid-template-columns:1fr;gap:9px;}
    .wp-btn{width:100%;}
}

body.embedded-mode .wp-shell,
body.fullscreen-embedded .wp-shell,
body.presentation-mode .wp-shell{
    position:absolute!important;inset:0!important;
    max-width:none!important;margin:0!important;
    padding:10px 12px!important;border-radius:0!important;
    display:flex!important;
    flex-direction:column!important;
    align-items:center!important;
    justify-content:flex-start!important;
    overflow-y:auto!important;overflow-x:hidden!important;
}

body.embedded-mode .wp-app,
body.fullscreen-embedded .wp-app,
body.presentation-mode .wp-app{
    width:min(860px,100%)!important;
    margin:0 auto!important;
}
body.embedded-mode .wp-board,
body.fullscreen-embedded .wp-board,
body.presentation-mode .wp-board{
    overflow:visible!important;
}
body.embedded-mode .wp-actions,
body.fullscreen-embedded .wp-actions,
body.presentation-mode .wp-actions{
    flex-shrink:0!important;padding-bottom:12px!important;
}
</style>

<div class="wp-shell">
<div class="wp-app" id="wp-app">

    <div class="wp-hero">
        <div class="wp-kicker">Activity <span id="wp-kicker-count">1 / <?php echo count($items); ?></span></div>
        <h1 class="wp-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="wp-subtitle">Read the text and write your translation in English.</p>
    </div>

    <div class="wp-board" id="wp-board" data-az-zoom>

        <div class="wp-progress-row">
            <div class="wp-progress-track">
                <div class="wp-progress-fill" id="wp-progress-fill"></div>
            </div>
            <div class="wp-progress-count" id="wp-progress-count">1 / <?php echo count($items); ?></div>
        </div>

        <div id="wp-exact-prompt">
            <div class="wp-section-label">Prompt</div>
            <div class="wp-prompt-box">
                <div class="wp-instruction-badge" id="wp-instruction"></div>
                <p class="wp-prompt-text" id="wp-prompt-text"></p>
            </div>
        </div>

        <div id="wp-essay-prompt" style="display:none;">
            <div class="wp-kicker" id="wp-essay-kicker" style="margin:0 0 10px;">Writing Task</div>
            <h2 class="wp-title" style="font-size:clamp(22px,3.6vw,32px);text-align:left;margin-bottom:6px;" id="wp-essay-title"></h2>
            <p class="wp-subtitle" style="text-align:left;margin:0 0 18px;" id="wp-essay-subtitle">Everything you need is broken down below — pick your app, hit the vocabulary target, and follow the guide.</p>

            <div class="wp-panel" id="wp-req-panel" style="display:none;">
                <div class="wp-panel-head">
                    <div class="wp-panel-head-left"><span class="wp-panel-head-icon">&#9999;&#65039;</span>Requirements</div>
                </div>
                <div class="wp-req-grid" id="wp-req-grid"></div>
            </div>

            <div class="wp-panel" id="wp-app-panel" style="display:none;">
                <div class="wp-panel-head">
                    <div class="wp-panel-head-left"><span class="wp-panel-head-icon">&#128241;</span>Choose one app to write about</div>
                </div>
                <div class="wp-chip-row" id="wp-app-chips"></div>
            </div>

            <div class="wp-panel" id="wp-vocab-panel" style="display:none;">
                <div class="wp-panel-head">
                    <div class="wp-panel-head-left"><span class="wp-panel-head-icon">&#10024;</span>Vocabulary Bank</div>
                    <div class="wp-badge-orange" id="wp-vocab-badge"></div>
                </div>
                <div class="wp-chip-row" id="wp-vocab-chips"></div>
            </div>

            <div class="wp-panel" id="wp-grammar-panel" style="display:none;">
                <div class="wp-panel-head">
                    <div class="wp-panel-head-left"><span class="wp-panel-head-icon">&#9999;&#65039;</span>Grammar to include</div>
                </div>
                <div class="wp-chip-row" id="wp-grammar-chips"></div>
            </div>

            <div class="wp-panel" id="wp-guide-panel" style="display:none;">
                <div class="wp-panel-head">
                    <div class="wp-panel-head-left"><span class="wp-panel-head-icon">&#128203;</span>Step-by-step guide</div>
                    <button type="button" class="wp-guide-toggle" id="wp-guide-toggle">&#9650;</button>
                </div>
                <ol class="wp-guide-list" id="wp-guide-list"></ol>
            </div>
        </div>

        <div class="wp-section-label" id="wp-answer-label">Your answer</div>
        <div class="wp-writing-wrap">
            <textarea class="wp-textarea" id="wp-textarea" placeholder="Write your answer here..."></textarea>
            <div class="wp-wordcount" id="wp-wordcount">0 words</div>
        </div>
        <div class="wp-essay-counts" id="wp-essay-counts" style="display:none;">
            <span id="wp-essay-wordcount">0 / 0 words</span>
            <span id="wp-essay-vocabcount">0 / 0 vocabulary words used</span>
        </div>

        <div class="wp-actions">
            <button type="button" class="wp-btn wp-btn-outline" id="wp-btn-reset">&#8635; Reset</button>
            <button type="button" class="wp-btn wp-btn-purple" id="wp-btn-show">Show Answer</button>
            <button type="button" class="wp-btn wp-btn-orange" id="wp-btn-check">Check &#10003;</button>
        </div>

        <div class="wp-answer-reveal" id="wp-answer-reveal">
            <div class="wp-answer-title">Model answer</div>
            <p class="wp-answer-text" id="wp-answer-text"></p>
        </div>

        <div class="wp-result" id="wp-result">
            <div class="wp-score-row">
                <div class="wp-score-card">
                    <div class="wp-score-num green" id="wp-s-correct">0</div>
                    <div class="wp-score-lbl">correct words</div>
                </div>
                <div class="wp-score-card">
                    <div class="wp-score-num orange" id="wp-s-total">0</div>
                    <div class="wp-score-lbl">words written</div>
                </div>
                <div class="wp-score-card">
                    <div class="wp-score-num purple" id="wp-s-score">0%</div>
                    <div class="wp-score-lbl">score</div>
                </div>
            </div>

            <div class="wp-legend">
                <div class="wp-leg"><div class="wp-leg-dot match"></div>Correct word</div>
                <div class="wp-leg"><div class="wp-leg-dot miss"></div>Missing / wrong</div>
                <div class="wp-leg"><div class="wp-leg-dot extra"></div>Extra word</div>
            </div>

            <div class="wp-comparison">
                <div class="wp-comp-label green">Your answer — word by word</div>
                <div class="wp-tokens" id="wp-student-tokens"></div>
            </div>
            <div class="wp-comparison">
                <div class="wp-comp-label purple">Model answer — word by word</div>
                <div class="wp-tokens" id="wp-answer-tokens"></div>
            </div>
        </div>

        <div class="wp-rubric-panel" id="wp-rubric-panel">
            <div class="wp-rubric-title">&#128202; Rubric check</div>
            <div class="wp-rubric-grid" id="wp-rubric-grid"></div>
            <div class="wp-rubric-sub" id="wp-rubric-grammar-sub" style="display:none;">Grammar to include</div>
            <div class="wp-chip-row" id="wp-rubric-grammar-chips"></div>
        </div>

        <div class="wp-nav-row">
            <button type="button" class="wp-btn wp-btn-outline" id="wp-btn-prev" style="min-width:100px;">&#9664; Prev</button>
            <span class="wp-nav-info" id="wp-nav-info">1 / <?php echo count($items); ?></span>
            <button type="button" class="wp-btn wp-btn-orange" id="wp-btn-next" style="min-width:100px;">Next &#9654;</button>
        </div>

    </div>
</div>
</div>

<audio id="wp-win" src="../../hangman/assets/win.mp3" preload="auto"></audio>

<script>
(function(){
'use strict';

var ITEMS = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var viewerTitle = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
var WP_GRAMMAR_LABELS = {
    'present_tense': 'Present Tense',
    'present_continuous': 'Present Continuous',
    'past_tense': 'Past Tense',
    'future_tense': 'Future Tense',
    'present_perfect': 'Present Perfect',
    'present_perfect_continuous': 'Present Perfect Continuous',
    'past_perfect': 'Past Perfect',
    'past_perfect_continuous': 'Past Perfect Continuous',
    'future_perfect': 'Future Perfect',
    'future_perfect_continuous': 'Future Perfect Continuous',
    'conditionals': 'Conditionals',
    'passive_voice': 'Passive Voice',
    'clauses': 'Clauses'
};
var WP_GRAMMAR_PATTERNS = {
    'present_tense': /\b(am|is|are)\b|\b(do|does|don't|doesn't)\b|\b(he|she|it)\s+\w+s\b|\b(I|you|we|they)\s+\w+\b/i,
    'present_continuous': /\b(am|is|are)\s+(not\s+)?\w+ing\b/i,
    'past_tense': /\b\w+ed\b|\b(was|were|wasn't|weren't|did|didn't)\b/i,
    'future_tense': /\b(will|won't|shall)\b|\bgoing to\b/i,
    'present_perfect_continuous': /\b(have|has)\s+been\s+\w+ing\b/i,
    'present_perfect': /\b(have|has|haven't|hasn't)\s+\w+/i,
    'past_perfect_continuous': /\bhad\s+been\s+\w+ing\b/i,
    'past_perfect': /\bhad(n't)?\s+\w+/i,
    'future_perfect_continuous': /\bwill\s+have\s+been\s+\w+ing\b/i,
    'future_perfect': /\bwill\s+have\s+\w+/i,
    'conditionals': /\bif\b|\bunless\b/i,
    'passive_voice': /\b(is|are|was|were|be|been|being|am)\s+\w+(ed|en)\b/i,
    'clauses': /\b(which|who|whom|whose|that|because|although|though|since|while|when)\b/i
};
var TOTAL = ITEMS.length;
var idx = 0;

function normalize(str){
    return String(str||'').toLowerCase().replace(/[^a-z0-9\s]/g,'').trim().split(/\s+/).filter(Boolean);
}

function el(id){ return document.getElementById(id); }
function countWords(str){ return String(str||'').trim().split(/\s+/).filter(Boolean).length; }
function countSentences(str){ return String(str||'').split(/[.!?]+/).map(function(s){return s.trim();}).filter(Boolean).length; }
function selectedAppChip(){
    var active = document.querySelector('#wp-app-chips .wp-app-chip.active');
    return active ? active.textContent : '';
}

function renderEssayPanels(item){
    var isEssay = item.mode === 'essay';
    el('wp-exact-prompt').style.display = isEssay ? 'none' : '';
    el('wp-essay-prompt').style.display = isEssay ? '' : 'none';
    el('wp-essay-counts').style.display = isEssay ? 'flex' : 'none';
    el('wp-wordcount').style.display = isEssay ? 'none' : '';
    el('wp-rubric-panel').classList.remove('show');
    if(!isEssay) return;

    el('wp-essay-title').textContent = item.instruction || viewerTitle;
    el('wp-essay-subtitle').textContent = item.prompt_text || 'Everything you need is broken down below \u2014 pick your app, hit the vocabulary target, and follow the guide.';

    var hasWords = item.min_words > 0 || item.max_words > 0;
    var hasSentences = item.min_sentences > 0 || item.max_sentences > 0;
    el('wp-req-panel').style.display = (hasWords || hasSentences) ? '' : 'none';
    var reqGrid = el('wp-req-grid');
    reqGrid.innerHTML = '';
    if(hasWords){
        var wCard = document.createElement('div');
        wCard.className = 'wp-req-card';
        wCard.innerHTML = '<div class="wp-req-num">' + (item.min_words||0) + '\u2013' + (item.max_words||0) + '</div><div class="wp-req-lbl">Words</div>';
        reqGrid.appendChild(wCard);
    }
    if(hasSentences){
        var sCard = document.createElement('div');
        sCard.className = 'wp-req-card';
        sCard.innerHTML = '<div class="wp-req-num">' + (item.min_sentences||0) + '\u2013' + (item.max_sentences||0) + '</div><div class="wp-req-lbl">Sentences</div>';
        reqGrid.appendChild(sCard);
    }

    var apps = item.required_any || [];
    el('wp-app-panel').style.display = apps.length ? '' : 'none';
    var appChips = el('wp-app-chips');
    appChips.innerHTML = '';
    apps.forEach(function(app, i){
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'wp-app-chip' + (i === 0 ? ' active' : '');
        chip.textContent = app;
        chip.addEventListener('click', function(){
            appChips.querySelectorAll('.wp-app-chip').forEach(function(c){ c.classList.remove('active'); });
            chip.classList.add('active');
        });
        appChips.appendChild(chip);
    });

    var vocab = item.vocab_bank || [];
    el('wp-vocab-panel').style.display = vocab.length ? '' : 'none';
    el('wp-vocab-badge').textContent = 'Use ' + (item.vocab_min||0) + ' of ' + vocab.length + ' words';
    var vocabChips = el('wp-vocab-chips');
    vocabChips.innerHTML = '';
    vocab.forEach(function(w){
        var chip = document.createElement('span');
        chip.className = 'wp-vocab-chip';
        chip.dataset.word = w.toLowerCase();
        chip.textContent = w;
        vocabChips.appendChild(chip);
    });

    var grammar = item.grammar_checklist || [];
    el('wp-grammar-panel').style.display = grammar.length ? '' : 'none';
    var grammarChips = el('wp-grammar-chips');
    grammarChips.innerHTML = '';
    grammar.forEach(function(g){
        var chip = document.createElement('span');
        chip.className = 'wp-grammar-chip';
        chip.textContent = '\u2022 ' + (WP_GRAMMAR_LABELS[g] || g);
        grammarChips.appendChild(chip);
    });

    var guide = item.sentence_guide || [];
    el('wp-guide-panel').style.display = guide.length ? '' : 'none';
    var guideList = el('wp-guide-list');
    guideList.innerHTML = '';
    guide.forEach(function(step, i){
        var li = document.createElement('li');
        li.className = 'wp-guide-step';
        var numSpan = document.createElement('span');
        numSpan.className = 'wp-guide-num';
        numSpan.textContent = (i+1);
        var textSpan = document.createElement('span');
        textSpan.textContent = step;
        li.appendChild(numSpan);
        li.appendChild(textSpan);
        guideList.appendChild(li);
    });
    guideList.classList.remove('collapsed');
    el('wp-guide-toggle').textContent = '\u25B2';

    updateEssayCounts();
}

function updateEssayCounts(){
    var item = ITEMS[idx] || {};
    if(item.mode !== 'essay') return;
    var text = el('wp-textarea').value;
    var words = countWords(text);
    var wLabel = words;
    if(item.min_words || item.max_words) wLabel = words + ' / ' + (item.min_words||0) + '-' + (item.max_words||0) + ' words';
    else wLabel = words + ' word' + (words!==1?'s':'');
    el('wp-essay-wordcount').textContent = wLabel;

    var vocab = item.vocab_bank || [];
    var lower = text.toLowerCase();
    var usedCount = 0;
    document.querySelectorAll('#wp-vocab-chips .wp-vocab-chip').forEach(function(chip){
        var used = lower.indexOf(chip.dataset.word) !== -1;
        chip.classList.toggle('used', used);
        if(used) usedCount++;
    });
    el('wp-essay-vocabcount').textContent = usedCount + ' / ' + (item.vocab_min || vocab.length) + ' vocabulary words used';
}

function loadItem(){
    var item = ITEMS[idx] || {};
    el('wp-instruction').textContent = item.instruction || 'Translate to English';
    el('wp-prompt-text').textContent = item.prompt_text || '';
    el('wp-textarea').value = '';
    el('wp-wordcount').textContent = '0 words';
    el('wp-answer-reveal').classList.remove('show');
    el('wp-answer-text').textContent = item.answer || '';
    el('wp-result').classList.remove('show');
    el('wp-student-tokens').innerHTML = '';
    el('wp-answer-tokens').innerHTML = '';

    renderEssayPanels(item);

    var pct = Math.max(1, Math.round(((idx+1)/TOTAL)*100));
    el('wp-progress-fill').style.width = pct + '%';
    var countText = (idx+1) + ' / ' + TOTAL;
    el('wp-progress-count').textContent = countText;
    el('wp-kicker-count').textContent = countText;
    el('wp-nav-info').textContent = countText;
    el('wp-btn-prev').style.visibility = idx === 0 ? 'hidden' : 'visible';
    el('wp-btn-next').textContent = idx >= TOTAL-1 ? 'Finish \u2713' : 'Next \u25BA';
}

function updateWC(){
    var item = ITEMS[idx] || {};
    if(item.mode === 'essay'){ updateEssayCounts(); return; }
    var words = countWords(el('wp-textarea').value);
    el('wp-wordcount').textContent = words + ' word' + (words!==1?'s':'');
}

function checkEssay(){
    var item = ITEMS[idx] || {};
    var text = el('wp-textarea').value;
    var words = countWords(text);
    var sentences = countSentences(text);
    var lower = text.toLowerCase();

    var grid = el('wp-rubric-grid');
    grid.innerHTML = '';

    function addChip(label, passed){
        var chip = document.createElement('div');
        chip.className = 'wp-rubric-chip ' + (passed ? 'pass' : 'fail');
        chip.innerHTML = '<span class="wp-chip-icon">' + (passed ? '\u2705' : '\u274C') + '</span><span>' + label + '</span>';
        grid.appendChild(chip);
    }

    if(item.min_words || item.max_words){
        var wordsOk = words >= (item.min_words||0) && (item.max_words ? words <= item.max_words : true);
        addChip(words + ' words', wordsOk);
    }
    if(item.min_sentences || item.max_sentences){
        var sentOk = sentences >= (item.min_sentences||0) && (item.max_sentences ? sentences <= item.max_sentences : true);
        addChip(sentences + ' sentences', sentOk);
    }
    var vocab = item.vocab_bank || [];
    if(vocab.length){
        var vocabUsed = vocab.filter(function(w){ return lower.indexOf(w.toLowerCase()) !== -1; }).length;
        addChip(vocabUsed + '/' + (item.vocab_min||0) + ' vocabulary words', vocabUsed >= (item.vocab_min||0));
    }
    var apps = item.required_any || [];
    if(apps.length){
        var chosen = selectedAppChip();
        var mentioned = chosen && lower.indexOf(chosen.toLowerCase()) !== -1;
        addChip('Mentions "' + (chosen||'') + '"', mentioned);
    }

    var grammar = item.grammar_checklist || [];
    var grammarChipsWrap = el('wp-rubric-grammar-chips');
    grammarChipsWrap.innerHTML = '';
    el('wp-rubric-grammar-sub').style.display = grammar.length ? '' : 'none';
    grammar.forEach(function(g){
        var label = WP_GRAMMAR_LABELS[g] || g;
        var hit = lower.indexOf(String(label).toLowerCase().replace(/[^a-z' ]/g,'').trim()) !== -1;
        var chip = document.createElement('span');
        chip.className = 'wp-grammar-chip' + (hit ? ' hit' : '');
        chip.textContent = '\u2022 ' + label;
        grammarChipsWrap.appendChild(chip);
    });

    el('wp-rubric-panel').classList.add('show');
    el('wp-answer-reveal').classList.remove('show');

    var allPass = grid.querySelectorAll('.wp-rubric-chip.fail').length === 0;
    if(allPass) launchConfetti();
}

function checkAnswer(){
    var item = ITEMS[idx] || {};
    if(item.mode === 'essay'){ checkEssay(); return; }
    var modelWords = normalize(item.answer || '');
    var studentWords = normalize(el('wp-textarea').value);

    var modelFreq = {};
    modelWords.forEach(function(w){ modelFreq[w] = (modelFreq[w]||0)+1; });
    var studentFreq = {};
    studentWords.forEach(function(w){ studentFreq[w] = (studentFreq[w]||0)+1; });

    var correct = 0;
    var usedCount = {};
    studentWords.forEach(function(w){
        usedCount[w] = (usedCount[w]||0)+1;
        if(modelFreq[w] && usedCount[w] <= modelFreq[w]) correct++;
    });

    var pct = modelWords.length > 0 ? Math.round((correct / modelWords.length)*100) : 0;
    el('wp-s-correct').textContent = correct;
    el('wp-s-total').textContent = studentWords.length;
    el('wp-s-score').textContent = pct + '%';

    var sTok = el('wp-student-tokens');
    sTok.innerHTML = '';
    var usedC2 = {};
    studentWords.forEach(function(w, i){
        usedC2[w] = (usedC2[w]||0)+1;
        var isMatch = modelFreq[w] && usedC2[w] <= modelFreq[w];
        var span = document.createElement('span');
        span.className = 'wp-token ' + (isMatch ? 'match' : 'extra');
        span.textContent = w;
        sTok.appendChild(span);
        if(i < studentWords.length - 1) sTok.appendChild(document.createTextNode(' '));
    });

    var aTok = el('wp-answer-tokens');
    aTok.innerHTML = '';
    var usedM = {};
    modelWords.forEach(function(w, i){
        usedM[w] = (usedM[w]||0)+1;
        var found = studentFreq[w] && usedM[w] <= studentFreq[w];
        var span = document.createElement('span');
        span.className = 'wp-token ' + (found ? 'neutral' : 'miss');
        span.textContent = w;
        aTok.appendChild(span);
        if(i < modelWords.length - 1) aTok.appendChild(document.createTextNode(' '));
    });

    el('wp-result').classList.add('show');
    el('wp-answer-reveal').classList.remove('show');

    if(pct >= 80) launchConfetti();
}

function launchConfetti(){
    var colors = ['#F97316','#7F77DD','#534AB7','#1D9E75','#FCDDBF','#EEEDFE'];
    for(var i=0;i<60;i++){
        (function(n){
            setTimeout(function(){
                var p = document.createElement('span');
                p.className = 'wp-confetti';
                p.style.left = Math.random()*100+'vw';
                p.style.background = colors[Math.floor(Math.random()*colors.length)];
                p.style.animationDuration = (2.2+Math.random()*1.8)+'s';
                p.style.transform = 'rotate('+(Math.random()*180)+'deg)';
                p.style.borderRadius = Math.random()>.5?'999px':'3px';
                document.body.appendChild(p);
                setTimeout(function(){ p.remove(); },4500);
            },n*12);
        })(i);
    }
    try{
        var win = document.getElementById('wp-win');
        win.pause(); win.currentTime=0; win.play();
    }catch(e){}
}

el('wp-textarea').addEventListener('input', updateWC);
el('wp-btn-reset').addEventListener('click', function(){
    el('wp-textarea').value='';
    el('wp-wordcount').textContent='0 words';
    el('wp-result').classList.remove('show');
    el('wp-rubric-panel').classList.remove('show');
    el('wp-answer-reveal').classList.remove('show');
    updateWC();
});
el('wp-btn-show').addEventListener('click', function(){
    el('wp-answer-reveal').classList.toggle('show');
});
el('wp-btn-check').addEventListener('click', checkAnswer);
el('wp-guide-toggle').addEventListener('click', function(){
    var list = el('wp-guide-list');
    var collapsed = list.classList.toggle('collapsed');
    el('wp-guide-toggle').textContent = collapsed ? '\u25BC' : '\u25B2';
});
el('wp-btn-prev').addEventListener('click', function(){
    if(idx>0){ idx--; loadItem(); }
});
el('wp-btn-next').addEventListener('click', function(){
    if(idx<TOTAL-1){ idx++; loadItem(); }
});

loadItem();
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-pen-to-square', $content);
