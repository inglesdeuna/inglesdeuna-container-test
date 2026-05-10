<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function qa_columns(PDO $pdo): array
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

function qa_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $columns = qa_columns($pdo);

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

function qa_default_title(): string
{
    return 'Questions & Answers';
}

function qa_normalize_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : qa_default_title();
}

function qa_normalize_payload($rawData): array
{
    $default = array('title' => qa_default_title(), 'voice_id' => 'nzFihrBIvB34imQBuxub', 'cards' => array());
    if ($rawData === null || $rawData === '') return $default;

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) return $default;

    $title = '';
    $cardsSource = $decoded;

    if (isset($decoded['title'])) $title = trim((string) $decoded['title']);
    if (isset($decoded['cards']) && is_array($decoded['cards'])) $cardsSource = $decoded['cards'];

    $cards = array();
    foreach ($cardsSource as $item) {
        if (!is_array($item)) continue;
        $cards[] = array(
            'id'       => isset($item['id'])       ? trim((string) $item['id'])       : uniqid('qa_'),
            'question' => isset($item['question']) ? trim((string) $item['question']) : '',
            'answer'   => isset($item['answer'])   ? trim((string) $item['answer'])   : '',
        );
    }

    return array(
        'title' => qa_normalize_title($title),
        'voice_id' => trim((string) ($decoded['voice_id'] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub',
        'cards' => $cards
    );
}

function qa_load_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = qa_columns($pdo);
    $selectFields = array('id');

    if (in_array('data', $columns, true)) $selectFields[] = 'data';
    if (in_array('content_json', $columns, true)) $selectFields[] = 'content_json';
    if (in_array('title', $columns, true)) $selectFields[] = 'title';
    if (in_array('name', $columns, true)) $selectFields[] = 'name';

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id = :id AND type = 'question_answer' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id = :unit AND type = 'question_answer' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit = :unit AND type = 'question_answer' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return array('title' => qa_default_title(), 'cards' => array());

    $rawData = null;
    if (isset($row['data'])) $rawData = $row['data'];
    elseif (isset($row['content_json'])) $rawData = $row['content_json'];

    $payload = qa_normalize_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') $columnTitle = trim((string) $row['title']);
    elseif (isset($row['name']) && trim((string) $row['name']) !== '') $columnTitle = trim((string) $row['name']);

    if ($columnTitle !== '') $payload['title'] = $columnTitle;

    return array(
        'title' => qa_normalize_title((string) $payload['title']),
        'voice_id' => isset($payload['voice_id']) ? (string) $payload['voice_id'] : 'nzFihrBIvB34imQBuxub',
        'cards' => isset($payload['cards']) && is_array($payload['cards']) ? $payload['cards'] : array(),
    );
}

if ($unit === '' && $activityId !== '') $unit = qa_resolve_unit($pdo, $activityId);

$activity = qa_load_activity($pdo, $unit, $activityId);
$cards = isset($activity['cards']) && is_array($activity['cards']) ? $activity['cards'] : array();
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : qa_default_title();

if (count($cards) === 0) die('No questions found');

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
    --li-purple:#7F77DD;
    --li-purple-dark:#534AB7;
    --li-purple-soft:#EEEDFE;
    --li-purple-mid:#AFA9EC;
    --li-blue:#2563EB;
    --li-blue-dark:#1D4ED8;
    --li-pink:#EC4899;
    --li-pink-dark:#BE185D;
    --li-teal:#1D9E75;
    --li-teal-dark:#085041;
    --li-teal-soft:#DDF8EF;
    --li-ink:#271B5D;
    --li-muted:#7C739B;
    --li-white:#FFFFFF;
    --li-shadow:0 24px 60px rgba(83,74,183,.20);
    --fc-orange: #F97316;
    --fc-orange-dark: #C2580A;
    --fc-orange-soft: #FFF0E6;
    --fc-purple: #7F77DD;
    --fc-purple-dark: #534AB7;
    --fc-purple-soft: #EEEDFE;
    --fc-lila: rgba(127,119,221,.13);
    --fc-lila-md: rgba(127,119,221,.18);
}

