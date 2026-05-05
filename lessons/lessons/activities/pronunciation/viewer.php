<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function activities_columns(PDO $pdo): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = array();

    $stmt = $pdo->query(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'activities'"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $cache[] = (string) $row['column_name'];
        }
    }

    return $cache;
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $columns = activities_columns($pdo);

    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit_id
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit_id'])) {
            return (string) $row['unit_id'];
        }
    }

    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit'])) {
            return (string) $row['unit'];
        }
    }

    return '';
}

function default_pronunciation_title(): string
{
    return 'Pronunciation';
}

function normalize_activity_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_pronunciation_title();
}

function normalize_pronunciation_payload($rawData): array
{
    $default = array(
        'title' => default_pronunciation_title(),
        'items' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;

    if (!is_array($decoded)) {
        return $default;
    }

    $title = '';
    $itemsSource = $decoded;

    if (isset($decoded['title'])) {
        $title = trim((string) $decoded['title']);
    }

    if (isset($decoded['items']) && is_array($decoded['items'])) {
        $itemsSource = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $itemsSource = $decoded['data'];
    } elseif (isset($decoded['words']) && is_array($decoded['words'])) {
        $itemsSource = $decoded['words'];
    }

    $normalizedItems = array();

    if (is_array($itemsSource)) {
        foreach ($itemsSource as $item) {
            if (!is_array($item)) {
                continue;
            }

            $en = isset($item['en']) ? trim((string) $item['en']) : '';
            if ($en === '' && isset($item['word'])) {
                $en = trim((string) $item['word']);
            }

            $normalizedItems[] = array(
                'img' => isset($item['img']) ? trim((string) $item['img']) : (isset($item['image']) ? trim((string) $item['image']) : ''),
                'en' => $en,
                'ph' => isset($item['ph']) ? trim((string) $item['ph']) : '',
                'es' => isset($item['es']) ? trim((string) $item['es']) : '',
                'audio' => isset($item['audio']) ? trim((string) $item['audio']) : '',
            );
        }
    }

    return array(
        'title' => normalize_activity_title($title),
        'items' => $normalizedItems,
    );
}

function load_pronunciation_activity(PDO $pdo, string $activityId, string $unit): array
{
    $columns = activities_columns($pdo);
    $selectFields = array('id');

    if (in_array('data', $columns, true)) {
        $selectFields[] = 'data';
    }
    if (in_array('content_json', $columns, true)) {
        $selectFields[] = 'content_json';
    }
    if (in_array('title', $columns, true)) {
        $selectFields[] = 'title';
    }
    if (in_array('name', $columns, true)) {
        $selectFields[] = 'name';
    }

    $fallback = array(
        'id' => '',
        'title' => default_pronunciation_title(),
        'items' => array(),
    );

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'pronunciation'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '' && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit_id = :unit
               AND type = 'pronunciation'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '' && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit = :unit
               AND type = 'pronunciation'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = normalize_pronunciation_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') {
        $columnTitle = trim((string) $row['title']);
    } elseif (isset($row['name']) && trim((string) $row['name']) !== '') {
        $columnTitle = trim((string) $row['name']);
    }

    if ($columnTitle !== '') {
        $payload['title'] = $columnTitle;
    }

    return array(
        'id' => isset($row['id']) ? (string) $row['id'] : '',
        'title' => normalize_activity_title((string) ($payload['title'] ?? '')),
        'items' => isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : array(),
    );
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_pronunciation_activity($pdo, $activityId, $unit);
$items = isset($activity['items']) && is_array($activity['items']) ? $activity['items'] : array();
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : default_pronunciation_title();

if (count($items) === 0) {
    die('No pronunciation data available');
}

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>

:root {
    --pron-orange: #F97316;
    --pron-orange-dark: #C2580A;
    --pron-orange-soft: #FFF0E6;
    --pron-purple: #7F77DD;
    --pron-purple-dark: #534AB7;
    --pron-purple-soft: #EEEDFE;
    --pron-white: #FFFFFF;
    --pron-lila-border: #EDE9FA;
    --pron-muted: #9B94BE;
    --pron-ink: #271B5D;
    --pron-green: #16a34a;
    --pron-red: #dc2626;
}

*{box-sizing:border-box}

html,
body {
    width:100%;
    min-height:100%;
}

body {
    margin:0!important;
    padding:0!important;
    background:#ffffff!important;
    font-family:'Nunito','Segoe UI',sans-serif!important;
}

.activity-wrapper {
    max-width:100%!important;
    margin:0!important;
    padding:0!important;
    min-height:100vh;
    display:flex!important;
    flex-direction:column!important;
    background:transparent!important;
}

.top-row {
    display:none!important;
}

.viewer-content {
    flex:1!important;
    display:flex!important;
    flex-direction:column!important;
    padding:0!important;
    margin:0!important;
    background:transparent!important;
    border:none!important;
    box-shadow:none!important;
    border-radius:0!important;
}

.pron-premium-shell{
    width:100%;
    min-height:100vh;
    padding:clamp(14px,2.5vw,34px);
    display:flex;
    align-items:flex-start;
    justify-content:center;
    font-family:'Nunito','Segoe UI',system-ui,sans-serif;
    background:#ffffff;
    border-radius:0;
    overflow:visible;
}

.pron-premium-app{
    width:min(860px,100%);
    display:grid;
    grid-template-columns:minmax(0,1fr);
    gap:0;
}

.pron-premium-board{
    position:relative;
    width:min(760px,100%);
    margin:0 auto;
    background:#ffffff;
    border:1px solid #F0EEF8;
    border-radius:34px;
    padding:clamp(16px,2.6vw,26px);
    box-shadow:0 8px 40px rgba(127,119,221,.13);
}

.pron-premium-title-panel{
    width:100%;
    margin:0 auto clamp(14px,2vw,22px);
    padding:0;
    text-align:center;
    border-radius:0;
    background:transparent;
    border:none;
    box-shadow:none;
}

.pron-premium-kicker{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    margin-bottom:10px;
    padding:7px 14px;
    border-radius:999px;
    background:#FFF0E6;
    border:1px solid #FCDDBF;
    color:#C2580A;
    font-size:12px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
}

.pron-premium-title{
    margin:0;
    font-family:'Fredoka',sans-serif;
    font-size:clamp(30px,5.5vw,58px);
    line-height:1.03;
    color:#F97316;
    font-weight:700;
}

.pron-premium-subtitle{
    margin:8px 0 0;
    color:#9B94BE;
    font-size:clamp(13px,1.8vw,17px);
    font-weight:800;
}

.pron-premium-progress-row{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:18px;
}

.pron-premium-progress-track{
    flex:1;
    height:12px;
    background:#F4F2FD;
    border-radius:999px;
    overflow:hidden;
    border:1px solid #E4E1F8;
}

.pron-premium-progress-fill{
    height:100%;
    width:0%;
    background:linear-gradient(90deg,#F97316,#7F77DD);
    border-radius:999px;
    transition:width .45s ease;
}

.pron-premium-progress-count{
    min-width:74px;
    text-align:center;
    padding:7px 11px;
    border-radius:999px;
    background:#7F77DD;
    color:#ffffff;
    font-size:12px;
    font-weight:900;
    box-shadow:none;
}

.pron-premium-card-wrap{
    position:relative;
}

.pron-premium-card{
    min-height:clamp(330px,45vh,470px);
    border-radius:28px;
    overflow:hidden;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    padding:clamp(22px,4vw,42px);
    border:1px solid #EDE9FA;
    box-shadow:0 12px 36px rgba(127,119,221,.13);
    background:#ffffff;
    text-align:center;
}

.pron-premium-image-box{
    width:min(250px,58vw);
    height:min(250px,58vw);
    max-height:270px;
    margin-bottom:clamp(12px,2vw,18px);
    border-radius:28px;
    background:#ffffff;
    border:1.5px solid #EDE9FA;
    box-shadow:0 8px 24px rgba(127,119,221,.10);
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
}

.pron-premium-image-box img{
    max-width:82%;
    max-height:82%;
    object-fit:contain;
    display:block;
}

.pron-premium-placeholder{
    font-family:'Fredoka',sans-serif;
    font-size:clamp(70px,15vw,132px);
    color:#7F77DD;
    font-weight:700;
}

.pron-premium-word{
    width:100%;
    max-width:620px;
    font-family:'Fredoka',sans-serif;
    font-size:clamp(38px,7.5vw,82px);
    color:#534AB7;
    font-weight:700;
    line-height:1.05;
    text-align:center;
    overflow-wrap:anywhere;
}

.pron-premium-phonetic{
    margin-top:10px;
    padding:8px 15px;
    border-radius:999px;
    background:#EEEDFE;
    color:#534AB7;
    font-size:clamp(13px,1.8vw,18px);
    font-weight:900;
}

.pron-premium-captured,
.pron-premium-answer,
.pron-premium-feedback{
    width:100%;
    max-width:620px;
    margin-top:10px;
    border-radius:18px;
    padding:12px 14px;
    font-size:14px;
    font-weight:900;
    text-align:center;
    box-sizing:border-box;
}

.pron-premium-captured:empty,
.pron-premium-feedback:empty{
    display:none;
}

.pron-premium-captured{
    border:1px solid #EDE9FA;
    background:#ffffff;
    color:#534AB7;
    box-shadow:0 4px 14px rgba(127,119,221,.08);
}

.pron-premium-captured.ok{
    border-color:#16a34a;
    background:#ffffff;
    color:#16a34a;
}

.pron-premium-captured.bad{
    border-color:#dc2626;
    background:#ffffff;
    color:#dc2626;
}

.pron-premium-answer{
    display:none;
    border:1px solid #EDE9FA;
    background:#EEEDFE;
    color:#534AB7;
    box-shadow:none;
}

.pron-premium-answer.show{
    display:block;
}

.pron-premium-feedback{
    color:#534AB7;
}

.pron-premium-feedback.bad{
    color:#dc2626;
}

.pron-premium-actions{
    border-top:1px solid #F0EEF8;
    margin-top:16px;
    padding-top:16px;
    display:flex;
    justify-content:center;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    background:#ffffff;
}

.pron-premium-btn{
    border:0;
    border-radius:999px;
    min-width:clamp(104px,16vw,146px);
    padding:13px 20px;
    color:#ffffff;
    font-family:'Nunito',sans-serif;
    font-size:13px;
    font-weight:900;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    box-shadow:0 6px 18px rgba(127,119,221,.18);
    transition:transform .12s,filter .12s,box-shadow .12s;
}

.pron-premium-btn:hover{
    transform:translateY(-1px);
    filter:brightness(1.07);
}

.pron-premium-btn:active{
    transform:scale(.98);
}

.pron-premium-btn:disabled{
    opacity:.55;
    cursor:not-allowed;
    transform:none;
    filter:none;
}

.pron-premium-btn-blue,
.pron-premium-btn-pink{
    background:#7F77DD;
    box-shadow:0 6px 18px rgba(127,119,221,.18);
}

.pron-premium-btn-teal{
    background:#F97316;
    box-shadow:0 6px 18px rgba(249,115,22,.22);
}

#pron-premium-listen,
#pron-premium-show,
#pron-premium-restart{
    background:#7F77DD;
    box-shadow:0 6px 18px rgba(127,119,221,.18);
}

#pron-premium-speak,
#pron-premium-next{
    background:#F97316;
    box-shadow:0 6px 18px rgba(249,115,22,.22);
}

