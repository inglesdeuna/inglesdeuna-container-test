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
<style>
:root{
    --p:#7F77DD;--pd:#534AB7;--pl:#EEEDFE;--pb:#AFA9EC;
    --t400:#1D9E75;--t800:#085041;
}
.fc-viewer-shell{max-width:1100px;margin:0 auto;font-family:'Nunito','Segoe UI',sans-serif}
.fc-intro{margin-bottom:18px;padding:20px 26px;border-radius:26px;
    border:1px solid var(--pb);
    background:linear-gradient(135deg,var(--pl) 0%,#f5f3ff 50%,var(--pl) 100%);
    box-shadow:0 16px 34px rgba(127,119,221,.12)}
.fc-intro h2{margin:0 0 6px;font-family:'Fredoka',sans-serif;font-size:30px;line-height:1.1;color:var(--pd)}
.fc-intro p{margin:0;color:#6b5fa6;font-size:15px;line-height:1.5}
.fc-stage{background:#fff;border:1.5px solid var(--pb);border-radius:24px;
    overflow:hidden;box-shadow:0 14px 28px rgba(127,119,221,.12)}
.fc-prog-wrap{padding:14px 20px 0;display:flex;align-items:center;gap:10px}
.fc-prog-track{flex:1;height:5px;background:var(--pl);border-radius:3px;
    border:1px solid var(--pb);overflow:hidden}
.fc-prog-fill{height:100%;background:var(--p);border-radius:3px;transition:width .35s ease}
.fc-prog-lbl{font-size:12px;font-weight:800;color:var(--p);white-space:nowrap;font-family:'Nunito',sans-serif}
.fc-card-area{min-height:420px;padding:20px 24px;display:flex;align-items:center;gap:14px}
.fc-arrow{flex-shrink:0;width:42px;height:42px;border-radius:50%;
    background:var(--pl);border:1.5px solid var(--pb);color:var(--pd);
    font-size:22px;cursor:pointer;display:flex;align-items:center;justify-content:center;
    transition:background .15s,transform .15s}
.fc-arrow:hover{background:var(--pb);transform:scale(1.08)}
.fc-card{flex:1;min-height:360px;border-radius:20px;border:1.5px solid var(--pb);
    box-shadow:0 4px 16px rgba(127,119,221,.10);position:relative;overflow:hidden;cursor:pointer}
.fc-face-front{position:absolute;inset:0;border-radius:19px;background:var(--pl);
    display:flex;align-items:center;justify-content:center;padding:28px;
    transition:opacity .35s;opacity:1}
.fc-face-front img{max-width:80%;max-height:320px;object-fit:contain;
    border-radius:16px;border:1.5px solid var(--pb);background:#fff}
.fc-face-back{position:absolute;inset:0;border-radius:19px;background:var(--pd);
    display:flex;align-items:center;justify-content:center;padding:28px;
    transition:opacity .35s;opacity:0}
.fc-back-text{font-family:'Fredoka',sans-serif;font-size:clamp(48px,8vw,96px);
    font-weight:600;color:#fff;text-align:center;line-height:1.1}
.fc-tap-hint{position:absolute;bottom:12px;left:0;right:0;text-align:center;
    font-size:11px;font-weight:600;color:var(--pb);font-family:'Nunito',sans-serif;
    z-index:2;pointer-events:none}
.fc-toolbar{display:flex;flex-direction:column;gap:8px;padding:14px 16px;
    border-top:1.5px solid var(--pb);
    background:linear-gradient(180deg,var(--pl) 0%,#f5f3ff 100%);align-items:center}
.fc-toolbar-row{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;align-items:center}
.fc-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;
    padding:10px 18px;border:none;border-radius:999px;
    font-weight:800;font-family:'Nunito',sans-serif;font-size:14px;
    min-width:130px;line-height:1;cursor:pointer;
    transition:transform .15s,filter .15s}
.fc-btn:hover{filter:brightness(1.06);transform:translateY(-1px)}
.fc-btn-purple{background:var(--p);color:#fff;box-shadow:0 6px 16px rgba(127,119,221,.20)}
.fc-btn-teal{background:var(--t400);color:#fff;box-shadow:0 6px 16px rgba(29,158,117,.20)}
.fc-count{font-weight:800;color:var(--pd);font-size:13px;font-family:'Nunito',sans-serif}
.fc-completed{display:none;text-align:center;max-width:600px;margin:40px auto;padding:40px 20px}
.fc-completed.active{display:block}
.fc-done-icon{font-size:80px;margin-bottom:16px}
.fc-done-title{font-family:'Fredoka',sans-serif;font-size:36px;font-weight:700;color:var(--pd);margin:0 0 12px}
.fc-done-text{font-size:16px;color:#6b5fa6;line-height:1.6;margin:0 0 20px}
.fc-done-bar-row{max-width:400px;margin:0 auto 20px;display:flex;flex-direction:column;gap:6px}
.fc-done-bar-hd{display:flex;justify-content:space-between}
.fc-done-bar-lbl{font-size:13px;font-weight:800;color:var(--pd)}
.fc-done-bar-val{font-size:13px;font-weight:800;color:var(--p)}
.fc-done-bar-track{height:10px;background:var(--pl);border-radius:6px;border:1px solid var(--pb);overflow:hidden}
.fc-done-bar-fill{height:100%;border-radius:6px;width:0%;
    background:linear-gradient(90deg,var(--t400),var(--p));
    transition:width .8s cubic-bezier(.34,1,.64,1)}
.fc-done-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.fc-done-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;
    padding:10px 20px;border:none;border-radius:999px;
    font-weight:800;font-family:'Nunito',sans-serif;font-size:14px;
    cursor:pointer;transition:transform .15s,filter .15s}
.fc-done-btn:hover{filter:brightness(1.06);transform:translateY(-1px)}
.fc-done-btn-purple{background:var(--p);color:#fff}
.fc-done-btn-teal{background:var(--t400);color:#fff}
@media(max-width:900px){
    .fc-intro{padding:16px 18px}.fc-intro h2{font-size:24px}
    .fc-card-area{padding:14px 12px;min-height:300px}.fc-card{min-height:260px}
    .fc-btn{min-width:100px;font-size:13px;padding:9px 14px}
    .fc-toolbar-row{flex-direction:column;align-items:stretch}
    .fc-btn{width:100%;justify-content:center}
}
</style>

<?php echo render_activity_header($viewerTitle, 'Tap each card to reveal the word.'); ?>

<div class="fc-viewer-shell">
    <div class="fc-stage" id="fc-stage">

        <div class="fc-prog-wrap">
            <div class="fc-prog-track">
                <div class="fc-prog-fill" id="fc-prog-fill"
                     style="width:<?php echo count($data) > 0 ? round(1/count($data)*100) : 100; ?>%"></div>
            </div>
            <span class="fc-prog-lbl" id="fc-prog-lbl">1 / <?php echo count($data); ?></span>
        </div>

        <div class="fc-card-area">
            <button type="button" class="fc-arrow" id="fc-arr-prev">&#8249;</button>

            <div class="fc-card" id="fc-card">
                <div class="fc-face-front" id="fc-front">
                    <img id="fc-img" src="" alt="" style="display:none">
                </div>
                <div class="fc-face-back" id="fc-back">
                    <div class="fc-back-text" id="fc-back-text"></div>
                </div>
                <span class="fc-tap-hint" id="fc-hint">Tap to reveal word</span>
            </div>

            <button type="button" class="fc-arrow" id="fc-arr-next">&#8250;</button>
        </div>

        <div class="fc-toolbar">
            <div class="fc-toolbar-row">
                <button type="button" class="fc-btn fc-btn-purple" id="fc-prev">&#9664; Prev</button>
                <span class="fc-count" id="fc-count">Card 1 / <?php echo count($data); ?></span>
                <button type="button" class="fc-btn fc-btn-teal" id="fc-listen">&#x1F50A; Listen</button>
                <button type="button" class="fc-btn fc-btn-purple" id="fc-next">Next &#9654;</button>
            </div>
        </div>

    </div>

    <div class="fc-completed" id="fc-completed">
        <div class="fc-done-icon">&#x2705;</div>
        <h2 class="fc-done-title">All Done!</h2>
        <p class="fc-done-text">You reviewed all the cards. Great vocabulary practice!</p>
        <div class="fc-done-bar-row">
            <div class="fc-done-bar-hd">
                <span class="fc-done-bar-lbl">Cards reviewed</span>
                <span class="fc-done-bar-val" id="fc-done-val">0 / 0</span>
            </div>
            <div class="fc-done-bar-track">
                <div class="fc-done-bar-fill" id="fc-done-bar"></div>
            </div>
        </div>
        <div class="fc-done-actions">
            <button type="button" class="fc-done-btn fc-done-btn-teal" id="fc-restart">&#8635; Review Again</button>
            <button type="button" class="fc-done-btn fc-done-btn-purple" onclick="history.back()">&#8592; Back</button>
        </div>
    </div>
</div>

<audio id="fc-win" src="../../hangman/assets/win.mp3" preload="auto"></audio>

<script>
(function(){
var CARDS=<?php echo json_encode($data, JSON_UNESCAPED_UNICODE); ?>;
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

function getWord(c){return c.english_text||c.text||'';}

function loadCard(){
    var c=CARDS[idx]||{};
    var imgEl=document.getElementById('fc-img');
    if(c.image){imgEl.src=c.image;imgEl.style.display='';}
    else{imgEl.style.display='none';imgEl.src='';}
    document.getElementById('fc-back-text').textContent=getWord(c)||'No text';
    flipped=false;
    document.getElementById('fc-front').style.opacity='1';
    document.getElementById('fc-back').style.opacity='0';
    document.getElementById('fc-hint').textContent='Tap to reveal word';
    var pct=Math.round((idx+1)/TOTAL*100);
    document.getElementById('fc-prog-fill').style.width=pct+'%';
    document.getElementById('fc-prog-lbl').textContent=(idx+1)+' / '+TOTAL;
    document.getElementById('fc-count').textContent='Card '+(idx+1)+' / '+TOTAL;
}

function doFlip(){
    if(done)return;
    flipped=!flipped;
    document.getElementById('fc-front').style.opacity=flipped?'0':'1';
    document.getElementById('fc-back').style.opacity=flipped?'1':'0';
    document.getElementById('fc-hint').textContent=flipped?'Tap to see image':'Tap to reveal word';
}

function goPrev(){if(done)return;idx=(idx-1+TOTAL)%TOTAL;loadCard();}
function goNext(){
    if(done)return;
    if(idx>=TOTAL-1){showDone();return;}
    idx++;loadCard();
}

function showDone(){
    done=true;
    document.getElementById('fc-stage').style.display='none';
    document.getElementById('fc-completed').classList.add('active');
    document.getElementById('fc-done-val').textContent=TOTAL+' / '+TOTAL;
    setTimeout(function(){document.getElementById('fc-done-bar').style.width='100%';},100);
    try{var s=document.getElementById('fc-win');s.pause();s.currentTime=0;s.play();}catch(e){}
}

document.getElementById('fc-card').addEventListener('click',doFlip);
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
    if(e.key==='Enter'||e.key===' '){e.preventDefault();doFlip();}
    if(e.key==='ArrowRight')goNext();
    if(e.key==='ArrowLeft')goPrev();
});

loadCard();
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-clone', $content);