*{box-sizing:border-box}

.qa-premium-shell{
    max-width:980px;
    margin:16px auto 28px;
    padding:18px;
    border-radius:28px;
    background: #ffffff;
}

.qa-premium-app{
    width:min(960px,100%);
    display:grid;
    grid-template-columns:minmax(0,1fr);
    gap:0;
}

.qa-premium-board{
    position:relative;
    border-radius:34px;
    background: #ffffff;
    border: 1px solid #F0EEF8;
    box-shadow: 0 8px 40px rgba(127,119,221,.13);
    padding:22px 22px 20px;
}

.qa-premium-title-panel{
    margin:0 auto clamp(14px,2vw,20px);
    text-align:center;
}

.qa-premium-kicker{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    margin-bottom:6px;
    padding:5px 12px;
    border-radius:999px;
    background:linear-gradient(135deg,var(--li-teal),var(--li-teal-dark));
    color:#fff;
    font-size:11px;
    font-weight:900;
    letter-spacing:.06em;
    text-transform:uppercase;
}

.qa-premium-title{
    margin:0;
    font-family:'Fredoka',sans-serif;
    font-size:clamp(24px,4.8vw,42px);
    line-height:1.03;
    color:var(--li-purple-dark);
    font-weight:700;
}

.qa-premium-subtitle{
    margin:6px 0 0;
    color:#6b5fa6;
    font-size:clamp(12px,1.7vw,15px);
    font-weight:800;
}

.qa-premium-progress-row{
    display:grid;
    grid-template-columns:1fr auto;
    gap:12px;
    align-items:center;
    margin-bottom:clamp(14px,2vw,20px);
}

.qa-premium-progress-track{
    height:12px;
    background:#ECEAFB;
    border-radius:999px;
    overflow:hidden;
    border:1px solid rgba(127,119,221,.18);
}

