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

    $findById = function (string $id) use ($pdo, $selectFields): ?array {
        if ($id === '') {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'pronunciation'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    };

    $findByUnitId = function (string $unitId) use ($pdo, $selectFields, $columns): ?array {
        if ($unitId === '' || !in_array('unit_id', $columns, true)) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit_id = :unit
               AND type = 'pronunciation'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unitId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    };

    $findByUnitLegacy = function (string $unitValue) use ($pdo, $selectFields, $columns): ?array {
        if ($unitValue === '' || !in_array('unit', $columns, true)) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit = :unit
               AND type = 'pronunciation'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unitValue));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    };

    $row = null;
    if ($activityId !== '') {
        $row = $findById($activityId);
    }
    if (!$row && $unit !== '') {
        $row = $findByUnitId($unit);
    }
    if (!$row && $unit !== '') {
        $row = $findByUnitLegacy($unit);
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
$cssVersion = file_exists(__DIR__ . '/../multiple_choice/multiple_choice.css') ? (string) filemtime(__DIR__ . '/../multiple_choice/multiple_choice.css') : (string) time();

ob_start();
?>

<style>
.pron-prompt,
.pron-hint,
.pron-captured,
.pron-answer{
    text-align: center;
}

.pron-prompt{
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(28px, 3.5vw, 48px);
    font-weight: 900;
    color: #5b21b6;
    line-height: 1.15;
    margin-bottom: 10px;
    letter-spacing: -0.01em;
}
.mc-btn-listen{
    background: linear-gradient(180deg, #14b8a6 0%, #0d9488 100%);
    box-shadow: 0 8px 18px rgba(20, 184, 166, 0.25);
}
.mc-btn-check{
    background: linear-gradient(180deg, #c084fc 0%, #a855f7 100%);
    box-shadow: 0 8px 18px rgba(168, 85, 247, 0.25);
}
.mc-btn-show{
    background: linear-gradient(180deg, #c084fc 0%, #a855f7 100%);
    box-shadow: 0 8px 18px rgba(168, 85, 247, 0.25);
}
.mc-btn-next{
    background: linear-gradient(180deg, #14b8a6 0%, #0d9488 100%);
    box-shadow: 0 8px 18px rgba(20, 184, 166, 0.25);
}
.mc-btn-speak{
    background: linear-gradient(180deg, #e9d5ff 0%, #d8b4fe 100%);
    color: #5b21b6;
    box-shadow: 0 8px 18px rgba(168, 85, 247, 0.2);
}
.mc-btn-listen:hover,
.mc-btn-listen:focus {
    box-shadow: 0 12px 28px rgba(20, 184, 166, 0.35);
}
.mc-btn-check:hover,
.mc-btn-check:focus,
.mc-btn-show:hover,
.mc-btn-show:focus {
    box-shadow: 0 12px 28px rgba(168, 85, 247, 0.35);
}
.mc-btn-next:hover,
.mc-btn-next:focus {
    box-shadow: 0 12px 28px rgba(20, 184, 166, 0.35);
}
.mc-btn-speak:hover,
.mc-btn-speak:focus {
    box-shadow: 0 12px 28px rgba(168, 85, 247, 0.3);
}
.pron-captured{
    margin-top: 8px;
    max-width: 620px;
    width: 100%;
    margin-left: auto;
    margin-right: auto;
    border: 2px solid #ddd6fe;
    border-radius: 14px;
    padding: 10px 12px;
    background: linear-gradient(180deg, #faf5ff 0%, #f5f3ff 100%);
    color: #5b21b6;
    font-weight: 700;
    font-size: 15px;
}

.pron-captured:empty{
    display:none;
}

.pron-captured.ok{
    border-color: #22c55e;
    background: linear-gradient(180deg, #f0fdf4 0%, #dcfce7 100%);
    color: #166534;
}

.pron-captured.bad{
    border-color: #ef4444;
    background: linear-gradient(180deg, #fef2f2 0%, #fee2e2 100%);
    color: #b91c1c;
}

.pron-answer{
    display: none;
    margin-top: 8px;
    max-width: 620px;
    width: 100%;
    margin-left: auto;
    margin-right: auto;
    border-radius: 14px;
    border: 1px solid #f5d8db;
    background: linear-gradient(180deg, #fef2f2 0%, #fee2e2 100%);
    color: #9f1239;
    padding: 10px 12px;
    font-weight: 700;
    font-size: 15px;
}

.pron-answer.show{
    display: block;
}

.mc-btn{
    border: none;
    border-radius: 999px;
    min-height: 40px;
    padding: 0 16px;
    color: #fff;
    font-weight: 700;
    font-size: 13px;
    box-shadow: 0 8px 16px rgba(92, 33, 182, 0.12);
    transition: transform 0.12s ease, filter 0.12s ease;
    cursor: pointer;
}

.mc-btn:hover,
.mc-btn:focus {
    transform: translateY(-1px);
    filter: brightness(1.06);
}

.mc-btn-listen{
    background: linear-gradient(180deg, #a78bfa 0%, #7c3aed 100%);
}

.mc-btn-check{
    background: linear-gradient(180deg, #a78bfa 0%, #7c3aed 100%);
}
.mc-btn-show{
    background: linear-gradient(180deg, #14b8a6 0%, #0f766e 100%);
}
.mc-btn-next{
    background: linear-gradient(180deg, #14b8a6 0%, #0f766e 100%);
}
.mc-btn-speak{
    background: linear-gradient(180deg, #c4b5fd 0%, #a78bfa 100%);
    color: #3b0764;
}

.mc-btn-listen {

    .mc-btn:hover,
    .mc-btn:focus {
        transform: translateY(-2px);
        filter: brightness(1.08);
    }

    .mc-status{
        font-size:14px;
        margin-bottom:8px;
    }
    background: linear-gradient(180deg, #14b8a6 0%, #0d9488 100%);
    box-shadow: 0 8px 18px rgba(20, 184, 166, 0.25);
}
.mc-btn-check {
    background: linear-gradient(180deg, #c084fc 0%, #a855f7 100%);
    box-shadow: 0 8px 18px rgba(168, 85, 247, 0.25);
}
.mc-btn-show {
    background: linear-gradient(180deg, #c084fc 0%, #a855f7 100%);
    box-shadow: 0 8px 18px rgba(168, 85, 247, 0.25);
}
.mc-btn-next {
    background: linear-gradient(180deg, #14b8a6 0%, #0d9488 100%);
    box-shadow: 0 8px 18px rgba(20, 184, 166, 0.25);
}
.mc-btn-speak {
    background: linear-gradient(180deg, #e9d5ff 0%, #d8b4fe 100%);
    color: #5b21b6;
    box-shadow: 0 8px 18px rgba(168, 85, 247, 0.2);
}
.mc-btn-listen:hover,
.mc-btn-listen:focus {
    box-shadow: 0 12px 28px rgba(20, 184, 166, 0.35);
}
.mc-btn-check:hover,
.mc-btn-check:focus,
.mc-btn-show:hover,
.mc-btn-show:focus {
    box-shadow: 0 12px 28px rgba(168, 85, 247, 0.35);
}
.mc-btn-next:hover,
.mc-btn-next:focus {
    box-shadow: 0 12px 28px rgba(20, 184, 166, 0.35);
}
.mc-btn-speak:hover,
.mc-btn-speak:focus {
    box-shadow: 0 12px 28px rgba(168, 85, 247, 0.3);
}

.mc-btn-listen:hover,
.mc-btn-listen:focus,
.mc-btn-show:hover,
.mc-btn-show:focus,
.mc-btn-next:hover,
.mc-btn-next:focus {
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.2);
}
.mc-btn-speak:hover,
.mc-btn-speak:focus {
    filter: brightness(1.08);
}

.mc-btn {
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.15) !important;
}
.mc-status{
    font-size:14px;
    margin-bottom:8px;
}
.mc-btn{
    border: none;
    border-radius: 999px;
    min-height: 40px;
    padding: 0 16px;
    color: #fff;
    font-weight: 700;
    font-size: 13px;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.15);
    transition: transform 0.12s ease, filter 0.12s ease, box-shadow 0.12s ease;
    cursor: pointer;
}

.mc-btn:hover,
.mc-btn:focus {
    transform: translateY(-2px);
    filter: brightness(1.08);
}

.mc-btn-listen {
    background: linear-gradient(180deg, #14b8a6 0%, #0d9488 100%);
    box-shadow: 0 8px 18px rgba(20, 184, 166, 0.25);
}

.mc-btn-check {
    background: linear-gradient(180deg, #c084fc 0%, #a855f7 100%);
    box-shadow: 0 8px 18px rgba(168, 85, 247, 0.25);
}

.mc-btn-show {
    background: linear-gradient(180deg, #c084fc 0%, #a855f7 100%);
    box-shadow: 0 8px 18px rgba(168, 85, 247, 0.25);
}

.mc-btn-next {
    background: linear-gradient(180deg, #14b8a6 0%, #0d9488 100%);
    box-shadow: 0 8px 18px rgba(20, 184, 166, 0.25);
}

.mc-btn-speak {
    background: linear-gradient(180deg, #e9d5ff 0%, #d8b4fe 100%);
    color: #5b21b6;
    box-shadow: 0 8px 18px rgba(168, 85, 247, 0.2);
}

.mc-btn-listen:hover,
.mc-btn-listen:focus {
    box-shadow: 0 12px 28px rgba(20, 184, 166, 0.35);
}

.mc-btn-check:hover,
.mc-btn-check:focus,
.mc-btn-show:hover,
.mc-btn-show:focus {
    box-shadow: 0 12px 28px rgba(168, 85, 247, 0.35);
}

.mc-btn-next:hover,
.mc-btn-next:focus {
    box-shadow: 0 12px 28px rgba(20, 184, 166, 0.35);
}

.mc-btn-speak:hover,
.mc-btn-speak:focus {
    box-shadow: 0 12px 28px rgba(168, 85, 247, 0.3);
}

.mc-status{
    font-size:14px;
    margin-bottom:8px;
}
.pron-listen-row{
    display:flex;
    justify-content:center;
    margin: 0 0 8px 0;
    gap:8px;
}

.pron-listen-row .mc-btn{
    min-width: auto;
}

#pron-viewer{
    width:100%;
    max-width:100%;
    min-height:calc(100vh - 120px);
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    padding: 24px 18px 32px;
    background: #f0f4fb;
}

#pron-viewer {
    background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 52%, #e9d5ff 100%) !important;
}
#pron-viewer {
    background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 52%, #e9d5ff 100%);
}
#pron-card{
    width: min(760px, 100%);
    max-width: 760px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:flex-start;
    gap: 12px;
    padding: 28px 24px;
    background: #ffffff;
    border: 1px solid #ddd6fe;
    border-radius: 24px;
    box-shadow: 0 20px 48px rgba(92, 33, 182, 0.12);
}

#pron-controls{
    display:flex;
    justify-content:center;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    margin-top: 12px;
    margin-bottom: 12px;
}

.completed-screen{
    display:none;
    text-align:center;
    max-width:600px;
    margin:0 auto;
    padding:40px 20px;
}

.completed-screen.active{
    display:block;
}

.completed-icon{
    font-size:80px;
    margin-bottom:20px;
}

.completed-title{
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    font-size:36px;
    font-weight:700;
    color:#5b21b6;
    margin:0 0 16px;
    line-height:1.2;
}

.completed-text{
    font-size:16px;
    color:#1f2937;
    line-height:1.6;
    margin:0 0 32px;
}

.completed-button{
    display:inline-block;
    padding:12px 24px;
    border:none;
    border-radius:999px;
    background:linear-gradient(180deg, #a78bfa 0%, #7c3aed 100%);
    color:#fff;
    font-weight:700;
    font-size:16px;
    cursor:pointer;
    box-shadow:0 10px 24px rgba(92, 33, 182, 0.2);
    transition:transform .18s ease, filter .18s ease;
}

.completed-button:hover{
    transform:scale(1.05);
    filter:brightness(1.08);
}
</style>

<div class="mc-viewer" id="pron-viewer">
        <div class="mc-status" id="pron-status"></div>

        <div class="mc-card" id="pron-card">
            <div class="pron-listen-row" id="pron-listen-row">
                <button type="button" class="mc-btn mc-btn-listen" id="pron-listen">Listen</button>
                <button type="button" class="mc-btn mc-btn-speak" id="pron-speak">Speaker</button>
            </div>
                <img id="pron-image" class="pron-image" alt="">
            <div class="pron-prompt" id="pron-prompt"></div>
            <div class="pron-hint" id="pron-hint"></div>
            <div class="pron-captured" id="pron-captured"></div>
                <div class="pron-answer" id="pron-answer"></div>
        </div>

        <div class="mc-controls" id="pron-controls">
                <button type="button" class="mc-btn mc-btn-show" id="pron-show">Show Answer</button>
                <button type="button" class="mc-btn mc-btn-next" id="pron-next">Next</button>
        </div>

        <div class="mc-feedback" id="pron-feedback"></div>

        <div id="pron-completed" class="completed-screen">
            <div class="completed-icon">✅</div>
            <h2 class="completed-title" id="pron-completed-title"></h2>
            <p class="completed-text" id="pron-completed-text"></p>
            <p class="completed-text" id="pron-score-text" style="font-weight:700;font-size:18px;color:#c2410c;"></p>
            <button type="button" class="completed-button" id="pron-restart">Restart</button>
        </div>
</div>

<link rel="stylesheet" href="../multiple_choice/multiple_choice.css?v=<?php echo urlencode($cssVersion); ?>">
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sourceData = Array.isArray(<?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?>) ? <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?> : [];
    var data = sourceData.slice();
    var activityTitle = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
    var PRON_ACTIVITY_ID = <?php echo json_encode($activity['id'] ?? '', JSON_UNESCAPED_UNICODE); ?>;
    var PRON_RETURN_TO = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;

    var statusEl = document.getElementById('pron-status');
    var promptEl = document.getElementById('pron-prompt');
    var hintEl = document.getElementById('pron-hint');
    var imageEl = document.getElementById('pron-image');
    var capturedEl = document.getElementById('pron-captured');
    var answerEl = document.getElementById('pron-answer');
    var feedbackEl = document.getElementById('pron-feedback');
    var cardEl = document.getElementById('pron-card');
    var listenRowEl = document.getElementById('pron-listen-row');
    var controlsEl = document.getElementById('pron-controls');
    var completedEl = document.getElementById('pron-completed');
    var completedTitleEl = document.getElementById('pron-completed-title');
    var completedTextEl = document.getElementById('pron-completed-text');
    var scoreTextEl = document.getElementById('pron-score-text');

    var listenBtn = document.getElementById('pron-listen');
    var speakBtn = document.getElementById('pron-speak');
    var checkBtn = document.getElementById('pron-check');
    var showBtn = document.getElementById('pron-show');
    var nextBtn = document.getElementById('pron-next');
    var restartBtn = document.getElementById('pron-restart');

    var recognition = null;
    var SpeechRecognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition || null;
    if (SpeechRecognitionCtor) {
        recognition = new SpeechRecognitionCtor();
        recognition.lang = 'en-US';
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;
        recognition.continuous = false;
    }

    var correctSound = new Audio('../../hangman/assets/win.mp3');
    var wrongSound = new Audio('../../hangman/assets/lose.mp3');
    var doneSound = new Audio('../../hangman/assets/win (1).mp3');

    var index = 0;
    var finished = false;
    var capturedText = '';
    var recognitionBusy = false;
    var correctCount = 0;
    var totalCount = data.length;
    var checkedCards = {};

    var pronIsSpeaking = false;
    var pronIsPaused = false;
    var pronSpeechOffset = 0;
    var pronSpeechSourceText = '';
    var pronSpeechSegmentStart = 0;
    var pronUtter = null;
    var pronCurrentAudio = null;

    if (completedTitleEl) {
        completedTitleEl.textContent = activityTitle || 'Pronunciation Practice';
    }

    if (completedTextEl) {
        completedTextEl.textContent = "You've completed " + (activityTitle || 'this activity') + '. Great job practicing.';
    }

    function persistScoreSilently(targetUrl) {
        if (!targetUrl) {
            return Promise.resolve(false);
        }

        return fetch(targetUrl, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
        }).then(function (response) {
            return !!(response && response.ok);
        }).catch(function () {
            return false;
        });
    }

    function navigateToReturn(targetUrl) {
        if (!targetUrl) {
            return;
        }

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
        if (!wa.length || !wb.length) { return 0; }
        var matches = wa.filter(function (w) { return wb.indexOf(w) !== -1; }).length;
        return matches / Math.max(wa.length, wb.length);
    }

    function soundex(word) {
        var w = String(word || '').toUpperCase().replace(/[^A-Z]/g, '');
        if (!w) { return ''; }

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
            if (code !== '0' && code !== prev) {
                out += code;
            }
            prev = code;
        }

        return (out + '000').slice(0, 4);
    }

    function phoneticTokensMatch(said, expected) {
        var saidTokens = said.split(' ').filter(Boolean);
        var expectedTokens = expected.split(' ').filter(Boolean);

        if (!saidTokens.length || saidTokens.length !== expectedTokens.length) {
            return false;
        }

        for (var i = 0; i < saidTokens.length; i++) {
            var s = saidTokens[i];
            var e = expectedTokens[i];

            if (s === e) {
                continue;
            }

            // For very short tokens, require exact text to avoid false positives like "gu" vs "go".
            if (s.length <= 2 || e.length <= 2) {
                return false;
            }

            if (soundex(s) !== soundex(e)) {
                return false;
            }
        }

        return true;
    }

    function isMatch(said, expected) {
        if (said === expected) { return true; }
        if (phoneticTokensMatch(said, expected)) { return true; }
        if (wordOverlapScore(said, expected) >= 0.8) { return true; }
        return false;
    }

    function playSound(sound) {
        try {
            sound.pause();
            sound.currentTime = 0;
            sound.play();
        } catch (e) {}
    }

    function speakCurrent() {
        if (!data[index]) {
            return;
        }

        // --- Audio file path ---
        if (data[index].audio) {
            var audioSrc = data[index].audio;
            if (!pronCurrentAudio || pronCurrentAudio.getAttribute('data-src') !== audioSrc) {
                if (pronCurrentAudio) { pronCurrentAudio.pause(); }
                pronCurrentAudio = new Audio(audioSrc);
                pronCurrentAudio.setAttribute('data-src', audioSrc);
                pronCurrentAudio.onended = function () { pronCurrentAudio = null; };
            }
            if (!pronCurrentAudio.paused) {
                pronCurrentAudio.pause();
            } else {
                pronCurrentAudio.play().catch(function () {});
            }
            return;
        }

        // --- TTS path ---
        if (!window.speechSynthesis) { return; }
        var text = data[index].en || '';
        if (!text) { return; }

        if (speechSynthesis.paused || pronIsPaused) {
            speechSynthesis.resume();
            pronIsSpeaking = true;
            pronIsPaused = false;
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
            return;
        }

        speechSynthesis.cancel();
        pronSpeechSourceText = text;
        pronSpeechOffset = 0;
        pronStartSpeechFromOffset();
    }

    function pronStartSpeechFromOffset() {
        var source = pronSpeechSourceText;
        if (!source) { return; }
        var safeOffset = Math.max(0, Math.min(pronSpeechOffset, source.length));
        var remaining = source.slice(safeOffset);
        if (!remaining.trim()) {
            pronIsSpeaking = false; pronIsPaused = false; pronSpeechOffset = 0;
            return;
        }
        speechSynthesis.cancel();
        pronSpeechSegmentStart = safeOffset;
        pronUtter = new SpeechSynthesisUtterance(remaining);
        pronUtter.lang = 'en-US';
        pronUtter.rate = 0.9;
        pronUtter.onstart   = function () { pronIsSpeaking = true; pronIsPaused = false; };
        pronUtter.onpause   = function () { pronIsPaused = true; pronIsSpeaking = true; };
        pronUtter.onresume  = function () { pronIsPaused = false; pronIsSpeaking = true; };
        pronUtter.onboundary = function (event) {
            if (typeof event.charIndex === 'number') {
                pronSpeechOffset = Math.max(pronSpeechSegmentStart, Math.min(source.length, pronSpeechSegmentStart + event.charIndex));
            }
        };
        pronUtter.onend = function () {
            if (pronIsPaused) { return; }
            pronIsSpeaking = false; pronIsPaused = false; pronSpeechOffset = 0;
        };
        speechSynthesis.speak(pronUtter);
    }

    function loadCard() {
        var item = data[index] || {};

        if (window.speechSynthesis) { speechSynthesis.cancel(); }
        pronIsSpeaking = false; pronIsPaused = false; pronSpeechOffset = 0; pronSpeechSourceText = ''; pronSpeechSegmentStart = 0; pronUtter = null;
        if (pronCurrentAudio) { pronCurrentAudio.pause(); pronCurrentAudio = null; }

        finished = false;
        capturedText = '';

        completedEl.classList.remove('active');
        cardEl.style.display = 'block';
        listenRowEl.style.display = 'flex';
        controlsEl.style.display = 'flex';

        statusEl.textContent = (index + 1) + ' / ' + data.length;
        promptEl.textContent = item.en || 'Listen and pronounce the word.';
        hintEl.textContent = item.ph || '';
        capturedEl.textContent = '';
        capturedEl.className = 'pron-captured';
        answerEl.classList.remove('show');
        answerEl.textContent = 'Correct answer: ' + (item.en || '');
        feedbackEl.textContent = '';
        feedbackEl.className = 'mc-feedback';

        if (item.img) {
            imageEl.style.display = 'block';
            imageEl.src = item.img;
            imageEl.alt = item.en || '';
        } else {
            imageEl.style.display = 'none';
            imageEl.removeAttribute('src');
            imageEl.alt = '';
        }

        nextBtn.disabled = false;
        nextBtn.textContent = index < data.length - 1 ? 'Next' : 'Finish';
    }

    function recordPronunciation() {
        if (!data[index]) {
            return;
        }

        if (recognitionBusy) {
            return;
        }

        if (!recognition) {
            feedbackEl.textContent = 'Speech recognition is not available in this browser.';
            feedbackEl.className = 'mc-feedback bad';
            return;
        }

        recognitionBusy = true;
        feedbackEl.textContent = 'Listening...';
        feedbackEl.className = 'mc-feedback';

        recognition.onresult = function (event) {
            capturedText = String((event.results && event.results[0] && event.results[0][0] && event.results[0][0].transcript) || '');
            recognitionBusy = false;
            feedbackEl.textContent = '';
            feedbackEl.className = 'mc-feedback';
            checkAnswer();
        };

        recognition.onerror = function () {
            capturedText = '';
            capturedEl.textContent = 'Could not capture voice. Try again.';
            feedbackEl.textContent = 'Try Again';
            feedbackEl.className = 'mc-feedback bad';
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
        if (!data[index]) {
            return;
        }

        var said = normalizeText(capturedText);
        var expected = normalizeText(data[index].en || '');

        if (said === '') {
            feedbackEl.textContent = 'Press Speaker first.';
            feedbackEl.className = 'mc-feedback bad';
            return;
        }

        var correct = isMatch(said, expected);
        var expectedLabel = data[index].en || '';

        if (correct) {
            capturedEl.textContent = '\u2714 Good: ' + expectedLabel;
            capturedEl.className = 'pron-captured ok';
            feedbackEl.textContent = '';
            playSound(correctSound);
        } else {
            capturedEl.textContent = '\u2718 Wrong';
            capturedEl.className = 'pron-captured bad';
            feedbackEl.textContent = '';
            playSound(wrongSound);
        }

        if (correct && !checkedCards[index]) {
            checkedCards[index] = true;
            correctCount++;
        } else if (!correct && !checkedCards[index]) {
            checkedCards[index] = false;
        }
    }

    function showAnswer() {
        if (!data[index]) {
            return;
        }

        var lines = [];
        if (capturedText) {
            lines.push('You said: \u201c' + capturedText + '\u201d');
        }
        lines.push('Correct: ' + (data[index].en || ''));
        answerEl.textContent = lines.join('   \u2192   ');
        answerEl.classList.add('show');
    }

    async function showCompleted() {
        finished = true;
        cardEl.style.display = 'none';
        listenRowEl.style.display = 'none';
        controlsEl.style.display = 'none';
        statusEl.textContent = 'Completed';
        feedbackEl.textContent = '';
        completedEl.classList.add('active');
        playSound(doneSound);

        var pct = totalCount > 0 ? Math.round((correctCount / totalCount) * 100) : 0;
        var errors = Math.max(0, totalCount - correctCount);

        if (completedTextEl) {
            completedTextEl.textContent = "You've completed " + (activityTitle || 'this activity') + '. Great job practicing.';
        }
        if (scoreTextEl) {
            scoreTextEl.textContent = 'Score: ' + correctCount + ' / ' + totalCount + ' (' + pct + '%)';
        }

        if (PRON_ACTIVITY_ID && PRON_RETURN_TO) {
            var joiner = PRON_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
            var saveUrl = PRON_RETURN_TO +
                joiner + 'activity_percent=' + pct +
                '&activity_errors=' + errors +
                '&activity_total=' + totalCount +
                '&activity_id=' + encodeURIComponent(PRON_ACTIVITY_ID) +
                '&activity_type=pronunciation';

            var ok = await persistScoreSilently(saveUrl);
            if (!ok) {
                navigateToReturn(saveUrl);
            }
        }
    }

    function goNext() {
        if (finished) {
            return;
        }

        if (index < data.length - 1) {
            index += 1;
            loadCard();
        } else {
            showCompleted();
        }
    }

    function restart() {
        correctCount = 0;
        totalCount = data.length;
        checkedCards = {};
        index = 0;
        loadCard();
    }

    if (!data.length) {
        promptEl.textContent = 'No pronunciation data available.';
        hintEl.textContent = '';
        capturedEl.style.display = 'none';
        listenRowEl.style.display = 'none';
        controlsEl.style.display = 'none';
        statusEl.textContent = '';
        imageEl.style.display = 'none';
        return;
    }

    listenBtn.addEventListener('click', speakCurrent);
    speakBtn.addEventListener('click', recordPronunciation);
    if (checkBtn) {
        checkBtn.addEventListener('click', checkAnswer);
    }
    showBtn.addEventListener('click', showAnswer);
    nextBtn.addEventListener('click', goNext);
    restartBtn.addEventListener('click', restart);

    loadCard();
});
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔊', $content);
