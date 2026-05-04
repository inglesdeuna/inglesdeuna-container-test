<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function activities_columns(PDO $pdo): array
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

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $columns = activities_columns($pdo);
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

function default_flashcards_title(): string { return 'Flashcards'; }

function normalize_flashcards_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_flashcards_title();
}

function normalize_flashcards_payload($rawData): array
{
    $default = array('title' => default_flashcards_title(), 'cards' => array());
    if ($rawData === null || $rawData === '') return $default;
    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) return $default;
    $title = '';
    $cardsSource = $decoded;
    if (isset($decoded['title'])) $title = trim((string) $decoded['title']);
    if (isset($decoded['cards']) && is_array($decoded['cards'])) $cardsSource = $decoded['cards'];
    $cards = array();
    if (is_array($cardsSource)) {
        foreach ($cardsSource as $item) {
            if (!is_array($item)) continue;
            $cards[] = array(
                'id'           => isset($item['id'])           ? trim((string) $item['id'])           : uniqid('fc_'),
                'english_text' => isset($item['english_text']) ? trim((string) $item['english_text']) : '',
                'spanish_text' => isset($item['spanish_text']) ? trim((string) $item['spanish_text']) : '',
                'text'         => isset($item['text'])         ? trim((string) $item['text'])         : '',
                'image'        => isset($item['image'])        ? trim((string) $item['image'])        : '',
            );
        }
    }
    return array('title' => normalize_flashcards_title($title), 'cards' => $cards);
}

function load_flashcards_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = activities_columns($pdo);
    $selectFields = array('id');
    if (in_array('data',         $columns, true)) $selectFields[] = 'data';
    if (in_array('content_json', $columns, true)) $selectFields[] = 'content_json';
    if (in_array('title',        $columns, true)) $selectFields[] = 'title';
    if (in_array('name',         $columns, true)) $selectFields[] = 'name';
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id = :id AND type = 'flashcards' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id = :unit AND type = 'flashcards' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit = :unit AND type = 'flashcards' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return array('title' => default_flashcards_title(), 'cards' => array());
    $rawData = null;
    if (isset($row['data'])) $rawData = $row['data'];
    elseif (isset($row['content_json'])) $rawData = $row['content_json'];
    $payload = normalize_flashcards_payload($rawData);
    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') $columnTitle = trim((string) $row['title']);
    elseif (isset($row['name']) && trim((string) $row['name']) !== '') $columnTitle = trim((string) $row['name']);
    if ($columnTitle !== '') $payload['title'] = $columnTitle;
    return array(
        'title' => normalize_flashcards_title((string) $payload['title']),
        'cards' => isset($payload['cards']) && is_array($payload['cards']) ? $payload['cards'] : array(),
    );
}

