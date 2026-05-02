<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

if (!empty($_SESSION['student_logged'])) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id'])         ? trim((string) $_GET['id'])         : '';
$unit       = isset($_GET['unit'])       ? trim((string) $_GET['unit'])       : '';
$source     = isset($_GET['source'])     ? trim((string) $_GET['source'])     : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

function fb_ed_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['unit_id'] ?? '';
}

function fb_ed_load(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = [
        'id'           => '',
        'instructions' => 'Write the missing words in the blanks.',
        'blocks'       => [],
        'wordbank'     => '',
        'media_type'   => 'none',
        'media_url'    => '',
        'tts_text'     => '',
    ];
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id=:id AND type='fillblank' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id=:unit AND type='fillblank' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;
    $data = json_decode($row['data'] ?? '', true);
    if (!isset($data['blocks']) && isset($data['text'])) {
        $blocks = [[
            'text'    => $data['text'],
            'answers' => array_map('trim', explode(',', $data['answerkey'] ?? '')),
            'image'   => '',
        ]];
    } else {
        $blocks = $data['blocks'] ?? [];
    }
    return [
        'id'           => (string)($row['id'] ?? ''),
        'instructions' => $data['instructions'] ?? $fallback['instructions'],
        'blocks'       => $blocks,
        'wordbank'     => $data['wordbank']   ?? '',
        'media_type'   => $data['media_type'] ?? 'none',
        'media_url'    => $data['media_url']  ?? '',
        'tts_text'     => $data['tts_text']   ?? '',
    ];
}

function fb_ed_save(PDO $pdo, string $unit, string $activityId, array $payload): string
{
    $json     = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $targetId = $activityId;
    if ($targetId === '') {
        $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id=:unit AND type='fillblank' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $targetId = trim((string)$stmt->fetchColumn());
    }
    if ($targetId !== '') {
        $pdo->prepare("UPDATE activities SET data=:data WHERE id=:id AND type='fillblank'")
            ->execute(['data' => $json, 'id' => $targetId]);
        return $targetId;
    }
    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data, position, created_at)
        VALUES (:uid, 'fillblank', :data,
            (SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id=:uid2),
            CURRENT_TIMESTAMP)
        RETURNING id");
    $stmt->execute(['uid' => $unit, 'uid2' => $unit, 'data' => $json]);
    return (string)$stmt->fetchColumn();
}

if ($unit === '' && $activityId !== '') $unit = fb_ed_resolve_unit($pdo, $activityId);
if ($unit === '') die('Unit not specified');

/* ── POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../core/cloudinary_upload.php';

    $mediaType = in_array($_POST['media_type'] ?? '', ['tts','audio','none'], true) ? $_POST['media_type'] : 'none';
    $mediaUrl  = trim((string)($_POST['media_url'] ?? ''));
    $ttsText   = trim((string)($_POST['tts_text']  ?? ''));

    if ($mediaType === 'audio') {
        if (isset($_FILES['media_file'])
            && !empty($_FILES['media_file']['name'])
            && ($_FILES['media_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploaded = upload_audio_to_cloudinary($_FILES['media_file']['tmp_name']);
            if ($uploaded) $mediaUrl = $uploaded;
        }
        if ($mediaUrl === '') $mediaUrl = trim((string)($_POST['current_media_url'] ?? ''));
    }

    $blockTexts   = isset($_POST['text'])      && is_array($_POST['text'])      ? $_POST['text']      : [];
    $blockAnswers = isset($_POST['answers'])   && is_array($_POST['answers'])   ? $_POST['answers']   : [];
    $blockImages  = isset($_POST['image_url']) && is_array($_POST['image_url']) ? $_POST['image_url'] : [];
    $imageUploads = $_FILES['image_upload'] ?? null;

    $blocks = [];
    foreach ($blockTexts as $i => $text) {
        $text = trim((string)$text);

        /*
         * SEPARATOR FIX:
         * Use | (pipe) to separate answers so multi-word phrases like idioms work.
         * "raining cats and dogs | watched me like a hawk | red herring"
         * Fallback to comma if no pipe found (backward-compat).
         */
        $rawAnswers = isset($blockAnswers[$i]) ? (string)$blockAnswers[$i] : '';
        if (strpos($rawAnswers, '|') !== false) {
            $answers = array_map('trim', explode('|', $rawAnswers));
        } else {
            $answers = array_map('trim', explode(',', $rawAnswers));
        }
        $answers = array_values(array_filter($answers, fn($a) => $a !== ''));

        $imgUrl = isset($blockImages[$i]) ? trim((string)$blockImages[$i]) : '';
        if ($imageUploads
            && isset($imageUploads['tmp_name'][$i])
            && $imageUploads['error'][$i] === UPLOAD_ERR_OK
            && !empty($imageUploads['name'][$i])) {
            $up = upload_to_cloudinary($imageUploads['tmp_name'][$i]);
            if ($up) $imgUrl = $up;
        }
        $blocks[] = ['text' => $text, 'answers' => $answers, 'image' => $imgUrl];
    }

    $savedId = fb_ed_save($pdo, $unit, $activityId, [
        'instructions' => trim((string)($_POST['instructions'] ?? '')),
        'wordbank'     => trim((string)($_POST['wordbank']     ?? '')),
        'media_type'   => $mediaType,
        'media_url'    => $mediaUrl,
        'tts_text'     => $ttsText,
        'blocks'       => $blocks,
    ]);

    $params = ['unit='.urlencode($unit), 'saved=1', 'id='.urlencode($savedId)];
    if ($assignment !== '') $params[] = 'assignment='.urlencode($assignment);
    if ($source     !== '') $params[] = 'source='.urlencode($source);
    header('Location: editor.php?'.implode('&', $params));
    exit;
}

