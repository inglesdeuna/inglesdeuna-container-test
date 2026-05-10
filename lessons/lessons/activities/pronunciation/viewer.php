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
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'activities'");
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
        $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['unit_id'])) {
            return (string) $row['unit_id'];
        }
    }
    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT unit FROM activities WHERE id = :id LIMIT 1");
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
    $default = array('title' => default_pronunciation_title(), 'items' => array());
    if ($rawData === null || $rawData === '') {
        return $default;
    }
    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }
    $title = isset($decoded['title']) ? trim((string) $decoded['title']) : '';
    $itemsSource = $decoded;
    if (isset($decoded['items']) && is_array($decoded['items'])) {
        $itemsSource = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $itemsSource = $decoded['data'];
    } elseif (isset($decoded['words']) && is_array($decoded['words'])) {
        $itemsSource = $decoded['words'];
    }
    $items = array();
    if (is_array($itemsSource)) {
        foreach ($itemsSource as $item) {
            if (!is_array($item)) {
                continue;
            }
            $en = isset($item['en']) ? trim((string) $item['en']) : '';
            if ($en === '' && isset($item['word'])) {
                $en = trim((string) $item['word']);
            }
            $img = isset($item['img']) ? trim((string) $item['img']) : (isset($item['image']) ? trim((string) $item['image']) : '');
            if ($en === '' && $img === '') {
                continue;
            }
            $items[] = array(
                'img' => $img,
                'en' => $en,
                'ph' => isset($item['ph']) ? trim((string) $item['ph']) : '',
                'es' => isset($item['es']) ? trim((string) $item['es']) : '',
                'audio' => isset($item['audio']) ? trim((string) $item['audio']) : '',
            );
        }
    }
    return array('title' => normalize_activity_title($title), 'items' => $items);
}

function load_pronunciation_activity(PDO $pdo, string $activityId, string $unit): array
{
    $columns = activities_columns($pdo);
    $selectFields = array('id');
    foreach (array('data', 'content_json', 'title', 'name') as $col) {
        if (in_array($col, $columns, true)) {
            $selectFields[] = $col;
        }
    }
    $fallback = array('id' => '', 'title' => default_pronunciation_title(), 'items' => array());
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id = :id AND type = 'pronunciation' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '' && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id = :unit AND type = 'pronunciation' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '' && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit = :unit AND type = 'pronunciation' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) {
        return $fallback;
    }
    $rawData = isset($row['data']) ? $row['data'] : (isset($row['content_json']) ? $row['content_json'] : null);
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
*{box-sizing:border-box}
html,body{width:100%;min-height:100%}
body{margin:0!important;padding:0!important;background:#ffffff!important;font-family:'Nunito','Segoe UI',sans-serif!important}
.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;min-height:100vh;display:flex!important;flex-direction:column!important;background:transparent!important}
.top-row{display:none!important}
.viewer-content{flex:1!important;display:flex!important;flex-direction:column!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important}