if ($unit === '' && $activityId !== '') $unit = resolve_unit_from_activity($pdo, $activityId);
$activity    = load_flashcards_activity($pdo, $unit, $activityId);
$data        = isset($activity['cards']) && is_array($activity['cards']) ? $activity['cards'] : array();
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : default_flashcards_title();
if (count($data) === 0) die('No flashcards found for this unit');

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
:root{
    --fc-bg-1:#fce4ec;
    --fc-bg-2:#f3e5f5;
    --fc-bg-3:#e8eaf6;
    --fc-card:#ffffff;
    --fc-line:#e0e0e0;
    --fc-muted:#9e9e9e;
    --fc-purple:#7F77DD;
    --fc-purple-dark:#534AB7;
    --fc-purple-light:#EEEDFE;
    --fc-blue:#1f66cc;
    --fc-blue-hover:#2f5bb5;
    --fc-fuchsia:#be185d;
    --fc-fuchsia-hover:#db2777;
    --fc-shadow:0 4px 20px rgba(0,0,0,.08);
}
.fc-app-page{
    min-height:480px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    padding:28px 16px;
    background:linear-gradient(135deg,var(--fc-bg-1) 0%,var(--fc-bg-2) 50%,var(--fc-bg-3) 100%);
    border-radius:12px;
    font-family:'Nunito','Segoe UI',Roboto,Arial,sans-serif;
    gap:6px;
    width:100%;
}
.fc-app-title{
    font-family:'Fredoka',sans-serif;
    font-size:clamp(20px,4vw,34px);
    font-weight:700;
    color:var(--fc-purple-dark);
    margin:0;
    line-height:1.1;
    text-align:center;
}
.fc-app-sub{
    font-family:'Nunito',sans-serif;
    font-size:clamp(12px,1.5vw,14px);
    font-weight:600;
    color:var(--fc-muted);
    margin:0 0 8px;
    text-align:center;
}
.fc-prog-wrap{
    width:700px;
    max-width:92vw;
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:2px;
}
.fc-prog-track{
    flex:1;
    height:4px;
    background:var(--fc-line);
    border-radius:3px;
    overflow:hidden;
}
.fc-prog-fill{
    height:100%;
    width:0%;
    background:var(--fc-purple);
    border-radius:3px;
    transition:width .35s ease;
}
.fc-prog-lbl{
    font-size:11px;
    font-weight:800;
    color:var(--fc-muted);
    font-family:'Nunito',sans-serif;
    white-space:nowrap;
}
.fc-card-frame{
    position:relative;
    width:700px;
    max-width:92vw;
}
.fc-game-card{
    position:relative;
    width:100%;
    aspect-ratio:7/5;
    border-radius:18px;
    overflow:hidden;
    background:var(--fc-card);
    border:1.5px solid var(--fc-line);
    box-shadow:var(--fc-shadow);
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
}
.fc-game-card-inner{
    width:calc(100% - 20px);
    height:calc(100% - 20px);
    border-radius:14px;
    background:#fff;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:10px;
    padding:20px;
    position:relative;
    overflow:hidden;
}
.fc-face{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
    transition:opacity .35s ease;
}
.fc-face-front{opacity:1;background:#fff;}
.fc-face-back{opacity:0;background:var(--fc-purple-dark);}
.fc-card-img{
    width:140px;
    height:140px;
    max-width:70%;
    max-height:70%;
    background:#fff;
    border-radius:12px;
    border:1px solid var(--fc-line);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:64px;
    overflow:hidden;
    color:var(--fc-purple);
    font-family:'Fredoka',sans-serif;
    font-weight:700;
    text-align:center;
}
.fc-card-img img{
    width:100%;
    height:100%;
    object-fit:contain;
    display:block;
}
.fc-back-text{
    font-family:'Fredoka',sans-serif;
    font-size:clamp(34px,8vw,86px);
    font-weight:700;
    color:#fff;
    line-height:1.05;
    text-align:center;
    overflow-wrap:anywhere;
}
.fc-tap-hint{
    position:absolute;
    left:0;
    right:0;
    bottom:10px;
    text-align:center;
    font-family:'Nunito',sans-serif;
    font-size:11px;
    font-weight:800;
    color:var(--fc-muted);
    pointer-events:none;
    z-index:4;
}
.fc-face-back + .fc-tap-hint{color:#fff;}
.fc-arrow{
    position:absolute;
    top:50%;
    transform:translateY(-50%);
    width:32px;
    height:32px;
    border-radius:50%;
    background:#fff;
    border:1px solid #d0d0d0;
    color:var(--fc-purple);
    font-size:16px;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 2px 6px rgba(0,0,0,.1);
    z-index:5;
    transition:filter .15s,transform .15s;
}
.fc-arrow:hover{filter:brightness(1.06);transform:translateY(-50%) scale(1.06)}
.fc-arrow.left{left:8px;}
.fc-arrow.right{right:8px;}
.fc-toolbar{
    width:700px;
    max-width:92vw;
    background:#fff;
    border:1.5px solid var(--fc-line);
    border-top:none;
    border-radius:0 0 18px 18px;
    padding:10px 16px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    flex-wrap:wrap;
    margin-top:-2px;
}
.fc-btn{
    padding:8px 18px;
    border-radius:999px;
    border:none;
    font-weight:800;
    font-family:'Nunito',sans-serif;
    font-size:13px;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:5px;
    min-width:90px;
    justify-content:center;
    transition:filter .15s,transform .15s,background .2s ease;
    color:#fff;
    line-height:1;
}
.fc-btn:hover{filter:brightness(1.08);transform:translateY(-1px)}
.fc-btn-blue{background:var(--fc-blue);}
.fc-btn-blue:hover{background:var(--fc-blue-hover);}
.fc-btn-fuchsia{background:var(--fc-fuchsia);}
.fc-btn-fuchsia:hover{background:var(--fc-fuchsia-hover);}
.fc-completed{
    display:none;
    width:700px;
    max-width:92vw;
    background:#fff;
    border:1.5px solid var(--fc-line);
    border-radius:18px;
    box-shadow:var(--fc-shadow);
    padding:28px 18px;
    text-align:center;
    font-family:'Nunito',sans-serif;
}
.fc-completed.active{display:block;}
.fc-done-icon{font-size:54px;margin-bottom:10px;}
.fc-done-title{
    font-family:'Fredoka',sans-serif;
    color:var(--fc-purple-dark);
    font-size:clamp(24px,4vw,34px);
    margin:0 0 8px;
}
.fc-done-text{color:var(--fc-muted);font-weight:700;margin:0 0 16px;}
.fc-done-bar-row{max-width:430px;margin:0 auto 18px;display:flex;flex-direction:column;gap:6px;}
.fc-done-bar-hd{display:flex;justify-content:space-between;font-size:13px;font-weight:800;color:var(--fc-purple-dark);}
.fc-done-bar-track{height:8px;background:var(--fc-line);border-radius:999px;overflow:hidden;}
.fc-done-bar-fill{height:100%;width:0%;background:linear-gradient(90deg,var(--fc-fuchsia),var(--fc-purple));border-radius:999px;transition:width .8s cubic-bezier(.34,1,.64,1);}
.fc-done-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}
@media(max-width:600px){
    .fc-app-page{padding:22px 12px;}
    .fc-game-card{aspect-ratio:5/4;}
    .fc-card-img{width:118px;height:118px;font-size:52px;}
    .fc-toolbar{padding:10px;}
    .fc-btn{width:100%;}
    .fc-arrow{width:30px;height:30px;}
}
</style>

<div class="fc-app-page">
    <h1 class="fc-app-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="fc-app-sub">Tap each card to reveal the word.</p>

    <div id="fc-stage">
        <div class="fc-prog-wrap">
            <div class="fc-prog-track">
                <div class="fc-prog-fill" id="fc-prog-fill"></div>
            </div>
            <span class="fc-prog-lbl" id="fc-prog-lbl">1 / <?php echo count($data); ?></span>
        </div>

        <div class="fc-card-frame">
            <div class="fc-game-card" id="fc-card" role="button" tabindex="0" aria-label="Tap to reveal word">
                <div class="fc-game-card-inner">
                    <div class="fc-face fc-face-front" id="fc-front">
                        <div class="fc-card-img" id="fc-card-img"><span id="fc-placeholder">?</span></div>
                    </div>
                    <div class="fc-face fc-face-back" id="fc-back">
                        <div class="fc-back-text" id="fc-back-text"></div>
                    </div>
                    <span class="fc-tap-hint" id="fc-hint">Tap to reveal word</span>
                </div>
            </div>
            <button type="button" class="fc-arrow left" id="fc-arr-prev" aria-label="Previous card">&#8249;</button>
            <button type="button" class="fc-arrow right" id="fc-arr-next" aria-label="Next card">&#8250;</button>
        </div>

        <div class="fc-toolbar">
            <button type="button" class="fc-btn fc-btn-blue" id="fc-prev">&#9664; Prev</button>
            <button type="button" class="fc-btn fc-btn-fuchsia" id="fc-listen">&#x1F50A; Listen</button>
            <button type="button" class="fc-btn fc-btn-blue" id="fc-next">Next &#9654;</button>
        </div>
    </div>

    <div class="fc-completed" id="fc-completed">
        <div class="fc-done-icon">&#x2705;</div>
        <h2 class="fc-done-title">All Done!</h2>
        <p class="fc-done-text">You reviewed all the cards. Great vocabulary practice!</p>
        <div class="fc-done-bar-row">
            <div class="fc-done-bar-hd">
                <span>Cards reviewed</span>
                <span id="fc-done-val">0 / 0</span>
            </div>
            <div class="fc-done-bar-track"><div class="fc-done-bar-fill" id="fc-done-bar"></div></div>
        </div>
        <div class="fc-done-actions">
            <button type="button" class="fc-btn fc-btn-fuchsia" id="fc-restart">&#8635; Review Again</button>
            <button type="button" class="fc-btn fc-btn-blue" onclick="history.back()">&#8592; Back</button>
        </div>
    </div>
</div>

<audio id="fc-win" src="../../hangman/assets/win.mp3" preload="auto"></audio>

<script>
(function(){
var CARDS=<?php echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var TOTAL=CARDS.length,idx=0,flipped=false,done=false;

var TTS=(function(){
    var H=['zira','samantha','karen','aria','jenny','emma','ava','siri','google us english','female','woman'];
    var _c=null,_t=0;
    function _load(cb){
        if(!window.speechSynthesis)return;
        var v=window.speechSynthesis.getVoices();
        if(v&&v.length){_c=v;cb(v);return;}
        if(window.speechSynthesis.onvoiceschanged!==undefined){
            window.speechSynthesis.onvoiceschanged=function(){_c=window.speechSynthesis.getVoices();if(_c.length)cb(_c);};}
        if(_t<12){_t++;setTimeout(function(){_load(cb);},150);}
    }
    function _pick(vs){
        if(!vs||!vs.length)return null;
        var pool=[];
        for(var i=0;i<vs.length;i++){var vl=String(vs[i].lang||'').toLowerCase();if(vl.indexOf('en')===0)pool.push(vs[i]);}
        if(!pool.length)pool=vs;
        for(var h=0;h<H.length;h++)for(var v=0;v<pool.length;v++){
            var l=(String(pool[v].name||'')+' '+String(pool[v].voiceURI||'')).toLowerCase();
            if(l.indexOf(H[h])!==-1)return pool[v];
        }
        return pool[0]||null;
    }
    function speak(text){
        if(!text||!window.speechSynthesis)return;
        window.speechSynthesis.cancel();
        function _do(vs){var u=new SpeechSynthesisUtterance(text);u.lang='en-US';u.rate=0.82;u.pitch=1;u.volume=1;var v=_pick(vs);if(v)u.voice=v;window.speechSynthesis.speak(u);}
        if(_c&&_c.length){_do(_c);}else{_load(function(v){_do(v);});}
    }
    if(window.speechSynthesis)_load(function(){});
    return{speak:speak};
})();

function getWord(c){return c.english_text||c.text||c.spanish_text||'';}
function getPlaceholder(word){
    var w=String(word||'?').trim();
    if(!w)return '?';
    if(/^\d+$/.test(w))return w;
    return w.charAt(0).toUpperCase();
}
function setFront(c){
    var box=document.getElementById('fc-card-img');
    var word=getWord(c);
    box.innerHTML='';
    if(c.image){
        var img=document.createElement('img');
        img.src=c.image;
        img.alt=word||'Flashcard image';
        img.onerror=function(){box.textContent=getPlaceholder(word);};
        box.appendChild(img);
    }else{
        box.textContent=getPlaceholder(word);
    }
}
function loadCard(){
    var c=CARDS[idx]||{};
    setFront(c);
    document.getElementById('fc-back-text').textContent=getWord(c)||'No text';
    flipped=false;
    document.getElementById('fc-front').style.opacity='1';
    document.getElementById('fc-back').style.opacity='0';
    document.getElementById('fc-hint').textContent='Tap to reveal word';
    document.getElementById('fc-card').setAttribute('aria-label','Tap to reveal word');
    var pct=TOTAL>0?Math.round((idx+1)/TOTAL*100):100;
    document.getElementById('fc-prog-fill').style.width=pct+'%';
    document.getElementById('fc-prog-lbl').textContent=(idx+1)+' / '+TOTAL;
}
function doFlip(){
    if(done)return;
    flipped=!flipped;
    document.getElementById('fc-front').style.opacity=flipped?'0':'1';
    document.getElementById('fc-back').style.opacity=flipped?'1':'0';
    document.getElementById('fc-hint').textContent=flipped?'Tap to see image':'Tap to reveal word';
    document.getElementById('fc-card').setAttribute('aria-label',flipped?'Tap to see image':'Tap to reveal word');
}
function goPrev(){if(done)return;idx=(idx-1+TOTAL)%TOTAL;loadCard();}
function goNext(){if(done)return;if(idx>=TOTAL-1){showDone();return;}idx++;loadCard();}
function showDone(){
    done=true;
    document.getElementById('fc-stage').style.display='none';
    document.getElementById('fc-completed').classList.add('active');
    document.getElementById('fc-done-val').textContent=TOTAL+' / '+TOTAL;
    setTimeout(function(){document.getElementById('fc-done-bar').style.width='100%';},100);
    try{var s=document.getElementById('fc-win');s.pause();s.currentTime=0;s.play();}catch(e){}
}

document.getElementById('fc-card').addEventListener('click',doFlip);
document.getElementById('fc-card').addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();doFlip();}});
document.getElementById('fc-arr-prev').addEventListener('click',goPrev);
document.getElementById('fc-arr-next').addEventListener('click',goNext);
document.getElementById('fc-prev').addEventListener('click',goPrev);
document.getElementById('fc-next').addEventListener('click',goNext);
document.getElementById('fc-listen').addEventListener('click',function(){TTS.speak(getWord(CARDS[idx]||{}));});
document.getElementById('fc-restart').addEventListener('click',function(){
    done=false;idx=0;
    document.getElementById('fc-stage').style.display='';
    document.getElementById('fc-completed').classList.remove('active');
    document.getElementById('fc-done-bar').style.width='0%';
    loadCard();
});
document.addEventListener('keydown',function(e){
    if(e.target&&(/input|textarea|select/i).test(e.target.tagName))return;
    if(e.key==='ArrowRight')goNext();
    if(e.key==='ArrowLeft')goPrev();
});
loadCard();
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-clone', $content);