$activity = fb_ed_load($pdo, $unit, $activityId);
if ($activityId === '' && $activity['id'] !== '') $activityId = $activity['id'];

ob_start();
if (isset($_GET['saved'])) {
    echo '<div class="fb-saved-banner">✔ Saved successfully</div>';
}
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --t50:#E1F5EE; --t100:#9FE1CB; --t200:#5DCAA5;
    --t400:#1D9E75; --t600:#0F6E56; --t800:#085041; --t900:#04342C;
    --purple:#7F77DD; --purple-d:#534AB7; --purple-l:#EEEDFE; --purple-b:#AFA9EC;
    --red:#dc2626; --radius:10px; --radius-lg:14px;
}
.fb-editor{max-width:820px;margin:0 auto;font-family:'Nunito','Segoe UI',sans-serif;padding-bottom:40px}
.fb-saved-banner{background:var(--t50);border:1.5px solid var(--t200);color:var(--t600);
    font-weight:800;font-size:14px;padding:10px 16px;border-radius:var(--radius);margin-bottom:16px}
.fb-section{background:#fff;border:1px solid var(--t100);border-radius:var(--radius-lg);
    margin-bottom:16px;overflow:hidden;box-shadow:0 2px 12px rgba(4,52,44,.08)}
.fb-section-header{background:var(--t50);border-bottom:1px solid var(--t100);
    padding:10px 18px;display:flex;align-items:center;gap:8px}
.fb-section-header h3{font-family:'Fredoka',sans-serif;font-size:16px;font-weight:600;color:var(--t800);margin:0}
.fb-section-body{padding:16px 18px}
.fb-field{margin-bottom:14px}
.fb-field:last-child{margin-bottom:0}
.fb-label{display:block;font-size:12px;font-weight:800;color:var(--t600);
    letter-spacing:.06em;text-transform:uppercase;margin-bottom:5px}
