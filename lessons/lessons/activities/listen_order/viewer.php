<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = trim((string)($_GET['id']   ?? ''));
$unit       = trim((string)($_GET['unit'] ?? ''));
if ($activityId === '' && $unit === '') die('Activity not specified');

function lo_viewer_resolve_unit(PDO $pdo, string $id): string {
    if ($id === '') return '';
    $st = $pdo->prepare("SELECT unit_id FROM activities WHERE id=:id LIMIT 1");
    $st->execute(['id' => $id]); $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? (string)$r['unit_id'] : '';
}

function lo_viewer_normalize(mixed $raw): array {
    $def = ['title' => 'Listen & Order', 'instructions' => '', 'blocks' => []];
    if (!$raw) return $def;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $def;
    $title = trim((string)($d['title'] ?? ''));
    $instr = trim((string)($d['instructions'] ?? ''));
    $src   = isset($d['blocks']) && is_array($d['blocks']) ? $d['blocks'] : $d;
    $out   = [];
    foreach ($src as $b) {
        if (!is_array($b)) continue;
        $sentence  = trim((string)($b['sentence']  ?? ''));
        $video_url = trim((string)($b['video_url'] ?? ''));
        $images = [];
        foreach ((array)($b['images'] ?? []) as $img) { $u=trim((string)$img); if($u!=='') $images[]=$u; }
        $dzImages = [];
        foreach ((array)($b['dropZoneImages'] ?? []) as $dzi) {
            if (!is_array($dzi)) continue;
            $dzSrc = trim((string)($dzi['src'] ?? '')); if($dzSrc==='') continue;
            $dzImages[] = ['id'=>trim((string)($dzi['id']??uniqid('dzi_'))),'src'=>$dzSrc,
                'left'=>(int)($dzi['left']??0),'top'=>(int)($dzi['top']??0),
                'width'=>max(60,min(800,(int)($dzi['width']??180)))];
        }
        if ($sentence==='' && $video_url==='' && empty($images)) continue;
        $out[] = ['sentence'=>$sentence,'video_url'=>$video_url,'images'=>$images,'dropZoneImages'=>$dzImages];
    }
    return ['title'=>$title!==''?$title:'Listen & Order','instructions'=>$instr,'blocks'=>$out];
}

