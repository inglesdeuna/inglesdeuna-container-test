<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source     = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

function us_resolve_unit(PDO $pdo, string $id): string {
    if ($id === '') return '';
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function us_default_title(): string { return 'Unscramble the Sentence'; }
function us_normalize_title(string $t): string { $t = trim($t); return $t !== '' ? $t : us_default_title(); }

function us_normalize_payload($raw): array {
    $default = ['title' => us_default_title(), 'voice_id' => 'nzFihrBIvB34imQBuxub', 'sentences' => []];
    if ($raw === null || $raw === '') return $default;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $d;
    $sentences = [];
    foreach ($d['sentences'] ?? [] as $item) {
        if (!is_array($item)) continue;
        $sentence = trim((string) ($item['sentence'] ?? $item['text'] ?? ''));
        if ($sentence === '') continue;
        $sentences[] = [
            'id'             => trim((string) ($item['id'] ?? uniqid('us_'))),
            'sentence'       => $sentence,
            'listen_enabled' => (bool) ($item['listen_enabled'] ?? true),
            'audio'          => trim((string) ($item['audio'] ?? '')),
            'voice_id'       => trim((string) ($item['voice_id'] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub',
        ];
    }
    return [
        'title'     => us_normalize_title((string) ($d['title'] ?? '')),
        'voice_id'  => trim((string) ($d['voice_id'] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub',
        'sentences' => $sentences,
    ];
}

function us_encode_payload(array $p): string {
    return json_encode([
        'title'     => us_normalize_title((string) ($p['title'] ?? '')),
        'voice_id'  => trim((string) ($p['voice_id'] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub',
        'sentences' => array_values($p['sentences'] ?? []),
    ], JSON_UNESCAPED_UNICODE);
}

function us_load(PDO $pdo, string $unit, string $activityId): array {
    $fallback = ['id' => '', 'title' => us_default_title(), 'voice_id' => 'nzFihrBIvB34imQBuxub', 'sentences' => []];
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'unscramble' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'unscramble' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;
    $payload = us_normalize_payload($row['data'] ?? null);
    return ['id' => (string) ($row['id'] ?? ''), 'title' => $payload['title'], 'voice_id' => $payload['voice_id'], 'sentences' => $payload['sentences']];
}

function us_save(PDO $pdo, string $unit, string $activityId, string $title, string $voiceId, array $sentences): string {
    $json = us_encode_payload(['title' => $title, 'voice_id' => $voiceId, 'sentences' => $sentences]);
    $targetId = $activityId;
    if ($targetId === '') {
        $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id = :unit AND type = 'unscramble' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $targetId = trim((string) $stmt->fetchColumn());
    }
    if ($targetId !== '') {
        $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'unscramble'");
        $stmt->execute(['data' => $json, 'id' => $targetId]);
        return $targetId;
    }
    $stmt = $pdo->prepare("INSERT INTO activities (unit_id, type, data, position, created_at) VALUES (:unit_id, 'unscramble', :data, (SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id=:unit_id2), CURRENT_TIMESTAMP) RETURNING id");
    $stmt->execute(['unit_id' => $unit, 'unit_id2' => $unit, 'data' => $json]);
    return (string) $stmt->fetchColumn();
}

if ($unit === '' && $activityId !== '') $unit = us_resolve_unit($pdo, $activityId);
if ($unit === '') die('Unit not specified');

$activity      = us_load($pdo, $unit, $activityId);
$activityTitle = (string) ($activity['title'] ?? us_default_title());
$activityVoiceId = (string) ($activity['voice_id'] ?? 'nzFihrBIvB34imQBuxub');
$sentences     = is_array($activity['sentences'] ?? null) ? $activity['sentences'] : [];
if ($activityId === '' && !empty($activity['id'])) $activityId = (string) $activity['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = trim((string) ($_POST['activity_title'] ?? ''));
    $allowedVoices = ['nzFihrBIvB34imQBuxub', 'NoOVOzCQFLOvtsMoNcdT', 'Nggzl2QAXh3OijoXD116'];
    $postedVoiceId = isset($_POST['voice_id']) && in_array(trim((string) $_POST['voice_id']), $allowedVoices, true) ? trim((string) $_POST['voice_id']) : 'nzFihrBIvB34imQBuxub';
    $sentenceIds    = isset($_POST['sentence_id']) && is_array($_POST['sentence_id']) ? $_POST['sentence_id'] : [];
    $sentenceTexts  = isset($_POST['sentence']) && is_array($_POST['sentence']) ? $_POST['sentence'] : [];
    $listenValues   = isset($_POST['listen_enabled']) && is_array($_POST['listen_enabled']) ? $_POST['listen_enabled'] : [];
    $audioValues    = isset($_POST['audio']) && is_array($_POST['audio']) ? $_POST['audio'] : [];
    $voiceValues    = isset($_POST['item_voice_id']) && is_array($_POST['item_voice_id']) ? $_POST['item_voice_id'] : [];
    $sanitized = [];
    foreach ($sentenceTexts as $i => $textRaw) {
        $sentence = trim((string) $textRaw);
        if ($sentence === '') continue;
        $itemVoiceId = isset($voiceValues[$i]) && in_array(trim((string) $voiceValues[$i]), $allowedVoices, true) ? trim((string) $voiceValues[$i]) : $postedVoiceId;
        $sanitized[] = [
            'id'             => trim((string) ($sentenceIds[$i] ?? uniqid('us_'))) ?: uniqid('us_'),
            'sentence'       => $sentence,
            'listen_enabled' => isset($listenValues[$i]) && (string) $listenValues[$i] === '1',
            'audio'          => trim((string) ($audioValues[$i] ?? '')),
            'voice_id'       => $itemVoiceId,
        ];
    }
    $savedId = us_save($pdo, $unit, $activityId, $postedTitle, $postedVoiceId, $sanitized);
    $params = ['unit=' . urlencode($unit), 'saved=1'];
    if ($savedId !== '') $params[] = 'id=' . urlencode($savedId);
    if ($assignment !== '') $params[] = 'assignment=' . urlencode($assignment);
    if ($source !== '') $params[] = 'source=' . urlencode($source);
    header('Location: editor.php?' . implode('&', $params));
    exit;
}

ob_start();
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap');
.us-form{max-width:860px;margin:0 auto;text-align:left;font-family:'Nunito','Segoe UI',sans-serif}
.title-box,.sentence-item{background:#f9fafb;padding:14px;margin-bottom:14px;border-radius:12px;border:1px solid #e5e7eb}
.title-box label,.sentence-item label{display:block;font-weight:700;margin-bottom:8px}
.title-box input,.title-box select,.sentence-item input,.sentence-item textarea{width:100%;padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;box-sizing:border-box;margin-bottom:12px;font-size:14px;font-family:'Nunito','Segoe UI',sans-serif}
.sentence-item textarea{min-height:80px;resize:vertical}
.toolbar-row{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:8px}
.btn-add{background:#16a34a;color:#fff;padding:10px 14px;border:none;border-radius:8px;cursor:pointer;font-weight:700}
.btn-remove{background:#ef4444;color:#fff;border:none;padding:8px 12px;border-radius:8px;cursor:pointer;font-weight:700}
.save-btn{background:linear-gradient(180deg,#7c3aed,#6d28d9);color:#fff;padding:10px 20px;border:none;border-radius:10px;cursor:pointer;font-weight:800;font-size:15px;font-family:'Nunito','Segoe UI',sans-serif}
.checkbox-row{display:flex;align-items:center;gap:8px;font-weight:700;margin-bottom:10px}
.checkbox-row input[type="checkbox"]{width:auto;margin:0}
.tts-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px}
.tts-row select{min-width:200px;padding:9px 11px;border-radius:8px;border:1px solid #d1d5db;font-size:14px;font-family:'Nunito','Segoe UI',sans-serif}
.tts-btn{background:#7c3aed;color:#fff;border:none;border-radius:999px;padding:10px 16px;font-size:12px;font-weight:900;cursor:pointer;font-family:'Nunito','Segoe UI',sans-serif}
.tts-status{font-size:12px;font-weight:800;min-height:18px}
.tts-status.stale{color:#b45309}
.tts-preview{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.tts-preview audio{flex:1;height:36px}
.tts-remove{background:none;border:none;color:#E24B4A;font-size:11px;font-weight:900;cursor:pointer}
.saved-notice{max-width:860px;margin:0 auto 14px;padding:10px 12px;border-radius:10px;border:1px solid #86efac;background:#f0fdf4;color:#166534;font-weight:800}
</style>

<?php if (isset($_GET['saved'])) { ?><p class="saved-notice">✔ Saved successfully</p><?php } ?>

<form method="post" class="us-form" id="unscrambleForm">
<div class="title-box">
  <label for="activity_title">Activity title</label>
  <input id="activity_title" type="text" name="activity_title" value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" placeholder="Unscramble the sentences" required>
  <label for="voice_id">Default voice</label>
  <select id="voice_id" name="voice_id">
    <option value="nzFihrBIvB34imQBuxub"<?= $activityVoiceId === 'nzFihrBIvB34imQBuxub' ? ' selected' : '' ?>>Adult Male (Josh)</option>
    <option value="NoOVOzCQFLOvtsMoNcdT"<?= $activityVoiceId === 'NoOVOzCQFLOvtsMoNcdT' ? ' selected' : '' ?>>Adult Female (Lily)</option>
    <option value="Nggzl2QAXh3OijoXD116"<?= $activityVoiceId === 'Nggzl2QAXh3OijoXD116' ? ' selected' : '' ?>>Child (Candy)</option>
  </select>
</div>

<div id="sentencesContainer">
<?php foreach ($sentences as $item) {
  $iv = isset($item['voice_id']) && $item['voice_id'] !== '' ? $item['voice_id'] : $activityVoiceId;
?>
<div class="sentence-item">
  <input type="hidden" name="sentence_id[]" value="<?= htmlspecialchars((string) ($item['id'] ?? uniqid('us_')), ENT_QUOTES, 'UTF-8') ?>">
  <label>Sentence</label>
  <textarea name="sentence[]" required><?= htmlspecialchars((string) ($item['sentence'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>

  <div class="tts-row">
    <div>
      <label>Voice</label>
      <select name="item_voice_id[]" class="js-item-voice">
        <option value="nzFihrBIvB34imQBuxub"<?= $iv === 'nzFihrBIvB34imQBuxub' ? ' selected' : '' ?>>Adult Male (Josh)</option>
        <option value="NoOVOzCQFLOvtsMoNcdT"<?= $iv === 'NoOVOzCQFLOvtsMoNcdT' ? ' selected' : '' ?>>Adult Female (Lily)</option>
        <option value="Nggzl2QAXh3OijoXD116"<?= $iv === 'Nggzl2QAXh3OijoXD116' ? ' selected' : '' ?>>Child (Candy)</option>
      </select>
    </div>
    <button type="button" class="tts-btn js-gen-tts">Generate audio</button>
  </div>

  <input type="hidden" name="audio[]" value="<?= htmlspecialchars((string) ($item['audio'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
  <div class="tts-status js-tts-status"></div>
  <?php if (!empty($item['audio'])) { ?>
  <div class="tts-preview js-tts-preview">
    <audio src="<?= htmlspecialchars($item['audio'], ENT_QUOTES, 'UTF-8') ?>" controls preload="none"></audio>
    <button type="button" class="tts-remove js-remove-tts">✖ Remove</button>
  </div>
  <?php } ?>

  <label class="checkbox-row">
    <input type="hidden" name="listen_enabled[]" value="0">
    <input type="checkbox" value="1" <?= !empty($item['listen_enabled']) ? 'checked' : '' ?> onchange="syncCb(this)">
    Activate Listen
  </label>
  <button type="button" class="btn-remove" onclick="removeSentence(this)">✖ Remove</button>
</div>
<?php } ?>
</div>

<div class="toolbar-row">
  <button type="button" class="btn-add" onclick="addSentence()">+ Add Sentence</button>
  <button type="submit" class="save-btn">💾 Save</button>
</div>
</form>

<script>
let formChanged = false, formSubmitted = false;
const ALLOWED = ['nzFihrBIvB34imQBuxub','NoOVOzCQFLOvtsMoNcdT','Nggzl2QAXh3OijoXD116'];

function markChanged(){ formChanged = true; }
function syncCb(cb){ const h=cb.parentElement.querySelector('input[type="hidden"]'); if(h) h.value=cb.checked?'1':'0'; markChanged(); }
function removeSentence(btn){ btn.closest('.sentence-item')?.remove(); markChanged(); }

function makeItem(){
  const div=document.createElement('div'); div.className='sentence-item';
  div.innerHTML=`
    <input type="hidden" name="sentence_id[]" value="us_${Date.now()}">
    <label>Sentence</label>
    <textarea name="sentence[]" required></textarea>
    <div class="tts-row">
      <div><label>Voice</label>
      <select name="item_voice_id[]" class="js-item-voice">
        <option value="nzFihrBIvB34imQBuxub">Adult Male (Josh)</option>
        <option value="NoOVOzCQFLOvtsMoNcdT">Adult Female (Lily)</option>
        <option value="Nggzl2QAXh3OijoXD116">Child (Candy)</option>
      </select></div>
      <button type="button" class="tts-btn js-gen-tts">Generate audio</button>
    </div>
    <input type="hidden" name="audio[]" value="">
    <div class="tts-status js-tts-status"></div>
    <label class="checkbox-row">
      <input type="hidden" name="listen_enabled[]" value="1">
      <input type="checkbox" value="1" checked onchange="syncCb(this)">
      Activate Listen
    </label>
    <button type="button" class="btn-remove" onclick="removeSentence(this)">✖ Remove</button>`;
  return div;
}
function addSentence(){ const c=document.getElementById('sentencesContainer'); const d=makeItem(); c.appendChild(d); bindTracking(d); markChanged(); }

function markAudioStale(block){
  const ai=block.querySelector('input[name="audio[]"]');
  const pr=block.querySelector('.js-tts-preview');
  const st=block.querySelector('.js-tts-status');
  if(ai&&ai.value){ ai.value=''; if(pr)pr.remove(); if(st){st.textContent='Text/voice changed — regenerate audio.';st.classList.add('stale');} }
}

document.getElementById('sentencesContainer').addEventListener('input',e=>{
  if(e.target.matches('textarea[name="sentence[]"]')) markAudioStale(e.target.closest('.sentence-item'));
});
document.getElementById('sentencesContainer').addEventListener('change',e=>{
  if(e.target.matches('.js-item-voice')) markAudioStale(e.target.closest('.sentence-item'));
});

document.getElementById('sentencesContainer').addEventListener('click',e=>{
  const genBtn=e.target.closest('.js-gen-tts');
  const remBtn=e.target.closest('.js-remove-tts');
  if(genBtn){
    const block=genBtn.closest('.sentence-item');
    const txt=block.querySelector('textarea')?.value.trim()||'';
    const voice=block.querySelector('.js-item-voice')?.value||'nzFihrBIvB34imQBuxub';
    const ai=block.querySelector('input[name="audio[]"]');
    const st=block.querySelector('.js-tts-status');
    if(!txt){alert('Enter the sentence first.');return;}
    genBtn.disabled=true; if(st){st.textContent='Generating…';st.style.color='';st.classList.remove('stale');}
    const fd=new FormData(); fd.append('text',txt); fd.append('voice_id',voice);
    fetch('tts.php',{method:'POST',body:fd,credentials:'same-origin'})
      .then(r=>{if(r.status===401||r.status===403)throw Object.assign(new Error('Unauthorized'),{code:'AUTH'});return r.json();})
      .then(data=>{
        if(data.error){if(/unauthorized/i.test(data.error))throw Object.assign(new Error('Unauthorized'),{code:'AUTH'});throw new Error(data.error);}
        if(ai) ai.value=data.url;
        const old=block.querySelector('.js-tts-preview'); if(old)old.remove();
        const pr=document.createElement('div'); pr.className='tts-preview js-tts-preview';
        pr.innerHTML=`<audio src="${data.url}" controls preload="none"></audio><button type="button" class="tts-remove js-remove-tts">✖ Remove</button>`;
        block.insertBefore(pr,block.querySelector('.checkbox-row'));
        if(st){st.textContent='✔ Audio generated';st.style.color='#16a34a';}
        markChanged();
      })
      .catch(err=>{
        if(err&&err.code==='AUTH'){if(st){st.textContent='Session expired';st.style.color='#E24B4A';} setTimeout(()=>location.href='/lessons/lessons/academic/login.php?error=session_expired',700);return;}
        if(st){st.textContent='✘ '+(err?.message||'Failed');st.style.color='#E24B4A';}
      })
      .finally(()=>{genBtn.disabled=false;});
  }
  if(remBtn){
    const block=remBtn.closest('.sentence-item');
    const ai=block?.querySelector('input[name="audio[]"]');
    const st=block?.querySelector('.js-tts-status');
    const pr=block?.querySelector('.js-tts-preview');
    if(ai) ai.value=''; if(pr) pr.remove();
    if(st){st.textContent='Audio removed.';st.style.color='';}
    markChanged();
  }
});

function bindTracking(s){ s.querySelectorAll('input,textarea,select').forEach(el=>{el.addEventListener('input',markChanged);el.addEventListener('change',markChanged);}); }
document.addEventListener('DOMContentLoaded',()=>{
  bindTracking(document);
  document.querySelectorAll('.checkbox-row input[type="checkbox"]').forEach(cb=>{const h=cb.parentElement.querySelector('input[type="hidden"]');if(h)h.value=cb.checked?'1':'0';});
  document.getElementById('unscrambleForm').addEventListener('submit',()=>{formSubmitted=true;formChanged=false;});
});
window.addEventListener('beforeunload',e=>{if(formChanged&&!formSubmitted){e.preventDefault();e.returnValue='';}})
</script>
<?php
$content = ob_get_clean();
render_activity_editor('🔀 Unscramble Editor', '🔀', $content);