.pron-premium-completed{
    display:none;
    background:#ffffff;
    border:1px solid #EDE9FA;
    border-radius:28px;
    box-shadow:0 12px 36px rgba(127,119,221,.13);
    min-height:clamp(300px,42vh,430px);
    flex-direction:column;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:clamp(28px,5vw,48px) 24px;
    gap:12px;
    box-sizing:border-box;
    width:min(760px,100%);
    margin:0 auto;
}

.pron-premium-completed.active{
    display:flex;
    animation:pronPop .35s ease;
}

.pron-premium-done-icon{
    font-size:64px;
    line-height:1;
    margin-bottom:4px;
}

.pron-premium-done-title{
    margin:0;
    font-family:'Fredoka',sans-serif;
    font-size:clamp(30px,5.5vw,58px);
    color:#F97316;
    line-height:1.03;
    font-weight:700;
}

.pron-premium-done-text{
    margin:0;
    max-width:520px;
    color:#9B94BE;
    font-size:clamp(13px,1.8vw,17px);
    font-weight:800;
    line-height:1.5;
}

.pron-premium-score{
    margin:0;
    color:#534AB7;
    font-size:15px;
    font-weight:900;
}

.pron-premium-done-track{
    height:12px;
    width:min(420px,100%);
    margin:4px auto;
    border-radius:999px;
    background:#F4F2FD;
    border:1px solid #E4E1F8;
    overflow:hidden;
}