function lo_viewer_load(PDO $pdo, string $activityId, string $unit): array {
    $fallback = ['title'=>'Listen & Order','instructions'=>'','blocks'=>[]];
    $row = null;
    if ($activityId !== '') {
        $st = $pdo->prepare("SELECT data FROM activities WHERE id=:id AND type IN ('listen_order','listen_and_order','listenorder') LIMIT 1");
        $st->execute(['id'=>$activityId]); $row=$st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $st = $pdo->prepare("SELECT data FROM activities WHERE unit_id=:unit AND type IN ('listen_order','listen_and_order','listenorder') ORDER BY id ASC LIMIT 1");
        $st->execute(['unit'=>$unit]); $row=$st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $st = $pdo->prepare("SELECT data FROM activities WHERE unit=:unit AND type IN ('listen_order','listen_and_order','listenorder') ORDER BY id ASC LIMIT 1");
        $st->execute(['unit'=>$unit]); $row=$st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;
    return lo_viewer_normalize($row['data'] ?? null);
}

if ($unit==='' && $activityId!=='') $unit = lo_viewer_resolve_unit($pdo, $activityId);
$activity     = lo_viewer_load($pdo, $activityId, $unit);
$viewerTitle  = (string)($activity['title']        ?? 'Listen & Order');
$viewerInstr  = (string)($activity['instructions'] ?? '');
$blocks       = is_array($activity['blocks'] ?? null) ? $activity['blocks'] : [];
$returnTo     = trim((string)($_GET['return_to'] ?? ''));

if (count($blocks) === 0) die('No activities for this unit');

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html,body{width:100%;min-height:100%}
body{margin:0!important;padding:0!important;background:#fff!important;font-family:'Nunito',sans-serif!important}
.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;min-height:100vh;display:flex!important;flex-direction:column!important;background:transparent!important}
.top-row{display:none!important}
.viewer-content{flex:1!important;display:flex!important;flex-direction:column!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important}
.lo-shell{width:100%;min-height:calc(100vh - 90px);padding:clamp(14px,2.5vw,34px);display:flex;align-items:flex-start;justify-content:center;background:#fff;overflow:visible}
.lo-app{width:min(980px,100%);margin:0 auto;display:flex;flex-direction:column}
.lo-hero{text-align:center;margin-bottom:clamp(14px,2vw,22px)}
.lo-kicker{display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;padding:7px 14px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}
.lo-title{margin:0;font-family:'Fredoka',sans-serif;font-size:clamp(30px,5.5vw,58px);line-height:1.03;color:#F97316;font-weight:700}
.lo-subtitle{margin:8px 0 0;color:#9B94BE;font-size:clamp(13px,1.8vw,17px);font-weight:800}
.lo-board{width:min(920px,100%);margin:0 auto;background:#fff;border:1px solid #F0EEF8;border-radius:28px;padding:clamp(18px,2.8vw,30px);box-shadow:0 8px 40px rgba(127,119,221,.13);overflow:visible}
/* audio player */
.lo-audio-player{background:#FAFAFE;border:1px solid #EDE9FA;border-radius:18px;padding:20px 22px;display:flex;align-items:center;gap:14px;margin-bottom:18px}
.lo-audio-icon{width:48px;height:48px;border-radius:50%;background:#7F77DD;box-shadow:0 6px 20px rgba(127,119,221,.28);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#fff;font-size:20px}
.lo-audio-info{flex:1;min-width:0}
.lo-audio-name{font-family:'Fredoka',sans-serif;font-weight:700;color:#271B5D;font-size:16px;margin-bottom:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lo-audio-track{height:7px;background:#E4E1F8;border-radius:999px;overflow:hidden}
.lo-audio-fill{height:100%;width:0%;background:linear-gradient(90deg,#F97316,#7F77DD);border-radius:999px;transition:width .3s linear}
.lo-audio-time{font-family:'Nunito',sans-serif;font-weight:900;color:#9B94BE;font-size:12px;margin-top:4px}
.lo-audio-play{width:44px;height:44px;border-radius:50%;background:#F97316;box-shadow:0 6px 18px rgba(249,115,22,.28);display:flex;align-items:center;justify-content:center;border:0;cursor:pointer;flex-shrink:0;color:#fff;font-size:18px;transition:transform .12s}
.lo-audio-play:hover{transform:scale(1.07)}
/* video player */
.lo-video-player{border-radius:18px;overflow:hidden;background:#000;margin-bottom:18px}
.lo-video-player video{width:100%;display:block;max-height:340px;object-fit:contain}
/* image grid */
.lo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(clamp(130px,16vw,190px),1fr));gap:16px;justify-content:center;margin-bottom:14px}
.lo-card{width:100%;aspect-ratio:1/1;border-radius:18px;border:2px solid #EDE9FA;background:#fff;box-shadow:0 4px 14px rgba(127,119,221,.10);cursor:pointer;position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;transition:border-color .15s,background .15s,box-shadow .15s,transform .15s;overflow:hidden;padding:4px 4px 12px}
.lo-card:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(127,119,221,.16)}
.lo-card img{width:100%;height:100%;object-fit:cover;display:block;border-radius:12px}
.lo-card-badge{position:absolute;top:8px;left:8px;width:26px;height:26px;border-radius:50%;background:#EEEDFE;color:#534AB7;font-size:12px;font-family:'Nunito',sans-serif;font-weight:900;display:flex;align-items:center;justify-content:center;line-height:1;transition:background .15s,color .15s;z-index:2}
.lo-card.selected{border-color:#F97316;background:#FFF8F4;box-shadow:0 0 0 3px rgba(249,115,22,.18);transform:translateY(-4px) scale(1.04)}
.lo-card.selected .lo-card-badge{background:#F97316;color:#fff}
.lo-card.correct{border-color:#1D9E75;background:#F0FDF9}
.lo-card.correct .lo-card-badge{background:#1D9E75;color:#fff}
.lo-card.wrong{border-color:#E24B4A;background:#FFF5F5}
.lo-card.wrong .lo-card-badge{background:#E24B4A;color:#fff}
.lo-hint{text-align:center;margin-bottom:14px;font-size:13px;min-height:26px}
.lo-hint-neutral{display:inline-block;background:#FFF0E6;color:#C2580A;border-radius:999px;padding:3px 10px;font-weight:900}
.lo-hint-selected{display:inline-block;background:#EEEDFE;color:#534AB7;border-radius:999px;padding:3px 10px;font-weight:900}
.lo-scores{display:flex;gap:10px;justify-content:center;margin-bottom:16px}
.lo-score-card{flex:1;max-width:110px;background:#FAFAFE;border:1px solid #EDE9FA;border-radius:14px;padding:12px;text-align:center}
.lo-score-num{font-family:'Fredoka',sans-serif;font-weight:700;font-size:28px;line-height:1.1}
.lo-score-num.green{color:#1D9E75}.lo-score-num.orange{color:#F97316}.lo-score-num.purple{color:#7F77DD}
.lo-score-label{font-family:'Nunito',sans-serif;font-weight:900;font-size:10px;text-transform:uppercase;color:#9B94BE;margin-top:2px}
#lo-feedback{font-size:15px;font-weight:900;min-height:20px;text-align:center;margin-bottom:8px}
.good{color:#1D9E75}.bad{color:#E24B4A}
.lo-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;padding-top:16px;border-top:1px solid #F0EEF8}
.lo-btn{padding:11px 24px;border-radius:999px;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;cursor:pointer;border:0;min-width:clamp(90px,12vw,120px);display:inline-flex;align-items:center;justify-content:center;transition:transform .12s,filter .12s}
.lo-btn:hover{transform:translateY(-2px);filter:brightness(1.07)}
.lo-btn:disabled{opacity:.55;cursor:not-allowed;transform:none;filter:none}
.lo-btn-reset{background:#fff;color:#534AB7;border:1.5px solid #EDE9FA}
.lo-btn-show{background:#7F77DD;color:#fff;box-shadow:0 6px 18px rgba(127,119,221,.22)}
.lo-btn-check{background:#F97316;color:#fff;box-shadow:0 6px 18px rgba(249,115,22,.22)}
.lo-btn-next{background:#7F77DD;color:#fff;box-shadow:0 6px 18px rgba(127,119,221,.22)}
#lo-status{text-align:center;margin-top:10px;font-size:13px;color:#9B94BE;font-weight:900}
.lo-completed{display:none;background:#fff;border:1px solid #EDE9FA;border-radius:28px;box-shadow:0 12px 36px rgba(127,119,221,.13);min-height:clamp(300px,42vh,430px);flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:clamp(28px,5vw,48px) 24px;gap:12px;width:min(860px,100%);margin:0 auto}
.lo-completed.active{display:flex}
.lo-done-icon{font-size:64px;line-height:1}
.lo-done-title{margin:0;font-family:'Fredoka',sans-serif;font-size:clamp(30px,5.5vw,58px);color:#F97316;font-weight:700}
.lo-completed .lo-done-title{display:none}
.lo-done-text{margin:0;max-width:520px;color:#9B94BE;font-size:clamp(13px,1.8vw,17px);font-weight:800;line-height:1.5}
.lo-done-score{margin:0;color:#534AB7;font-size:15px;font-weight:900}
.lo-done-track{height:12px;width:min(420px,100%);margin:4px auto;border-radius:999px;background:#F4F2FD;border:1px solid #E4E1F8;overflow:hidden}
.lo-done-fill{height:100%;width:0%;background:linear-gradient(90deg,#F97316,#7F77DD);transition:width .8s ease}
@media(max-width:640px){
    .lo-shell{padding:12px}.lo-board{border-radius:22px;padding:14px}
    .lo-grid{grid-template-columns:repeat(auto-fit,minmax(96px,1fr));gap:10px}
    .lo-card{border-radius:14px;padding:3px 3px 10px}
    .lo-card-badge{top:6px;left:6px;width:22px;height:22px;font-size:11px}
    .lo-actions{flex-direction:column;align-items:center}.lo-btn{width:100%;max-width:280px}
    .lo-audio-player{flex-wrap:wrap;gap:10px}
}
</style>

<div class="lo-shell"><div class="lo-app">
    <div class="lo-hero">
        <div class="lo-kicker">Activity <span id="lo-kicker-count">1 / <?= count($blocks) ?></span></div>
        <h1 class="lo-title"><?= htmlspecialchars($viewerTitle,ENT_QUOTES,'UTF-8') ?></h1>
        <p class="lo-subtitle"><?= $viewerInstr!=='' ? htmlspecialchars($viewerInstr,ENT_QUOTES,'UTF-8') : 'Listen and tap the images in the correct order.' ?></p>
    </div>

    <div class="lo-board" id="lo-board">
        <!-- media area: only one is shown at a time, switched by JS based on block data -->
        <div id="lo-audio-player" class="lo-audio-player" style="display:none">
            <div class="lo-audio-icon">🎵</div>
            <div class="lo-audio-info">
                <div class="lo-audio-name" id="lo-audio-name"><?= htmlspecialchars($viewerTitle,ENT_QUOTES,'UTF-8') ?></div>
                <div class="lo-audio-track"><div class="lo-audio-fill" id="lo-audio-fill"></div></div>
                <div class="lo-audio-time" id="lo-audio-time">0:00</div>
            </div>
            <button type="button" class="lo-audio-play" id="lo-listen-btn">▶</button>
        </div>

        <div id="lo-video-player" class="lo-video-player" style="display:none">
            <video id="lo-video-el" controls preload="metadata"></video>
        </div>

        <div id="lo-grid" class="lo-grid"></div>

        <div id="lo-hint" class="lo-hint">
            <span class="lo-hint-neutral">Tap an image to select it, then tap another to swap</span>
        </div>

        <div id="lo-scores" class="lo-scores" style="display:none">
            <div class="lo-score-card"><div class="lo-score-num green" id="lo-sc-correct">0</div><div class="lo-score-label">Correct</div></div>
            <div class="lo-score-card"><div class="lo-score-num orange" id="lo-sc-wrong">0</div><div class="lo-score-label">Wrong</div></div>
            <div class="lo-score-card"><div class="lo-score-num purple" id="lo-sc-pct">0%</div><div class="lo-score-label">Score</div></div>
        </div>

        <div id="lo-feedback"></div>

        <div class="lo-actions" id="lo-actions">
            <button type="button" class="lo-btn lo-btn-reset" id="lo-btn-reset">Reset</button>
            <button type="button" class="lo-btn lo-btn-show"  id="lo-btn-show">Show Answer</button>
            <button type="button" class="lo-btn lo-btn-check" id="lo-btn-check">Check</button>
            <button type="button" class="lo-btn lo-btn-next"  id="lo-btn-next">Next</button>
        </div>
        <div id="lo-status"></div>
    </div>

    <div id="lo-completed" class="lo-completed">
        <div class="lo-done-icon">✅</div>
        <h2 class="lo-done-title" id="lo-done-title"></h2>
        <p class="lo-done-text" id="lo-done-text"></p>
        <p class="lo-done-score" id="lo-done-score"></p>
        <div class="lo-done-track"><div class="lo-done-fill" id="lo-done-fill"></div></div>
        <div class="lo-actions">
            <button type="button" class="lo-btn lo-btn-check" onclick="loRestart()">Restart</button>
            <button type="button" class="lo-btn lo-btn-next"  onclick="history.back()">Back</button>
        </div>
    </div>
</div></div>

<audio id="snd-win"  src="../../hangman/assets/win.mp3"     preload="auto"></audio>
<audio id="snd-lose" src="../../hangman/assets/lose.mp3"    preload="auto"></audio>
<audio id="snd-done" src="../../hangman/assets/win (1).mp3" preload="auto"></audio>
<audio id="lo-tts-audio" preload="none"></audio>

<script>
var SRC_BLOCKS   = <?= json_encode($blocks,      JSON_UNESCAPED_UNICODE) ?>;
var ACTIVITY_TTL = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
var ACT_ID       = <?= json_encode($activityId ?? '', JSON_UNESCAPED_UNICODE) ?>;
var RETURN_TO    = <?= json_encode($returnTo,       JSON_UNESCAPED_UNICODE) ?>;

// pick subset
var loParams  = new URLSearchParams(window.location.search||'');
var loPick    = parseInt(loParams.get('lo_pick')||'',10);
var loRatio   = Number(loParams.get('lo_ratio')||'0.75');
if (!Number.isFinite(loRatio)||loRatio<=0) loRatio=0.75; if(loRatio>1) loRatio=1;
var loCount   = Number.isFinite(loPick)&&loPick>0 ? Math.min(loPick,SRC_BLOCKS.length) : Math.max(1,Math.ceil(SRC_BLOCKS.length*loRatio));
var BLOCKS    = SRC_BLOCKS.length>1 ? shuffle(SRC_BLOCKS).slice(0,loCount) : SRC_BLOCKS.slice();

// voice cache
var _voices=[];
function _loadVoices(){ if('speechSynthesis' in window) _voices=speechSynthesis.getVoices()||[]; }
if ('speechSynthesis' in window){ _loadVoices(); speechSynthesis.addEventListener('voiceschanged',_loadVoices); }
function bestVoice(){
    if(!_voices.length) _loadVoices();
    var en=_voices.filter(function(v){ var l=String(v.lang||'').toLowerCase(); return l==='en-us'||l.startsWith('en-'); });
    if(!en.length) en=_voices; if(!en.length) return null;
    var names=['samantha','daniel','karen','alex','aria','jenny','emma','ava','allison'];
    for(var n=0;n<names.length;n++) for(var v=0;v<en.length;v++) if((en[v].name+' '+en[v].voiceURI).toLowerCase().indexOf(names[n])!==-1) return en[v];
    for(var v2=0;v2<en.length;v2++) if(!en[v2].localService) return en[v2];
    return en[0];
}

// state
var idx=0, correct=[], userOrder=[], selIdx=null, curSentence='', curAudioUrl='';
var speaking=false, paused=false, utter=null, spOffset=0, spSrc='', spSegStart=0;
var done=false, blockDone=false, totalBlocks=BLOCKS.length;
var totalImages=Math.max(1,BLOCKS.reduce(function(sum,b){
    var imgs = Array.isArray(b&&b.images) ? b.images.length : 0;
    return sum + imgs;
},0));
var correctImagesCount=0;
var blockScored={};
var attempts={}, checked={};

// DOM
var gridEl    = document.getElementById('lo-grid');
var feedEl    = document.getElementById('lo-feedback');
var statusEl  = document.getElementById('lo-status');
var kickerEl  = document.getElementById('lo-kicker-count');
var hintEl    = document.getElementById('lo-hint');
var scoresEl  = document.getElementById('lo-scores');
var scCorEl   = document.getElementById('lo-sc-correct');
var scWrEl    = document.getElementById('lo-sc-wrong');
var scPctEl   = document.getElementById('lo-sc-pct');
var compEl    = document.getElementById('lo-completed');
var boardEl   = document.getElementById('lo-board');
var actionsEl = document.getElementById('lo-actions');
var sndWin    = document.getElementById('snd-win');
var sndLose   = document.getElementById('snd-lose');
var sndDone   = document.getElementById('snd-done');
var listenBtn = document.getElementById('lo-listen-btn');
var fillEl    = document.getElementById('lo-audio-fill');
var nameEl    = document.getElementById('lo-audio-name');
var timeEl    = document.getElementById('lo-audio-time');
var audioPlEl = document.getElementById('lo-audio-player');
var videoPEl  = document.getElementById('lo-video-player');
var videoEl   = document.getElementById('lo-video-el');
var ttsAudio  = document.getElementById('lo-tts-audio');
var doneTitleEl = document.getElementById('lo-done-title');
var doneTextEl  = document.getElementById('lo-done-text');
var doneScoreEl = document.getElementById('lo-done-score');
var doneFillEl  = document.getElementById('lo-done-fill');

if(doneTitleEl) doneTitleEl.textContent = ACTIVITY_TTL||'Listen & Order';
if(doneTextEl)  doneTextEl.textContent  = "You've completed "+(ACTIVITY_TTL||'this activity')+'. Great job!';

function shuffle(a){ return a.slice().sort(function(){ return Math.random()-.5; }); }
function playSound(a){ try{ a.pause(); a.currentTime=0; a.play(); }catch(e){} }

function setListenState(s){
    if(!listenBtn) return;
    listenBtn.textContent = s==='speaking'?'⏸':'▶';
    if(fillEl) fillEl.style.width = s==='speaking'?'50%':'0%';
}

// TTS / real audio
function fmtTime(s){ var m=Math.floor(s/60)|0; return m+':'+(('0'+(Math.floor(s)%60)).slice(-2)); }

if(ttsAudio){
    ttsAudio.addEventListener('timeupdate',function(){
        if(!ttsAudio.duration) return;
        var pct=ttsAudio.currentTime/ttsAudio.duration*100;
        if(fillEl) fillEl.style.width=pct+'%';
        if(timeEl) timeEl.textContent=fmtTime(ttsAudio.currentTime);
    });
    ttsAudio.addEventListener('ended',function(){
        setListenState('idle');
        if(fillEl) fillEl.style.width='0%';
        if(timeEl) timeEl.textContent='0:00';
    });
    ttsAudio.addEventListener('pause', function(){ setListenState('idle'); });
    ttsAudio.addEventListener('play',  function(){ setListenState('speaking'); });
}

function playAudio(){
    if(done) return;
    if(curAudioUrl && ttsAudio){
        if(!ttsAudio.paused){ ttsAudio.pause(); return; }
        ttsAudio.play().catch(function(){});
        return;
    }
    if(!curSentence) return;
    if(speechSynthesis.paused||paused){ speechSynthesis.resume(); speaking=true; paused=false; setListenState('speaking');
        setTimeout(function(){ if(!speechSynthesis.speaking&&spOffset<spSrc.length) doSpeak(); },80); return; }
    if(speechSynthesis.speaking&&!speechSynthesis.paused){ speechSynthesis.pause(); paused=true; setListenState('idle'); return; }
    speechSynthesis.cancel(); spSrc=curSentence; spOffset=0; doSpeak();
}
function doSpeak(){
    var rem=spSrc.slice(Math.max(0,spOffset)); if(!rem.trim()){ speaking=false; paused=false; spOffset=0; return; }
    speechSynthesis.cancel(); spSegStart=Math.max(0,spOffset);
    utter=new SpeechSynthesisUtterance(rem); utter.lang='en-US'; utter.rate=0.9; utter.pitch=1; utter.volume=1;
    var bv=bestVoice(); if(bv) utter.voice=bv;
    utter.onstart  =function(){ speaking=true; paused=false; setListenState('speaking'); };
    utter.onpause  =function(){ paused=true; speaking=true; setListenState('idle'); };
    utter.onresume =function(){ paused=false; speaking=true; setListenState('speaking'); };
    utter.onboundary=function(e){ if(typeof e.charIndex==='number') spOffset=Math.max(spSegStart,Math.min(spSrc.length,spSegStart+e.charIndex)); };
    utter.onend  =function(){ if(paused) return; speaking=false; paused=false; spOffset=0; setListenState('idle'); };
    utter.onerror=function(){ speaking=false; paused=false; spOffset=0; setListenState('idle'); };
    speechSynthesis.speak(utter);
}

function updateHint(){
    if(!hintEl) return;
    hintEl.innerHTML = selIdx===null
        ? '<span class="lo-hint-neutral">Tap an image to select it, then tap another to swap</span>'
        : '<span class="lo-hint-selected">Position '+(selIdx+1)+' selected — tap another to swap</span>';
}

function renderGrid(states){
    if(!gridEl) return; gridEl.innerHTML='';
    userOrder.forEach(function(src,i){
        var card=document.createElement('div'); card.className='lo-card';
        if(states){ if(states[i]==='correct') card.classList.add('correct'); else if(states[i]==='wrong') card.classList.add('wrong'); }
        else if(selIdx===i) card.classList.add('selected');
        var badge=document.createElement('div'); badge.className='lo-card-badge'; badge.textContent=String(i+1); card.appendChild(badge);
        var img=document.createElement('img'); img.src=src; img.alt=''; img.draggable=false; card.appendChild(img);
        if(!blockDone&&!states)(function(ii){ card.addEventListener('click',function(){ onTap(ii); }); })(i);
        gridEl.appendChild(card);
    });
}

function lockBlockScore(score){
    if (blockScored[idx]) return;
    var blockTotal = Array.isArray(correct) ? correct.length : 0;
    var safe = Math.max(0, Math.min(blockTotal, Number(score) || 0));
    blockScored[idx] = true;
    correctImagesCount += safe;
}

function onTap(i){
    if(blockDone||done) return;
    if(selIdx===null){ selIdx=i; }
    else if(selIdx===i){ selIdx=null; }
    else{ var t=userOrder[selIdx]; userOrder[selIdx]=userOrder[i]; userOrder[i]=t; selIdx=null;
        if(scoresEl) scoresEl.style.display='none'; feedEl.textContent=''; feedEl.className=''; }
    renderGrid(null); updateHint();
}

function checkAnswer(){
    if(done||checked[idx]) return;
    var states=[],ok=0;
    for(var i=0;i<correct.length;i++){ if(userOrder[i]===correct[i]){states.push('correct');ok++;}else{states.push('wrong');} }
    var wr=correct.length-ok, pct=correct.length>0?Math.round(ok/correct.length*100):0, all=ok===correct.length;
    if(scCorEl) scCorEl.textContent=String(ok);
    if(scWrEl)  scWrEl.textContent=String(wr);
    if(scPctEl) scPctEl.textContent=pct+'%';
    if(scoresEl) scoresEl.style.display='flex';
    var att=(attempts[idx]||0)+1; attempts[idx]=att;
    if(all){ feedEl.textContent='✔ Correct!'; feedEl.className='good'; playSound(sndWin); checked[idx]=true; blockDone=true; lockBlockScore(ok); renderGrid(states); }
    else if(att>=2){ feedEl.textContent='✘ Wrong — correct order shown below'; feedEl.className='bad'; playSound(sndLose); checked[idx]=true; blockDone=true; lockBlockScore(ok); renderGrid(states); }
    else{ feedEl.textContent='✘ Not quite — try again (1/2)'; feedEl.className='bad'; playSound(sndLose); renderGrid(states); }
}

function showAnswer(){ userOrder=correct.slice(); selIdx=null; feedEl.textContent='👁 Correct order shown'; feedEl.className='good'; blockDone=true; checked[idx]=true; lockBlockScore(0); if(scoresEl) scoresEl.style.display='none'; renderGrid(null); updateHint(); }
function resetBlock(){ userOrder=shuffle(correct); selIdx=null; blockDone=false; feedEl.textContent=''; feedEl.className=''; if(scoresEl) scoresEl.style.display='none'; renderGrid(null); updateHint(); }
function updateStatus(){ var t=(idx+1)+' / '+totalBlocks; if(statusEl) statusEl.textContent=t; if(kickerEl) kickerEl.textContent=t; }

function loadBlock(){
    if(window.speechSynthesis) speechSynthesis.cancel();
    if(ttsAudio){ ttsAudio.pause(); ttsAudio.src=''; }
    speaking=false; paused=false; spOffset=0; spSrc=''; spSegStart=0;
    done=false; blockDone=false; selIdx=null;
    if(compEl)    compEl.classList.remove('active');
    if(boardEl)   boardEl.style.display='';
    if(actionsEl) actionsEl.style.display='';
    if(scoresEl)  scoresEl.style.display='none';
    feedEl.textContent=''; feedEl.className='';
    setListenState('idle');

    var block    = BLOCKS[idx]||{};
    curSentence  = typeof block.sentence==='string' ? block.sentence : '';
    curAudioUrl  = typeof block.audio_url==='string' ? block.audio_url.trim() : '';
    spSrc        = curSentence;
    correct      = Array.isArray(block.images) ? block.images.slice() : [];
    userOrder    = shuffle(correct);
    var videoUrl = typeof block.video_url==='string' ? block.video_url.trim() : '';

    // Load ElevenLabs audio element if audio_url is present
    if(ttsAudio && curAudioUrl){
        ttsAudio.src = curAudioUrl;
        ttsAudio.load();
        if(fillEl) fillEl.style.width='0%';
        if(timeEl) timeEl.textContent='0:00';
    }

    // AUTO: show video player if video_url exists, otherwise audio player
    if(videoUrl){
        if(videoEl){ videoEl.src=videoUrl; videoEl.load(); }
        if(videoPEl) videoPEl.style.display='';
        if(audioPlEl) audioPlEl.style.display='none';
    } else {
        if(videoEl){ videoEl.src=''; }
        if(videoPEl) videoPEl.style.display='none';
        if(audioPlEl) audioPlEl.style.display = (curAudioUrl||curSentence) ? '' : 'none';
        if(nameEl) nameEl.textContent = curSentence || ACTIVITY_TTL || 'Listen & Order';
    }

    updateStatus(); renderGrid(null); updateHint();
}

async function showCompleted(){
    done=true; blockDone=true; feedEl.textContent=''; feedEl.className='';
    if(boardEl) boardEl.style.display='none';
    if(compEl)  compEl.classList.add('active');
    setTimeout(function(){ if(doneFillEl) doneFillEl.style.width='100%'; },120);
    playSound(sndDone);
    var pct=totalImages>0?Math.round(correctImagesCount/totalImages*100):0;
    var err=Math.max(0,totalImages-correctImagesCount);
    if(doneScoreEl) doneScoreEl.textContent='Score: '+correctImagesCount+' / '+totalImages+' ('+pct+'%)';
    if(ACT_ID&&RETURN_TO){
        var j=RETURN_TO.indexOf('?')!==-1?'&':'?';
        var url=RETURN_TO+j+'activity_percent='+pct+'&activity_errors='+err+'&activity_total='+totalImages+'&activity_id='+encodeURIComponent(ACT_ID)+'&activity_type=listen_order';
        var ok=await fetch(url,{method:'GET',credentials:'same-origin',cache:'no-store'}).then(function(r){return !!(r&&r.ok);}).catch(function(){ return false; });
        if(!ok){ try{ if(window.top&&window.top!==window.self){ window.top.location.href=url; return; } }catch(e){} window.location.href=url; }
    }
}

function nextBlock(){
    if(blockDone||checked[idx]){ if(idx>=BLOCKS.length-1){ showCompleted(); return; } idx++; loadBlock(); }
    else{ feedEl.textContent='Check your answer first.'; feedEl.className='bad'; }
}

function loRestart(){
    idx=0; totalBlocks=BLOCKS.length; correctImagesCount=0; blockScored={}; attempts={}; checked={};
    if(doneFillEl) doneFillEl.style.width='0%';
    if(compEl) compEl.classList.remove('active');
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
$content=ob_get_clean();
render_activity_viewer($viewerTitle,'🎧',$content);