.pron-shell{width:100%;min-height:calc(100vh - 90px);padding:clamp(14px,2.5vw,34px);display:flex;align-items:flex-start;justify-content:center;background:#ffffff;font-family:'Nunito','Segoe UI',sans-serif}
.pron-app{width:min(760px,100%);margin:0 auto}
.pron-board{width:min(760px,100%);margin:0 auto;background:#ffffff;border:1px solid #F0EEF8;border-radius:34px;padding:clamp(16px,2.6vw,26px);box-shadow:0 8px 40px rgba(127,119,221,.13)}
.pron-header{text-align:center;margin-bottom:14px}
.pron-kicker{display:inline-flex;align-items:center;justify-content:center;margin-bottom:8px;padding:7px 14px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.08em}
.pron-title{margin:0;font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:clamp(30px,5.5vw,58px);line-height:1.03;color:#F97316;font-weight:700}
.pron-subtitle{margin:8px 0 0;color:#9B94BE;font-size:clamp(13px,1.8vw,17px);font-weight:800}
.pron-progress{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.pron-track{flex:1;height:12px;background:#F4F2FD;border:1px solid #E4E1F8;border-radius:999px;overflow:hidden}
.pron-fill{height:100%;width:0%;background:linear-gradient(90deg,#F97316,#7F77DD);border-radius:999px;transition:width .45s ease}
.pron-count{min-width:74px;text-align:center;padding:7px 11px;border-radius:999px;background:#7F77DD;color:#ffffff;font-size:12px;font-weight:900}

.pron-card{min-height:480px;border:1px solid #EDE9FA;border-radius:30px;background:#ffffff;padding:18px;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;text-align:center;box-shadow:0 8px 24px rgba(127,119,221,.09)}
.pron-listen-cue{display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;padding:6px 13px;border-radius:999px;background:#EEEDFE;color:#534AB7;font-size:12px;font-weight:900}
.pron-image{width:100%;max-width:600px;height:340px;margin-bottom:14px;border-radius:24px;background:#ffffff;border:1px solid #EDE9FA;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:10px}
.pron-image img{width:100%;height:100%;object-fit:contain;display:block;border-radius:18px}
.pron-placeholder{font-family:'Fredoka',sans-serif;font-size:62px;color:#CECBF6;font-weight:700;line-height:1.05;padding:0 12px;overflow-wrap:anywhere}
.pron-word{max-width:620px;font-family:'Nunito','Segoe UI',sans-serif;font-size:clamp(20px,2.8vw,30px);font-weight:900;line-height:1.18;color:#534AB7;overflow-wrap:anywhere}
.pron-card.image-only .pron-word{display:none}
.pron-card.text-only .pron-image{display:none}
.pron-card.text-only .pron-placeholder{display:none}
.pron-card.text-only{justify-content:center}
.pron-card.text-only .pron-word{font-size:clamp(30px,5.4vw,52px)}

.pron-phonetic{display:none;margin-top:8px;padding:0;color:#4B5563;font-size:17px;font-weight:800;font-family:'Nunito',sans-serif;line-height:1.35}
.pron-box{width:100%;max-width:620px;margin-top:8px;border-radius:12px;padding:9px 12px;font-size:13px;font-weight:800;text-align:center}
.pron-box:empty{display:none}
.pron-captured{border:1px solid #EDE9FA;background:#ffffff;color:#534AB7}
.pron-captured.ok{border-color:#EDE9FA;background:#EEEDFE;color:#534AB7}
.pron-captured.bad{border-color:#FCDDBF;background:#FFF0E6;color:#C2580A}
.pron-answer{display:none;border:1px solid #EDE9FA;background:#ffffff;color:#9B94BE}
.pron-answer.show{display:block}
.pron-feedback{color:#9B94BE}
.pron-feedback.bad{color:#C2580A}
.pron-actions{display:flex;justify-content:center;align-items:center;gap:10px;flex-wrap:wrap;margin-top:16px;padding-top:16px;border-top:1px solid #F0EEF8}
.pron-btn{border:0;border-radius:999px;min-width:clamp(104px,16vw,146px);padding:13px 20px;color:#ffffff;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;cursor:pointer;box-shadow:0 6px 18px rgba(127,119,221,.18);transition:filter .15s,transform .15s}
.pron-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.pron-btn:disabled{opacity:.55;cursor:not-allowed;transform:none;filter:none}
.pron-purple{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18)}
.pron-orange{background:#F97316;box-shadow:0 6px 18px rgba(249,115,22,.22)}

.pron-completed{display:none;width:min(760px,100%);margin:0 auto;background:#ffffff;border:1px solid #F0EEF8;border-radius:34px;box-shadow:0 8px 40px rgba(127,119,221,.13);min-height:300px;padding:34px 22px;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:12px}
.pron-completed.active{display:flex}
.pron-done-icon{font-size:42px;line-height:1;color:#7F77DD}
.pron-done-title{margin:0;font-family:'Fredoka',sans-serif;font-size:clamp(30px,5.5vw,58px);color:#F97316;line-height:1.03;font-weight:700}
.pron-done-text{margin:0;max-width:520px;color:#9B94BE;font-size:clamp(13px,1.8vw,17px);font-weight:800;line-height:1.5}
.pron-score{margin:0;color:#534AB7;font-size:15px;font-weight:900}
.pron-done-track{height:12px;width:min(420px,100%);border-radius:999px;background:#F4F2FD;border:1px solid #E4E1F8;overflow:hidden}
.pron-done-fill{height:100%;width:0%;background:linear-gradient(90deg,#F97316,#7F77DD);transition:width .8s ease}

body.embedded-mode .pron-shell,
body.fullscreen-embedded .pron-shell,
body.presentation-mode .pron-shell{position:absolute!important;inset:0!important;overflow-y:auto!important;overflow-x:hidden!important;padding:10px 12px!important}

@media(max-width:640px){
    .pron-shell{padding:12px}
    .pron-board{padding:14px;border-radius:26px}
    .pron-card{min-height:420px;padding:14px;border-radius:24px}
    .pron-image{height:280px;border-radius:20px}
    .pron-placeholder{font-size:44px}
    .pron-word{font-size:clamp(18px,5.5vw,28px)}
    .pron-card.text-only .pron-word{font-size:clamp(26px,7vw,40px)}
    .pron-actions{display:grid;grid-template-columns:1fr;gap:9px}
    .pron-btn{width:100%}
}
</style>

<div class="pron-shell"><div class="pron-app"><section class="pron-board" id="pron-board"><div class="pron-header"><div class="pron-kicker">Activity <span id="pron-kicker-count">1 / <?php echo count($items); ?></span></div><h1 class="pron-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1><p class="pron-subtitle">Pronunciation practice</p></div><div class="pron-progress"><div class="pron-track"><div class="pron-fill" id="pron-progress-fill"></div></div><div class="pron-count" id="pron-progress-count">1 / <?php echo count($items); ?></div></div><div class="pron-card" id="pron-card"><div class="pron-listen-cue">Listen first</div><div class="pron-image"><img id="pron-img" src="" alt="" style="display:none;"><div class="pron-placeholder" id="pron-placeholder">A</div></div><div class="pron-word" id="pron-word"></div><div class="pron-phonetic" id="pron-phonetic"></div><div class="pron-box pron-captured" id="pron-captured"></div><div class="pron-box pron-answer" id="pron-answer"></div><div class="pron-box pron-feedback" id="pron-feedback"></div></div><div class="pron-actions"><button type="button" class="pron-btn pron-purple" id="pron-listen">Listen</button><button type="button" class="pron-btn pron-purple" id="pron-speak">Speaker</button><button type="button" class="pron-btn pron-orange" id="pron-next">Next</button></div></section><section class="pron-completed" id="pron-completed"><div class="pron-done-icon">Done</div><h2 class="pron-done-title" id="pron-completed-title">All Done!</h2><p class="pron-done-text" id="pron-completed-text">Great job practicing pronunciation.</p><p class="pron-score" id="pron-score-text"></p><div class="pron-done-track"><div class="pron-done-fill" id="pron-done-fill"></div></div><div class="pron-actions"><button type="button" class="pron-btn pron-orange" id="pron-restart">Restart</button></div></section></div></div>
<audio id="pron-win" src="../../hangman/assets/win.mp3" preload="auto"></audio><audio id="pron-lose" src="../../hangman/assets/lose.mp3" preload="auto"></audio>
<script>
document.addEventListener('DOMContentLoaded',function(){'use strict';var data=<?php echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;if(!Array.isArray(data))data=[];var activityTitle=<?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;var PRON_ACTIVITY_ID=<?php echo json_encode($activity['id'] ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;var PRON_RETURN_TO=<?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;var TOTAL=data.length,index=0,correctCount=0,checkedCards={},capturedText='',recognitionBusy=false,pronIsSpeaking=false,pronIsPaused=false,pronCurrentAudio=null,pronUtter=null;var els={board:document.getElementById('pron-board'),card:document.getElementById('pron-card'),completed:document.getElementById('pron-completed'),img:document.getElementById('pron-img'),placeholder:document.getElementById('pron-placeholder'),word:document.getElementById('pron-word'),phonetic:document.getElementById('pron-phonetic'),captured:document.getElementById('pron-captured'),answer:document.getElementById('pron-answer'),feedback:document.getElementById('pron-feedback'),progressFill:document.getElementById('pron-progress-fill'),progressCount:document.getElementById('pron-progress-count'),kickerCount:document.getElementById('pron-kicker-count'),completedTitle:document.getElementById('pron-completed-title'),completedText:document.getElementById('pron-completed-text'),scoreText:document.getElementById('pron-score-text'),doneFill:document.getElementById('pron-done-fill'),win:document.getElementById('pron-win'),lose:document.getElementById('pron-lose')};var listenBtn=document.getElementById('pron-listen'),speakBtn=document.getElementById('pron-speak'),nextBtn=document.getElementById('pron-next'),restartBtn=document.getElementById('pron-restart');var recognition=null,SpeechRecognitionCtor=window.SpeechRecognition||window.webkitSpeechRecognition||null;if(SpeechRecognitionCtor){recognition=new SpeechRecognitionCtor();recognition.lang='en-US';recognition.interimResults=false;recognition.maxAlternatives=1;recognition.continuous=false}function normalizeText(text){return String(text||'').toLowerCase().trim().replace(/[.,!?;:'"\-]/g,'').replace(/\s+/g,' ')}function wordOverlapScore(a,b){var wa=a.split(' ').filter(Boolean),wb=b.split(' ').filter(Boolean);if(!wa.length||!wb.length)return 0;var matches=wa.filter(function(w){return wb.indexOf(w)!==-1}).length;return matches/Math.max(wa.length,wb.length)}function isMatch(said,expected){return said===expected||wordOverlapScore(said,expected)>=.8}function playSound(sound){try{sound.pause();sound.currentTime=0;sound.play()}catch(e){}}function getCurrentWord(){return String((data[index]&&data[index].en)||'').trim()}function getPreferredVoice(){var voices=[];try{voices=speechSynthesis.getVoices()||[]}catch(e){voices=[]}var names=['Google US English','Microsoft Jenny','Microsoft Aria','Samantha','Alex','Daniel','Karen'];for(var i=0;i<names.length;i++){for(var j=0;j<voices.length;j++){var label=String((voices[j].name||'')+' '+(voices[j].voiceURI||'')).toLowerCase();if(String(voices[j].lang||'').toLowerCase().indexOf('en')===0&&label.indexOf(names[i].toLowerCase())!==-1)return voices[j]}}for(var k=0;k<voices.length;k++)if(String(voices[k].lang||'').toLowerCase()==='en-us')return voices[k];for(var m=0;m<voices.length;m++)if(String(voices[m].lang||'').toLowerCase().indexOf('en')===0)return voices[m];return null}function setListenButtonLabel(){listenBtn.textContent=pronIsPaused?'Resume':(pronIsSpeaking?'Pause':'Listen')}function speakCurrent(){if(!data[index])return;var item=data[index];if(item.audio){if(!pronCurrentAudio||pronCurrentAudio.getAttribute('data-src')!==item.audio){if(pronCurrentAudio)pronCurrentAudio.pause();pronCurrentAudio=new Audio(item.audio);pronCurrentAudio.setAttribute('data-src',item.audio);pronCurrentAudio.onended=function(){pronIsSpeaking=false;pronIsPaused=false;setListenButtonLabel()}}if(!pronCurrentAudio.paused){pronCurrentAudio.pause();pronIsSpeaking=true;pronIsPaused=true}else{pronCurrentAudio.play().then(function(){pronIsSpeaking=true;pronIsPaused=false;setListenButtonLabel()}).catch(function(){})}setListenButtonLabel();return}if(!window.speechSynthesis)return;if(speechSynthesis.speaking&&!speechSynthesis.paused){speechSynthesis.pause();pronIsSpeaking=true;pronIsPaused=true;setListenButtonLabel();return}if(speechSynthesis.paused||pronIsPaused){speechSynthesis.resume();pronIsSpeaking=true;pronIsPaused=false;setListenButtonLabel();return}var text=getCurrentWord();if(!text)return;speechSynthesis.cancel();pronUtter=new SpeechSynthesisUtterance(text);pronUtter.lang='en-US';pronUtter.rate=.82;pronUtter.pitch=1;pronUtter.volume=1;var voice=getPreferredVoice();if(voice)pronUtter.voice=voice;pronUtter.onstart=function(){pronIsSpeaking=true;pronIsPaused=false;setListenButtonLabel()};pronUtter.onend=function(){pronIsSpeaking=false;pronIsPaused=false;setListenButtonLabel()};speechSynthesis.speak(pronUtter)}function setCardMode(mode){if(!els.card)return;els.card.classList.remove('text-only');els.card.classList.remove('image-only');if(mode)els.card.classList.add(mode)}function loadCard(){var item=data[index]||{},word=getCurrentWord()||'Listen and repeat.';if(window.speechSynthesis)speechSynthesis.cancel();if(pronCurrentAudio)pronCurrentAudio.pause();pronIsSpeaking=false;pronIsPaused=false;capturedText='';setListenButtonLabel();setCardMode('');els.word.style.display='';els.image = document.querySelector('.pron-image');els.word.textContent=word;els.captured.textContent='';els.captured.className='pron-box pron-captured';els.answer.textContent='Text: '+word;els.answer.classList.remove('show');els.feedback.textContent='';els.feedback.className='pron-box pron-feedback';if(item.ph){els.phonetic.textContent=item.ph;els.phonetic.style.display='block'}else{els.phonetic.textContent='';els.phonetic.style.display='none'}if(item.img){setCardMode('image-only');els.img.src=item.img;els.img.alt=word;els.img.style.display='';els.placeholder.style.display='none';els.word.textContent='';els.word.style.display='none';els.img.onerror=function(){setCardMode('text-only');els.img.removeAttribute('src');els.img.style.display='none';els.placeholder.style.display='none';els.word.textContent=word;els.word.style.display=''}}else{setCardMode('text-only');els.img.removeAttribute('src');els.img.style.display='none';els.placeholder.style.display='none';els.word.textContent=word;els.word.style.display=''}var countText=(index+1)+' / '+TOTAL;els.progressFill.style.width=Math.max(1,Math.round(((index+1)/TOTAL)*100))+'%';els.progressCount.textContent=countText;els.kickerCount.textContent=countText;nextBtn.textContent=index<TOTAL-1?'Next':'Finish'}function recordPronunciation(){if(!recognition||recognitionBusy){if(!recognition){els.feedback.textContent='Speech recognition is not available in this browser.';els.feedback.className='pron-box pron-feedback bad'}return}recognitionBusy=true;els.feedback.textContent='Listening...';els.feedback.className='pron-box pron-feedback';recognition.onresult=function(event){capturedText=String((event.results&&event.results[0]&&event.results[0][0]&&event.results[0][0].transcript)||'');recognitionBusy=false;checkAnswer()};recognition.onerror=function(){capturedText='';els.captured.textContent='Could not capture voice. Try again.';els.captured.className='pron-box pron-captured bad';els.feedback.textContent='Try Again';els.feedback.className='pron-box pron-feedback bad';recognitionBusy=false};recognition.onend=function(){recognitionBusy=false};try{recognition.start()}catch(e){recognitionBusy=false}}function checkAnswer(){var said=normalizeText(capturedText),expected=normalizeText(getCurrentWord());if(!said){els.feedback.textContent="You didn't record your voice.";els.feedback.className='pron-box pron-feedback bad';return}var correct=isMatch(said,expected);if(correct){els.captured.textContent='Good';els.captured.className='pron-box pron-captured ok';els.feedback.textContent='';playSound(els.win)}else{els.captured.textContent='Try again';els.captured.className='pron-box pron-captured bad';els.feedback.textContent='';playSound(els.lose)}if(correct&&!checkedCards[index]){checkedCards[index]=true;correctCount++}else if(!correct&&!checkedCards[index]){checkedCards[index]=false}}function persistScoreSilently(targetUrl){if(!targetUrl)return Promise.resolve(false);return fetch(targetUrl,{method:'GET',credentials:'same-origin',cache:'no-store'}).then(function(response){return!!(response&&response.ok)}).catch(function(){return false})}async function showCompleted(){els.board.style.display='none';els.completed.classList.add('active');setTimeout(function(){els.doneFill.style.width='100%'},120);playSound(els.win);var pct=TOTAL>0?Math.round((correctCount/TOTAL)*100):0,errors=Math.max(0,TOTAL-correctCount);els.completedTitle.textContent='All Done!';els.completedText.textContent="You've completed "+(activityTitle||'this activity')+'. Great job listening and repeating.';els.scoreText.textContent='Score: '+correctCount+' / '+TOTAL+' ('+pct+'%)';if(PRON_ACTIVITY_ID&&PRON_RETURN_TO){var joiner=PRON_RETURN_TO.indexOf('?')!==-1?'&':'?';var saveUrl=PRON_RETURN_TO+joiner+'activity_percent='+pct+'&activity_errors='+errors+'&activity_total='+TOTAL+'&activity_id='+encodeURIComponent(PRON_ACTIVITY_ID)+'&activity_type=pronunciation';var ok=await persistScoreSilently(saveUrl);if(!ok)window.location.href=saveUrl}}function goNext(){if(index<TOTAL-1){index++;loadCard()}else{showCompleted()}}function restart(){correctCount=0;checkedCards={};index=0;els.doneFill.style.width='0%';els.completed.classList.remove('active');els.board.style.display='';loadCard()}listenBtn.addEventListener('click',speakCurrent);speakBtn.addEventListener('click',recordPronunciation);nextBtn.addEventListener('click',goNext);restartBtn.addEventListener('click',restart);loadCard()});
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔊', $content);
