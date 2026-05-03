<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])   ? trim((string)$_GET['id'])   : '';
$unit       = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';

if ($activityId === '' && $unit === '') die('Activity not specified');

function qa_columns(PDO $pdo): array {
    static $c = null;
    if (is_array($c)) return $c;
    $c = array();
    $s = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='activities'");
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { if (isset($r['column_name'])) $c[] = (string)$r['column_name']; }
    return $c;
}

function qa_unit(PDO $pdo, string $id): string {
    if ($id==='') return '';
    $cols = qa_columns($pdo);
    $col  = in_array('unit_id',$cols,true)?'unit_id':(in_array('unit',$cols,true)?'unit':'');
    if ($col==='') return '';
    $s = $pdo->prepare("SELECT {$col} FROM activities WHERE id=:id LIMIT 1");
    $s->execute(array('id'=>$id));
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ? (string)($r[$col]??'') : '';
}

function qa_load(PDO $pdo, string $unit, string $id): array {
    $empty = array('title'=>'Questions & Answers','cards'=>array());
    $cols  = qa_columns($pdo);
    $flds  = array('id');
    if (in_array('data',$cols,true))         $flds[] = 'data';
    if (in_array('content_json',$cols,true)) $flds[] = 'content_json';
    if (in_array('title',$cols,true))        $flds[] = 'title';
    if (in_array('name',$cols,true))         $flds[] = 'name';
    $sel = implode(', ', $flds);
    $row = null;
    if ($id!=='') {
        $s = $pdo->prepare("SELECT {$sel} FROM activities WHERE id=:id AND type='question_answer' LIMIT 1");
        $s->execute(array('id'=>$id)); $row = $s->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit_id',$cols,true)) {
        $s = $pdo->prepare("SELECT {$sel} FROM activities WHERE unit_id=:u AND type='question_answer' ORDER BY id ASC LIMIT 1");
        $s->execute(array('u'=>$unit)); $row = $s->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $empty;
    $raw  = isset($row['data']) ? $row['data'] : (isset($row['content_json']) ? $row['content_json'] : null);
    $data = is_string($raw) ? json_decode($raw,true) : array();
    if (!is_array($data)) $data = array();
    $cardsRaw = isset($data['cards'])&&is_array($data['cards']) ? $data['cards'] : $data;
    $cards = array();
    foreach ($cardsRaw as $item) {
        if (!is_array($item)) continue;
        $cards[] = array(
            'question' => isset($item['question']) ? trim((string)$item['question']) : '',
            'answer'   => isset($item['answer'])   ? trim((string)$item['answer'])   : '',
        );
    }
    $title = '';
    if (isset($row['title'])&&trim((string)$row['title'])!=='') $title=trim((string)$row['title']);
    if ($title===''&&isset($row['name'])&&trim((string)$row['name'])!=='') $title=trim((string)$row['name']);
    if ($title===''&&isset($data['title'])) $title=trim((string)$data['title']);
    if ($title==='') $title='Questions & Answers';
    return array('title'=>$title,'cards'=>$cards);
}

if ($unit===''&&$activityId!=='') $unit = qa_unit($pdo,$activityId);
$activity    = qa_load($pdo,$unit,$activityId);
$cards       = $activity['cards'];
$viewerTitle = $activity['title'];
if (empty($cards)) die('No questions found');

ob_start();
?>
<style>
:root{
    --p:#7F77DD;--pd:#534AB7;--pl:#EEEDFE;--pb:#AFA9EC;
    --t400:#1D9E75;--t800:#085041;
    --green:#16a34a;--gold:#f59e0b;
}

/* ── use .flashcards-wrap so template expands it in presentation-mode ── */
.flashcards-wrap {
    display: flex;
    flex-direction: column;
    width: 100%;
    height: 100%;
    min-height: 100vh;
    background: #f0faf6;
    font-family: 'Nunito','Segoe UI',sans-serif;
    overflow: hidden;
}
body.presentation-mode   .flashcards-wrap,
body.fullscreen-embedded .flashcards-wrap,
body.embedded-mode       .flashcards-wrap {
    min-height: 0;
    height: 100%;
}

/* topbar */
.qa-topbar {
    flex-shrink: 0; height: 42px;
    background: var(--pl); border-bottom: 1.5px solid var(--pb);
    display: flex; align-items: center; padding: 0 16px; gap: 12px;
}
body.presentation-mode   .qa-topbar,
body.fullscreen-embedded .qa-topbar,
body.embedded-mode       .qa-topbar { display: none; }

.qa-topbar-title {
    font-size: 12px; font-weight: 800; color: var(--pd);
    letter-spacing: .1em; text-transform: uppercase;
    margin: 0 auto; font-family: 'Nunito',sans-serif;
}
.qa-back-btn {
    display: inline-flex; align-items: center; gap: 5px;
    border: none; border-radius: 999px; font-family: 'Nunito',sans-serif;
    font-weight: 800; font-size: 12px; color: #fff; cursor: pointer;
    padding: 6px 14px; line-height: 1; text-decoration: none;
    background: var(--p); box-shadow: 0 3px 8px rgba(127,119,221,.28);
}

/* bottombar */
.qa-bottombar {
    flex-shrink: 0; height: 36px;
    background: var(--pl); border-top: 1.5px solid var(--pb);
}
body.presentation-mode   .qa-bottombar,
body.fullscreen-embedded .qa-bottombar,
body.embedded-mode       .qa-bottombar { display: none; }

/* body */
.qa-body {
    flex: 1; min-height: 0;
    display: flex; flex-direction: column;
    align-items: center; padding: 10px 14px 8px;
    gap: 8px; overflow: hidden; background: #f0faf6;
}

/* progress */
.qa-prog-row {
    flex-shrink: 0; width: 100%; max-width: 940px;
    display: flex; align-items: center; gap: 10px;
}
.qa-prog-track {
    flex: 1; height: 5px; background: var(--pl);
    border-radius: 3px; border: 1px solid var(--pb); overflow: hidden;
}
.qa-prog-fill { height: 100%; background: var(--p); border-radius: 3px; transition: width .35s; }
.qa-prog-lbl {
    font-size: 11px; font-weight: 800; color: var(--p);
    white-space: nowrap; font-family: 'Nunito',sans-serif;
}

/* card area */
.qa-card-area {
    flex: 1; min-height: 0; width: 100%; max-width: 940px;
    display: flex; align-items: center; gap: 10px;
}
.qa-arrow {
    flex-shrink: 0; width: 40px; height: 40px; border-radius: 50%;
    background: var(--pl); border: 1.5px solid var(--pb); color: var(--pd);
    font-size: 20px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s,transform .15s; line-height: 1;
}
.qa-arrow:hover { background: var(--pb); transform: scale(1.1); }

/* white card */
.qa-card-shell {
    flex: 1; height: 100%; background: #fff;
    border-radius: 20px; border: 1.5px solid var(--pb);
    box-shadow: 0 4px 20px rgba(127,119,221,.10);
    position: relative; overflow: hidden; cursor: pointer;
}

/* card faces */
.qa-face {
    position: absolute; inset: 0; border-radius: 19px;
    display: flex; align-items: center; justify-content: center;
    padding: 28px 40px; transition: opacity .4s;
}
.qa-face-front { background: var(--pl); opacity: 1; }
.qa-face-back  { background: var(--pd); opacity: 0; }

.qa-face-text {
    font-family: 'Fredoka',sans-serif;
    font-size: clamp(24px,5vw,56px);
    font-weight: 600; text-align: center; line-height: 1.1;
}
.qa-face-front .qa-face-text { color: var(--pd); }
.qa-face-back  .qa-face-text { color: #fff; }
.qa-tap-hint {
    position: absolute; bottom: 10px;
    font-size: 10px; font-weight: 600; color: var(--pb);
    font-family: 'Nunito',sans-serif;
}

/* controls */
.qa-controls {
    flex-shrink: 0; width: 100%; max-width: 940px;
    display: flex; gap: 8px; justify-content: center;
}
.qa-btn {
    display: inline-flex; align-items: center; gap: 5px;
    border: none; border-radius: 999px; font-family: 'Nunito',sans-serif;
    font-weight: 800; font-size: 13px; color: #fff; cursor: pointer;
    padding: 9px 20px; line-height: 1;
    background: var(--p); box-shadow: 0 3px 10px rgba(127,119,221,.28);
    transition: transform .18s cubic-bezier(.34,1.4,.64,1),filter .15s;
}
.qa-btn:hover { transform: translateY(-2px) scale(1.04); filter: brightness(1.08); }
.qa-btn.teal { background: var(--t400); }

/* FULLSCREEN scaling */
body.presentation-mode   .qa-face-text,
body.fullscreen-embedded .qa-face-text { font-size: clamp(36px,7vw,80px) !important; }
body.presentation-mode   .qa-btn,
body.fullscreen-embedded .qa-btn { padding: 11px 24px !important; font-size: 15px !important; }
body.presentation-mode   .qa-arrow,
body.fullscreen-embedded .qa-arrow { width: 48px !important; height: 48px !important; font-size: 24px !important; }
body.presentation-mode   .qa-prog-lbl,
body.fullscreen-embedded .qa-prog-lbl { font-size: 13px !important; }
body.presentation-mode   .qa-face,
body.fullscreen-embedded .qa-face { padding: 36px 52px !important; }

/* completed */
.qa-completed {
    display: none; position: absolute; inset: 0;
    background: #f0faf6; border-radius: 19px;
    flex-direction: column; align-items: center;
    justify-content: center; padding: 20px; z-index: 10;
}
.qa-completed.active { display: flex; }
.done-card {
    background: #fff; border-radius: 20px; border: 1.5px solid var(--pb);
    box-shadow: 0 4px 24px rgba(127,119,221,.10);
    width: 100%; max-width: 480px;
    display: flex; flex-direction: column;
    align-items: center; padding: 26px 24px; gap: 12px; text-align: center;
}
.done-confetti { width:100%; height:5px; border-radius:3px; background:linear-gradient(90deg,var(--p) 0%,var(--t400) 35%,#f59e0b 65%,var(--p) 100%); }
.done-icon  { font-size:54px; line-height:1; }
.done-title { font-family:'Fredoka',sans-serif; font-size:26px; font-weight:700; color:var(--t800); margin:0; }
.done-sub   { font-size:13px; font-weight:600; color:#64748b; max-width:300px; line-height:1.5; margin:0; }
.done-bar-row   { width:100%; display:flex; flex-direction:column; gap:5px; }
.done-bar-hd    { display:flex; justify-content:space-between; align-items:center; }
.done-bar-lbl   { font-size:12px; font-weight:800; color:var(--pd); }
.done-bar-val   { font-size:12px; font-weight:800; color:var(--p); }
.done-bar-track { width:100%; height:10px; background:var(--pl); border-radius:6px; border:1px solid var(--pb); overflow:hidden; }
.done-bar-fill  { height:100%; border-radius:6px; width:0%; background:linear-gradient(90deg,var(--t400),var(--p)); transition:width .8s cubic-bezier(.34,1,.64,1); }
.done-stat-box  { background:var(--pl); border-radius:16px; border:1.5px solid var(--pb); padding:14px 20px; width:100%; display:flex; align-items:center; justify-content:center; gap:12px; }
.done-stat-num  { font-family:'Fredoka',sans-serif; font-size:24px; font-weight:700; color:var(--pd); }
.done-stat-lbl  { font-size:11px; font-weight:800; color:var(--p); text-transform:uppercase; letter-spacing:.06em; }
.done-btns { display:flex; gap:8px; flex-wrap:wrap; justify-content:center; }
.done-btn { display:inline-flex; align-items:center; gap:4px; border:none; border-radius:999px; font-family:'Nunito',sans-serif; font-weight:800; font-size:13px; color:#fff; cursor:pointer; padding:9px 20px; line-height:1; background:var(--p); box-shadow:0 3px 10px rgba(127,119,221,.28); }
.done-btn.teal { background:var(--t400); }
</style>

<div class="flashcards-wrap">

    <div class="qa-topbar">
        <a class="qa-back-btn" href="<?php echo htmlspecialchars(
            (isset($_GET['return_to'])&&$_GET['return_to']!=='') ? $_GET['return_to'] :
            (isset($_GET['assignment'])&&$_GET['assignment']!==''
                ? '../../academic/teacher_unit.php?assignment='.urlencode($_GET['assignment']).'&unit='.urlencode($unit)
                : '../../academic/unit_view.php?unit='.urlencode($unit)),
        ENT_QUOTES,'UTF-8'); ?>">
            <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                <path d="M6.5 1.5L3 5l3.5 3.5" stroke="#fff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Back
        </a>
        <span class="qa-topbar-title">Questions &amp; Answers</span>
    </div>

    <div class="qa-body">

        <div class="qa-prog-row">
            <div class="qa-prog-track">
                <div class="qa-prog-fill" id="qa-prog" style="width:<?php echo count($cards)>0?round(1/count($cards)*100):100; ?>%"></div>
            </div>
            <span class="qa-prog-lbl" id="qa-prog-lbl">1 / <?php echo count($cards); ?></span>
        </div>

        <div class="qa-card-area">
            <button type="button" class="qa-arrow" id="qa-arr-prev">&#8249;</button>

            <div class="qa-card-shell" id="qa-shell">
                <div class="qa-face qa-face-front" id="qa-front">
                    <div class="qa-face-text" id="qa-q"></div>
                    <span class="qa-tap-hint">Tap to reveal answer</span>
                </div>
                <div class="qa-face qa-face-back" id="qa-back">
                    <div class="qa-face-text" id="qa-a"></div>
                </div>

                <div class="qa-completed" id="qa-completed">
                    <div class="done-card">
                        <div class="done-confetti"></div>
                        <div class="done-icon">&#x2705;</div>
                        <h2 class="done-title">All Done!</h2>
                        <p class="done-sub">You reviewed all questions. Excellent fluency practice!</p>
                        <div class="done-bar-row">
                            <div class="done-bar-hd">
                                <span class="done-bar-lbl">Questions reviewed</span>
                                <span class="done-bar-val" id="qa-done-count">0 / 0</span>
                            </div>
                            <div class="done-bar-track"><div class="done-bar-fill" id="qa-done-bar"></div></div>
                        </div>
                        <div class="done-stat-box">
                            <span style="font-size:36px">&#x1F4AC;</span>
                            <div style="text-align:left">
                                <div class="done-stat-num" id="qa-done-num">0</div>
                                <div class="done-stat-lbl">questions practised today</div>
                            </div>
                        </div>
                        <div class="done-btns">
                            <button type="button" class="done-btn teal" id="qa-restart">&#8635; Review Again</button>
                            <button type="button" class="done-btn" onclick="history.back()">Next Activity &#8594;</button>
                        </div>
                    </div>
                </div>
            </div>

            <button type="button" class="qa-arrow" id="qa-arr-next">&#8250;</button>
        </div>

        <div class="qa-controls">
            <button type="button" class="qa-btn" id="qa-prev">&#9664; Prev</button>
            <button type="button" class="qa-btn teal" id="qa-listen">&#x1F50A; Listen</button>
            <button type="button" class="qa-btn" id="qa-next">Next &#9654;</button>
        </div>

    </div>

    <div class="qa-bottombar"></div>
</div>

<audio id="qa-win" src="../../hangman/assets/win.mp3" preload="auto"></audio>

<script>
(function(){

var CARDS = <?php echo json_encode($cards, JSON_UNESCAPED_UNICODE); ?>;
var TOTAL = CARDS.length;
var idx   = 0;
var done  = false;
var flip  = false;

var TTS=(function(){
    var FEMALE=['zira','samantha','karen','aria','jenny','emma','ava','siri','google us english','microsoft zira','microsoft aria','female','woman'];
    var MALE=['guy','ryan','daniel','liam','microsoft guy','microsoft david','male','man'];
    var _c=null,_t=0;
    function _load(cb){
        if(!window.speechSynthesis)return;
        var v=window.speechSynthesis.getVoices();
        if(v&&v.length){_c=v;cb(v);return;}
        if(window.speechSynthesis.onvoiceschanged!==undefined){window.speechSynthesis.onvoiceschanged=function(){_c=window.speechSynthesis.getVoices();if(_c.length)cb(_c);};}
        if(_t<12){_t++;setTimeout(function(){_load(cb);},150);}
    }
    function _pick(vs,lang,gender){
        if(!vs||!vs.length)return null;
        var pre=lang.split('-')[0].toLowerCase();
        var pool=[];
        for(var i=0;i<vs.length;i++){var vl=String(vs[i].lang||'').toLowerCase();if(vl===lang.toLowerCase()||vl.indexOf(pre+'-')===0||vl.indexOf(pre+'_')===0)pool.push(vs[i]);}
        if(!pool.length)pool=vs;
        var hints=gender==='male'?MALE:FEMALE;
        var q=['neural','premium','enhanced','natural'];
        for(var qi=0;qi<q.length;qi++)for(var h=0;h<hints.length;h++)for(var v=0;v<pool.length;v++){var l=(String(pool[v].name||'')+' '+String(pool[v].voiceURI||'')).toLowerCase();if(l.indexOf(q[qi])!==-1&&l.indexOf(hints[h])!==-1)return pool[v];}
        for(var h2=0;h2<hints.length;h2++)for(var v2=0;v2<pool.length;v2++){var l2=(String(pool[v2].name||'')+' '+String(pool[v2].voiceURI||'')).toLowerCase();if(l2.indexOf(hints[h2])!==-1)return pool[v2];}
        return pool[0]||null;
    }
    function speak(text,opts){
        if(!text||!window.speechSynthesis)return;
        opts=opts||{};
        var lang=opts.lang||'en-US',gender=opts.gender||'female';
        var rate=typeof opts.rate!=='undefined'?opts.rate:0.82;
        window.speechSynthesis.cancel();
        function _do(vs){var u=new SpeechSynthesisUtterance(text);u.lang=lang;u.rate=rate;u.pitch=1;u.volume=1;var v=_pick(vs,lang,gender);if(v)u.voice=v;window.speechSynthesis.speak(u);}
        if(_c&&_c.length){_do(_c);}else{_load(function(v){_do(v);});}
    }
    if(window.speechSynthesis)_load(function(){});
    return{speak:speak};
})();

function loadCard(){
    var card=CARDS[idx]||{};
    document.getElementById('qa-q').textContent=card.question||'No question';
    document.getElementById('qa-a').textContent=card.answer||'No answer';
    document.getElementById('qa-prog').style.width=Math.round((idx+1)/TOTAL*100)+'%';
    document.getElementById('qa-prog-lbl').textContent=(idx+1)+' / '+TOTAL;
    flip=false;
    document.getElementById('qa-front').style.opacity='1';
    document.getElementById('qa-back').style.opacity='0';
}

function doFlip(){
    if(done)return;
    flip=!flip;
    document.getElementById('qa-front').style.opacity=flip?'0':'1';
    document.getElementById('qa-back').style.opacity=flip?'1':'0';
}

function goPrev(){
    if(done)return; flip=false;
    idx=(idx-1+TOTAL)%TOTAL; loadCard();
}

function goNext(){
    if(done)return; flip=false;
    if(idx>=TOTAL-1){ showDone(); return; }
    idx++; loadCard();
}

function showDone(){
    done=true;
    document.getElementById('qa-completed').classList.add('active');
    document.getElementById('qa-done-count').textContent=TOTAL+' / '+TOTAL;
    document.getElementById('qa-done-num').textContent=TOTAL;
    setTimeout(function(){ document.getElementById('qa-done-bar').style.width='100%'; },100);
    try{ var s=document.getElementById('qa-win'); s.pause(); s.currentTime=0; s.play(); }catch(e){}
}

document.getElementById('qa-shell').addEventListener('click', doFlip);
document.getElementById('qa-arr-prev').addEventListener('click', goPrev);
document.getElementById('qa-arr-next').addEventListener('click', goNext);
document.getElementById('qa-prev').addEventListener('click', goPrev);
document.getElementById('qa-next').addEventListener('click', goNext);

document.getElementById('qa-listen').addEventListener('click', function(){
    var card=CARDS[idx]||{};
    TTS.speak(flip?card.answer:card.question, {gender:'male',rate:0.82});
});

document.getElementById('qa-restart').addEventListener('click', function(){
    done=false; idx=0;
    document.getElementById('qa-completed').classList.remove('active');
    document.getElementById('qa-done-bar').style.width='0%';
    loadCard();
});

document.addEventListener('keydown',function(e){
    if(e.key==='Enter'||e.key===' '){ e.preventDefault(); doFlip(); }
    if(e.key==='ArrowRight') goNext();
    if(e.key==='ArrowLeft')  goPrev();
});

loadCard();
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-circle-question', $content);
