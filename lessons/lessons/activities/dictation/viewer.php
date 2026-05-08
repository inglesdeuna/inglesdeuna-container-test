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

function default_dictation_title(): string
{
    return 'Dictation';
}

function normalize_activity_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_dictation_title();
}

function normalize_dictation_payload($rawData): array
{
    $default = array(
        'title' => default_dictation_title(),
        'voice_id' => 'nzFihrBIvB34imQBuxub',
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

    $voiceId = 'nzFihrBIvB34imQBuxub';
    if (isset($decoded['voice_id']) && trim((string) $decoded['voice_id']) !== '') {
        $voiceId = trim((string) $decoded['voice_id']);
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
            if ($en === '' && isset($item['sentence'])) {
                $en = trim((string) $item['sentence']);
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
        'voice_id' => $voiceId,
        'items' => $normalizedItems,
    );
}

function load_dictation_activity(PDO $pdo, string $activityId, string $unit): array
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
        'title' => default_dictation_title(),
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
               AND type = 'dictation'
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
               AND type = 'dictation'
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
               AND type = 'dictation'
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

    $payload = normalize_dictation_payload($rawData);

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

$activity = load_dictation_activity($pdo, $activityId, $unit);
$items = isset($activity['items']) && is_array($activity['items']) ? $activity['items'] : array();
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : default_dictation_title();

ob_start();
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
    --dict-orange: #F97316;
    --dict-orange-dark: #C2580A;
    --dict-orange-soft: #FFF0E6;
    --dict-purple: #7F77DD;
    --dict-purple-dark: #534AB7;
    --dict-purple-soft: #EEEDFE;
    --dict-white: #FFFFFF;
    --dict-lila-border: #EDE9FA;
    --dict-muted: #9B94BE;
    --dict-ink: #271B5D;
    --dict-green: #16a34a;
    --dict-red: #dc2626;
}

html,
body {
    width: 100%;
    min-height: 100%;
}

body {
    margin: 0 !important;
    padding: 0 !important;
    background: #ffffff !important;
    font-family: 'Nunito', 'Segoe UI', sans-serif !important;
}

.activity-wrapper {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    min-height: 100vh;
    display: flex !important;
    flex-direction: column !important;
    background: transparent !important;
}

.top-row {
    display: none !important;
}

.viewer-content {
    flex: 1 !important;
    display: flex !important;
    flex-direction: column !important;
    padding: 0 !important;
    margin: 0 !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    border-radius: 0 !important;
}

.dict-page {
    width: 100%;
    min-height: 100vh;
    padding: clamp(14px, 2.5vw, 34px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: #ffffff;
    box-sizing: border-box;
}

.dict-app {
    width: min(860px, 100%);
    margin: 0 auto;
}

.dict-topbar {
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 8px;
    position: relative;
}

.dict-topbar-title {
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 900;
    color: #9B94BE;
    letter-spacing: .1em;
    text-transform: uppercase;
}

.dict-hero {
    text-align: center;
    margin-bottom: clamp(14px, 2vw, 22px);
}

.dict-kicker {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 14px;
    border-radius: 999px;
    background: #FFF0E6;
    border: 1px solid #FCDDBF;
    color: #C2580A;
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 10px;
}

.dict-hero h1 {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(30px, 5.5vw, 58px);
    font-weight: 700;
    color: #F97316;
    margin: 0;
    line-height: 1.03;
}

.dict-hero p {
    font-family: 'Nunito', sans-serif;
    font-size: clamp(13px, 1.8vw, 17px);
    font-weight: 800;
    color: #9B94BE;
    margin: 8px 0 0;
}

.dict-board {
    background: #ffffff;
    border: 1px solid #F0EEF8;
    border-radius: 34px;
    padding: clamp(16px, 2.6vw, 26px);
    box-shadow: 0 8px 40px rgba(127,119,221,.13);
    width: min(760px, 100%);
    margin: 0 auto;
    box-sizing: border-box;
    position: relative;
}

.dict-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
}

.dict-progress-track {
    flex: 1;
    height: 12px;
    background: #F4F2FD;
    border: 1px solid #E4E1F8;
    border-radius: 999px;
    overflow: hidden;
}

.dict-progress-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #F97316, #7F77DD);
    border-radius: 999px;
    transition: width .45s ease;
}

.dict-status {
    background: #7F77DD;
    color: #ffffff;
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 900;
    border-radius: 999px;
    padding: 7px 11px;
    white-space: nowrap;
}

.dict-card {
    background: #ffffff;
    border: 1px solid #EDE9FA;
    border-radius: 28px;
    box-shadow: 0 12px 36px rgba(127,119,221,.13);
    padding: clamp(18px, 3vw, 28px);
    min-height: clamp(260px, 35vh, 390px);
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.dict-listen-row {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}

.dict-voice-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.dict-voice-label {
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 900;
    color: #9B94BE;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.dict-voice-select {
    border: 1px solid #EDE9FA;
    border-radius: 999px;
    background: #fff;
    color: #534AB7;
    padding: 7px 12px;
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 800;
}

.dict-image {
    display: none;
    width: min(100%, 220px);
    max-width: 100%;
    max-height: 170px;
    object-fit: contain;
    border-radius: 22px;
    margin: 0 auto 18px auto;
    background: #ffffff;
    border: 1px solid #EDE9FA;
    box-shadow: 0 8px 24px rgba(127,119,221,.10);
}

.dict-prompt,
.dict-hint {
    display: none;
}

.dict-answer-box {
    width: 100%;
    max-width: 620px;
    min-height: 130px;
    padding: 16px;
    border: 1.5px solid #EDE9FA;
    background: #ffffff;
    border-radius: 22px;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-size: clamp(16px, 2vw, 19px);
    line-height: 1.45;
    font-weight: 800;
    color: #534AB7;
    resize: vertical;
    transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
    box-sizing: border-box;
    margin: 0 auto;
    display: block;
    outline: none;
    box-shadow: 0 4px 14px rgba(127,119,221,.08);
}

.dict-answer-box::placeholder {
    color: #9B94BE;
    font-weight: 800;
}

.dict-answer-box:focus {
    border-color: #7F77DD;
    box-shadow: 0 0 0 3px rgba(127,119,221,.18);
}

.dict-answer-box.ok {
    border-color: #16a34a;
    background: #ffffff;
    color: #16a34a;
}

.dict-answer-box.bad {
    border-color: #dc2626;
    background: #ffffff;
    color: #dc2626;
}

.dict-answer-reveal {
    display: none;
    margin-top: 12px;
    border-radius: 18px;
    border: 1px solid #EDE9FA;
    background: #EEEDFE;
    color: #534AB7;
    padding: 12px 14px;
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
    text-align: center;
    width: 100%;
    box-sizing: border-box;
}

.dict-answer-reveal.show {
    display: block;
}

.dict-diff {
    display: grid;
    gap: 8px;
    text-align: left;
}

.dict-diff-row {
    background: #ffffff;
    border: 1px solid #EDE9FA;
    border-radius: 14px;
    padding: 10px 12px;
}

.dict-diff-label {
    display: block;
    font-family: 'Nunito', sans-serif;
    font-size: 11px;
    font-weight: 900;
    color: #9B94BE;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 6px;
}

.dict-word {
    display: inline-block;
    border-radius: 8px;
    padding: 2px 5px;
    margin: 1px;
    font-weight: 900;
}

.dict-word.ok {
    color: #16a34a;
    background: rgba(22,163,74,.10);
}

.dict-word.bad {
    color: #dc2626;
    background: rgba(220,38,38,.12);
}

.dict-word.missing {
    color: #534AB7;
    background: #EEEDFE;
}

.dict-controls {
    border-top: 1px solid #F0EEF8;
    margin-top: 16px;
    padding-top: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
    background: #ffffff;
    position: relative;
    z-index: 5;
}

.dict-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 13px 20px;
    min-width: clamp(112px, 16vw, 154px);
    border: none;
    border-radius: 999px;
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
    color: #ffffff;
    cursor: pointer;
    white-space: nowrap;
    pointer-events: auto;
    transition: transform .12s, filter .12s, box-shadow .12s;
}

.dict-btn:hover {
    filter: brightness(1.07);
    transform: translateY(-1px);
}

.dict-btn:active {
    transform: scale(.98);
}

.dict-btn:disabled {
    opacity: .45;
    cursor: default;
    transform: none;
    filter: none;
    box-shadow: none;
}

.dict-btn-listen {
    background: #7F77DD;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
}

.dict-btn-check {
    background: #F97316;
    box-shadow: 0 6px 18px rgba(249,115,22,.22);
}

.dict-btn-show {
    background: #7F77DD;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
}

.dict-btn-next {
    background: #F97316;
    box-shadow: 0 6px 18px rgba(249,115,22,.22);
}

.dict-feedback {
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
    text-align: center;
    min-height: 18px;
    width: 100%;
    margin-top: 10px;
}

.dict-feedback.good {
    color: #16a34a;
}

.dict-feedback.bad {
    color: #dc2626;
}

.dict-completed {
    display: none;
    background: #ffffff;
    border: 1px solid #EDE9FA;
    border-radius: 28px;
    box-shadow: 0 12px 36px rgba(127,119,221,.13);
    min-height: clamp(300px, 42vh, 430px);
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: clamp(28px, 5vw, 48px) 24px;
    gap: 12px;
    box-sizing: border-box;
}

.dict-completed.active {
    display: flex;
}

.dict-completed-icon {
    font-size: 64px;
    line-height: 1;
    margin-bottom: 4px;
}

.dict-completed-title {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(30px, 5.5vw, 58px);
    font-weight: 700;
    color: #F97316;
    margin: 0;
    line-height: 1.03;
}

.dict-completed-text {
    font-family: 'Nunito', sans-serif;
    font-size: clamp(13px, 1.8vw, 17px);
    font-weight: 800;
    color: #9B94BE;
    margin: 0;
}

.dict-score-ring {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    background: #EEEDFE;
    border: 3px solid #EDE9FA;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    margin-top: 4px;
}

.dict-score-pct {
    font-family: 'Fredoka', sans-serif;
    font-size: 28px;
    font-weight: 700;
    color: #534AB7;
    line-height: 1;
}

.dict-score-lbl {
    font-size: 10px;
    font-weight: 900;
    color: #7F77DD;
    letter-spacing: .04em;
}

.dict-score-text {
    font-family: 'Nunito', sans-serif;
    font-size: 15px;
    font-weight: 900;
    color: #534AB7;
    margin: 0;
}

.dict-restart {
    background: #7F77DD;
    color: #ffffff;
    border: none;
    border-radius: 999px;
    padding: 13px 28px;
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
    transition: filter .15s, transform .15s;
    margin-top: 4px;
}

.dict-restart:hover {
    filter: brightness(1.07);
    transform: scale(1.04);
}

@media (max-width: 640px) {
    .dict-page {
        padding: 12px;
    }

    .dict-topbar {
        height: 30px;
        margin-bottom: 4px;
    }

    .dict-kicker {
        padding: 5px 11px;
        font-size: 11px;
        margin-bottom: 6px;
    }

    .dict-hero h1 {
        font-size: clamp(26px, 8vw, 38px);
    }

    .dict-board {
        border-radius: 26px;
        padding: 14px;
        width: 100%;
    }

    .dict-card {
        border-radius: 22px;
        padding: 16px;
        min-height: 250px;
    }

    .dict-image {
        max-height: 140px;
    }

    .dict-answer-box {
        min-height: 120px;
        border-radius: 18px;
    }

    .dict-controls {
        display: grid;
        grid-template-columns: 1fr;
        gap: 9px;
    }

    .dict-btn {
        width: 100%;
    }

    .dict-completed {
        border-radius: 26px;
    }
}
</style>

