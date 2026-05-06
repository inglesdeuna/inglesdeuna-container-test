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
*{box-sizing:border-box}html,body{width:100%;min-height:100%}body{margin:0!important;padding:0!important;background:#fff!important;font-family:'Nunito','Segoe UI',sans-serif!important}.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;min-height:100vh;display:flex!important;flex-direction:column!important;background:transparent!important}.top-row{display:none!important}.viewer-content{flex:1!important;display:flex!important;flex-direction:column!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important}
.pron-shell{width:100%;min-height:calc(100vh - 90px);padding:14px 12px 18px;display:flex;align-items:flex-start;justify-content:center;background:#fff;font-family:'Nunito','Segoe UI',sans-serif}.pron-app{width:min(820px,100%);margin:0 auto}.pron-board{width:min(720px,100%);margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:20px;box-shadow:0 10px 28px rgba(0,0,0,.09)}.pron-header{text-align:center;margin-bottom:14px}.pron-kicker{display:inline-flex;align-items:center;justify-content:center;margin-bottom:8px;padding:5px 12px;border-radius:999px;background:#eff6ff;border:1px solid #dbeafe;color:#1d4ed8;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.08em}.pron-title{margin:0;font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:clamp(22px,3vw,30px);line-height:1.12;color:#1d4ed8;font-weight:700}.pron-subtitle{margin:5px 0 0;color:#475569;font-size:14px;font-weight:700}.pron-progress{display:flex;align-items:center;gap:10px;margin-bottom:14px}.pron-track{flex:1;height:9px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:999px;overflow:hidden}.pron-fill{height:100%;width:0%;background:linear-gradient(90deg,#2563eb,#14b8a6);border-radius:999px;transition:width .35s ease}.pron-count{min-width:70px;text-align:center;padding:5px 10px;border-radius:999px;background:#2563eb;color:#fff;font-size:12px;font-weight:900}.pron-card{min-height:300px;border:1px solid #e2e8f0;border-radius:18px;background:#fff;padding:22px 18px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}.pron-image{width:160px;height:160px;max-height:160px;margin-bottom:14px;border-radius:16px;background:#f8fafc;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;overflow:hidden}.pron-image img{max-width:86%;max-height:86%;object-fit:contain;display:block}.pron-placeholder{font-family:'Fredoka',sans-serif;font-size:64px;color:#2563eb;font-weight:700}.pron-word{max-width:560px;font-size:clamp(28px,4.6vw,46px);font-weight:900;line-height:1.08;color:#0f172a;overflow-wrap:anywhere}.pron-phonetic{display:none;margin-top:8px;padding:6px 12px;border-radius:999px;background:#eff6ff;border:1px solid #dbeafe;color:#1d4ed8;font-size:14px;font-weight:900}.pron-box{width:100%;max-width:560px;margin-top:8px;border-radius:12px;padding:10px 12px;font-size:14px;font-weight:800;text-align:center}.pron-box:empty{display:none}.pron-captured{border:1px solid #e2e8f0;background:#fff;color:#1d4ed8}.pron-captured.ok{border-color:#86efac;background:#f0fdf4;color:#166534}.pron-captured.bad{border-color:#fca5a5;background:#fff5f5;color:#991b1b}.pron-answer{display:none;border:1px solid #e2e8f0;background:#f8fafc;color:#0f172a}.pron-answer.show{display:block}.pron-feedback{color:#1d4ed8}.pron-feedback.bad{color:#dc2626}.pron-actions{display:flex;justify-content:center;align-items:center;gap:9px;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid #e2e8f0}.pron-btn{border:0;border-radius:999px;min-width:118px;padding:11px 18px;color:#fff;font-family:'Nunito',sans-serif;font-size:14px;font-weight:800;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.07);transition:filter .15s,transform .15s}.pron-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}.pron-btn:disabled{opacity:.55;cursor:not-allowed;transform:none;filter:none}.pron-blue{background:linear-gradient(180deg,#2563eb 0%,#1d4ed8 100%)}.pron-teal{background:linear-gradient(180deg,#14b8a6 0%,#0f766e 100%)}.pron-fuchsia{background:linear-gradient(180deg,#d946ef 0%,#a21caf 100%)}.pron-completed{display:none;width:min(720px,100%);margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:22px;box-shadow:0 10px 28px rgba(0,0,0,.09);min-height:320px;padding:34px 22px;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:12px}.pron-completed.active{display:flex}.pron-done-icon{font-size:52px;line-height:1}.pron-done-title{margin:0;font-family:'Fredoka',sans-serif;font-size:clamp(24px,3.4vw,34px);color:#0f766e;line-height:1.1;font-weight:700}.pron-done-text{margin:0;max-width:520px;color:#475569;font-size:15px;font-weight:700;line-height:1.5}.pron-score{margin:0;color:#1d4ed8;font-size:15px;font-weight:900}.pron-done-track{height:10px;width:min(420px,100%);border-radius:999px;background:#f1f5f9;border:1px solid #e2e8f0;overflow:hidden}.pron-done-fill{height:100%;width:0%;background:linear-gradient(90deg,#2563eb,#14b8a6);transition:width .6s ease}body.embedded-mode .pron-shell,body.fullscreen-embedded .pron-shell,body.presentation-mode .pron-shell{position:absolute!important;inset:0!important;overflow-y:auto!important;overflow-x:hidden!important;padding:10px 12px!important}@media(max-width:640px){.pron-shell{padding:10px}.pron-board{padding:14px;border-radius:18px}.pron-card{min-height:280px;padding:16px}.pron-image{width:130px;height:130px;max-height:130px}.pron-word{font-size:clamp(24px,8vw,34px)}.pron-actions{display:grid;grid-template-columns:1fr}.pron-btn{width:100%}}
</style>

<div class="pron-shell"><div class="pron-app"><section class="pron-board" id="pron-board"><div class="pron-header"><div class="pron-kicker">Activity <span id="pron-kicker-count">1 / <?php echo count($items); ?></span></div><h1 class="pron-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1><p class="pron-subtitle">Listen, repeat, and check your pronunciation.</p></div><div class="pron-progress"><div class="pron-track"><div class="pron-fill" id="pron-progress-fill"></div></div><div class="pron-count" id="pron-progress-count">1 / <?php echo count($items); ?></div></div><div class="pron-card"><div class="pron-image"><img id="pron-img" src="" alt="" style="display:none;"><div class="pron-placeholder" id="pron-placeholder">A</div></div><div class="pron-word" id="pron-word"></div><div class="pron-phonetic" id="pron-phonetic"></div><div class="pron-box pron-captured" id="pron-captured"></div><div class="pron-box pron-answer" id="pron-answer"></div><div class="pron-box pron-feedback" id="pron-feedback"></div></div><div class="pron-actions"><button type="button" class="pron-btn pron-blue" id="pron-listen">Listen</button><button type="button" class="pron-btn pron-teal" id="pron-speak">Speak</button><button type="button" class="pron-btn pron-fuchsia" id="pron-show">Show Answer</button><button type="button" class="pron-btn pron-teal" id="pron-next">Next</button></div></section><section class="pron-completed" id="pron-completed"><div class="pron-done-icon">OK</div><h2 class="pron-done-title" id="pron-completed-title">All Done!</h2><p class="pron-done-text" id="pron-completed-text">Great job practicing pronunciation.</p><p class="pron-score" id="pron-score-text"></p><div class="pron-done-track"><div class="pron-done-fill" id="pron-done-fill"></div></div><div class="pron-actions"><button type="button" class="pron-btn pron-teal" id="pron-restart">Restart</button><button type="button" class="pron-btn pron-blue" onclick="history.back()">Back</button></div></section></div></div>
<audio id="pron-win" src="../../hangman/assets/win.mp3" preload="auto"></audio><audio id="pron-lose" src="../../hangman/assets/lose.mp3" preload="auto"></audio>
<script>
document.addEventListener('DOMContentLoaded',function(){'use strict';var data=<?php echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;if(!Array.isArray(data))data=[];var activityTitle=<?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;var PRON_ACTIVITY_ID=<?php echo json_encode($activity['id'] ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;var PRON_RETURN_TO=<?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;var TOTAL=data.length,index=0,correctCount=0,checkedCards={},capturedText='',recognitionBusy=false,pronIsSpeaking=false,pronIsPaused=false,pronCurrentAudio=null,pronUtter=null;var els={board:document.getElementById('pron-board'),completed:document.getElementById('pron-completed'),img:document.getElementById('pron-img'),placeholder:document.getElementById('pron-placeholder'),word:document.getElementById('pron-word'),phonetic:document.getElementById('pron-phonetic'),captured:document.getElementById('pron-captured'),answer:document.getElementById('pron-answer'),feedback:document.getElementById('pron-feedback'),progressFill:document.getElementById('pron-progress-fill'),progressCount:document.getElementById('pron-progress-count'),kickerCount:document.getElementById('pron-kicker-count'),completedTitle:document.getElementById('pron-completed-title'),completedText:document.getElementById('pron-completed-text'),scoreText:document.getElementById('pron-score-text'),doneFill:document.getElementById('pron-done-fill'),win:document.getElementById('pron-win'),lose:document.getElementById('pron-lose')};var listenBtn=document.getElementById('pron-listen'),speakBtn=document.getElementById('pron-speak'),showBtn=document.getElementById('pron-show'),nextBtn=document.getElementById('pron-next'),restartBtn=document.getElementById('pron-restart');var recognition=null,SpeechRecognitionCtor=window.SpeechRecognition||window.webkitSpeechRecognition||null;if(SpeechRecognitionCtor){recognition=new SpeechRecognitionCtor();recognition.lang='en-US';recognition.interimResults=false;recognition.maxAlternatives=1;recognition.continuous=false}function normalizeText(text){return String(text||'').toLowerCase().trim().replace(/[.,!?;:'"\-]/g,'').replace(/\s+/g,' ')}function wordOverlapScore(a,b){var wa=a.split(' ').filter(Boolean),wb=b.split(' ').filter(Boolean);if(!wa.length||!wb.length)return 0;var matches=wa.filter(function(w){return wb.indexOf(w)!==-1}).length;return matches/Math.max(wa.length,wb.length)}function isMatch(said,expected){return said===expected||wordOverlapScore(said,expected)>=.8}function playSound(sound){try{sound.pause();sound.currentTime=0;sound.play()}catch(e){}}function getCurrentWord(){return String((data[index]&&data[index].en)||'').trim()}function getPlaceholder(word){return word?word.charAt(0).toUpperCase():'A'}function getPreferredVoice(){var voices=[];try{voices=speechSynthesis.getVoices()||[]}catch(e){voices=[]}var names=['Google US English','Microsoft Jenny','Microsoft Aria','Samantha','Alex','Daniel','Karen'];for(var i=0;i<names.length;i++){for(var j=0;j<voices.length;j++){var label=String((voices[j].name||'')+' '+(voices[j].voiceURI||'')).toLowerCase();if(String(voices[j].lang||'').toLowerCase().indexOf('en')===0&&label.indexOf(names[i].toLowerCase())!==-1)return voices[j]}}for(var k=0;k<voices.length;k++)if(String(voices[k].lang||'').toLowerCase()==='en-us')return voices[k];for(var m=0;m<voices.length;m++)if(String(voices[m].lang||'').toLowerCase().indexOf('en')===0)return voices[m];return null}function setListenButtonLabel(){listenBtn.textContent=pronIsPaused?'Resume':(pronIsSpeaking?'Pause':'Listen')}function speakCurrent(){if(!data[index])return;var item=data[index];if(item.audio){if(!pronCurrentAudio||pronCurrentAudio.getAttribute('data-src')!==item.audio){if(pronCurrentAudio)pronCurrentAudio.pause();pronCurrentAudio=new Audio(item.audio);pronCurrentAudio.setAttribute('data-src',item.audio);pronCurrentAudio.onended=function(){pronIsSpeaking=false;pronIsPaused=false;setListenButtonLabel()}}if(!pronCurrentAudio.paused){pronCurrentAudio.pause();pronIsSpeaking=true;pronIsPaused=true}else{pronCurrentAudio.play().then(function(){pronIsSpeaking=true;pronIsPaused=false;setListenButtonLabel()}).catch(function(){})}setListenButtonLabel();return}if(!window.speechSynthesis)return;if(speechSynthesis.speaking&&!speechSynthesis.paused){speechSynthesis.pause();pronIsSpeaking=true;pronIsPaused=true;setListenButtonLabel();return}if(speechSynthesis.paused||pronIsPaused){speechSynthesis.resume();pronIsSpeaking=true;pronIsPaused=false;setListenButtonLabel();return}var text=getCurrentWord();if(!text)return;speechSynthesis.cancel();pronUtter=new SpeechSynthesisUtterance(text);pronUtter.lang='en-US';pronUtter.rate=.82;pronUtter.pitch=1;pronUtter.volume=1;var voice=getPreferredVoice();if(voice)pronUtter.voice=voice;pronUtter.onstart=function(){pronIsSpeaking=true;pronIsPaused=false;setListenButtonLabel()};pronUtter.onend=function(){pronIsSpeaking=false;pronIsPaused=false;setListenButtonLabel()};speechSynthesis.speak(pronUtter)}function loadCard(){var item=data[index]||{},word=getCurrentWord()||'Listen and pronounce the word.';if(window.speechSynthesis)speechSynthesis.cancel();if(pronCurrentAudio)pronCurrentAudio.pause();pronIsSpeaking=false;pronIsPaused=false;capturedText='';setListenButtonLabel();els.word.textContent=word;els.captured.textContent='';els.captured.className='pron-box pron-captured';els.answer.textContent='Correct answer: '+word;els.answer.classList.remove('show');els.feedback.textContent='';els.feedback.className='pron-box pron-feedback';if(item.ph){els.phonetic.textContent=item.ph;els.phonetic.style.display='inline-flex'}else{els.phonetic.textContent='';els.phonetic.style.display='none'}if(item.img){els.img.src=item.img;els.img.alt=word;els.img.style.display='';els.placeholder.style.display='none'}else{els.img.removeAttribute('src');els.img.style.display='none';els.placeholder.textContent=getPlaceholder(word);els.placeholder.style.display=''}var countText=(index+1)+' / '+TOTAL;els.progressFill.style.width=Math.max(1,Math.round(((index+1)/TOTAL)*100))+'%';els.progressCount.textContent=countText;els.kickerCount.textContent=countText;nextBtn.textContent=index<TOTAL-1?'Next':'Finish'}function recordPronunciation(){if(!recognition||recognitionBusy){if(!recognition){els.feedback.textContent='Speech recognition is not available in this browser.';els.feedback.className='pron-box pron-feedback bad'}return}recognitionBusy=true;els.feedback.textContent='Listening...';els.feedback.className='pron-box pron-feedback';recognition.onresult=function(event){capturedText=String((event.results&&event.results[0]&&event.results[0][0]&&event.results[0][0].transcript)||'');recognitionBusy=false;checkAnswer()};recognition.onerror=function(){capturedText='';els.captured.textContent='Could not capture voice. Try again.';els.captured.className='pron-box pron-captured bad';els.feedback.textContent='Try Again';els.feedback.className='pron-box pron-feedback bad';recognitionBusy=false};recognition.onend=function(){recognitionBusy=false};try{recognition.start()}catch(e){recognitionBusy=false}}function checkAnswer(){var said=normalizeText(capturedText),expected=normalizeText(getCurrentWord());if(!said){els.feedback.textContent="You didn't record your voice.";els.feedback.className='pron-box pron-feedback bad';return}var correct=isMatch(said,expected);if(correct){els.captured.textContent='Good: '+getCurrentWord();els.captured.className='pron-box pron-captured ok';els.feedback.textContent='';playSound(els.win)}else{els.captured.textContent='Try again';els.captured.className='pron-box pron-captured bad';els.feedback.textContent='';playSound(els.lose)}if(correct&&!checkedCards[index]){checkedCards[index]=true;correctCount++}else if(!correct&&!checkedCards[index]){checkedCards[index]=false}}function showAnswer(){var lines=[];if(capturedText)lines.push('You said: '+capturedText);lines.push('Correct: '+getCurrentWord());els.answer.textContent=lines.join(' -> ');els.answer.classList.add('show')}function persistScoreSilently(targetUrl){if(!targetUrl)return Promise.resolve(false);return fetch(targetUrl,{method:'GET',credentials:'same-origin',cache:'no-store'}).then(function(response){return!!(response&&response.ok)}).catch(function(){return false})}async function showCompleted(){els.board.style.display='none';els.completed.classList.add('active');setTimeout(function(){els.doneFill.style.width='100%'},120);playSound(els.win);var pct=TOTAL>0?Math.round((correctCount/TOTAL)*100):0,errors=Math.max(0,TOTAL-correctCount);els.completedTitle.textContent='All Done!';els.completedText.textContent="You've completed "+(activityTitle||'this activity')+'. Great job practicing.';els.scoreText.textContent='Score: '+correctCount+' / '+TOTAL+' ('+pct+'%)';if(PRON_ACTIVITY_ID&&PRON_RETURN_TO){var joiner=PRON_RETURN_TO.indexOf('?')!==-1?'&':'?';var saveUrl=PRON_RETURN_TO+joiner+'activity_percent='+pct+'&activity_errors='+errors+'&activity_total='+TOTAL+'&activity_id='+encodeURIComponent(PRON_ACTIVITY_ID)+'&activity_type=pronunciation';var ok=await persistScoreSilently(saveUrl);if(!ok)window.location.href=saveUrl}}function goNext(){if(index<TOTAL-1){index++;loadCard()}else{showCompleted()}}function restart(){correctCount=0;checkedCards={};index=0;els.doneFill.style.width='0%';els.completed.classList.remove('active');els.board.style.display='';loadCard()}listenBtn.addEventListener('click',speakCurrent);speakBtn.addEventListener('click',recordPronunciation);showBtn.addEventListener('click',showAnswer);nextBtn.addEventListener('click',goNext);restartBtn.addEventListener('click',restart);loadCard()});
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔊', $content);
