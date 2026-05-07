<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])   ? trim((string) $_GET['id'])   : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function default_listen_order_title(): string { return 'Listen & Order'; }

function normalize_listen_order_payload(mixed $rawData): array
{
    $default = ['title' => default_listen_order_title(), 'instructions' => '', 'blocks' => []];
    if ($rawData === null || $rawData === '') return $default;

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) return $default;

    $title        = trim((string) ($decoded['title']        ?? ''));
    $instructions = trim((string) ($decoded['instructions'] ?? ''));
    $blocksSource = isset($decoded['blocks']) && is_array($decoded['blocks']) ? $decoded['blocks'] : $decoded;

    $blocks = [];
    foreach ($blocksSource as $block) {
        if (!is_array($block)) continue;

        $sentence  = trim((string) ($block['sentence']  ?? ''));
        $video_url = trim((string) ($block['video_url'] ?? ''));

        $images = [];
        if (isset($block['images']) && is_array($block['images'])) {
            foreach ($block['images'] as $img) {
                $url = trim((string) $img);
                if ($url !== '') $images[] = $url;
            }
        }

        $dropZoneImages = [];
        if (isset($block['dropZoneImages']) && is_array($block['dropZoneImages'])) {
            foreach ($block['dropZoneImages'] as $dzi) {
                if (!is_array($dzi)) continue;
                $dzSrc = trim((string) ($dzi['src'] ?? ''));
                if ($dzSrc === '') continue;
                $dropZoneImages[] = [
                    'id'    => trim((string) ($dzi['id'] ?? uniqid('dzi_'))),
                    'src'   => $dzSrc,
                    'left'  => (int) ($dzi['left']  ?? 0),
                    'top'   => (int) ($dzi['top']   ?? 0),
                    'width' => max(60, min(800, (int) ($dzi['width'] ?? 180))),
                ];
            }
        }

        if ($sentence === '' && trim((string) ($block['video_url'] ?? '')) === '' && empty($images)) {
            continue;
        }

        $blocks[] = [
            'sentence'       => $sentence,
            'video_url'      => $video_url,
            'images'         => $images,
            'dropZoneImages' => $dropZoneImages,
        ];
    }

    return ['title' => $title !== '' ? $title : default_listen_order_title(), 'instructions' => $instructions, 'blocks' => $blocks];
}

function load_listen_order_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = ['title' => default_listen_order_title(), 'instructions' => '', 'blocks' => []];
    $row = null;

    // FIX: query accepts any type variant so a mismatched type in DB still loads
    if ($activityId !== '') {
        $stmt = $pdo->prepare("
            SELECT data FROM activities
            WHERE id = :id
              AND type IN ('listen_order','listen_and_order','listenorder')
            LIMIT 1
        ");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("
            SELECT data FROM activities
            WHERE unit_id = :unit
              AND type IN ('listen_order','listen_and_order','listenorder')
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return $fallback;
    return normalize_listen_order_payload($row['data'] ?? null);
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity           = load_listen_order_activity($pdo, $activityId, $unit);
$viewerTitle        = (string) ($activity['title']        ?? default_listen_order_title());
$viewerInstructions = (string) ($activity['instructions'] ?? '');
$blocks             = is_array($activity['blocks'] ?? null) ? $activity['blocks'] : [];
$returnTo           = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if (count($blocks) === 0) {
    die('No activities for this unit');
}

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
*{box-sizing:border-box}
html,body{width:100%;min-height:100%}
body{margin:0!important;padding:0!important;background:#ffffff!important;font-family:'Nunito','Segoe UI',sans-serif!important}

.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;min-height:100vh;display:flex!important;flex-direction:column!important;background:transparent!important}
.top-row{display:none!important}
.viewer-content{flex:1!important;display:flex!important;flex-direction:column!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important}

.lo-shell{
    width:100%;
    min-height:calc(100vh - 90px);
    padding:clamp(14px,2.5vw,34px);
    display:flex;
    align-items:flex-start;
    justify-content:center;
    font-family:'Nunito','Segoe UI',system-ui,sans-serif;
    background:#ffffff;
    overflow:visible;
}

.lo-app{
    width:min(860px,100%);
    margin:0 auto;
    display:flex;
    flex-direction:column;
    gap:0;
}

.lo-hero{text-align:center;margin-bottom:clamp(14px,2vw,22px);padding:0}
.lo-kicker{display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;padding:7px 14px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}
.lo-title{margin:0;font-family:'Fredoka',sans-serif;font-size:clamp(30px,5.5vw,58px);line-height:1.03;color:#F97316;font-weight:700}
.lo-subtitle{margin:8px 0 0;color:#9B94BE;font-size:clamp(13px,1.8vw,17px);font-weight:800}

.lo-board{width:min(760px,100%);margin:0 auto;background:#ffffff;border:1px solid #F0EEF8;border-radius:28px;padding:clamp(16px,2.6vw,26px);box-shadow:0 8px 40px rgba(127,119,221,.13);overflow:visible}

.lo-mode-row{display:flex;justify-content:center;gap:10px;margin-bottom:18px}
.lo-tab{padding:10px 22px;border-radius:999px;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;cursor:pointer;border:1.5px solid #EDE9FA;background:#ffffff;color:#534AB7;transition:all .15s}
.lo-tab.active{background:#7F77DD;color:#ffffff;border-color:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.22)}

.lo-audio-player{background:#FAFAFE;border:1px solid #EDE9FA;border-radius:18px;padding:20px 22px;display:flex;align-items:center;gap:14px;margin-bottom:18px}
.lo-audio-icon{width:48px;height:48px;border-radius:50%;background:#7F77DD;box-shadow:0 6px 20px rgba(127,119,221,.28);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#ffffff;font-size:20px}
.lo-audio-info{flex:1;min-width:0}
.lo-audio-name{font-family:'Fredoka',sans-serif;font-weight:700;color:#271B5D;font-size:16px;margin-bottom:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lo-audio-track{height:7px;background:#E4E1F8;border-radius:999px;overflow:hidden}
.lo-audio-fill{height:100%;width:0%;background:linear-gradient(90deg,#F97316,#7F77DD);border-radius:999px;transition:width .3s linear}
.lo-audio-time{font-family:'Nunito',sans-serif;font-weight:900;color:#9B94BE;font-size:12px;margin-top:4px}
.lo-audio-play{width:44px;height:44px;border-radius:50%;background:#F97316;box-shadow:0 6px 18px rgba(249,115,22,.28);display:flex;align-items:center;justify-content:center;border:0;cursor:pointer;flex-shrink:0;color:#ffffff;font-size:18px;transition:transform .12s,filter .12s}
.lo-audio-play:hover{transform:scale(1.07);filter:brightness(1.07)}

.lo-video-player{border-radius:18px;overflow:hidden;aspect-ratio:16/7;background:#000;margin-bottom:18px;position:relative}
.lo-video-player video{width:100%;height:100%;object-fit:contain}
.lo-video-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center}
.lo-video-play-btn{width:56px;height:56px;border-radius:50%;background:rgba(249,115,22,.9);display:flex;align-items:center;justify-content:center;color:#ffffff;font-size:22px}

.lo-grid{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-bottom:12px}
.lo-card{width:110px;height:110px;border-radius:16px;border:2px solid #EDE9FA;background:#ffffff;box-shadow:0 4px 14px rgba(127,119,221,.10);cursor:pointer;position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;transition:border-color .15s,background .15s,box-shadow .15s,transform .15s;overflow:hidden;padding:6px 6px 18px}
.lo-card:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(127,119,221,.16)}
.lo-card img{max-width:72%;max-height:66%;object-fit:contain;display:block;border-radius:8px}
.lo-card-badge{position:absolute;top:5px;left:5px;width:22px;height:22px;border-radius:50%;background:#EEEDFE;color:#534AB7;font-size:11px;font-family:'Nunito',sans-serif;font-weight:900;display:flex;align-items:center;justify-content:center;line-height:1;transition:background .15s,color .15s;z-index:2}
.lo-card-label{position:absolute;bottom:4px;left:0;right:0;text-align:center;font-family:'Nunito',sans-serif;font-size:11px;font-weight:900;color:#9B94BE;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 4px}
.lo-card.selected{border-color:#F97316;background:#FFF8F4;box-shadow:0 0 0 3px rgba(249,115,22,.18),0 4px 14px rgba(127,119,221,.10);transform:translateY(-4px) scale(1.04)}
.lo-card.selected .lo-card-badge{background:#F97316;color:#ffffff}
.lo-card.correct{border-color:#1D9E75;background:#F0FDF9}
.lo-card.correct .lo-card-badge{background:#1D9E75;color:#ffffff}
.lo-card.wrong{border-color:#E24B4A;background:#FFF5F5}
.lo-card.wrong .lo-card-badge{background:#E24B4A;color:#ffffff}

.lo-hint{text-align:center;margin-bottom:14px;font-size:13px;min-height:26px}
.lo-hint-neutral{display:inline-block;background:#FFF0E6;color:#C2580A;border-radius:999px;padding:3px 10px;font-weight:900;font-family:'Nunito',sans-serif}
.lo-hint-selected{display:inline-block;background:#EEEDFE;color:#534AB7;border-radius:999px;padding:3px 10px;font-weight:900;font-family:'Nunito',sans-serif}

.lo-scores{display:flex;gap:10px;justify-content:center;margin-bottom:16px}
.lo-score-card{flex:1;max-width:110px;background:#FAFAFE;border:1px solid #EDE9FA;border-radius:14px;padding:12px;text-align:center}
.lo-score-num{font-family:'Fredoka',sans-serif;font-weight:700;font-size:28px;line-height:1.1}
.lo-score-num.green{color:#1D9E75}
.lo-score-num.orange{color:#F97316}
.lo-score-num.purple{color:#7F77DD}
.lo-score-label{font-family:'Nunito',sans-serif;font-weight:900;font-size:10px;text-transform:uppercase;color:#9B94BE;margin-top:2px}

#lo-feedback{font-size:15px;font-weight:900;min-height:20px;text-align:center;margin-bottom:8px;font-family:'Nunito',sans-serif}
.good{color:#1D9E75}
.bad{color:#E24B4A}

.lo-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;padding-top:16px;border-top:1px solid #F0EEF8;overflow:visible}
.lo-btn{padding:11px 24px;border-radius:999px;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;cursor:pointer;border:0;min-width:clamp(90px,12vw,120px);display:inline-flex;align-items:center;justify-content:center;transition:transform .12s,filter .12s;overflow:visible}
.lo-btn:hover{transform:translateY(-2px);filter:brightness(1.07)}
.lo-btn:active{transform:scale(.97)}
.lo-btn:disabled{opacity:.55;cursor:not-allowed;transform:none;filter:none}
.lo-btn-reset{background:#ffffff;color:#534AB7;border:1.5px solid #EDE9FA}
.lo-btn-show{background:#7F77DD;color:#ffffff;box-shadow:0 6px 18px rgba(127,119,221,.22)}
.lo-btn-check{background:#F97316;color:#ffffff;box-shadow:0 6px 18px rgba(249,115,22,.22)}
.lo-btn-next{background:#7F77DD;color:#ffffff;box-shadow:0 6px 18px rgba(127,119,221,.22)}

#lo-status{text-align:center;margin-top:10px;font-size:13px;color:#9B94BE;font-weight:900;font-family:'Nunito',sans-serif}

.lo-completed{display:none;background:#ffffff;border:1px solid #EDE9FA;border-radius:28px;box-shadow:0 12px 36px rgba(127,119,221,.13);min-height:clamp(300px,42vh,430px);flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:clamp(28px,5vw,48px) 24px;gap:12px;width:min(860px,100%);margin:0 auto;box-sizing:border-box}
.lo-completed.active{display:flex}
.lo-done-icon{font-size:64px;line-height:1}
.lo-done-title{margin:0;font-family:'Fredoka',sans-serif;font-size:clamp(30px,5.5vw,58px);color:#F97316;font-weight:700}
.lo-done-text{margin:0;max-width:520px;color:#9B94BE;font-size:clamp(13px,1.8vw,17px);font-weight:800;line-height:1.5}
.lo-done-score{margin:0;color:#534AB7;font-size:15px;font-weight:900}
.lo-done-track{height:12px;width:min(420px,100%);margin:4px auto;border-radius:999px;background:#F4F2FD;border:1px solid #E4E1F8;overflow:hidden}
.lo-done-fill{height:100%;width:0%;background:linear-gradient(90deg,#F97316,#7F77DD);transition:width .8s ease}

body.embedded-mode .lo-shell,body.fullscreen-embedded .lo-shell,body.presentation-mode .lo-shell{position:absolute!important;inset:0!important;width:100%!important;max-width:none!important;margin:0!important;padding:10px 12px!important;border-radius:0!important;display:flex!important;flex-direction:column!important;align-items:center!important;justify-content:flex-start!important;overflow-y:auto!important;overflow-x:hidden!important}
body.embedded-mode .lo-app,body.fullscreen-embedded .lo-app,body.presentation-mode .lo-app{width:min(860px,100%)!important;margin:0 auto!important}
body.embedded-mode .lo-board,body.fullscreen-embedded .lo-board,body.presentation-mode .lo-board{overflow:visible!important}
body.embedded-mode .lo-actions,body.fullscreen-embedded .lo-actions,body.presentation-mode .lo-actions{flex-shrink:0!important;padding-bottom:12px!important}

@media(max-width:640px){
    .lo-shell{padding:12px;min-height:100vh}
    .lo-board{border-radius:22px;padding:14px}
    .lo-hero{margin-bottom:14px}
    .lo-card{width:90px;height:90px}
    .lo-actions{flex-direction:column;align-items:center}
    .lo-btn{width:100%;max-width:280px}
    .lo-scores{gap:6px}
    .lo-score-card{padding:8px 6px}
    .lo-score-num{font-size:22px}
    .lo-audio-player{flex-wrap:wrap;gap:10px}
}
</style>

<div class="lo-shell">
    <div class="lo-app">

        <div class="lo-hero">
            <div class="lo-kicker">Activity <span id="lo-kicker-count">1 / <?php echo count($blocks); ?></span></div>
            <h1 class="lo-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="lo-subtitle"><?php echo $viewerInstructions !== '' ? htmlspecialchars($viewerInstructions, ENT_QUOTES, 'UTF-8') : 'Listen and tap images to put them in the correct order.'; ?></p>
        </div>

        <div class="lo-board" id="lo-board">

            <div class="lo-mode-row">
                <button type="button" class="lo-tab active" id="lo-tab-audio">Audio</button>
                <button type="button" class="lo-tab" id="lo-tab-video">Video</button>
            </div>

            <div id="lo-audio-player" class="lo-audio-player">
                <div class="lo-audio-icon">🎵</div>
                <div class="lo-audio-info">
                    <div class="lo-audio-name" id="lo-audio-name"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="lo-audio-track"><div class="lo-audio-fill" id="lo-audio-fill"></div></div>
                    <div class="lo-audio-time" id="lo-audio-time">0:00</div>
                </div>
                <button type="button" class="lo-audio-play" id="lo-listen-btn" title="Listen">▶</button>
            </div>

            <div id="lo-video-player" class="lo-video-player" style="display:none">
                <video controls preload="metadata" style="width:100%;height:100%;object-fit:contain"></video>
            </div>

            <div id="lo-grid" class="lo-grid"></div>

            <div id="lo-hint" class="lo-hint">
                <span class="lo-hint-neutral">Tap an image to select it, then tap another to swap</span>
            </div>

            <div id="lo-scores" class="lo-scores" style="display:none">
                <div class="lo-score-card">
                    <div class="lo-score-num green" id="lo-score-correct">0</div>
                    <div class="lo-score-label">Correct</div>
                </div>
                <div class="lo-score-card">
                    <div class="lo-score-num orange" id="lo-score-wrong">0</div>
                    <div class="lo-score-label">Wrong</div>
                </div>
                <div class="lo-score-card">
                    <div class="lo-score-num purple" id="lo-score-pct">0%</div>
                    <div class="lo-score-label">Score</div>
                </div>
            </div>

            <div id="lo-feedback"></div>

            <div class="lo-actions" id="lo-actions">
                <button type="button" class="lo-btn lo-btn-reset" id="lo-btn-reset">Reset</button>
                <button type="button" class="lo-btn lo-btn-show" id="lo-btn-show">Show Answer</button>
                <button type="button" class="lo-btn lo-btn-check" id="lo-btn-check">Check</button>
                <button type="button" class="lo-btn lo-btn-next" id="lo-btn-next">Next</button>
            </div>

            <div id="lo-status"></div>
        </div>

        <div id="lo-completed" class="lo-completed">
            <div class="lo-done-icon">✅</div>
            <h2 class="lo-done-title" id="lo-completed-title"></h2>
            <p class="lo-done-text" id="lo-completed-text"></p>
            <p class="lo-done-score" id="lo-score-text"></p>
            <div class="lo-done-track"><div class="lo-done-fill" id="lo-done-fill"></div></div>
            <div class="lo-actions">
                <button type="button" class="lo-btn lo-btn-check" onclick="restartActivity()">Restart</button>
                <button type="button" class="lo-btn lo-btn-next" onclick="history.back()">Back</button>
            </div>
        </div>

    </div>
</div>

<audio id="winSound"  src="../../hangman/assets/win.mp3"      preload="auto"></audio>
<audio id="loseSound" src="../../hangman/assets/lose.mp3"     preload="auto"></audio>
<audio id="doneSound" src="../../hangman/assets/win (1).mp3"  preload="auto"></audio>

<script>
var sourceBlocks  = <?= json_encode($blocks,      JSON_UNESCAPED_UNICODE) ?>;
var activityTitle = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
var LO_ACTIVITY_ID = <?= json_encode($activityId ?? '', JSON_UNESCAPED_UNICODE) ?>;
var LO_RETURN_TO   = <?= json_encode($returnTo,       JSON_UNESCAPED_UNICODE) ?>;

var loParams         = new URLSearchParams(window.location.search || '');
var loRequestedPick  = parseInt(loParams.get('lo_pick')  || '', 10);
var loRequestedRatio = Number(loParams.get('lo_ratio') || '0.75');
var loRatio          = Number.isFinite(loRequestedRatio) ? Math.max(0.1, Math.min(1, loRequestedRatio)) : 0.75;
var loComputedPick   = Number.isFinite(loRequestedPick) && loRequestedPick > 0
    ? Math.min(loRequestedPick, sourceBlocks.length)
    : Math.max(1, Math.ceil(sourceBlocks.length * loRatio));
var blocks = sourceBlocks.length > 1
    ? shuffle(sourceBlocks).slice(0, loComputedPick)
    : sourceBlocks.slice();

/* Voice cache */
var _loVoices = [];
function _loLoadVoices() { if ('speechSynthesis' in window) _loVoices = window.speechSynthesis.getVoices() || []; }
if ('speechSynthesis' in window) { _loLoadVoices(); window.speechSynthesis.addEventListener('voiceschanged', _loLoadVoices); }

function getPreferredVoice() {
    if (!_loVoices.length) _loLoadVoices();
    var voices = _loVoices;
    if (!voices.length) return null;
    var enVoices = voices.filter(function(v){ var l = String(v.lang||'').toLowerCase(); return l==='en-us'||l.startsWith('en-')||l.startsWith('en_'); });
    if (!enVoices.length) enVoices = voices;
    var preferred = ['samantha','daniel','karen','moira','fiona','alex','aria','jenny','guy','davis','tony','emma','olivia','ava','allison','victoria','kate','zira','hazel','mark'];
    for (var p=0;p<preferred.length;p++) { for (var v=0;v<enVoices.length;v++) { if ((enVoices[v].name+' '+enVoices[v].voiceURI).toLowerCase().indexOf(preferred[p])!==-1) return enVoices[v]; } }
    for (var v2=0;v2<enVoices.length;v2++) { if (!enVoices[v2].localService) return enVoices[v2]; }
    return enVoices[0];
}

/* State */
var index=0,correct=[],userOrder=[],selectedIdx=null,currentSentence='';
var isSpeaking=false,isPaused=false,utter=null,speechOffset=0,speechSourceText='',speechSegmentStart=0;
var finished=false,blockFinished=false,correctCount=0,totalCount=blocks.length;
var attemptsByBlock={},checkedBlocks={};

/* DOM */
var gridEl=document.getElementById('lo-grid'),feedbackEl=document.getElementById('lo-feedback'),statusEl=document.getElementById('lo-status');
var kickerCountEl=document.getElementById('lo-kicker-count'),hintEl=document.getElementById('lo-hint'),scoresEl=document.getElementById('lo-scores');
var scCorrectEl=document.getElementById('lo-score-correct'),scWrongEl=document.getElementById('lo-score-wrong'),scPctEl=document.getElementById('lo-score-pct');
var completedEl=document.getElementById('lo-completed'),completedTitleEl=document.getElementById('lo-completed-title'),completedTextEl=document.getElementById('lo-completed-text');
var scoreTextEl=document.getElementById('lo-score-text'),doneFillEl=document.getElementById('lo-done-fill'),boardEl=document.getElementById('lo-board'),actionsEl=document.getElementById('lo-actions');
var winSound=document.getElementById('winSound'),loseSound=document.getElementById('loseSound'),doneSound=document.getElementById('doneSound');
var listenBtn=document.getElementById('lo-listen-btn'),audioFillEl=document.getElementById('lo-audio-fill'),audioNameEl=document.getElementById('lo-audio-name');
var loVideoEl=document.querySelector('#lo-video-player video');

if (completedTitleEl) completedTitleEl.textContent = activityTitle || 'Listen & Order';
if (completedTextEl)  completedTextEl.textContent  = "You've completed " + (activityTitle||'this activity') + '. Great job practicing.';

/* Audio/Video toggle */
document.getElementById('lo-tab-audio').addEventListener('click', function(){
    document.getElementById('lo-tab-audio').classList.add('active');
    document.getElementById('lo-tab-video').classList.remove('active');
    document.getElementById('lo-audio-player').style.display='';
    document.getElementById('lo-video-player').style.display='none';
    if (loVideoEl) loVideoEl.pause();
});
document.getElementById('lo-tab-video').addEventListener('click', function(){
    document.getElementById('lo-tab-video').classList.add('active');
    document.getElementById('lo-tab-audio').classList.remove('active');
    document.getElementById('lo-video-player').style.display='';
    document.getElementById('lo-audio-player').style.display='none';
});

function setListenBtnState(state){
    if (!listenBtn) return;
    if (state==='speaking'){ listenBtn.textContent='⏸'; if (audioFillEl) audioFillEl.style.width='50%'; }
    else { listenBtn.textContent='▶'; if (state!=='paused' && audioFillEl) audioFillEl.style.width='0%'; }
}

function playSound(audio){ try{ audio.pause(); audio.currentTime=0; audio.play(); }catch(e){} }

function persistScoreSilently(url){
    if (!url) return Promise.resolve(false);
    return fetch(url,{method:'GET',credentials:'same-origin',cache:'no-store'}).then(function(r){return !!(r&&r.ok);}).catch(function(){return false;});
}

function navigateToReturn(url){ if (!url) return; try{ if (window.top&&window.top!==window.self){window.top.location.href=url;return;} }catch(e){} window.location.href=url; }

function shuffle(list){ return list.slice().sort(function(){return Math.random()-.5;}); }

/* TTS */
function playAudio(){
    if (finished||!currentSentence||String(currentSentence).trim()==='') return;
    if (speechSynthesis.paused||isPaused){ speechSynthesis.resume(); isSpeaking=true; isPaused=false; setListenBtnState('speaking'); setTimeout(function(){if(!speechSynthesis.speaking&&speechOffset<speechSourceText.length)startSpeechFromOffset();},80); return; }
    if (speechSynthesis.speaking&&!speechSynthesis.paused){ speechSynthesis.pause(); isSpeaking=true; isPaused=true; setListenBtnState('paused'); return; }
    speechSynthesis.cancel(); speechSourceText=currentSentence||''; speechOffset=0; startSpeechFromOffset();
}

function startSpeechFromOffset(){
    var source=speechSourceText||currentSentence||'';
    if (!source) return;
    var safeOffset=Math.max(0,Math.min(speechOffset,source.length));
    var remaining=source.slice(safeOffset);
    if (!remaining.trim()){isSpeaking=false;isPaused=false;speechOffset=0;return;}
    speechSynthesis.cancel(); speechSegmentStart=safeOffset;
    utter=new SpeechSynthesisUtterance(remaining); utter.lang='en-US'; utter.rate=0.9; utter.pitch=1; utter.volume=1;
    var bestVoice=getPreferredVoice(); if (bestVoice) utter.voice=bestVoice;
    utter.onstart   = function(){ isSpeaking=true;  isPaused=false; setListenBtnState('speaking'); };
    utter.onpause   = function(){ isPaused=true;  isSpeaking=true;  setListenBtnState('paused'); };
    utter.onresume  = function(){ isPaused=false; isSpeaking=true;  setListenBtnState('speaking'); };
    utter.onboundary= function(e){ if (typeof e.charIndex==='number') speechOffset=Math.max(speechSegmentStart,Math.min(source.length,speechSegmentStart+e.charIndex)); };
    utter.onend  = function(){ if(isPaused)return; isSpeaking=false; isPaused=false; speechOffset=0; setListenBtnState('idle'); };
    utter.onerror= function(){ isSpeaking=false; isPaused=false; speechOffset=0; setListenBtnState('idle'); };
    speechSynthesis.speak(utter);
}

function updateHint(){
    if (!hintEl) return;
    hintEl.innerHTML = selectedIdx===null
        ? '<span class="lo-hint-neutral">Tap an image to select it, then tap another to swap</span>'
        : '<span class="lo-hint-selected">Position '+(selectedIdx+1)+' selected — tap another to swap</span>';
}

function renderGrid(states){
    if (!gridEl) return;
    gridEl.innerHTML='';
    userOrder.forEach(function(src,i){
        var card=document.createElement('div'); card.className='lo-card';
        if (states){ if(states[i]==='correct') card.classList.add('correct'); else if(states[i]==='wrong') card.classList.add('wrong'); }
        else if (selectedIdx===i) card.classList.add('selected');
        var badge=document.createElement('div'); badge.className='lo-card-badge'; badge.textContent=String(i+1); card.appendChild(badge);
        var img=document.createElement('img'); img.src=src; img.alt=''; img.draggable=false; card.appendChild(img);
        if (!blockFinished&&!states){ (function(idx){ card.addEventListener('click',function(){onCardTap(idx);}); })(i); }
        gridEl.appendChild(card);
    });
}

function onCardTap(i){
    if (blockFinished||finished) return;
    if (selectedIdx===null){ selectedIdx=i; }
    else if (selectedIdx===i){ selectedIdx=null; }
    else { var tmp=userOrder[selectedIdx]; userOrder[selectedIdx]=userOrder[i]; userOrder[i]=tmp; selectedIdx=null; if(scoresEl) scoresEl.style.display='none'; feedbackEl.textContent=''; feedbackEl.className=''; }
    renderGrid(null); updateHint();
}

function checkAnswer(){
    if (finished||checkedBlocks[index]) return;
    var states=[],correctItems=0;
    for (var i=0;i<correct.length;i++){ if(userOrder[i]===correct[i]){states.push('correct');correctItems++;}else{states.push('wrong');} }
    var wrongItems=correct.length-correctItems, pct=correct.length>0?Math.round((correctItems/correct.length)*100):0, allCorrect=correctItems===correct.length;
    if(scCorrectEl) scCorrectEl.textContent=String(correctItems);
    if(scWrongEl)   scWrongEl.textContent=String(wrongItems);
    if(scPctEl)     scPctEl.textContent=pct+'%';
    if(scoresEl)    scoresEl.style.display='flex';
    var attempts=(attemptsByBlock[index]||0)+1; attemptsByBlock[index]=attempts;
    if (allCorrect){ feedbackEl.textContent='✔ Correct!'; feedbackEl.className='good'; playSound(winSound); checkedBlocks[index]=true; correctCount++; blockFinished=true; renderGrid(states); }
    else if (attempts>=2){ feedbackEl.textContent='✘ Wrong — see the correct order below'; feedbackEl.className='bad'; playSound(loseSound); checkedBlocks[index]=true; blockFinished=true; renderGrid(states); }
    else { feedbackEl.textContent='✘ Not quite — try again (attempt 1/2)'; feedbackEl.className='bad'; playSound(loseSound); renderGrid(states); }
}

function showAnswer(){ userOrder=correct.slice(); selectedIdx=null; feedbackEl.textContent='👁 Correct order shown'; feedbackEl.className='good'; blockFinished=true; if(scoresEl) scoresEl.style.display='none'; renderGrid(null); updateHint(); }
function resetBlock(){ userOrder=shuffle(correct); selectedIdx=null; blockFinished=false; feedbackEl.textContent=''; feedbackEl.className=''; if(scoresEl) scoresEl.style.display='none'; renderGrid(null); updateHint(); }

function updateStatus(){ var t=(index+1)+' / '+totalCount; if(statusEl) statusEl.textContent=t; if(kickerCountEl) kickerCountEl.textContent=t; }

function loadBlock(){
    if (window.speechSynthesis) speechSynthesis.cancel();
    isSpeaking=false; isPaused=false; speechOffset=0; speechSourceText=''; speechSegmentStart=0;
    finished=false; blockFinished=false; selectedIdx=null;
    if(completedEl) completedEl.classList.remove('active');
    if(boardEl)     boardEl.style.display='';
    if(actionsEl)   actionsEl.style.display='';
    if(scoresEl)    scoresEl.style.display='none';
    feedbackEl.textContent=''; feedbackEl.className='';
    setListenBtnState('idle');

    var block=blocks[index]||{};
    currentSentence=typeof block.sentence==='string'?block.sentence:'';
    speechSourceText=currentSentence;
    correct=Array.isArray(block.images)?block.images.slice():[];
    userOrder=shuffle(correct);
    if(audioNameEl) audioNameEl.textContent=currentSentence||activityTitle||'Listen & Order';

    /* Video URL — auto-switch tab if present */
    var videoUrl=typeof block.video_url==='string'?block.video_url.trim():'';
    if (videoUrl && loVideoEl){
        loVideoEl.src=videoUrl; loVideoEl.load();
        document.getElementById('lo-tab-video').click();
    } else {
        if(loVideoEl){loVideoEl.src='';loVideoEl.load();}
        document.getElementById('lo-tab-audio').click();
    }

    updateStatus(); renderGrid(null); updateHint();
}

async function showCompleted(){
    finished=true; blockFinished=true; feedbackEl.textContent=''; feedbackEl.className='';
    if(boardEl) boardEl.style.display='none';
    if(completedEl) completedEl.classList.add('active');
    setTimeout(function(){if(doneFillEl) doneFillEl.style.width='100%';},120);
    playSound(doneSound);
    var pct=totalCount>0?Math.round((correctCount/totalCount)*100):0;
    var errors=Math.max(0,totalCount-correctCount);
    if(scoreTextEl) scoreTextEl.textContent='Score: '+correctCount+' / '+totalCount+' ('+pct+'%)';
    if (LO_ACTIVITY_ID&&LO_RETURN_TO){
        var joiner=LO_RETURN_TO.indexOf('?')!==-1?'&':'?';
        var saveUrl=LO_RETURN_TO+joiner+'activity_percent='+pct+'&activity_errors='+errors+'&activity_total='+totalCount+'&activity_id='+encodeURIComponent(LO_ACTIVITY_ID)+'&activity_type=listen_order';
        var ok=await persistScoreSilently(saveUrl);
        if (!ok) navigateToReturn(saveUrl);
    }
}

function nextBlock(){
    if (blockFinished||checkedBlocks[index]){ if(index>=blocks.length-1){showCompleted();return;} index+=1; loadBlock(); }
    else { feedbackEl.textContent='Check your answer first.'; feedbackEl.className='bad'; }
}

function restartActivity(){
    index=0; correctCount=0; totalCount=blocks.length; attemptsByBlock={}; checkedBlocks={};
    if(doneFillEl) doneFillEl.style.width='0%';
    if(completedEl) completedEl.classList.remove('active');
    loadBlock();
}

if(listenBtn) listenBtn.addEventListener('click',playAudio);
document.getElementById('lo-btn-reset').addEventListener('click',resetBlock);
document.getElementById('lo-btn-show').addEventListener('click',showAnswer);
document.getElementById('lo-btn-check').addEventListener('click',checkAnswer);
document.getElementById('lo-btn-next').addEventListener('click',nextBlock);

loadBlock();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🎧', $content);