<div class="dict-page">
    <div class="dict-app">

        <div class="dict-topbar">
            <span class="dict-topbar-title">Dictation</span>
        </div>

        <div class="dict-hero">
            <div class="dict-kicker">Activity</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Listen carefully and write what you hear.</p>
        </div>

        <div class="dict-board" id="dict-viewer">

            <div class="dict-progress" id="dict-progress">
                <div class="dict-progress-track">
                    <div class="dict-progress-fill" id="dict-progress-fill"></div>
                </div>
                <div class="dict-status" id="dict-status"></div>
            </div>

            <div class="dict-card" id="dict-card">
                <div class="dict-listen-row" id="dict-listen-row">
                    <button type="button" class="dict-btn dict-btn-listen" id="dict-listen">Listen</button>
                    <div class="dict-voice-row">
                        <label class="dict-voice-label" for="dict-voice-select">Voice</label>
                        <select id="dict-voice-select" class="dict-voice-select">
                            <option value="nzFihrBIvB34imQBuxub">Adult Male (Josh)</option>
                            <option value="NoOVOzCQFLOvtsMoNcdT">Adult Female (Lily)</option>
                            <option value="Nggzl2QAXh3OijoXD116">Child (Candy)</option>
                        </select>
                    </div>
                </div>

                <div class="dict-prompt" id="dict-prompt"></div>
                <div class="dict-hint" id="dict-hint"></div>
                <img id="dict-image" class="dict-image" alt="">
                <textarea id="dict-answer" class="dict-answer-box" placeholder="Write what you hear..."></textarea>
                <div id="dict-reveal" class="dict-answer-reveal"></div>
            </div>

            <div class="dict-controls" id="dict-controls">
                <button type="button" class="dict-btn dict-btn-check" id="dict-check">Check</button>
                <button type="button" class="dict-btn dict-btn-show" id="dict-show">Show Answer</button>
                <button type="button" class="dict-btn dict-btn-next" id="dict-next">Next</button>
            </div>

            <div class="dict-feedback" id="dict-feedback"></div>

            <div id="dict-completed" class="dict-completed">
                <div class="dict-completed-icon">✅</div>
                <h2 class="dict-completed-title" id="dict-completed-title"></h2>
                <p class="dict-completed-text" id="dict-completed-text"></p>
                <div class="dict-score-ring">
                    <span class="dict-score-pct" id="dict-score-pct">—</span>
                    <span class="dict-score-lbl">SCORE</span>
                </div>
                <p class="dict-score-text" id="dict-score-text"></p>
                <button type="button" class="dict-restart" id="dict-restart">Restart</button>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var sourceData = Array.isArray(<?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?>) ? <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?> : [];
    var data = sourceData.slice();

    if (data.length > 1) {
        data = data.sort(function () { return Math.random() - 0.5; }).slice(0, Math.max(1, Math.ceil(data.length * 0.75)));
    }

    var activityTitle = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
    var DICT_ACTIVITY_ID = <?php echo json_encode($activity['id'] ?? '', JSON_UNESCAPED_UNICODE); ?>;
    var DICT_RETURN_TO = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;

    var statusEl = document.getElementById('dict-status');
    var progressEl = document.getElementById('dict-progress');
    var progressFillEl = document.getElementById('dict-progress-fill');
    var promptEl = document.getElementById('dict-prompt');
    var hintEl = document.getElementById('dict-hint');
    var imageEl = document.getElementById('dict-image');
    var answerEl = document.getElementById('dict-answer');
    var revealEl = document.getElementById('dict-reveal');
    var feedbackEl = document.getElementById('dict-feedback');
    var cardEl = document.getElementById('dict-card');
    var listenRowEl = document.getElementById('dict-listen-row');
    var controlsEl = document.getElementById('dict-controls');
    var completedEl = document.getElementById('dict-completed');
    var completedTitleEl = document.getElementById('dict-completed-title');
    var completedTextEl = document.getElementById('dict-completed-text');
    var scoreTextEl = document.getElementById('dict-score-text');
    var scorePctEl = document.getElementById('dict-score-pct');

    var listenBtn = document.getElementById('dict-listen');
    var checkBtn = document.getElementById('dict-check');
    var showBtn = document.getElementById('dict-show');
    var nextBtn = document.getElementById('dict-next');
    var restartBtn = document.getElementById('dict-restart');

    var correctSound = new Audio('../../hangman/assets/win.mp3');
    var wrongSound = new Audio('../../hangman/assets/lose.mp3');
    var doneSound = new Audio('../../hangman/assets/win (1).mp3');

    var index = 0;
    var finished = false;
    var correctCount = 0;
    var totalCount = data.length;
    var checkedCards = {};
    var attemptsByCard = {};

    var isSpeaking = false;
    var isPaused = false;
    var speechOffset = 0;
    var speechSourceText = '';
    var speechSegmentStart = 0;
    var dictUtter = null;
    var dictCurrentAudio = null;
    var dictVoiceSelect = document.getElementById('dict-voice-select');
    var DICT_TTS_URL = 'tts.php';

    if (completedTitleEl) {
        completedTitleEl.textContent = activityTitle || 'Dictation';
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
            .replace(/[.,!?;:]/g, '')
            .replace(/\s+/g, ' ');
    }

    function escapeHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildDiffHtml(studentText, correctText) {
        var studentWords = normalizeText(studentText).split(' ').filter(Boolean);
        var correctWords = normalizeText(correctText).split(' ').filter(Boolean);
        var max = Math.max(studentWords.length, correctWords.length);
        var studentHtml = [];
        var correctHtml = [];

        for (var i = 0; i < max; i++) {
            var s = studentWords[i] || '';
            var c = correctWords[i] || '';

            if (s && c && s === c) {
                studentHtml.push('<span class="dict-word ok">' + escapeHtml(s) + '</span>');
                correctHtml.push('<span class="dict-word ok">' + escapeHtml(c) + '</span>');
            } else {
                if (s) {
                    studentHtml.push('<span class="dict-word bad">' + escapeHtml(s) + '</span>');
                } else {
                    studentHtml.push('<span class="dict-word bad">___</span>');
                }

                if (c) {
                    correctHtml.push('<span class="dict-word missing">' + escapeHtml(c) + '</span>');
                }
            }
        }

        return '<div class="dict-diff">' +
            '<div class="dict-diff-row"><span class="dict-diff-label">You wrote</span>' + studentHtml.join(' ') + '</div>' +
            '<div class="dict-diff-row"><span class="dict-diff-label">Correct answer</span>' + correctHtml.join(' ') + '</div>' +
        '</div>';
    }

    function playSound(sound) {
        try {
            sound.pause();
            sound.currentTime = 0;
            sound.play();
        } catch (e) {}
    }

    function setListenButtonLabel() {
        if (!listenBtn) return;
        if (isPaused) {
            listenBtn.textContent = 'Resume';
        } else if (isSpeaking) {
            listenBtn.textContent = 'Pause';
        } else {
            listenBtn.textContent = 'Listen';
        }
    }

    function speakCurrent() {
        if (!data[index]) {
            return;
        }

        // If a pre-generated audio is stored, play it (toggle pause/resume)
        if (data[index].audio) {
            var audioSrc = data[index].audio;
            if (!dictCurrentAudio || dictCurrentAudio.getAttribute('data-src') !== audioSrc) {
                if (dictCurrentAudio) { dictCurrentAudio.pause(); }
                dictCurrentAudio = new Audio(audioSrc);
                dictCurrentAudio.setAttribute('data-src', audioSrc);
                dictCurrentAudio.onended = function () {
                    dictCurrentAudio = null;
                    isSpeaking = false;
                    isPaused = false;
                    setListenButtonLabel();
                };
            }

            if (!dictCurrentAudio.paused) {
                dictCurrentAudio.pause();
                isSpeaking = true;
                isPaused = true;
            } else {
                dictCurrentAudio.play().then(function () {
                    isSpeaking = true;
                    isPaused = false;
                    setListenButtonLabel();
                }).catch(function () {});
            }

            setListenButtonLabel();
            return;
        }

        // Toggle pause/resume on ElevenLabs audio if already loaded
        if (dictCurrentAudio && dictCurrentAudio.getAttribute('data-tts') === '1') {
            if (!dictCurrentAudio.paused) {
                dictCurrentAudio.pause();
                isSpeaking = true;
                isPaused = true;
                setListenButtonLabel();
            } else {
                dictCurrentAudio.play().then(function () {
                    isSpeaking = true;
                    isPaused = false;
                    setListenButtonLabel();
                }).catch(function () {});
            }
            return;
        }

        // Call ElevenLabs TTS endpoint
        var text = data[index].en || '';
        if (!text) { return; }
        var voiceId = dictVoiceSelect ? dictVoiceSelect.value : 'nzFihrBIvB34imQBuxub';

        isSpeaking = true;
        isPaused = false;
        if (listenBtn) listenBtn.textContent = '...';
        if (listenBtn) listenBtn.disabled = true;

        var fd = new FormData();
        fd.append('text', text);
        fd.append('voice_id', voiceId);

        fetch(DICT_TTS_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) { throw new Error('TTS error ' + res.status); }
                return res.blob();
            })
            .then(function (blob) {
                var url = URL.createObjectURL(blob);
                if (dictCurrentAudio) { dictCurrentAudio.pause(); }
                dictCurrentAudio = new Audio(url);
                dictCurrentAudio.setAttribute('data-tts', '1');
                dictCurrentAudio.onended = function () {
                    URL.revokeObjectURL(url);
                    dictCurrentAudio = null;
                    isSpeaking = false;
                    isPaused = false;
                    setListenButtonLabel();
                };
                if (listenBtn) listenBtn.disabled = false;
                dictCurrentAudio.play().then(function () {
                    isSpeaking = true;
                    isPaused = false;
                    setListenButtonLabel();
                }).catch(function () {
                    isSpeaking = false;
                    isPaused = false;
                    setListenButtonLabel();
                });
            })
            .catch(function () {
                isSpeaking = false;
                isPaused = false;
                if (listenBtn) listenBtn.disabled = false;
                setListenButtonLabel();
            });

        if (!window.speechSynthesis) { return; }

        var text = data[index].en || '';
        if (!text) { return; }

        if (speechSynthesis.paused || isPaused) {
            speechSynthesis.resume();
            isSpeaking = true;
            isPaused = false;
            setListenButtonLabel();

            setTimeout(function () {
                if (!speechSynthesis.speaking && speechOffset < speechSourceText.length) {
                    dictStartSpeechFromOffset();
                }
            }, 80);

            return;
        }

        if (speechSynthesis.speaking && !speechSynthesis.paused) {
            speechSynthesis.pause();
            isSpeaking = true;
            isPaused = true;
            setListenButtonLabel();
            return;
        }

        speechSynthesis.cancel();
        speechSourceText = text;
        speechOffset = 0;
        dictStartSpeechFromOffset();
    }

    function dictStartSpeechFromOffset() {
        var source = speechSourceText;
        if (!source) { return; }

        var safeOffset = Math.max(0, Math.min(speechOffset, source.length));
        var remaining = source.slice(safeOffset);

        if (!remaining.trim()) {
            isSpeaking = false;
            isPaused = false;
            speechOffset = 0;
            setListenButtonLabel();
            return;
        }

        speechSynthesis.cancel();

        speechSegmentStart = safeOffset;
        dictUtter = new SpeechSynthesisUtterance(remaining);
        dictUtter.lang = 'en-US';
        dictUtter.rate = 0.9;

        var preferredVoice = getPreferredVoice('en-US');
        if (preferredVoice) dictUtter.voice = preferredVoice;

        dictUtter.onstart = function () {
            isSpeaking = true;
            isPaused = false;
            setListenButtonLabel();
        };

        dictUtter.onpause = function () {
            isPaused = true;
            isSpeaking = true;
            setListenButtonLabel();
        };

        dictUtter.onresume = function () {
            isPaused = false;
            isSpeaking = true;
            setListenButtonLabel();
        };

        dictUtter.onboundary = function (event) {
            if (typeof event.charIndex === 'number') {
                speechOffset = Math.max(speechSegmentStart, Math.min(source.length, speechSegmentStart + event.charIndex));
            }
        };

        dictUtter.onend = function () {
            if (isPaused) { return; }
            isSpeaking = false;
            isPaused = false;
            speechOffset = 0;
            setListenButtonLabel();
        };

        dictUtter.onerror = function () {
            isSpeaking = false;
            isPaused = false;
            speechOffset = 0;
            setListenButtonLabel();
        };

        speechSynthesis.speak(dictUtter);
    }

    function getPreferredVoice(lang) {
        lang = lang || 'en-US';
        var voices = window.speechSynthesis ? window.speechSynthesis.getVoices() : [];

        if (!Array.isArray(voices) || voices.length === 0) {
            return null;
        }

        var langPrefix = lang.split('-')[0].toLowerCase();
        var matchedVoices = voices.filter(function (voice) {
            var vl = String(voice.lang || '').toLowerCase();
            return vl === lang.toLowerCase() || vl.indexOf(langPrefix + '-') === 0 || vl.indexOf(langPrefix + '_') === 0;
        });

        if (!matchedVoices.length) {
            return voices[0] || null;
        }

        var femaleHints = ['female', 'woman', 'zira', 'samantha', 'karen', 'aria', 'jenny', 'emma', 'olivia', 'ava',
            'paulina', 'sabina', 'esperanza', 'mónica', 'monica', 'conchita'];

        var femaleVoice = matchedVoices.find(function (voice) {
            var label = (String(voice.name || '') + ' ' + String(voice.voiceURI || '')).toLowerCase();
            return femaleHints.some(function (hint) { return label.indexOf(hint) !== -1; });
        });

        return femaleVoice || matchedVoices[0];
    }

    function updateProgress() {
        var pct = data.length > 0 ? Math.round(((index + 1) / data.length) * 100) : 0;
        statusEl.textContent = (index + 1) + ' / ' + data.length;
        progressFillEl.style.width = pct + '%';
    }

    function loadCard() {
        var item = data[index] || {};

        if (window.speechSynthesis) { speechSynthesis.cancel(); }

        isSpeaking = false;
        isPaused = false;
        speechOffset = 0;
        speechSourceText = '';
        speechSegmentStart = 0;
        dictUtter = null;

        if (dictCurrentAudio) {
            dictCurrentAudio.pause();
            if (dictCurrentAudio.getAttribute('data-tts') === '1') {
                try { URL.revokeObjectURL(dictCurrentAudio.src); } catch (e) {}
            }
            dictCurrentAudio = null;
        }

        setListenButtonLabel();

        finished = false;
        completedEl.classList.remove('active');
        if (progressEl) progressEl.style.display = 'flex';
        cardEl.style.display = 'flex';
        listenRowEl.style.display = 'flex';
        controlsEl.style.display = 'flex';

        updateProgress();

        promptEl.textContent = '';
        hintEl.textContent = '';
        answerEl.value = '';
        answerEl.className = 'dict-answer-box';
        answerEl.disabled = false;
        feedbackEl.textContent = '';
        feedbackEl.className = 'dict-feedback';
        revealEl.classList.remove('show');
        revealEl.textContent = item.en || '';

        if (item.img) {
            imageEl.style.display = 'block';
            imageEl.src = item.img;
            imageEl.alt = item.en || '';
        } else {
            imageEl.style.display = 'none';
            imageEl.removeAttribute('src');
            imageEl.alt = '';
        }

        checkBtn.disabled = false;
        showBtn.disabled = false;
        nextBtn.disabled = false;
        nextBtn.textContent = index < data.length - 1 ? 'Next' : 'Finish';

        setTimeout(function () {
            answerEl.focus();
        }, 80);
    }

    function checkAnswer() {
        if (!data[index]) {
            return;
        }

        if (checkedCards[index]) {
            feedbackEl.textContent = 'Already graded. Press Next.';
            feedbackEl.className = 'dict-feedback good';
            return;
        }

        var answer = normalizeText(answerEl.value);
        var expected = normalizeText(data[index].en || '');

        if (answer === '') {
            feedbackEl.textContent = 'Write an answer first.';
            feedbackEl.className = 'dict-feedback bad';
            return;
        }

        var currentAttempts = (attemptsByCard[index] || 0) + 1;
        attemptsByCard[index] = currentAttempts;

        if (answer === expected) {
            feedbackEl.textContent = 'Right!';
            feedbackEl.className = 'dict-feedback good';
            answerEl.className = 'dict-answer-box ok';
            revealEl.classList.remove('show');
            revealEl.textContent = '';
            playSound(correctSound);
            checkedCards[index] = true;
            correctCount++;
            answerEl.disabled = true;
            checkBtn.disabled = true;
        } else {
            answerEl.className = 'dict-answer-box bad';
            revealEl.innerHTML = buildDiffHtml(answerEl.value, data[index].en || '');
            revealEl.classList.add('show');

            if (currentAttempts >= 2) {
                feedbackEl.textContent = 'Wrong (2/2)';
                feedbackEl.className = 'dict-feedback bad';
                playSound(wrongSound);
                checkedCards[index] = true;
                answerEl.disabled = true;
                checkBtn.disabled = true;
            } else {
                feedbackEl.textContent = 'Wrong (1/2) — check the red words and try again';
                feedbackEl.className = 'dict-feedback bad';
                playSound(wrongSound);
            }
        }
    }

    function autoCheckIfNeeded() {
        if (finished || !data[index]) {
            return;
        }

        if (checkedCards[index]) {
            return;
        }

        if (String(answerEl.value || '').trim() === '') {
            return;
        }

        checkAnswer();
    }

    function showAnswer() {
        if (!data[index]) {
            return;
        }

        if (answerEl.value.trim() !== '') {
            revealEl.innerHTML = buildDiffHtml(answerEl.value, data[index].en || '');
        } else {
            revealEl.innerHTML =
                '<div class="dict-diff">' +
                    '<div class="dict-diff-row"><span class="dict-diff-label">Correct answer</span>' +
                    '<span class="dict-word missing">' + escapeHtml(data[index].en || '') + '</span></div>' +
                '</div>';
        }

        revealEl.classList.add('show');
    }

    async function showCompleted() {
        finished = true;
        cardEl.style.display = 'none';
        listenRowEl.style.display = 'none';
        controlsEl.style.display = 'none';
        if (progressEl) progressEl.style.display = 'none';
        statusEl.textContent = 'Done';
        progressFillEl.style.width = '100%';
        feedbackEl.textContent = '';
        completedEl.classList.add('active');
        playSound(doneSound);

        var pct = totalCount > 0 ? Math.round((correctCount / totalCount) * 100) : 0;
        var errors = Math.max(0, totalCount - correctCount);

        if (completedTextEl) {
            completedTextEl.textContent = "You've completed " + (activityTitle || 'this activity') + '. Great job practicing.';
        }

        if (scoreTextEl) {
            scoreTextEl.textContent = correctCount + ' / ' + totalCount + ' correct';
        }

        if (scorePctEl) {
            scorePctEl.textContent = pct + '%';
        }

        if (DICT_ACTIVITY_ID && DICT_RETURN_TO) {
            var joiner = DICT_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
            var saveUrl = DICT_RETURN_TO +
                joiner + 'activity_percent=' + pct +
                '&activity_errors=' + errors +
                '&activity_total=' + totalCount +
                '&activity_id=' + encodeURIComponent(DICT_ACTIVITY_ID) +
                '&activity_type=dictation';

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

        autoCheckIfNeeded();

        if (!checkedCards[index]) {
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
        attemptsByCard = {};
        index = 0;
        loadCard();
    }

    if (!data.length) {
        cardEl.style.display = 'flex';
        listenRowEl.style.display = 'none';
        controlsEl.style.display = 'none';
        statusEl.textContent = '';
        progressFillEl.style.width = '0%';
        answerEl.style.display = 'none';
        imageEl.style.display = 'none';
        feedbackEl.textContent = 'No dictation data available.';
        feedbackEl.className = 'dict-feedback bad';
        return;
    }

    listenBtn.addEventListener('click', speakCurrent);
    checkBtn.addEventListener('click', checkAnswer);
    showBtn.addEventListener('click', showAnswer);
    nextBtn.addEventListener('click', goNext);
    restartBtn.addEventListener('click', restart);

    answerEl.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            autoCheckIfNeeded();
        }
    });

    loadCard();
});
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✍️', $content);