.fb-label small{text-transform:none;font-weight:600;letter-spacing:0;color:#6b7280;font-size:11px}
.fb-input,.fb-textarea,.fb-select{width:100%;padding:9px 12px;border:1.5px solid var(--t100);
    border-radius:var(--radius);font-size:14px;font-family:'Nunito',sans-serif;font-weight:600;
    color:#1e293b;background:#fff;box-sizing:border-box;outline:none;
    transition:border-color .15s,box-shadow .15s}
.fb-input:focus,.fb-textarea:focus,.fb-select:focus{border-color:var(--t400);box-shadow:0 0 0 3px rgba(29,158,117,.12)}
.fb-textarea{min-height:80px;resize:vertical}
.fb-select{appearance:none;cursor:pointer;padding-right:32px;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%230F6E56' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 12px center}
.fb-help{font-size:12px;color:#6b7280;font-weight:600;margin-top:4px}

/* callout */
.fb-callout{display:flex;align-items:flex-start;gap:8px;background:var(--purple-l);
    border:1px solid var(--purple-b);border-radius:9px;padding:10px 12px;
    font-size:12px;font-weight:700;color:var(--purple-d);margin-bottom:12px;line-height:1.6}
.fb-callout code{background:#fff;border-radius:4px;padding:1px 5px;
    font-size:13px;color:var(--purple);font-weight:800}

.fb-media-panel{display:none;margin-top:12px;padding-top:12px;border-top:1px dashed var(--t100)}
.fb-media-panel.active{display:block}
#fb-blocks-list{display:flex;flex-direction:column;gap:10px}
.fb-block-item{background:#f9fffe;border:1.5px solid var(--t100);border-radius:var(--radius);
    padding:12px 14px;transition:border-color .15s,box-shadow .15s}
.fb-block-item:hover{border-color:var(--t200);box-shadow:0 2px 8px rgba(29,158,117,.10)}
.fb-block-hd{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.fb-block-num{font-family:'Fredoka',sans-serif;font-size:13px;font-weight:600;color:var(--t400)}
/* live counter badge */
.fb-count{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;
    font-size:11px;font-weight:800}
.fb-count.ok{background:#dcfce7;color:#166534}
.fb-count.warn{background:#fee2e2;color:#991b1b}
.fb-thumb{width:56px;height:56px;border-radius:8px;object-fit:contain;
    border:1.5px solid var(--t100);background:var(--t50);margin-top:4px}
.fb-btn-add{display:inline-flex;align-items:center;gap:6px;background:var(--t400);color:#fff;
    border:none;border-radius:var(--radius);padding:9px 16px;font-size:13px;font-weight:800;
    font-family:'Nunito',sans-serif;cursor:pointer;transition:background .15s,transform .12s}
.fb-btn-add:hover{background:var(--t600);transform:translateY(-1px)}
.fb-btn-remove{background:#fee2e2;color:var(--red);border:1px solid #fca5a5;
    border-radius:8px;padding:6px 12px;font-size:12px;font-weight:800;
    font-family:'Nunito',sans-serif;cursor:pointer;transition:background .12s}
.fb-btn-remove:hover{background:#fecaca}
.fb-btn-save{display:inline-flex;align-items:center;gap:8px;background:var(--t800);color:#fff;
    border:none;border-radius:var(--radius-lg);padding:12px 28px;font-size:15px;font-weight:800;
    font-family:'Fredoka',sans-serif;cursor:pointer;
    box-shadow:0 4px 14px rgba(8,80,65,.25);transition:background .15s,transform .15s}
.fb-btn-save:hover{background:var(--t900);transform:translateY(-2px)}
.fb-save-row{display:flex;justify-content:center;margin-top:8px}
.fb-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px}
</style>

<form method="post" enctype="multipart/form-data" class="fb-editor" id="fbEditorForm" novalidate>
<input type="hidden" name="current_media_url" value="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>">

    <!-- Settings -->
    <div class="fb-section">
        <div class="fb-section-header"><span>📝</span><h3>Activity settings</h3></div>
        <div class="fb-section-body">
            <div class="fb-field">
                <label class="fb-label">Instructions</label>
                <input class="fb-input" type="text" name="instructions"
                    value="<?= htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Write the missing words in the blanks." required>
            </div>
            <div class="fb-field">
                <label class="fb-label">Word bank <small>(optional — leave blank to hide)</small></label>
                <input class="fb-input" type="text" name="wordbank"
                    value="<?= htmlspecialchars($activity['wordbank'], ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="phrase one | phrase two | single word">
                <p class="fb-help">Use <strong>|</strong> to separate entries — e.g. <em>goes to the dogs | raining cats and dogs | red herring</em></p>
            </div>
        </div>
    </div>

    <!-- Media -->
    <div class="fb-section">
        <div class="fb-section-header"><span>🎵</span><h3>Media</h3></div>
        <div class="fb-section-body">
            <div class="fb-field">
                <label class="fb-label">Media type</label>
                <select class="fb-select" name="media_type" id="fb-media-type" onchange="fbToggleMedia(this.value)">
                    <option value="none" <?= $activity['media_type']==='none'  ? 'selected' : '' ?>>— No media</option>
                    <option value="tts"  <?= $activity['media_type']==='tts'   ? 'selected' : '' ?>>🔊 Text-to-Speech (TTS)</option>
                    <option value="audio"<?= $activity['media_type']==='audio' ? 'selected' : '' ?>>🎵 Audio file upload</option>
                </select>
            </div>
            <div id="fb-panel-tts" class="fb-media-panel <?= $activity['media_type']==='tts' ? 'active' : '' ?>">
                <div class="fb-field">
                    <label class="fb-label">TTS text <small>(students listen while filling blanks)</small></label>
                    <textarea class="fb-textarea" name="tts_text"
                        placeholder="Paste the full text here for TTS playback…"
                    ><?= htmlspecialchars($activity['tts_text'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <p class="fb-help">Leave blank to auto-read all block texts in order.</p>
                </div>
            </div>
            <div id="fb-panel-audio" class="fb-media-panel <?= $activity['media_type']==='audio' ? 'active' : '' ?>">
                <div class="fb-field">
                    <label class="fb-label">Audio URL</label>
                    <input class="fb-input" type="text" name="media_url"
                        value="<?= $activity['media_type']==='audio' ? htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') : '' ?>"
                        placeholder="https://...">
                </div>
                <div class="fb-field">
                    <label class="fb-label">Or upload audio file</label>
                    <input type="file" name="media_file" accept="audio/*" style="font-size:13px;font-family:'Nunito',sans-serif">
                    <?php if ($activity['media_type']==='audio' && !empty($activity['media_url'])): ?>
                        <p class="fb-help">Current: <a href="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="color:var(--t600)">Listen</a></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Blocks -->
    <div class="fb-section">
        <div class="fb-section-header"><span>🗂️</span>
            <h3>Blocks — use <code style="background:var(--t50);padding:1px 6px;border-radius:4px;font-size:13px;color:var(--t600)">___</code> for each blank</h3>
        </div>
        <div class="fb-section-body">
            <div class="fb-callout">
                <span>💡</span>
                <span>Separate answers with <code>|</code> (pipe) — works for single words AND multi-word idioms.<br>
                Example: <code>raining cats and dogs | watched me like a hawk | red herring</code></span>
            </div>
            <p class="fb-help" style="margin-bottom:12px">
                Each block = one screen. Count of <code>___</code> must match count of <code>|</code>-separated answers.
            </p>
            <div id="fb-blocks-list"></div>
            <div class="fb-toolbar">
                <button type="button" class="fb-btn-add" onclick="fbAddBlock()">+ Add Block</button>
            </div>
        </div>
    </div>

    <div class="fb-save-row">
        <button type="submit" class="fb-btn-save">💾 Save Activity</button>
    </div>
</form>

<script>
var FB_BLOCKS = <?= json_encode(array_map(function($b){
    return ['text'=>$b['text']??'','answers'=>$b['answers']??[],'image'=>$b['image']??''];
}, $activity['blocks']), JSON_UNESCAPED_UNICODE) ?>;

function fbToggleMedia(val){
    ['tts','audio'].forEach(function(id){
        var el=document.getElementById('fb-panel-'+id);
        if(el) el.classList.toggle('active',id===val);
    });
}

function countBlanks(text){ return (text.match(/___/g)||[]).length; }

function parseAnswers(raw){
    if(raw.indexOf('|')!==-1) return raw.split('|').map(function(s){return s.trim();}).filter(Boolean);
    return raw.split(',').map(function(s){return s.trim();}).filter(Boolean);
}

function updateCounter(blockItem){
    var ta=blockItem.querySelector('textarea');
    var ai=blockItem.querySelector('input[name="answers[]"]');
    var badge=blockItem.querySelector('.fb-count');
    if(!ta||!ai||!badge) return;
    var b=countBlanks(ta.value), a=parseAnswers(ai.value).length;
    badge.textContent=b+' blank'+(b!==1?'s':'')+' / '+a+' answer'+(a!==1?'s':'');
    badge.className='fb-count '+(b===a&&b>0?'ok':'warn');
}

function fbRenderBlocks(){
    var c=document.getElementById('fb-blocks-list');
    c.innerHTML='';
    if(FB_BLOCKS.length===0){fbAddBlock();return;}
    FB_BLOCKS.forEach(function(block,idx){
        var answersDisplay=Array.isArray(block.answers)?block.answers.join(' | '):(block.answers||'');
        var div=document.createElement('div');
        div.className='fb-block-item';
        div.innerHTML=
            '<div class="fb-block-hd">'+
                '<span class="fb-block-num">Block '+(idx+1)+'</span>'+
                '<span class="fb-count warn">0 blanks / 0 answers</span>'+
            '</div>'+
            '<div class="fb-field">'+
                '<label class="fb-label">Sentence / paragraph <small>— use ___ for each blank</small></label>'+
                '<textarea class="fb-textarea" name="text[]" required placeholder="Yesterday it was ___ and my boss ___ all day.">'+(block.text?block.text.replace(/</g,'&lt;').replace(/>/g,'&gt;'):'')+'</textarea>'+
            '</div>'+
            '<div class="fb-field">'+
                '<label class="fb-label">Answers <small>— separate with | (pipe), one per blank</small></label>'+
                '<input class="fb-input" type="text" name="answers[]"'+
                    ' value="'+answersDisplay.replace(/"/g,'&quot;')+'"'+
                    ' placeholder="raining cats and dogs | watched me like a hawk | red herring">'+
            '</div>'+
            '<div class="fb-field">'+
                '<label class="fb-label">Image URL <small>(optional)</small></label>'+
                '<input class="fb-input" type="text" name="image_url[]"'+
                    ' value="'+(block.image||'').replace(/"/g,'&quot;')+'"'+
                    ' placeholder="https://...">'+
            '</div>'+
            '<div class="fb-field">'+
                '<label class="fb-label">Or upload image</label>'+
                '<input type="file" name="image_upload[]" accept="image/*" style="font-size:13px;font-family:\'Nunito\',sans-serif">'+
                (block.image?'<img class="fb-thumb" src="'+block.image+'" alt="">':'')+
            '</div>'+
            '<button type="button" class="fb-btn-remove" onclick="fbRemoveBlock(this)">✖ Remove block</button>';
        c.appendChild(div);
        var ta=div.querySelector('textarea');
        var ai=div.querySelector('input[name="answers[]"]');
        updateCounter(div);
        ta.addEventListener('input',function(){updateCounter(div);});
        ai.addEventListener('input',function(){updateCounter(div);});
    });
}

function fbAddBlock(){
    FB_BLOCKS.push({text:'',answers:[],image:''});
    fbRenderBlocks();
    var cards=document.querySelectorAll('.fb-block-item');
    if(cards.length) cards[cards.length-1].scrollIntoView({behavior:'smooth',block:'start'});
    var tas=document.querySelectorAll('.fb-block-item textarea');
    if(tas.length) tas[tas.length-1].focus();
}

function fbRemoveBlock(btn){
    var idx=Array.from(document.querySelectorAll('.fb-block-item')).indexOf(btn.closest('.fb-block-item'));
    if(idx>-1) FB_BLOCKS.splice(idx,1);
    fbRenderBlocks();
}

document.getElementById('fbEditorForm').addEventListener('submit',function(e){
    var cards=document.querySelectorAll('.fb-block-item');
    for(var i=0;i<cards.length;i++){
        var text=cards[i].querySelector('textarea').value;
        var rawAns=cards[i].querySelector('input[name="answers[]"]').value;
        var answers=parseAnswers(rawAns);
        var blanks=countBlanks(text);
        if(blanks!==answers.length){
            alert(
                'Block '+(i+1)+': '+blanks+' blank(s) but '+answers.length+' answer(s).\n\n'+
                'Tip: separate answers with | (pipe), not comma.\n'+
                'Example: "raining cats and dogs | watched me like a hawk"'
            );
            e.preventDefault();
            cards[i].querySelector('textarea').focus();
            return false;
        }
    }
});

document.addEventListener('DOMContentLoaded',fbRenderBlocks);
</script>

<?php
$content = ob_get_clean();
render_activity_editor('Fill-in-the-Blank Editor', 'fa-solid fa-pen-to-square', $content);