.pron-premium-done-fill{
    height:100%;
    width:0%;
    background:linear-gradient(90deg,#F97316,#7F77DD);
    transition:width .8s ease;
}

.pron-premium-confetti{
    position:fixed;
    width:10px;
    height:14px;
    top:-20px;
    z-index:99999;
    opacity:.95;
    animation:pronFall linear forwards;
    pointer-events:none;
}

@keyframes pronPop{
    from{opacity:0;transform:translateY(12px) scale(.96)}
    to{opacity:1;transform:translateY(0) scale(1)}
}

@keyframes pronFall{
    to{transform:translateY(110vh) rotate(720deg);opacity:1}
}

@media(max-width:640px){
    .pron-premium-shell{
        min-height:100vh;
        padding:12px;
        border-radius:0;
    }

    .pron-premium-board{
        border-radius:26px;
        padding:14px;
        width:100%;
    }

    .pron-premium-title-panel{
        width:100%;
    }

    .pron-premium-kicker{
        padding:5px 11px;
        font-size:11px;
        margin-bottom:6px;
    }

    .pron-premium-title{
        font-size:clamp(26px,8vw,38px);
    }

    .pron-premium-progress-row{
        flex-direction:column;
        align-items:stretch;
        gap:8px;
    }

    .pron-premium-progress-count{
        align-self:center;
    }

    .pron-premium-card{
        border-radius:22px;
        padding:18px;
        min-height:min(380px,58vh);
    }

    .pron-premium-image-box{
        width:210px;
        height:210px;
    }

    .pron-premium-word{
        font-size:clamp(34px,11vw,56px);
    }

    .pron-premium-actions{
        display:grid;
        grid-template-columns:1fr;
        gap:9px;
    }

    .pron-premium-btn{
        width:100%;
    }

    .pron-premium-completed{
        border-radius:26px;
        width:100%;
    }
}

</style>

<div class="pron-premium-shell">
    <div class="pron-premium-app" id="pron-premium-app">
        <section class="pron-premium-board" id="pron-premium-board">
            <div class="pron-premium-title-panel">
                <div class="pron-premium-kicker">Activity <span id="pron-premium-kicker-count">1 / <?php echo count($items); ?></span></div>
                <h1 class="pron-premium-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="pron-premium-subtitle">Listen, repeat, and check your pronunciation.</p>
            </div>

            <div class="pron-premium-progress-row">
                <div class="pron-premium-progress-track">
                    <div class="pron-premium-progress-fill" id="pron-premium-progress-fill"></div>
                </div>
                <div class="pron-premium-progress-count" id="pron-premium-progress-count">1 / <?php echo count($items); ?></div>
            </div>

            <div class="pron-premium-card-wrap">
                <div class="pron-premium-card" id="pron-premium-card">
                    <div class="pron-premium-image-box" id="pron-premium-image-box">
                        <img id="pron-premium-img" src="" alt="" style="display:none;">
                        <div class="pron-premium-placeholder" id="pron-premium-placeholder">🔊</div>
                    </div>
                    <div class="pron-premium-word" id="pron-premium-word"></div>
                    <div class="pron-premium-phonetic" id="pron-premium-phonetic" style="display:none;"></div>
                    <div class="pron-premium-captured" id="pron-premium-captured"></div>
                    <div class="pron-premium-answer" id="pron-premium-answer"></div>
                    <div class="pron-premium-feedback" id="pron-premium-feedback"></div>
                </div>
            </div>

            <div class="pron-premium-actions" id="pron-premium-actions-main">
                <button type="button" class="pron-premium-btn pron-premium-btn-teal" id="pron-premium-listen">Listen</button>
                <button type="button" class="pron-premium-btn pron-premium-btn-teal" id="pron-premium-speak">Speaker</button>
                <button type="button" class="pron-premium-btn pron-premium-btn-pink" id="pron-premium-show">Show Answer</button>
                <button type="button" class="pron-premium-btn pron-premium-btn-blue" id="pron-premium-next">Next</button>
            </div>
        </section>

        <section class="pron-premium-completed" id="pron-premium-completed">
            <div class="pron-premium-done-icon">✅</div>
            <h2 class="pron-premium-done-title" id="pron-premium-completed-title">All Done!</h2>
            <p class="pron-premium-done-text" id="pron-premium-completed-text">Great job practicing pronunciation.</p>
            <p class="pron-premium-score" id="pron-premium-score-text"></p>
            <div class="pron-premium-done-track">
                <div class="pron-premium-done-fill" id="pron-premium-done-fill"></div>
            </div>
            <div class="pron-premium-actions">
                <button type="button" class="pron-premium-btn pron-premium-btn-teal" id="pron-premium-restart">Restart</button>
                <button type="button" class="pron-premium-btn pron-premium-btn-blue" onclick="history.back()">Back</button>
            </div>
        </section>
    </div>
</div>

<audio id="pron-premium-win" src="../../hangman/assets/win.mp3" preload="auto"></audio>
<audio id="pron-premium-lose" src="../../hangman/assets/lose.mp3" preload="auto"></audio>

<script>
document.addEventListener('DOMContentLoaded', function () {
'use strict';

var data = Array.isArray(<?php echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>) ? <?php echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> : [];
var activityTitle = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var PRON_ACTIVITY_ID = <?php echo json_encode($activity['id'] ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var PRON_RETURN_TO = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

var TOTAL = data.length;
var index = 0;
var finished = false;
var capturedText = '';
var recognitionBusy = false;
var correctCount = 0;
var checkedCards = {};

var els = {
    board: document.getElementById('pron-premium-board'),
    completed: document.getElementById('pron-premium-completed'),
    actions: document.getElementById('pron-premium-actions-main'),
    img: document.getElementById('pron-premium-img'),
    placeholder: document.getElementById('pron-premium-placeholder'),
    word: document.getElementById('pron-premium-word'),
    phonetic: document.getElementById('pron-premium-phonetic'),
    captured: document.getElementById('pron-premium-captured'),
    answer: document.getElementById('pron-premium-answer'),
    feedback: document.getElementById('pron-premium-feedback'),
    progressFill: document.getElementById('pron-premium-progress-fill'),
    progressCount: document.getElementById('pron-premium-progress-count'),
    kickerCount: document.getElementById('pron-premium-kicker-count'),
    doneFill: document.getElementById('pron-premium-done-fill'),
    completedTitle: document.getElementById('pron-premium-completed-title'),
    completedText: document.getElementById('pron-premium-completed-text'),
    scoreText: document.getElementById('pron-premium-score-text'),
    win: document.getElementById('pron-premium-win'),
    lose: document.getElementById('pron-premium-lose')
};

var listenBtn = document.getElementById('pron-premium-listen');
var speakBtn = document.getElementById('pron-premium-speak');
var showBtn = document.getElementById('pron-premium-show');
var nextBtn = document.getElementById('pron-premium-next');
var restartBtn = document.getElementById('pron-premium-restart');

var recognition = null;
var SpeechRecognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition || null;
if (SpeechRecognitionCtor) {
    recognition = new SpeechRecognitionCtor();
    recognition.lang = 'en-US';
    recognition.interimResults = false;
    recognition.maxAlternatives = 1;
    recognition.continuous = false;
}

var pronIsSpeaking = false;
var pronIsPaused = false;
var pronSpeechOffset = 0;
var pronSpeechSourceText = '';
var pronSpeechSegmentStart = 0;
var pronUtter = null;
var pronCurrentAudio = null;

function setListenButtonLabel() {
    if (!listenBtn) return;
    if (pronIsPaused) {
        listenBtn.textContent = 'Resume';
    } else if (pronIsSpeaking) {
        listenBtn.textContent = 'Pause';
    } else {
        listenBtn.textContent = 'Listen';
    }
}


function persistScoreSilently(targetUrl) {
    if (!targetUrl) return Promise.resolve(false);
    return fetch(targetUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
    }).then(function (response) {
        return !!(response && response.ok);
    }).catch(function () {
        return false;
    });
}

function navigateToReturn(targetUrl) {
    if (!targetUrl) return;
    try {
        if (window.top && window.top !== window.self) {
            window.top.location.href = targetUrl;
            return;
        }
    } catch (e) {}
    window.location.href = targetUrl;
}

function normalizeText(text) {
    return String(text || '')
        .toLowerCase()
        .trim()
        .replace(/[.,!?;:'"\-]/g, '')
        .replace(/\s+/g, ' ');
}

function wordOverlapScore(a, b) {
    var wa = a.split(' ').filter(Boolean);
    var wb = b.split(' ').filter(Boolean);
    if (!wa.length || !wb.length) return 0;
    var matches = wa.filter(function (w) { return wb.indexOf(w) !== -1; }).length;
    return matches / Math.max(wa.length, wb.length);
}

function soundex(word) {
    var w = String(word || '').toUpperCase().replace(/[^A-Z]/g, '');
    if (!w) return '';
    var first = w.charAt(0);
    var map = {
        B: '1', F: '1', P: '1', V: '1',
        C: '2', G: '2', J: '2', K: '2', Q: '2', S: '2', X: '2', Z: '2',
        D: '3', T: '3',
        L: '4',
        M: '5', N: '5',
        R: '6'
    };
    var out = first;
    var prev = map[first] || '';
    for (var i = 1; i < w.length; i++) {
        var ch = w.charAt(i);
        var code = map[ch] || '0';
        if (code !== '0' && code !== prev) out += code;
        prev = code;
    }
    return (out + '000').slice(0, 4);
}

function phoneticTokensMatch(said, expected) {
    var saidTokens = said.split(' ').filter(Boolean);
    var expectedTokens = expected.split(' ').filter(Boolean);
    if (!saidTokens.length || saidTokens.length !== expectedTokens.length) return false;
    for (var i = 0; i < saidTokens.length; i++) {
        var s = saidTokens[i];
        var e = expectedTokens[i];
        if (s === e) continue;
        if (s.length <= 2 || e.length <= 2) return false;
        if (soundex(s) !== soundex(e)) return false;
    }
    return true;
}

function isMatch(said, expected) {
    if (said === expected) return true;
    if (phoneticTokensMatch(said, expected)) return true;
    if (wordOverlapScore(said, expected) >= 0.8) return true;
    return false;
}

function playSound(sound) {
    try {
        sound.pause();
        sound.currentTime = 0;
        sound.play();
    } catch (e) {}
}

function getCurrentWord() {
    return String((data[index] && data[index].en) || '').trim();
}

function getPlaceholder(word) {
    if (!word) return '🔊';
    return word.charAt(0).toUpperCase() || '🔊';
}

function speakCurrent() {
    if (!data[index]) return;

    if (data[index].audio) {
        var audioSrc = data[index].audio;
        if (!pronCurrentAudio || pronCurrentAudio.getAttribute('data-src') !== audioSrc) {
            if (pronCurrentAudio) pronCurrentAudio.pause();
            pronCurrentAudio = new Audio(audioSrc);
            pronCurrentAudio.setAttribute('data-src', audioSrc);
            pronCurrentAudio.onended = function () { pronCurrentAudio = null; pronIsSpeaking = false; pronIsPaused = false; setListenButtonLabel(); };
        }
        if (!pronCurrentAudio.paused) {
            pronCurrentAudio.pause();
            pronIsSpeaking = true;
            pronIsPaused = true;
        } else {
            pronCurrentAudio.play().then(function () {
                pronIsSpeaking = true;
                pronIsPaused = false;
                setListenButtonLabel();
            }).catch(function () {});
        }
        setListenButtonLabel();
        return;
    }

    if (!window.speechSynthesis) return;
    var text = getCurrentWord();
    if (!text) return;

    if (speechSynthesis.paused || pronIsPaused) {
        speechSynthesis.resume();
        pronIsSpeaking = true;
        pronIsPaused = false;
        setListenButtonLabel();
        setTimeout(function () {
            if (!speechSynthesis.speaking && pronSpeechOffset < pronSpeechSourceText.length) {
                pronStartSpeechFromOffset();
            }
        }, 80);
        return;
    }

    if (speechSynthesis.speaking && !speechSynthesis.paused) {
        speechSynthesis.pause();
        pronIsSpeaking = true;
        pronIsPaused = true;
        setListenButtonLabel();
        return;
    }

    speechSynthesis.cancel();
    pronSpeechSourceText = text;
    pronSpeechOffset = 0;
    pronStartSpeechFromOffset();
}

function pronStartSpeechFromOffset() {
    var source = pronSpeechSourceText;
    if (!source) return;
    var safeOffset = Math.max(0, Math.min(pronSpeechOffset, source.length));
    var remaining = source.slice(safeOffset);
    if (!remaining.trim()) {
        pronIsSpeaking = false;
        pronIsPaused = false;
        pronSpeechOffset = 0;
        return;
    }
    speechSynthesis.cancel();
    pronSpeechSegmentStart = safeOffset;
    pronUtter = new SpeechSynthesisUtterance(remaining);
    pronUtter.lang = 'en-US';
    pronUtter.rate = 0.88;
    pronUtter.pitch = 1.05;
    pronUtter.onstart = function () { pronIsSpeaking = true; pronIsPaused = false; setListenButtonLabel(); };
    pronUtter.onpause = function () { pronIsPaused = true; pronIsSpeaking = true; setListenButtonLabel(); };
    pronUtter.onresume = function () { pronIsPaused = false; pronIsSpeaking = true; setListenButtonLabel(); };
    pronUtter.onboundary = function (event) {
        if (typeof event.charIndex === 'number') {
            pronSpeechOffset = Math.max(pronSpeechSegmentStart, Math.min(source.length, pronSpeechSegmentStart + event.charIndex));
        }
    };
    pronUtter.onend = function () {
        if (pronIsPaused) return;
        pronIsSpeaking = false;
        pronIsPaused = false;
        pronSpeechOffset = 0;
        setListenButtonLabel();
    };
    speechSynthesis.speak(pronUtter);
}

function loadCard() {
    if (!TOTAL) return;

    var item = data[index] || {};
    var word = getCurrentWord() || 'Listen and pronounce the word.';

    if (window.speechSynthesis) speechSynthesis.cancel();
    pronIsSpeaking = false;
    pronIsPaused = false;
    pronSpeechOffset = 0;
    pronSpeechSourceText = '';
    pronSpeechSegmentStart = 0;
    pronUtter = null;
    if (pronCurrentAudio) {
        pronCurrentAudio.pause();
        pronCurrentAudio = null;
    }

    finished = false;
    capturedText = '';

    els.word.textContent = word;
    els.captured.textContent = '';
    els.captured.className = 'pron-premium-captured';
    els.answer.textContent = 'Correct answer: ' + word;
    els.answer.classList.remove('show');
    els.feedback.textContent = '';
    els.feedback.className = 'pron-premium-feedback';

    if (item.ph) {
        els.phonetic.textContent = item.ph;
        els.phonetic.style.display = '';
    } else {
        els.phonetic.textContent = '';
        els.phonetic.style.display = 'none';
    }

    if (item.img) {
        els.img.src = item.img;
        els.img.alt = word;
        els.img.style.display = '';
        els.placeholder.style.display = 'none';
    } else {
        els.img.removeAttribute('src');
        els.img.alt = '';
        els.img.style.display = 'none';
        els.placeholder.textContent = getPlaceholder(word);
        els.placeholder.style.display = '';
    }

    var countText = (index + 1) + ' / ' + TOTAL;
    var pct = Math.max(1, Math.round(((index + 1) / TOTAL) * 100));
    els.progressFill.style.width = pct + '%';
    els.progressCount.textContent = countText;
    els.kickerCount.textContent = countText;
    nextBtn.textContent = index < TOTAL - 1 ? 'Next' : 'Finish';
}

function recordPronunciation() {
    if (!data[index]) return;
    if (recognitionBusy) return;

    if (!recognition) {
        els.feedback.textContent = 'Speech recognition is not available in this browser.';
        els.feedback.className = 'pron-premium-feedback bad';
        return;
    }

    recognitionBusy = true;
    els.feedback.textContent = 'Listening...';
    els.feedback.className = 'pron-premium-feedback';

    recognition.onresult = function (event) {
        capturedText = String((event.results && event.results[0] && event.results[0][0] && event.results[0][0].transcript) || '');
        recognitionBusy = false;
        els.feedback.textContent = '';
        els.feedback.className = 'pron-premium-feedback';
        checkAnswer();
    };

    recognition.onerror = function () {
        capturedText = '';
        els.captured.textContent = 'Could not capture voice. Try again.';
        els.captured.className = 'pron-premium-captured bad';
        els.feedback.textContent = 'Try Again';
        els.feedback.className = 'pron-premium-feedback bad';
        recognitionBusy = false;
    };

    recognition.onend = function () {
        recognitionBusy = false;
    };

    try {
        recognition.start();
    } catch (e) {
        recognitionBusy = false;
    }
}

function checkAnswer() {
    if (!data[index]) return;

    var said = normalizeText(capturedText);
    var expected = normalizeText(getCurrentWord());

    if (said === '') {
        els.feedback.textContent = "You didn't record your voice.";
        els.feedback.className = 'pron-premium-feedback bad';
        return;
    }

    var correct = isMatch(said, expected);
    var expectedLabel = getCurrentWord();

    if (correct) {
        els.captured.textContent = '✔ Good: ' + expectedLabel;
        els.captured.className = 'pron-premium-captured ok';
        els.feedback.textContent = '';
        playSound(els.win);
    } else {
        els.captured.textContent = '✘ Wrong';
        els.captured.className = 'pron-premium-captured bad';
        els.feedback.textContent = '';
        playSound(els.lose);
    }

    if (correct && !checkedCards[index]) {
        checkedCards[index] = true;
        correctCount++;
    } else if (!correct && !checkedCards[index]) {
        checkedCards[index] = false;
    }
}

function showAnswer() {
    if (!data[index]) return;
    var lines = [];
    if (capturedText) {
        lines.push('You said: “' + capturedText + '”');
    }
    lines.push('Correct: ' + getCurrentWord());
    els.answer.textContent = lines.join('   →   ');
    els.answer.classList.add('show');
}

async function showCompleted() {
    finished = true;
    els.board.style.display = 'none';
    els.completed.classList.add('active');
    setTimeout(function(){ els.doneFill.style.width = '100%'; }, 120);
    launchConfetti();
    playSound(els.win);

    var pct = TOTAL > 0 ? Math.round((correctCount / TOTAL) * 100) : 0;
    var errors = Math.max(0, TOTAL - correctCount);

    els.completedTitle.textContent = 'All Done!';
    els.completedText.textContent = "You've completed " + (activityTitle || 'this activity') + '. Great job practicing.';
    els.scoreText.textContent = 'Score: ' + correctCount + ' / ' + TOTAL + ' (' + pct + '%)';

    if (PRON_ACTIVITY_ID && PRON_RETURN_TO) {
        var joiner = PRON_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
        var saveUrl = PRON_RETURN_TO +
            joiner + 'activity_percent=' + pct +
            '&activity_errors=' + errors +
            '&activity_total=' + TOTAL +
            '&activity_id=' + encodeURIComponent(PRON_ACTIVITY_ID) +
            '&activity_type=pronunciation';

        var ok = await persistScoreSilently(saveUrl);
        if (!ok) {
            navigateToReturn(saveUrl);
        }
    }
}

function goNext() {
    if (finished) return;
    if (index < TOTAL - 1) {
        index++;
        loadCard();
    } else {
        showCompleted();
    }
}

function restart() {
    correctCount = 0;
    checkedCards = {};
    index = 0;
    finished = false;
    els.doneFill.style.width = '0%';
    els.completed.classList.remove('active');
    els.board.style.display = '';
    loadCard();
}

function launchConfetti(){
    var colors = ['#1D9E75','#085041','#7F77DD','#EC4899','#2563EB','#FFFFFF'];
    var amount = 80;

    for (var i = 0; i < amount; i++) {
        (function(n){
            setTimeout(function(){
                var piece = document.createElement('span');
                piece.className = 'pron-premium-confetti';
                piece.style.left = Math.random() * 100 + 'vw';
                piece.style.background = colors[Math.floor(Math.random() * colors.length)];
                piece.style.animationDuration = (2.2 + Math.random() * 1.8) + 's';
                piece.style.transform = 'rotate(' + (Math.random() * 180) + 'deg)';
                piece.style.borderRadius = Math.random() > .5 ? '999px' : '3px';
                document.body.appendChild(piece);
                setTimeout(function(){ piece.remove(); }, 4500);
            }, n * 10);
        })(i);
    }
}

listenBtn.addEventListener('click', speakCurrent);
speakBtn.addEventListener('click', recordPronunciation);
showBtn.addEventListener('click', showAnswer);
nextBtn.addEventListener('click', goNext);
restartBtn.addEventListener('click', restart);

loadCard();
});
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔊', $content);
