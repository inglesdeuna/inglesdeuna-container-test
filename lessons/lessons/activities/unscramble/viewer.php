<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function us_resolve_unit_from_activity_view(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $stmt = $pdo->prepare("
        SELECT unit_id
        FROM activities
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function us_default_title_view(): string
{
    return 'Unscramble the Sentence';
}

function us_parse_listen($raw): bool
{
    if (is_bool($raw)) return $raw;
    if (is_numeric($raw)) return (int) $raw === 1;
    if (is_string($raw)) {
        $value = strtolower(trim($raw));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) return true;
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) return false;
    }
    return true;
}

function us_normalize_payload_view($rawData): array
{
    $default = [
        'title' => us_default_title_view(),
        'voice_id' => 'nzFihrBIvB34imQBuxub',
        'sentences' => [],
    ];

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = trim((string) ($decoded['title'] ?? ''));

    $voiceId = trim((string) ($decoded['voice_id'] ?? 'nzFihrBIvB34imQBuxub'));
    if ($voiceId === '') {
        $voiceId = 'nzFihrBIvB34imQBuxub';
    }

    $sentencesSource = [];
    if (isset($decoded['sentences']) && is_array($decoded['sentences'])) {
        $sentencesSource = $decoded['sentences'];
    }

    $sentences = [];

    foreach ($sentencesSource as $item) {
        if (!is_array($item)) {
            continue;
        }

        $sentence = '';
        if (isset($item['sentence']) && is_string($item['sentence'])) {
            $sentence = trim($item['sentence']);
        } elseif (isset($item['text']) && is_string($item['text'])) {
            $sentence = trim($item['text']);
        }

        if ($sentence === '') {
            continue;
        }

        $listenEnabled = array_key_exists('listen_enabled', $item)
            ? us_parse_listen($item['listen_enabled'])
            : (array_key_exists('listen', $item) ? us_parse_listen($item['listen']) : true);

        $sentences[] = [
            'sentence' => $sentence,
            'listen_enabled' => $listenEnabled,
        ];
    }

    return [
        'title' => $title !== '' ? $title : us_default_title_view(),
        'voice_id' => $voiceId,
        'sentences' => $sentences,
    ];
}

function us_load_activity_view(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'id' => '',
        'title' => us_default_title_view(),
        'voice_id' => 'nzFihrBIvB34imQBuxub',
        'sentences' => [],
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE id = :id
              AND type = 'unscramble'
            LIMIT 1
        ");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE unit_id = :unit
              AND type = 'unscramble'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = us_normalize_payload_view($row['data'] ?? null);

    return [
        'id' => isset($row['id']) ? (string) $row['id'] : '',
        'title' => (string) ($payload['title'] ?? us_default_title_view()),
        'voice_id' => (string) ($payload['voice_id'] ?? 'nzFihrBIvB34imQBuxub'),
        'sentences' => is_array($payload['sentences'] ?? null) ? $payload['sentences'] : [],
    ];
}

if ($unit === '' && $activityId !== '') {
    $unit = us_resolve_unit_from_activity_view($pdo, $activityId);
}

