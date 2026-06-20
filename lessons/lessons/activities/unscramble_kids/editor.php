<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';
if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) { header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied'); exit; }
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) { header('Location: /lessons/lessons/academic/login.php'); exit; }
$activityId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';
$source = isset($_GET['source']) ? trim((string)$_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string)$_GET['assignment']) : '';

function usk_ed_resolve_unit(PDO $pdo, string $id): string {
    if ($id === '') return '';
    $st = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $st->execute(['id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r && isset($r['unit_id']) ? (string)$r['unit_id'] : '';
}
function usk_ed_def($c): array { return ['title'=>'Spell the Word','voice_id'=>'Nggzl2QAXh3OijoXD116','words'=>[]]; }
function usk_ed_norm($raw): array {
    $df = usk_ed_def(0);
    if ($raw === null || $raw === '') return $df;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $df;
    $words = [];
    foreach (($d['words'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $w = strtoupper(trim((string)($it['word'] ?? '')));
        if ($w === '') continue;
        $words[] = ['id'=>trim((string)($it['id'] ?? uniqid('usk_'))),'word'=>$w,'emoji'=>trim((string)($it['emoji'] ?? '')),'hint'=>trim((string)($it['hint'] ?? '')),'audio'=>trim((string)($it['audio'] ?? '')),'voice_id'=>trim((string)($it['voice_id'] ?? ''))];
    }
    $title = trim((string)($d['title'] ?? ''));
    $void = trim((string)($d['voice_id'] ?? 'Nggzl2QAXh3OijoXD116')) ?: 'Nggzl2QAXh3OijoXD116';
    return ['title'=>$title ? $title : 'Spell the Word','voice_id'=>$void,'words'=>$words];
}
function usk_ed_enc(array $p): string {
    return json_encode(['title'=>trim((string)($p['title'] ?? '')) ?: 'Spell the Word','voice_id'=>trim((string)($p['voice_id'] ?? 'Nggzl2QAXh3OijoXD116')) ?: 'Nggzl2QAXh3OijoXD116','words'=>array_values($p['words'] ?? [])],JSON_UNESCAPED_UNICODE);
}
function usk_ed_load(PDO $pdo, string $unit, string $id): array {
    $fb = ['id'=>'','title'=>'Spell the Word','voice_id'=>'Nggzl2QAXh3OijoXD116','words'=>[]];
    $row = null;
    if ($id !== '') { $st = $pdo->prepare("SELECT id,data FROM activities WHERE id=:id AND type='unscramble_kids' LIMIT 1"); $st->execute(['id'=>$id]); $row=$st->fetch(PDO::FETCH_ASSOC); }
    if (!$row && $unit !== '') { $st = $pdo->prepare("SELECT id,data FROM activities WHERE unit_id=:u AND type='unscramble_kids' ORDER BY id ASC LIMIT 1"); $st->execute(['u'=>$unit]); $row=$st->fetch(PDO::FETCH_ASSOC); }
    if (!$row) return $fb;
    $p = usk_ed_norm($row['data'] ?? null);
    return ['id'=>(string)($row['id'] ?? ''),'title'=>$p['title'],'voice_id'=>$p['voice_id'],'words'=>$p['words']];
}
function usk_ed_save(PDO $pdo, string $unit, string $id, string $title, string $void, array $words): string {
    $json = usk_ed_enc(['title'=>$title,'voice_id'=>$void,'words'=>$words]);
    $tid = $id;
    if ($tid === '') { $st=$pdo->prepare("SELECT id FROM activities WHERE unit_id=:u AND type='unscramble_kids' ORDER BY id ASC LIMIT 1"); $st->execute(['u'=>$unit]); $tid=trim((string)$st->fetchColumn()); }
    if ($tid !== '') { $st=$pdo->prepare("UPDATE activities SET data=:data WHERE id=:id AND type='unscramble_kids'"); $st->execute(['data'=>$json,'id'=>$tid]); return $tid; }
    $st=$pdo->prepare("INSERT INTO activities(unit_id,type,data,position,created_at) VALUES(:u,'unscramble_kids',:d,(SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id=:u2),CURRENT_TIMESTAMP) RETURNING id");
    $st->execute(['u'=>$unit,'u2'=>$unit,'d'=>$json]);
    return (string)$st->fetchColumn();
}
if ($unit==='' && $activityId!=='') { $unit=usk_ed_resolve_unit($pdo,$activityId); }
if ($unit==='') die('Unit not specified');
$activity = usk_ed_load($pdo,$unit,$activityId);
$activityTitle = $activity['title'];
$activityVoice = $activity['voice_id'];
$words = $activity['words'];
if ($activityId==='' && !empty($activity['id'])) $activityId=$activity['id'];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $postedTitle=trim((string)($_POST['activity_title'] ?? ''));
    $allowedVoices=['Nggzl2QAXh3OijoXD116','nzFihrBIvB34imQBuxub','NoOVOzCQFLOvtsMoNcdT'];
    $postedVoice=in_array(trim((string)($_POST['voice_id'] ?? '')),$allowedVoices,true) ? trim((string)$_POST['voice_id']) : 'Nggzl2QAXh3OijoXD116';
    $ids=is_array($_POST['word_id'] ?? null) ? $_POST['word_id'] : [];
    $wt=is_array($_POST['word'] ?? null) ? $_POST['word'] : [];
    $em=is_array($_POST['emoji'] ?? null) ? $_POST['emoji'] : [];
    $hi=is_array($_POST['hint'] ?? null) ? $_POST['hint'] : [];
    $au=is_array($_POST['audio'] ?? null) ? $_POST['audio'] : [];
    $vd=is_array($_POST['item_voice_id'] ?? null) ? $_POST['item_voice_id'] : [];
    $san=[];
    foreach($wt as $i => $rw) {
        $w=strtoupper(preg_replace('/[^A-Za-z]/','',trim((string)$rw)));
        if($w==='') continue;
        $san[]=['id'=>trim((string)($ids[$i] ?? uniqid('usk_'))) ?: uniqid('usk_'),'word'=>$w, 'emoji'=>trim((string)($em[$i] ?? '')),'hint'=>trim((string)($hi[$i] ?? '')),'audio'=>trim((string)($au[$i] ?? '')),'voice_id'=>trim((string)($vd[$i] ?? ''))];
    }
    $sid=usk_ed_save($pdo,$unit,$activityId,$postedTitle,$postedVoice,$san);
    $pr=['unit='.urlencode($unit),'saved=1'];
    if($sid!=='') $pr[]='id='.urlencode($sid);
    if($assignment!=='') $pr[]='assignment='.urlencode($assignment);
    if($source!=='') $pr[]='source='.urlencode($source);
    header('Location: editor.php?'.implode('&',$pr)); exit;
}
ob_start();
if(isset($_GET['saved'])) echo '<p style="color:#16a34a;font-weight:700;margin-bottom:15px">✔ Saved successfully</p>';
?>
<style>.usk-form{max-width:860px;margin:0 auto}.usk-title-box,.usk-word-item{background:#f9fafb;padding:16px;margin-bottom:14px;border-radius:12px;border:1px solid #e5e7eb}.usk-title-box label,.usk-word-item label{display:block;font-weight:700;margin-bottom:6px;font-size:13px;color:#374151}.usk-title-box input,.usk-title-box select,.usk-word-item input,.usk-word-item textarea{width:100%;padding:9px 12px;border-radius:8px;border:1px solid #d1d5db;box-sizing:border-box;margin-bottom:12px;font-size:14px;font-family:inherit}.usk-word-item .usk-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}.usk-word-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.usk-word-num{font-family:'Fredoka',sans-serif;font-size:16px;font-weight:600;color:#7F77DD}.usk-toolbar{display:flex;gap:10px;justify-content:center;margin-top:8px}.usk-btn-add{background:#16a34a;color:#fff;padding:10px 14px;border:none;border-radius:8px;cursor:pointer;font-weight:700}.usk-btn-remove{background:#ef4444;color:#fff;border:none;padding:7px 12px;border-radius:8px;cursor:pointer;font-weight:700}.usk-btn-save{background:linear-gradient(180deg,#7c3aed,#6d28d9);color:#fff;padding:10px 24px;border:none;border-radius:10px;cursor:pointer;font-weight:800;font-size:15}.usk-help{margin:-8px 0 10px;color:#6b7280;font-size:12px}.usk-btn-tts{display:inline-flex;align-items:center;gap:6px;background:#F97316;color:#fff;border:none;padding:7px 12px;border-radius:8px;cursor:pointer;font-weight:700;font-size:12px;margin-bottom:10px}.usk-audio-url{font-size:11px;color:#6b7280;word-break:break-all;margin-bottom:10px;display:none}.usk-audio-url.has-audio{display:block;color:#16a34a}</style>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@700;800" rel="stylesheet">
<form method="post" class="usk-form" id="uskEdForm">
<div class="usk-title-box">
<label for="usk_activity_title">Activity title</label>
<input id="usk_activity_title" type="text" name="activity_title" value="<?= htmlspecialchars($activityTitle,ENT_QUOTES,'UTF-8') ?>" placeholder="e.g. Spell the Animals" required>
<label for="usk_voice_id">Default voice for students</label>
<select id="usk_voice_id" name="voice_id">
<option value="Nggzl2QAXh3OijoXD116"<?= $activityVoice==='Nggzl2QAXh3OijoXD116'?' selected':'' ?>>Child (Candy) -- recommended</option>
<option value="nzFihrBIvB34imQBuxub"<?= $activityVoice==='nzFihrBIvB34imQBuxub'?' selected':'' ?>>Adult Male (Josh)</option>
<option value="NoOVOzCQFLOvtsMoNcdT"<?= $activityVoice==='NoOVOzCQFLOvtsMoNcdT'?' selected':'' ?>>Adult Female (Lily-)</option>
</select></div>
<div id="uskWordsContainer">
<?php foreach($words as $idx => $item): ?>
<div class="usk-word-item">
<input type="hidden" name="word_id[]" value="<?= htmlspecialchars((string)($item['id'] ?? uniqid('usk_')),ENT_QUOTES,'UTF-8') ?>">
<div class="usk-word-header"><span class="usk-word-num">Word <?= $idx+1 ?></span><button type="button" class="usk-btn-remove" onclick="uskRW(this)">✖ Remove</button></div>
<div class="usk-row2"><div><label>Word</label><input type="text" name="word[]" value="<?= htmlspecialchars((string)($item['word'] ?? ''),ENT_QUOTES,'UTF-8') ?>" placeholder="CAT" style="text-transform:uppercase;font-family:'Fredoka';font-size:18px;font-weight:600" required></div><div><label>Emoji (picture clue)</label><input type="text" name="emoji[]" value="<?= htmlspecialchars((string)($item['emoji'] ?? ''),ENT_QUOTES,'UTF-8') ?>" placeholder="🐱" style="font-size:22px"></div></div>
<label>Hint text (shown below picture)</label><input type="text" name="hint[]" value="<?= htmlspecialchars((string)($item['hint'] ?? ''),ENT_QUOTES,'UTF-8') ?>" placeholder="A pet that meows">
<input type="hidden" name="audio[]" class="usk-audio-hidden" value="<?= htmlspecialchars((string)($item['audio'] ?? ''),ENT_QUOTES,'UTF-8') ?>">
<input type="hidden" name="item_voice_id[]" value="<?= htmlspecialchars((string)($item['voice_id'] ?? ''),ENT_QUOTES,'UTF-8') ?>">
<input type="text" name="pre_audio_show" readonly style="display:none" value="<?= htmlspecialchars((string)($item['audio'] ?? ''),ENT_QUOTES,'UTF-8') ?>">
<button type="button" class="usk-btn-tts" onclick="uskGA(this)">🔊 Generate audio</button>
<div class="usk-audio-url<?= !empty($item['audio']) ? ' has-audio' : '' ?>"><?= !empty($item['audio']) ? '✔ '.htmlspecialchars((string)$item['audio'],ENT_QUOTES,'UTF-8') : '' ?></div>
</div>
<?php endforeach; ?>
</div>
<div class="usk-toolbar"><button type="button" class="usk-btn-add" onclick="uskAW()">+ Add Word</button><button type="submit" class="usk-btn-save">💾 Save</button></div>
</form>
<script>
let uskFC=false,uskFS=false,uskWC=<?=count($words)?>;
function uskMC(){uskFC=true;}
function uskRW(btn){btn.closest('.usk-word-item').remove();uskMC();uskRN();}
function uskRN(){document.querySelectorAll('.usk-word-item .usk-word-num').forEach((e,i)=>{e.textContent='Word '+(i+1);});}
function uskAW(){uskWC++;const id='usk_'+Date.now();const div=document.createElement('div');div.className='usk-word-item';div.innerHTML=`<input type="hidden" name="word_id[]" value="${id}"><div class="usk-word-header"><span class="usk-word-num">Word ${document.querySelectorAll('.usk-word-item').length+1}</span><button type="button" class="usk-btn-remove" onclick="uskRW(this)">✖ Remove</button></div><div class="usk-row2"><div><label>Word</label><input type="text" name="word[]" placeholder="DOG" style="text-transform:uppercase;font-family:'Fredoka';font-size:18px;font-weight:600" required></div><div><label>Emoji</label><input type="text" name="emoji[]" placeholder="🐶" style="font-size:22px"></div></div><label>Hint</label><input type="text" name="hint[]" placeholder="A pet that barks"><input type="hidden" name="audio[]" class="usk-audio-hidden" value=""><input type="hidden" name="item_voice_id[]" value=""><button type="button" class="usk-btn-tts" onclick="uskGA(this)">🔊 Generate</button><div class="usk-audio-url"></div>`;document.getElementById('uskWordsContainer').appendChild(div);uskBC(div);uskMC();uskRN();}
function uskGA(btn){const it=btn.closest('.usk-word-item');const we=it.querySelector('input[name="word[]"]');const ae=it.querySelector('.usk-audio-hidden');const se=it.querySelector('.usk-audio-url');const vi=document.getElementById('usk_voice_id').value;const w=(we?.value||'').trim().toLowerCase();if(!w){alert('Enter word first');return;}btn.disabled=true;btn.textContent='⏳';const fd=new FormData();fd.append('text',w);fd.append('voice_id',vi);fetch('tts.php',{method:'POST',body:fd,credentials:'same-origin'}).then(r=>{if(!r.ok)throw new Error('TTS '+r.status);return r.blob();}).then(blob=>{const cf=new FormData();cf.append('file',blob,w+'.mp3');cf.append('upload_preset','ml_default');return fetch('https://api.cloudinary.com/v1_1/YOUR_CLOUD_NAME/auto/upload',{method:'POST',body:cf});}).then(r=>r.json()).then(d=>{const u=d.secure_url||'';if(!u)throw new Error('No URL');ae.value=u;se.className='usk-audio-url has-audio';se.textContent='✔ '+u;uskMC();}).catch(e=>{se.textContent='✘ '+e.message;}).finally(()=>{btn.disabled=false;btn.textContent='🔊 Generate';});}
function uskBC(s){s.querySelectorAll('input,textarea,select').forEach(e=>{e.addEventListener('input',uskMC);e.addEventListener('change',uskMC);});}
document.addEventListener('DOMContentLoaded',()=>{uskBC(document);const f=document.getElementById('uskEdForm');if(f)f.addEventListener('submit',()=>{uskFS=true;uskFC=false;});});
window.addEventListener('beforeunload',e=>{if(uskFC&&!uskFS){e.preventDefault();e.returnValue='';}});
</script>
<?php
$content=ob_get_clean();
render_activity_editor('🔤 Unscramble Kids Editor','fa-solid fa-spell-check',$content);