.qa-premium-progress-fill{
    height:100%;
    width:0;
    border-radius:999px;
    background: linear-gradient(90deg, #F97316, #7F77DD);
    transition:width .35s ease;
}

.qa-premium-progress-count{
    flex:0 0 auto;
    min-width:86px;
    text-align:center;
    font-size:12px;
    font-weight:800;
    letter-spacing:.4px;
    color:#fff;
    background: #7F77DD;
    padding:7px 12px;
    border-radius:999px;
}

.qa-premium-card-wrap{
    position:relative;
    display:grid;
    grid-template-columns:auto minmax(0,1fr) auto;
    align-items:center;
    gap:clamp(8px,1.6vw,18px);
}

.qa-premium-arrow{
    width:48px;
    height:48px;
    border-radius:999px;
    border: 1px solid #E4E1F8;
    background: #ffffff;
    color: #534AB7;
    font-size:25px;
    line-height:1;
    font-weight:800;
    cursor:pointer;
    display:grid;
    place-items:center;
    box-shadow: 0 4px 14px rgba(127,119,221,.13);
    transition:transform .18s ease, box-shadow .18s ease;
    user-select:none;
}

.qa-premium-arrow:hover{
    transform:translateY(-2px) scale(1.05);
    background:var(--li-teal-soft);
    box-shadow:0 16px 28px rgba(8,80,65,.18);
}

.qa-premium-card{
    perspective:1200px;
    min-height:clamp(220px,30vh,300px);
    cursor:pointer;
    outline:none;
}

.qa-premium-card-inner{
    position:relative;
    width:100%;
    height:100%;
    min-height:inherit;
    transform-style:preserve-3d;
    transition:transform .65s cubic-bezier(.2,.8,.2,1);
}

.qa-premium-card.is-flipped .qa-premium-card-inner{
    transform:rotateY(180deg);
}

.qa-premium-face{
    position:absolute;
    inset:0;
    border-radius:20px;
    backface-visibility:hidden;
    overflow:hidden;
    display:flex;
    flex-direction:row;
    align-items:center;
    justify-content:center;
    gap:12px;
    padding:14px clamp(48px,7vw,72px);
    border:1px solid rgba(127,119,221,.16);
    box-shadow:0 18px 36px rgba(39,27,93,.13);
}

.qa-premium-face.qa-premium-front{
    background: #ffffff;
    border: 1px solid #EDE9FA;
}

.qa-premium-back{
    transform:rotateY(180deg);
    background:linear-gradient(135deg, #F97316 0%, #C2580A 100%);
}

.qa-premium-label{
    position:absolute;
    top:10px;
    left:16px;
    transform:none;
    padding:4px 10px;
    border-radius:999px;
    font-size:10px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
}

.qa-premium-front .qa-premium-label{
    background:var(--li-purple-soft);
    color:var(--li-purple-dark);
}

.qa-premium-back .qa-premium-label{
    background:rgba(255,255,255,.20);
    color:#fff;
}

.qa-premium-text{
    width:100%;
    max-width:780px;
    font-family:'Fredoka',sans-serif;
    font-size:clamp(16px,2.4vw,28px);
    font-weight:700;
    line-height:1.2;
    text-align:center;
    overflow-wrap:break-word;
    word-break:normal;
}

.qa-premium-front .qa-premium-text{
    color:var(--li-purple-dark);
}

.qa-premium-back .qa-premium-text{
    color:#fff;
    text-shadow:0 8px 22px rgba(0,0,0,.18);
}

.qa-premium-hint{
    position:absolute;
    left:50%;
    bottom:clamp(14px,2vw,22px);
    transform:translateX(-50%);
    color:var(--li-muted);
    font-size:12px;
    font-weight:900;
    letter-spacing:.02em;
    text-align:center;
    pointer-events:none;
}

.qa-premium-back .qa-premium-hint{
    color:rgba(255,255,255,.78);
}

.qa-premium-actions{
    margin-top:clamp(16px,2.4vw,24px);
    display:flex;
    justify-content:center;
    align-items:center;
    gap:clamp(8px,1.4vw,12px);
    flex-wrap:wrap;
}

.qa-premium-btn{
    border:0;
    border-radius:10px;
    min-width:clamp(72px,10vw,100px);
    padding:8px 14px;
    color:#fff;
    font-family:'Nunito',sans-serif;
    font-size:clamp(11px,1.4vw,13px);
    font-weight:900;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    box-shadow:0 8px 16px rgba(37,99,235,.18);
    transition:transform .18s ease, filter .18s ease, box-shadow .18s ease;
}

.qa-premium-btn:hover{
    transform:translateY(-2px);
    filter:brightness(1.05);
}

.qa-premium-btn-blue{background: #F97316;box-shadow: 0 6px 18px rgba(249,115,22,.22);}

.qa-premium-btn-pink{background: #7F77DD;box-shadow: 0 6px 18px rgba(127,119,221,.18);}

.qa-premium-btn-teal{
    background:linear-gradient(135deg,var(--li-teal),var(--li-teal-dark));
    box-shadow:0 12px 22px rgba(8,80,65,.20);
}

.qa-premium-completed{
    display:none;
    width:min(680px,100%);
    margin:0 auto;
    text-align:center;
    padding:clamp(28px,5vw,54px);
    border-radius:34px;
    background:rgba(255,255,255,.88);
    border:1px solid rgba(255,255,255,.82);
    box-shadow:var(--li-shadow);
    backdrop-filter:blur(14px);
}

.qa-premium-completed.active{
    display:block;
    animation:qaPop .45s cubic-bezier(.2,.9,.2,1);
}

.qa-premium-done-icon{
    font-size:clamp(66px,12vw,112px);
    margin-bottom:12px;
}

.qa-premium-done-title{
    margin:0 0 10px;
    font-family:'Fredoka',sans-serif;
    font-size:clamp(34px,6vw,62px);
    color:var(--li-teal-dark);
    line-height:1;
}

.qa-premium-done-text{
    margin:0 auto 22px;
    max-width:520px;
    color:var(--li-muted);
    font-size:clamp(14px,2vw,18px);
    font-weight:800;
    line-height:1.5;
}

.qa-premium-done-track{
    height:14px;
    max-width:420px;
    margin:0 auto 18px;
    border-radius:999px;
    background:#E2F7EF;
    overflow:hidden;
}

.qa-premium-done-fill{
    height:100%;
    width:0%;
    border-radius:999px;
    background:linear-gradient(90deg,var(--li-teal),var(--li-purple),var(--li-pink));
    transition:width .8s cubic-bezier(.2,.9,.2,1);
}

.qa-premium-confetti{
    position:fixed;
    width:10px;
    height:14px;
    top:-20px;
    z-index:99999;
    opacity:.95;
    animation:qaFall linear forwards;
    pointer-events:none;
}

@keyframes qaPop{
    from{opacity:0;transform:translateY(12px) scale(.96)}
    to{opacity:1;transform:translateY(0) scale(1)}
}

@keyframes qaFall{
    to{transform:translateY(110vh) rotate(720deg);opacity:1}
}

@media(max-width:900px){
    .qa-premium-board{width:min(700px,100%)}
    .qa-premium-card{min-height:clamp(220px,30vh,300px)}
}

@media(max-width:640px){
    .qa-premium-shell{min-height:calc(100vh - 70px);padding:12px;border-radius:12px}
    .qa-premium-board{border-radius:26px;padding:14px}
    .qa-premium-title-panel{width:100%;border-radius:22px}
    .qa-premium-progress-row{grid-template-columns:1fr;gap:8px}
    .qa-premium-progress-count{justify-self:center}
    .qa-premium-card-wrap{grid-template-columns:1fr}
    .qa-premium-arrow{position:absolute;top:50%;transform:translateY(-50%)}
    .qa-premium-arrow-left{left:-4px}
    .qa-premium-arrow-right{right:-4px}
    .qa-premium-card{min-height:min(220px,28vh)}
    .qa-premium-actions{display:grid;grid-template-columns:1fr;gap:9px}
    .qa-premium-btn{width:100%}
}

@media(max-width:390px){
    .qa-premium-shell{padding:8px}
    .qa-premium-board{padding:10px;border-radius:22px}
    .qa-premium-title{font-size:24px}
    .qa-premium-subtitle{font-size:12px}
    .qa-premium-card{min-height:220px}
    .qa-premium-text{font-size:clamp(14px,4vw,22px)}
}

/* ── Fullscreen / embedded: card fills the iframe ── */
body.embedded-mode .viewer-content,
body.fullscreen-embedded .viewer-content,
body.presentation-mode .viewer-content {
    padding: 0 !important;
    position: relative !important;
}

body.embedded-mode .qa-premium-shell,
body.fullscreen-embedded .qa-premium-shell,
body.presentation-mode .qa-premium-shell {
    position: absolute !important;
    inset: 0 !important;
    max-width: 100% !important;
    margin: 0 auto !important;
    padding: 16px clamp(16px,4vw,48px) !important;
    border-radius: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    overflow: hidden !important;
}

body.embedded-mode .qa-premium-app,
body.fullscreen-embedded .qa-premium-app,
body.presentation-mode .qa-premium-app {
    flex: 1 !important;
    width: min(100%, 1100px) !important;
    min-height: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 10px !important;
}

body.embedded-mode .qa-premium-board,
body.fullscreen-embedded .qa-premium-board,
body.presentation-mode .qa-premium-board {
    flex: 1 !important;
    min-height: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: flex-start !important;
    padding: 14px 20px !important;
    border-radius: 24px !important;
    width: 100% !important;
}

body.embedded-mode .qa-premium-card-wrap,
body.fullscreen-embedded .qa-premium-card-wrap,
body.presentation-mode .qa-premium-card-wrap {
    flex: 0 0 auto !important;
    min-height: 0 !important;
    align-items: center !important;
    grid-template-columns: auto minmax(0, 760px) auto !important;
    margin: 0 auto !important;
}

body.embedded-mode .qa-premium-card,
body.fullscreen-embedded .qa-premium-card,
body.presentation-mode .qa-premium-card {
    min-height: clamp(180px, 24vh, 260px) !important;
    max-width: 760px !important;
    width: 100% !important;
    flex: 0 0 auto !important;
    height: auto !important;
}

body.embedded-mode .qa-premium-card-inner,
body.fullscreen-embedded .qa-premium-card-inner,
body.presentation-mode .qa-premium-card-inner {
    height: auto !important;
    min-height: inherit !important;
}

body.embedded-mode .qa-premium-text,
body.fullscreen-embedded .qa-premium-text,
body.presentation-mode .qa-premium-text {
    font-size: clamp(20px, 3.5vh, 42px) !important;
    max-width: 860px !important;
}
</style>

<div class="qa-premium-shell">
    <div class="qa-premium-app" id="qa-premium-app">
        <div style="text-align:center;margin-bottom:10px;">
            <h1 style="margin:0;font-family:'Fredoka',sans-serif;font-size:clamp(30px,5.5vw,58px);line-height:1;color:#F97316;font-weight:700;"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        </div>
        <section class="qa-premium-board" id="qa-premium-board">
            <div class="qa-premium-progress-row">
                <div class="qa-premium-progress-track">
                    <div class="qa-premium-progress-fill" id="qa-premium-progress-fill"></div>
                </div>
                <div class="qa-premium-progress-count" id="qa-premium-progress-count">1 / <?php echo count($cards); ?></div>
            </div>

            <div class="qa-premium-card-wrap">
                <button type="button" class="qa-premium-arrow qa-premium-arrow-left" id="qa-premium-prev-arrow" aria-label="Previous question">&#8249;</button>

                <div class="qa-premium-card" id="qa-premium-card" role="button" tabindex="0" aria-label="Tap to reveal answer">
                    <div class="qa-premium-card-inner">
                        <div class="qa-premium-face qa-premium-front">
                            <div class="qa-premium-label">Question</div>
                            <div class="qa-premium-text" id="qa-premium-question"></div>
                            <div class="qa-premium-hint">Tap to reveal answer</div>
                        </div>

                        <div class="qa-premium-face qa-premium-back">
                            <div class="qa-premium-label">Answer</div>
                            <div class="qa-premium-text" id="qa-premium-answer"></div>
                            <div class="qa-premium-hint">Tap to see question</div>
                        </div>
                    </div>
                </div>

                <button type="button" class="qa-premium-arrow qa-premium-arrow-right" id="qa-premium-next-arrow" aria-label="Next question">&#8250;</button>
            </div>

            <div class="qa-premium-actions">
                <button type="button" class="qa-premium-btn qa-premium-btn-blue" id="qa-premium-prev">&#9664; Prev</button>
                <button type="button" class="qa-premium-btn qa-premium-btn-pink" id="qa-premium-listen">&#x1F50A; Listen</button>
                <button type="button" class="qa-premium-btn qa-premium-btn-blue" id="qa-premium-next">Next &#9654;</button>
            </div>
        </section>

        <section class="qa-premium-completed" id="qa-premium-completed">
            <div class="qa-premium-done-icon">🎉</div>
            <h2 class="qa-premium-done-title">All Done!</h2>
            <p class="qa-premium-done-text">You reviewed all questions. Excellent fluency practice!</p>
            <div class="qa-premium-done-track">
                <div class="qa-premium-done-fill" id="qa-premium-done-fill"></div>
            </div>
            <div class="qa-premium-actions">
                <button type="button" class="qa-premium-btn qa-premium-btn-teal" id="qa-premium-restart">&#8635; Review Again</button>
            </div>
        </section>
    </div>
</div>

<audio id="qa-premium-win" src="../../hangman/assets/win.mp3" preload="auto"></audio>

<script>
(function(){
'use strict';

var CARDS = <?php echo json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var TOTAL = CARDS.length;
var QA_VOICE_ID = <?php echo json_encode((string) ($activity['voice_id'] ?? 'nzFihrBIvB34imQBuxub'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var QA_TTS_URL = 'tts.php';
var idx = 0;
var flipped = false;
var done = false;

var els = {
    board: document.getElementById('qa-premium-board'),
    completed: document.getElementById('qa-premium-completed'),
    card: document.getElementById('qa-premium-card'),
    question: document.getElementById('qa-premium-question'),
    answer: document.getElementById('qa-premium-answer'),
    progressFill: document.getElementById('qa-premium-progress-fill'),
    progressCount: document.getElementById('qa-premium-progress-count'),
    kickerCount: document.getElementById('qa-premium-kicker-count'),
    doneFill: document.getElementById('qa-premium-done-fill'),
    win: document.getElementById('qa-premium-win')
};

var TTS = (function(){
    var audio = null;
    var audioUrl = '';

    function cleanup() {
        if (audio) {
            try { audio.pause(); } catch (e) {}
            try { audio.currentTime = 0; } catch (e) {}
            audio = null;
        }
        if (audioUrl) {
            try { URL.revokeObjectURL(audioUrl); } catch (e) {}
            audioUrl = '';
        }
    }

    function speak(text){
        text = String(text || '').trim();
        if (!text) return;

        if (audio) {
            if (!audio.paused) {
                audio.pause();
            } else {
                audio.play().catch(function(){});
            }
            return;
        }

        var fd = new FormData();
        fd.append('text', text);
        fd.append('voice_id', QA_VOICE_ID || 'nzFihrBIvB34imQBuxub');

        fetch(QA_TTS_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('TTS error ' + res.status);
                return res.blob();
            })
            .then(function (blob) {
                audioUrl = URL.createObjectURL(blob);
                audio = new Audio(audioUrl);

                audio.onended = function () {
                    cleanup();
                };

                audio.play().catch(function () {
                    cleanup();
                });
            })
            .catch(function () {
                cleanup();
            });
    }

    return { speak: speak, stop: cleanup };
})();

function getQuestion(card){
    return String((card && card.question) || '').trim();
}

function getAnswer(card){
    return String((card && card.answer) || '').trim();
}

function setFlipped(value){
    flipped = !!value;
    if (flipped) els.card.classList.add('is-flipped');
    else els.card.classList.remove('is-flipped');
}

function loadCard(){
    if (!TOTAL) return;

    var card = CARDS[idx] || {};
    els.question.textContent = getQuestion(card) || 'No question';
    els.answer.textContent = getAnswer(card) || 'No answer';

    setFlipped(false);

    var countText = (idx + 1) + ' / ' + TOTAL;
    var pct = Math.max(1, Math.round(((idx + 1) / TOTAL) * 100));
    els.progressFill.style.width = pct + '%';
    els.progressCount.textContent = countText;
    els.kickerCount.textContent = countText;
}

function flipCard(){
    if (done) return;
    setFlipped(!flipped);
}

function prevCard(){
    if (done) return;
    idx = (idx - 1 + TOTAL) % TOTAL;
    loadCard();
}

function nextCard(){
    if (done) return;
    if (idx >= TOTAL - 1) {
        showDone();
        return;
    }
    idx++;
    loadCard();
}

function showDone(){
    done = true;
    TTS.stop();
    els.board.style.display = 'none';
    els.completed.classList.add('active');
    setTimeout(function(){ els.doneFill.style.width = '100%'; }, 120);
    launchConfetti();
    try {
        els.win.pause();
        els.win.currentTime = 0;
        els.win.play();
    } catch(e) {}
}

function restart(){
    done = false;
    TTS.stop();
    idx = 0;
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
                piece.className = 'qa-premium-confetti';
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

function bind(id, eventName, handler){
    var el = document.getElementById(id);
    if (el) el.addEventListener(eventName, handler);
}

els.card.addEventListener('click', flipCard);
els.card.addEventListener('keydown', function(e){
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        flipCard();
    }
});

bind('qa-premium-prev-arrow', 'click', prevCard);
bind('qa-premium-next-arrow', 'click', nextCard);
bind('qa-premium-prev', 'click', prevCard);
bind('qa-premium-next', 'click', nextCard);
bind('qa-premium-restart', 'click', restart);
bind('qa-premium-listen', 'click', function(){
    var card = CARDS[idx] || {};
    TTS.speak(flipped ? getAnswer(card) : getQuestion(card));
});

document.addEventListener('keydown', function(e){
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        flipCard();
    }
    if (e.key === 'ArrowRight') nextCard();
    if (e.key === 'ArrowLeft') prevCard();
});

loadCard();
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-circle-question', $content);