$activity = us_load_activity_view($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title'] ?? us_default_title_view());
$activityVoiceId = (string) ($activity['voice_id'] ?? 'nzFihrBIvB34imQBuxub');
$sentences = is_array($activity['sentences'] ?? null) ? $activity['sentences'] : [];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if (count($sentences) === 0) {
    die('No sentences found for this activity');
}

ob_start();
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{--us-orange:#F97316;--us-purple:#7F77DD;--us-purple-dark:#534AB7;--us-purple-soft:#EEEDFE;--us-lila:#EDE9FA;--us-muted:#9B94BE;--us-green:#16a34a;--us-red:#dc2626}
html,body{width:100%;min-height:100%}
body{margin:0!important;padding:0!important;background:#fff!important;font-family:'Nunito','Segoe UI',sans-serif!important}
.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;min-height:100vh;display:flex!important;flex-direction:column!important;background:transparent!important}
.top-row,.activity-header,.activity-title,.activity-subtitle{display:none!important}
.viewer-content{flex:1!important;display:flex!important;flex-direction:column!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important}
.us-page{width:100%;min-height:100vh;padding:clamp(14px,2.5vw,34px);display:flex;align-items:flex-start;justify-content:center;background:#fff;box-sizing:border-box}
.us-app{width:min(860px,100%);margin:0 auto}
.us-topbar{height:36px;display:flex;align-items:center;justify-content:center;margin-bottom:8px}
.us-topbar-title{font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;color:#9B94BE;letter-spacing:.1em;text-transform:uppercase}
.us-hero{text-align:center;margin-bottom:clamp(14px,2vw,22px)}
.us-kicker{display:inline-flex;align-items:center;justify-content:center;padding:7px 14px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px}
.us-hero h1{font-family:'Fredoka',sans-serif;font-size:clamp(30px,5.5vw,58px);font-weight:700;color:#F97316;margin:0;line-height:1.03}
.us-hero p{font-family:'Nunito',sans-serif;font-size:clamp(13px,1.8vw,17px);font-weight:800;color:#9B94BE;margin:8px 0 0}
.us-stage{background:#fff;border:1px solid #F0EEF8;border-radius:34px;padding:clamp(16px,2.6vw,26px);box-shadow:0 8px 40px rgba(127,119,221,.13);width:min(760px,100%);margin:0 auto;box-sizing:border-box;position:relative}
.us-intro{display:none}
#sentenceBox{margin:0 auto;padding:clamp(18px,3vw,28px);background:#fff;border:1px solid #EDE9FA;border-radius:28px;max-width:100%;min-height:clamp(240px,34vh,380px);box-shadow:0 12px 36px rgba(127,119,221,.13);box-sizing:border-box;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
#buildArea{width:100%;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;min-height:86px;gap:12px;padding:16px;border-radius:22px;border:2px dashed #EDE9FA;background:#fff;margin-top:12px;box-shadow:0 4px 14px rgba(127,119,221,.08);transition:border-color .15s,background .15s,box-shadow .15s}
#buildArea.drag-over{border-color:#7F77DD;background:#FAFAFE;box-shadow:0 8px 24px rgba(127,119,221,.12)}
.us-placeholder{color:#9B94BE;font-size:15px;font-weight:800;font-style:normal;pointer-events:none}
.us-chip{display:inline-flex;align-items:center;justify-content:center;padding:14px 20px;min-height:48px;min-width:74px;border-radius:999px;font-family:'Nunito',sans-serif;font-size:clamp(15px,1.9vw,18px);font-weight:900;letter-spacing:.01em;cursor:grab;user-select:none;transition:transform .12s,box-shadow .12s,border-color .12s,background .12s}
.us-chip:active{cursor:grabbing}
.us-chip.bank-chip{background:#7F77DD;color:#FFFFFF;border:2px solid #7F77DD;box-shadow:0 10px 22px rgba(127,119,221,.18)}
.us-chip.bank-chip:hover{transform:translateY(-2px) scale(1.02);border-color:#534AB7;background:#534AB7;box-shadow:0 16px 28px rgba(127,119,221,.18)}
.us-chip.built-chip{background:#534AB7;color:#FFFFFF;border:2px solid #534AB7;box-shadow:0 10px 22px rgba(127,119,221,.18)}
.us-chip.built-chip:hover{transform:translateY(-1px);filter:brightness(1.06);box-shadow:0 12px 24px rgba(127,119,221,.16)}
.us-chip.correct-chip{background:#fff;border:2px solid #16a34a;color:#16a34a;box-shadow:0 0 0 2px rgba(22,163,74,.22);cursor:default}
.us-chip.incorrect-chip{background:#fff;border:2px solid #dc2626;color:#dc2626;box-shadow:0 0 0 2px rgba(220,38,38,.18);cursor:default}
#wordBank{display:flex;flex-wrap:wrap;justify-content:center;gap:12px;margin:20px 0 0;min-height:64px}
.us-btn,.us-completed-button{display:inline-flex;align-items:center;justify-content:center;padding:13px 20px;border:none;border-radius:999px;color:#fff;cursor:pointer;min-width:clamp(104px,16vw,146px);font-weight:900;font-family:'Nunito','Segoe UI',sans-serif;font-size:13px;line-height:1;box-shadow:0 6px 18px rgba(127,119,221,.18);transition:transform .12s,filter .12s,box-shadow .12s}
.us-btn:hover,.us-completed-button:hover{filter:brightness(1.07);transform:translateY(-1px)}
.us-btn:active,.us-completed-button:active{transform:scale(.98)}
.us-btn-listen{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18);margin-bottom:16px}
.us-btn-show{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18)}
.us-btn-next{background:#F97316;box-shadow:0 6px 18px rgba(249,115,22,.22)}
#listenBtn.hidden{display:none}
#feedback{text-align:center;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;min-height:18px;margin-top:10px;color:#534AB7}
.good{color:#16a34a!important}.bad{color:#dc2626!important}
.controls{border-top:1px solid #F0EEF8;margin-top:16px;padding-top:16px;text-align:center;display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;background:#fff}
.us-completed-screen{display:none;background:#fff;border:1px solid #EDE9FA;border-radius:28px;box-shadow:0 12px 36px rgba(127,119,221,.13);min-height:clamp(300px,42vh,430px);flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:clamp(28px,5vw,48px) 24px;gap:12px;box-sizing:border-box}
.us-completed-screen.active{display:flex}
.us-completed-icon{font-size:64px;line-height:1;margin-bottom:4px}
.us-completed-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:clamp(30px,5.5vw,58px);font-weight:700;color:#F97316;margin:0;line-height:1.03}
.us-completed-text{font-family:'Nunito',sans-serif;font-size:clamp(13px,1.8vw,17px);font-weight:800;color:#9B94BE;line-height:1.5;margin:0}
#us-score-text{color:#534AB7!important;font-family:'Nunito',sans-serif!important;font-size:15px!important;font-weight:900!important}
.us-completed-button{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18);margin-top:4px}
@media(max-width:760px){.us-page{padding:12px}.us-topbar{height:30px;margin-bottom:4px}.us-kicker{padding:5px 11px;font-size:11px;margin-bottom:6px}.us-hero h1{font-size:clamp(26px,8vw,38px)}.us-stage{border-radius:26px;padding:14px;width:100%}#sentenceBox{border-radius:22px;padding:18px;min-height:260px}#buildArea{border-radius:18px;min-height:74px;padding:12px;gap:10px}.us-chip{padding:12px 16px;min-height:44px;min-width:68px;font-size:clamp(14px,4.2vw,16px)}#wordBank{gap:10px;min-height:58px}.controls{display:grid;grid-template-columns:1fr;gap:9px}.us-btn,.us-completed-button{width:100%}.us-completed-screen{border-radius:26px}}
</style>

<div class="us-page">
    <div class="us-app">
        <div class="us-topbar">
            <span class="us-topbar-title">Unscramble</span>
        </div>

        <div class="us-hero">
            <div class="us-kicker">Activity</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Unscramble the words to form the correct sentence.</p>
        </div>

        <div class="us-stage">
            <div id="sentenceBox">
                <button id="listenBtn" class="us-btn us-btn-listen" type="button" onclick="usSpeak()">Listen</button>
                <div id="buildArea">
                    <span class="us-placeholder" id="buildPlaceholder">Drag words here to build the sentence…</span>
                </div>
            </div>

            <div id="wordBank"></div>

            <div class="controls">
                <button class="us-btn us-btn-show" type="button" onclick="usShowAnswer()">Show Answer</button>
                <button class="us-btn us-btn-next" type="button" onclick="usNextSentence()">Next</button>
            </div>

            <div id="feedback"></div>

            <div id="us-completed" class="us-completed-screen">
                <div class="us-completed-icon">✅</div>
                <h2 class="us-completed-title">Completed!</h2>
                <p class="us-completed-text" id="us-completed-text"></p>
                <p class="us-completed-text" id="us-score-text" style="font-weight:900;font-size:15px;color:#534AB7;"></p>
                <button type="button" class="us-completed-button" id="us-restart" onclick="usRestartActivity()">Restart</button>
            </div>
        </div>
    </div>
</div>

<audio id="winSound" src="../../hangman/assets/win.mp3" preload="auto"></audio>
<audio id="loseSound" src="../../hangman/assets/lose.mp3" preload="auto"></audio>
<audio id="doneSound" src="../../hangman/assets/win (1).mp3" preload="auto"></audio>
<audio id="us-tts-audio" preload="none"></audio>
<script>
const usSentences = <?= json_encode($sentences, JSON_UNESCAPED_UNICODE) ?>;
const usActivityTitle = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
const US_ACTIVITY_ID = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;
const US_RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const US_VOICE_ID = <?= json_encode($activityVoiceId, JSON_UNESCAPED_UNICODE) ?>;
const US_TTS_URL = 'tts.php';

// Shuffle a portion of sentences if there are more than 1
const usBlocks = usSentences.length > 1
    ? usSentences.slice().sort(function () { return Math.random() - 0.5; }).slice(0, Math.max(1, Math.ceil(usSentences.length * 0.75)))
    : usSentences.slice();

let usIndex = 0;
let usDragged = null;
let usCurrentSentence = '';
let usCurrentWords = [];
let usListenEnabled = true;
let usIsSpeaking = false;
let usIsPaused = false;
let usCurrentAudio = null;
let usCurrentAudioUrl = '';
let usFinished = false;
let usBlockFinished = false;
let usCorrectCount = 0;
let usTotalCount = 0;
let usCheckedBlocks = {};
let usAttemptsByBlock = {};
let usScoredByBlock = {};
let selectedVoice = null;

const usBuildArea = document.getElementById('buildArea');
const usWordBank = document.getElementById('wordBank');
const usFeedback = document.getElementById('feedback');
const usListenBtn = document.getElementById('listenBtn');
const usWinSound = document.getElementById('winSound');
const usLoseSound = document.getElementById('loseSound');
const usDoneSound = document.getElementById('doneSound');
const usTtsAudio = document.getElementById('us-tts-audio');
const usSentenceBox = document.getElementById('sentenceBox');
const usControls = document.querySelector('.controls');
const usCompletedEl = document.getElementById('us-completed');
const usCompletedTextEl = document.getElementById('us-completed-text');
const usScoreTextEl = document.getElementById('us-score-text');
const usBuildPlaceholder = document.getElementById('buildPlaceholder');

function usPlaySound(audio) {
    try {
        audio.pause();
        audio.currentTime = 0;
        audio.play();
    } catch (e) {}
}

function usPersistScoreSilently(targetUrl) {
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

function usNavigateToReturn(targetUrl) {
    if (!targetUrl) return;
    try {
        if (window.top && window.top !== window.self) {
            window.top.location.href = targetUrl;
            return;
        }
    } catch (e) {}
    window.location.href = targetUrl;
}

function usSetListenVisible(visible) {
    if (visible) {
        usListenBtn.classList.remove('hidden');
        usSetListenLabel();
    } else {
        usListenBtn.classList.add('hidden');
        if (usCurrentAudio) {
            usCurrentAudio.pause();
            usCurrentAudio.currentTime = 0;
            usCurrentAudio = null;
        }
        if (usCurrentAudioUrl) {
            try { URL.revokeObjectURL(usCurrentAudioUrl); } catch (e) {}
            usCurrentAudioUrl = '';
        }
        usIsSpeaking = false;
        usIsPaused = false;
        usSetListenLabel();
    }
}

function usShuffle(list) {
    return list.slice().sort(function () { return Math.random() - 0.5; });
}

function usGetBuiltWords() {
    const chips = Array.prototype.slice.call(usBuildArea.querySelectorAll('.built-chip'));
    return chips.map(function (chip) { return chip.dataset.word || ''; });
}

function usUpdatePlaceholder() {
    if (usBuildPlaceholder) {
        const hasChips = usBuildArea.querySelectorAll('.built-chip').length > 0;
        usBuildPlaceholder.style.display = hasChips ? 'none' : 'inline';
    }
}

function usCreateBankChip(word) {
    const chip = document.createElement('span');
    chip.textContent = word;
    chip.className = 'us-chip bank-chip';
    chip.draggable = true;
    chip.dataset.word = word;

    chip.addEventListener('dragstart', function () {
        usDragged = chip;
        usDragged.dataset.source = 'bank';
    });

    chip.addEventListener('click', function () {
        if (usFinished || usBlockFinished) return;
        usMoveToBuildArea(chip);
        setTimeout(usAutoCheck, 40);
    });

    return chip;
}

function usCreateBuiltChip(word) {
    const chip = document.createElement('span');
    chip.textContent = word;
    chip.className = 'us-chip built-chip';
    chip.draggable = true;
    chip.dataset.word = word;

    chip.addEventListener('dragstart', function () {
        usDragged = chip;
        usDragged.dataset.source = 'build';
    });

    chip.addEventListener('click', function () {
        if (usFinished || usBlockFinished) return;
        usMoveToBank(chip);
        usFeedback.textContent = '';
        usFeedback.className = '';
    });

    return chip;
}

function usMoveToBuildArea(chip) {
    const builtChip = usCreateBuiltChip(chip.dataset.word);
    usBuildArea.appendChild(builtChip);
    chip.remove();
    usUpdatePlaceholder();
}

function usMoveToBank(chip) {
    const bankChip = usCreateBankChip(chip.dataset.word);
    usWordBank.appendChild(bankChip);
    chip.remove();
    usUpdatePlaceholder();
}

// Build area drop support
usBuildArea.addEventListener('dragover', function (e) {
    e.preventDefault();
    usBuildArea.classList.add('drag-over');
});

usBuildArea.addEventListener('dragleave', function () {
    usBuildArea.classList.remove('drag-over');
});

usBuildArea.addEventListener('drop', function (e) {
    e.preventDefault();
    usBuildArea.classList.remove('drag-over');
    if (!usDragged || usFinished || usBlockFinished) return;

    const source = usDragged.dataset.source || 'bank';
    const word = usDragged.dataset.word || '';

    if (source === 'bank') {
        const builtChip = usCreateBuiltChip(word);
        usBuildArea.appendChild(builtChip);
        usDragged.remove();
    }
    // If dragging a built-chip to build area, it moves to the end
    if (source === 'build') {
        usBuildArea.appendChild(usDragged);
    }

    usDragged = null;
    usUpdatePlaceholder();
    setTimeout(usAutoCheck, 40);
});

// Word bank drop support (return chips)
usWordBank.addEventListener('dragover', function (e) { e.preventDefault(); });

usWordBank.addEventListener('drop', function (e) {
    e.preventDefault();
    if (!usDragged || usFinished || usBlockFinished) return;

    const source = usDragged.dataset.source || 'bank';

    if (source === 'build') {
        const bankChip = usCreateBankChip(usDragged.dataset.word);
        usWordBank.appendChild(bankChip);
        usDragged.remove();
        usUpdatePlaceholder();
        usFeedback.textContent = '';
        usFeedback.className = '';
    }

    usDragged = null;
});

function usLoadSentence() {
    if (usCurrentAudio) {
        usCurrentAudio.pause();
        usCurrentAudio.currentTime = 0;
        usCurrentAudio = null;
    }
    if (usCurrentAudioUrl) {
        try { URL.revokeObjectURL(usCurrentAudioUrl); } catch (e) {}
        usCurrentAudioUrl = '';
    }
    usIsSpeaking = false;
    usIsPaused = false;
    usDragged = null;
    usFinished = false;
    usBlockFinished = false;

    if (usCompletedEl) usCompletedEl.classList.remove('active');
    if (usSentenceBox) usSentenceBox.style.display = 'block';
    if (usWordBank) usWordBank.style.display = 'flex';
    if (usControls) usControls.style.display = 'block';

    usFeedback.textContent = '';
    usFeedback.className = '';

    // Clear build area (keep placeholder)
    Array.prototype.slice.call(usBuildArea.querySelectorAll('.built-chip, .correct-chip, .incorrect-chip')).forEach(function (c) { c.remove(); });
    usUpdatePlaceholder();
    usWordBank.innerHTML = '';

    const block = usBlocks[usIndex] || {};
    usCurrentSentence = typeof block.sentence === 'string' ? block.sentence.trim() : '';
    usListenEnabled = !!block.listen_enabled;
    usSetListenVisible(usListenEnabled);

    if (!usCurrentSentence) {
        usFeedback.textContent = 'Empty sentence';
        usFeedback.className = 'bad';
        return;
    }

    // Split sentence into words (preserve punctuation attached to words)
    usCurrentWords = usCurrentSentence.split(/\s+/).filter(function (w) { return w.length > 0; });

    // Shuffle all words into bank
    usShuffle(usCurrentWords).forEach(function (word) {
        usWordBank.appendChild(usCreateBankChip(word));
    });
}

async function usShowCompleted() {
    usFinished = true;
    usBlockFinished = true;
    if (usCurrentAudio) {
        usCurrentAudio.pause();
        usCurrentAudio.currentTime = 0;
        usCurrentAudio = null;
    }
    if (usCurrentAudioUrl) {
        try { URL.revokeObjectURL(usCurrentAudioUrl); } catch (e) {}
        usCurrentAudioUrl = '';
    }
    usIsSpeaking = false;
    usIsPaused = false;
    usFeedback.textContent = '';
    usFeedback.className = '';

    if (usSentenceBox) usSentenceBox.style.display = 'none';
    if (usWordBank) usWordBank.style.display = 'none';
    if (usControls) usControls.style.display = 'none';
    if (usCompletedEl) usCompletedEl.classList.add('active');

    usPlaySound(usDoneSound);

    const pct = usTotalCount > 0 ? Math.round((usCorrectCount / usTotalCount) * 100) : 0;
    const errors = Math.max(0, usTotalCount - usCorrectCount);

    if (usCompletedTextEl) {
        usCompletedTextEl.textContent = "You've completed " + (usActivityTitle || 'this activity') + '. Great job!';
    }

    if (usScoreTextEl) {
        usScoreTextEl.textContent = 'Score: ' + usCorrectCount + ' / ' + usTotalCount + ' (' + pct + '%)';
    }

    if (US_ACTIVITY_ID && US_RETURN_TO) {
        const joiner = US_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
        const saveUrl = US_RETURN_TO
            + joiner + 'activity_percent=' + pct
            + '&activity_errors=' + errors
            + '&activity_total=' + usTotalCount
            + '&activity_id=' + encodeURIComponent(US_ACTIVITY_ID)
            + '&activity_type=unscramble';

        const ok = await usPersistScoreSilently(saveUrl);
        if (!ok) {
            usNavigateToReturn(saveUrl);
        }
    }
}

function usRegisterSentenceScore(builtWords) {
    if (Object.prototype.hasOwnProperty.call(usScoredByBlock, usIndex)) {
        return;
    }

    const built = Array.isArray(builtWords) ? builtWords : usGetBuiltWords();
    const isCorrect = JSON.stringify(built) === JSON.stringify(usCurrentWords);
    const score = isCorrect ? 1 : 0;
    usScoredByBlock[usIndex] = score;
    usCorrectCount += score;
}

function usCheckSentence() {
    if (usFinished || usBlockFinished || usCheckedBlocks[usIndex]) return;

    const built = usGetBuiltWords();

    if (built.length < usCurrentWords.length) {
        usFeedback.textContent = 'Place all words first.';
        usFeedback.className = 'bad';
        return;
    }

    const currentAttempts = (usAttemptsByBlock[usIndex] || 0) + 1;
    usAttemptsByBlock[usIndex] = currentAttempts;

    const isCorrect = JSON.stringify(built) === JSON.stringify(usCurrentWords);

    if (isCorrect) {
        usFeedback.textContent = '✔ Correct!';
        usFeedback.className = 'good';
        usPlaySound(usWinSound);
        usCheckedBlocks[usIndex] = true;
        usRegisterSentenceScore(built);
        usBlockFinished = true;
        // Mark chips green
        usBuildArea.querySelectorAll('.built-chip').forEach(function (chip) {
            chip.className = 'us-chip correct-chip';
            chip.draggable = false;
        });
    } else {
        if (currentAttempts >= 2) {
            usFeedback.textContent = '✘ Incorrect (2/2)';
            usFeedback.className = 'bad';
            usPlaySound(usLoseSound);
            usCheckedBlocks[usIndex] = true;
            usRegisterSentenceScore(built);
            usBlockFinished = true;
            // Mark chips red/green by position
            const chips = Array.prototype.slice.call(usBuildArea.querySelectorAll('.built-chip'));
            chips.forEach(function (chip, i) {
                if ((built[i] || '') === (usCurrentWords[i] || '')) {
                    chip.className = 'us-chip correct-chip';
                } else {
                    chip.className = 'us-chip incorrect-chip';
                }
                chip.draggable = false;
            });
        } else {
            usFeedback.textContent = '✘ Incorrect (1/2) — try again';
            usFeedback.className = 'bad';
            usPlaySound(usLoseSound);
        }
    }
}

function usAutoCheck() {
    if (usFinished || usBlockFinished || usCheckedBlocks[usIndex]) return;

    const built = usGetBuiltWords();
    if (built.length < usCurrentWords.length) return;

    usCheckSentence();
}

function usShowAnswer() {
    const built = usGetBuiltWords();
    usRegisterSentenceScore(built);
    usCheckedBlocks[usIndex] = true;

    // Clear build area
    Array.prototype.slice.call(usBuildArea.querySelectorAll('.built-chip, .incorrect-chip, .correct-chip')).forEach(function (c) { c.remove(); });
    usWordBank.innerHTML = '';

    // Place correct words in order
    usCurrentWords.forEach(function (word) {
        const chip = document.createElement('span');
        chip.textContent = word;
        chip.className = 'us-chip correct-chip';
        chip.draggable = false;
        chip.dataset.word = word;
        usBuildArea.appendChild(chip);
    });

    usUpdatePlaceholder();
    usFeedback.textContent = 'Correct order shown';
    usFeedback.className = 'good';
    usBlockFinished = true;
}

function usNextSentence() {
    if (usFinished) return;

    usAutoCheck();

    if (!usBlockFinished && !usCheckedBlocks[usIndex]) {
        return;
    }

    usRegisterSentenceScore();

    if (usIndex >= usBlocks.length - 1) {
        usShowCompleted();
        return;
    }

    usIndex += 1;
    usLoadSentence();
}

function usRestartActivity() {
    usIndex = 0;
    usCorrectCount = 0;
    usTotalCount = usBlocks.length;
    usCheckedBlocks = {};
    usAttemptsByBlock = {};
    usScoredByBlock = {};
    usLoadSentence();
}

function usSpeak() {
    if (!usListenEnabled) return;
    if (!usCurrentSentence || String(usCurrentSentence).trim() === '') return;

    if (usCurrentAudio) {
        if (!usCurrentAudio.paused) {
            usCurrentAudio.pause();
            usIsSpeaking = true;
            usIsPaused = true;
            usSetListenLabel();
        } else {
            usCurrentAudio.play().then(function () {
                usIsSpeaking = true;
                usIsPaused = false;
                usSetListenLabel();
            }).catch(function () {});
        }
        return;
    }

    usIsSpeaking = true;
    usIsPaused = false;
    usListenBtn.textContent = '...';
    usListenBtn.disabled = true;

    const fd = new FormData();
    fd.append('text', usCurrentSentence);
    fd.append('voice_id', US_VOICE_ID || 'nzFihrBIvB34imQBuxub');

    fetch(US_TTS_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (res) {
            if (!res.ok) throw new Error('TTS error ' + res.status);
            return res.blob();
        })
        .then(function (blob) {
            usCurrentAudioUrl = URL.createObjectURL(blob);
            usCurrentAudio = usTtsAudio || new Audio();
            usCurrentAudio.src = usCurrentAudioUrl;

            usCurrentAudio.onended = function () {
                usIsSpeaking = false;
                usIsPaused = false;
                usSetListenLabel();
                if (usCurrentAudioUrl) {
                    try { URL.revokeObjectURL(usCurrentAudioUrl); } catch (e) {}
                    usCurrentAudioUrl = '';
                }
                usCurrentAudio = null;
            };

            usCurrentAudio.onpause = function () {
                if (usCurrentAudio && usCurrentAudio.currentTime < (usCurrentAudio.duration || Infinity)) {
                    usIsSpeaking = true;
                    usIsPaused = true;
                    usSetListenLabel();
                }
            };

            usCurrentAudio.play().then(function () {
                usIsSpeaking = true;
                usIsPaused = false;
                usSetListenLabel();
            }).catch(function () {
                usIsSpeaking = false;
                usIsPaused = false;
                usSetListenLabel();
            });
        })
        .catch(function () {
            usIsSpeaking = false;
            usIsPaused = false;
            usSetListenLabel();
        })
        .finally(function () {
            usListenBtn.disabled = false;
        });
}

function usSetListenLabel() {
    if (!usListenBtn) return;
    if (usIsPaused) {
        usListenBtn.textContent = 'Resume';
    } else if (usIsSpeaking) {
        usListenBtn.textContent = 'Pause';
    } else {
        usListenBtn.textContent = 'Listen';
    }
}

// Init
usTotalCount = usBlocks.length;
usLoadSentence();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔀', $content);
