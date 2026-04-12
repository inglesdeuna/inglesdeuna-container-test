<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])   ? trim((string) $_GET['id'])   : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') die('Activity not specified');

/* ── helpers ── */
function os_default_title_v(): string { return 'Order the Sentences'; }

function os_resolve_unit_v(PDO $pdo, string $id): string
{
    if ($id === '') return '';
    $s = $pdo->prepare('SELECT unit_id FROM activities WHERE id=:id LIMIT 1');
    $s->execute(['id' => $id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ? (string)($r['unit_id'] ?? '') : '';
}

function os_normalize_v(mixed $raw): array
{
    $def = [
        'title' => os_default_title_v(),
        'instructions' => 'Listen and put the sentences in the correct order.',
        'media_type' => 'tts', 'media_url' => '', 'tts_text' => '',
        'sentences' => [],
    ];
    if ($raw === null || $raw === '') return $def;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $def;

    $sentences = [];
    foreach ((array)($d['sentences'] ?? []) as $s) {
        $text = trim((string)($s['text'] ?? ''));
        if ($text === '') continue;
        $sentences[] = ['id' => (string)($s['id'] ?? uniqid('os_')), 'text' => $text];
    }

    return [
        'title'        => trim((string)($d['title'] ?? '')) ?: $def['title'],
        'instructions' => trim((string)($d['instructions'] ?? '')) ?: $def['instructions'],
        'media_type'   => in_array($d['media_type'] ?? '', ['tts','video','audio','none'], true)
                            ? $d['media_type'] : 'tts',
        'media_url'    => trim((string)($d['media_url'] ?? '')),
        'tts_text'     => trim((string)($d['tts_text'] ?? '')),
        'sentences'    => $sentences,
    ];
}

function os_load_v(PDO $pdo, string $activityId, string $unit): array
{
    $row = null;
    if ($activityId !== '') {
    $s = $pdo->prepare("SELECT id, data FROM activities WHERE id=:id AND type='order_sentences' LIMIT 1");
        $s->execute(['id' => $activityId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
    $s = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id=:u AND type='order_sentences' ORDER BY id ASC LIMIT 1");
        $s->execute(['u' => $unit]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
    }
  if (!$row) {
    $fallback = os_normalize_v(null);
    $fallback['id'] = '';
    return $fallback;
  }
  $payload = os_normalize_v($row['data'] ?? null);
  $payload['id'] = (string) ($row['id'] ?? '');
  return $payload;
}

if ($unit === '' && $activityId !== '') $unit = os_resolve_unit_v($pdo, $activityId);

$activity     = os_load_v($pdo, $activityId, $unit);
$viewerTitle  = $activity['title'];
$resolvedActivityId = trim((string) ($activity['id'] ?? ''));
if ($activityId === '' && $resolvedActivityId !== '') {
  $activityId = $resolvedActivityId;
}
$sentences    = $activity['sentences'];
$isVideoLayout = ($activity['media_type'] === 'video' && trim((string) $activity['media_url']) !== '');

if (count($sentences) === 0) die('No sentences configured for this activity');

ob_start();
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap');

.os-stage{
  max-width:780px;
  margin:0 auto;
  font-family:'Nunito','Segoe UI',sans-serif;
}

.os-stage.os-video-layout{
  max-width:1200px;
}

.os-stage.os-video-layout .os-right-col{
  min-width:0;
}

.os-stage.os-video-layout .os-header{
  margin-bottom:14px;
}

.os-stage.os-video-layout .os-list-wrap{
  margin-bottom:14px;
}

/* ── header card ── */
.os-header{
  background:linear-gradient(135deg,#eef4ff 0%,#f0e9ff 50%,#e8fff7 100%);
  border:1px solid #d9cff6;
  border-radius:26px;
  padding:26px 28px 20px;
  margin-bottom:22px;
  box-shadow:0 16px 34px rgba(15,23,42,.09);
  text-align:center;
}
.os-header h2{
  margin:0 0 6px;
  font-family:'Fredoka','Trebuchet MS',sans-serif;
  font-size:30px;
  color:#4c1d95;
  line-height:1.1;
}
.os-header p.os-instructions{
  margin:0 0 16px;
  color:#5b516f;
  font-size:16px;
}

/* ── media ── */
.os-media{
  margin-bottom:6px;
}
.os-video-wrap{
  position:relative;
  padding-bottom:56.25%;
  height:0;
  border-radius:16px;
  overflow:hidden;
  box-shadow:0 8px 20px rgba(0,0,0,.12);
}
.os-video-wrap iframe,
.os-video-wrap video{
  position:absolute;top:0;left:0;
  width:100%;height:100%;border:none;border-radius:16px;
}
.os-audio-wrap{
  margin:0 auto;
  max-width:480px;
}
.os-audio-wrap audio{width:100%;border-radius:10px;}

.os-btn-listen{
  display:inline-flex;align-items:center;gap:8px;
  padding:12px 24px;border:none;border-radius:999px;
  background:linear-gradient(180deg,#38bdf8,#0ea5e9);
  color:#fff;font-weight:800;font-size:15px;
  font-family:inherit;cursor:pointer;
  box-shadow:0 8px 18px rgba(14,165,233,.3);
  transition:transform .15s,filter .15s;
}
.os-btn-listen:hover{filter:brightness(1.06);transform:scale(1.03);}
.os-btn-listen .icon{font-size:20px;}

/* ── sentence list ── */
.os-list-wrap{
  background:linear-gradient(180deg,#fdfcff,#f4f1ff);
  border:1px solid #ddd6fe;
  border-radius:24px;
  padding:20px;
  box-shadow:0 12px 24px rgba(15,23,42,.08);
  margin-bottom:18px;
}
.os-list-wrap h3{
  margin:0 0 14px;
  font-family:'Fredoka','Trebuchet MS',sans-serif;
  font-size:20px;
  color:#5b21b6;
  text-align:center;
}

#os-sortable{
  list-style:none;
  margin:0;
  padding:0;
  display:flex;
  flex-direction:column;
  gap:10px;
}

.os-item{
  display:flex;
  align-items:center;
  gap:12px;
  background:#fff;
  border:2px solid #ede9fe;
  border-radius:14px;
  padding:12px 14px;
  cursor:grab;
  user-select:none;
  transition:border-color .18s,box-shadow .18s,transform .12s;
  box-shadow:0 4px 10px rgba(109,40,217,.07);
}
.os-item:hover{
  border-color:#a78bfa;
  box-shadow:0 8px 20px rgba(109,40,217,.14);
}
.os-item.dragging{
  opacity:.45;
}
.os-item.over{
  border-color:#7c3aed;
  box-shadow:0 0 0 3px rgba(124,58,237,.18);
  transform:scale(1.015);
}

.os-item .pos-badge{
  min-width:30px;height:30px;
  background:linear-gradient(180deg,#8b5cf6,#7c3aed);
  border-radius:50%;display:flex;align-items:center;justify-content:center;
  color:#fff;font-weight:800;font-size:13px;
  flex-shrink:0;
}
.os-item .drag-handle{
  color:#c4b5fd;font-size:20px;flex-shrink:0;
  transition:color .15s;
}
.os-item:hover .drag-handle{color:#7c3aed;}
.os-item .sentence-text{
  flex:1;font-size:16px;font-weight:700;color:#1e1b4b;line-height:1.3;
}
.os-item .check-icon{
  font-size:20px;min-width:24px;text-align:center;
  opacity:0;transition:opacity .2s;
}

/* states after check */
.os-item.correct{
  background:#f0fdf4;border-color:#86efac;
}
.os-item.correct .pos-badge{background:linear-gradient(180deg,#22c55e,#16a34a);}
.os-item.correct .check-icon{opacity:1;}

.os-item.incorrect{
  background:#fef2f2;border-color:#fca5a5;
}
.os-item.incorrect .pos-badge{background:linear-gradient(180deg,#f87171,#dc2626);}
.os-item.incorrect .check-icon{opacity:1;}

/* ── controls ── */
.os-controls{
  display:flex;flex-wrap:wrap;justify-content:center;gap:12px;
  margin-bottom:14px;
}
.os-btn{
  padding:12px 22px;border:none;border-radius:999px;
  color:#fff;font-weight:800;font-size:14px;font-family:inherit;
  cursor:pointer;min-width:140px;
  box-shadow:0 8px 18px rgba(15,23,42,.13);
  transition:transform .15s,filter .15s;
}
.os-btn:hover{filter:brightness(1.05);transform:translateY(-1px);}
.os-btn-check  {background:linear-gradient(180deg,#8b5cf6,#7c3aed);}
.os-btn-show   {background:linear-gradient(180deg,#f97316,#ea580c);}
.os-btn-retry  {background:linear-gradient(180deg,#38bdf8,#0ea5e9);}
.os-btn-next   {background:linear-gradient(180deg,#2dd4bf,#0f766e);}

#os-feedback{
  text-align:center;font-size:18px;font-weight:800;
  min-height:26px;margin-bottom:10px;
  transition:color .2s;
}
.os-good{color:#16a34a;}
.os-bad {color:#dc2626;}

/* ── completed screen ── */
.os-completed{
  display:none;text-align:center;padding:40px 16px;
  max-width:500px;margin:0 auto;
}
.os-completed.active{display:block;}
.os-completed-icon{font-size:72px;margin-bottom:14px;}
.os-completed h3{
  font-family:'Fredoka','Trebuchet MS',sans-serif;
  font-size:32px;color:#4c1d95;margin:0 0 10px;
}
.os-completed p{color:#5b516f;font-size:16px;margin:0 0 24px;}
.os-completed .os-btn-retry{display:inline-block;}

@media(max-width:600px){
  .os-header{padding:18px 14px 14px;}
  .os-header h2{font-size:24px;}
  .os-list-wrap{padding:14px;}
  .os-btn{min-width:120px;font-size:13px;}
  .os-item .sentence-text{font-size:14px;}
}
</style>

<?php
$videoUrl = trim((string) ($activity['media_url'] ?? ''));
$isYoutubeMedia = false;
$embedVideoUrl = $videoUrl;
if ($isVideoLayout) {
  $isYoutubeMedia = (bool) preg_match('/youtube\.com|youtu\.be/', $videoUrl);
  if ($isYoutubeMedia) {
    preg_match('/(?:v=|youtu\.be\/)([A-Za-z0-9_\-]{11})/', $videoUrl, $m);
    $vid = $m[1] ?? '';
    $embedVideoUrl = $vid ? "https://www.youtube.com/embed/{$vid}?rel=0" : $videoUrl;
  }
}
?>

<?php if ($isVideoLayout): ?>
<div class="os-stage os-video-layout vtc-layout">
  <div class="vtc-video-col">
    <?php if ($isYoutubeMedia): ?>
      <div class="vtc-video-box is-iframe">
        <iframe src="<?= htmlspecialchars($embedVideoUrl, ENT_QUOTES, 'UTF-8') ?>"
                allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope"
                allowfullscreen></iframe>
      </div>
    <?php else: ?>
      <div class="vtc-video-box">
        <video controls>
          <source src="<?= htmlspecialchars($embedVideoUrl, ENT_QUOTES, 'UTF-8') ?>">
        </video>
      </div>
    <?php endif; ?>
  </div>

  <div class="vtc-content-col os-right-col">
    <div class="os-header">
      <h2><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
      <p class="os-instructions"><?= htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="os-list-wrap" id="os-main">
      <h3>📋 Drag the sentences into the correct order</h3>
      <ul id="os-sortable"></ul>
    </div>

    <div class="os-controls">
      <button type="button" class="os-btn os-btn-check"  onclick="osCheck()">✔ Check Order</button>
      <button type="button" class="os-btn os-btn-show"   onclick="osShowAnswer()">💡 Show Answer</button>
      <button type="button" class="os-btn os-btn-retry"  onclick="osReset()">🔄 Try Again</button>
    </div>

    <div id="os-feedback"></div>

    <div class="os-completed" id="os-completed">
      <div class="os-completed-icon">🎉</div>
      <h3 id="os-completed-title"></h3>
      <p id="os-completed-msg"></p>
      <button type="button" class="os-btn os-btn-retry" onclick="osReset()">🔄 Play Again</button>
    </div>
  </div>
</div>
<?php else: ?>

<div class="os-stage">

  <!-- header / media -->
  <div class="os-header">
    <h2><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="os-instructions"><?= htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8') ?></p>

    <div class="os-media" id="os-media">
      <?php if ($activity['media_type'] === 'video' && $activity['media_url'] !== ''): ?>
        <?php
          $vurl = $activity['media_url'];
          // Detect YouTube
          $isYT = preg_match('/youtube\.com|youtu\.be/', $vurl);
          if ($isYT) {
              // Convert to embed URL
              preg_match('/(?:v=|youtu\.be\/)([A-Za-z0-9_\-]{11})/', $vurl, $m);
              $vid = $m[1] ?? '';
              $vurl = $vid ? "https://www.youtube.com/embed/{$vid}?rel=0" : $vurl;
          }
        ?>
        <div class="os-video-wrap">
          <?php if ($isYT): ?>
            <iframe src="<?= htmlspecialchars($vurl, ENT_QUOTES, 'UTF-8') ?>"
                    allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope"
                    allowfullscreen></iframe>
          <?php else: ?>
            <video controls>
              <source src="<?= htmlspecialchars($vurl, ENT_QUOTES, 'UTF-8') ?>">
            </video>
          <?php endif; ?>
        </div>

      <?php elseif ($activity['media_type'] === 'audio' && $activity['media_url'] !== ''): ?>
        <div class="os-audio-wrap">
          <audio controls>
            <source src="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>">
          </audio>
        </div>

      <?php elseif ($activity['media_type'] === 'tts'): ?>
        <button class="os-btn-listen" type="button" id="os-listen-btn" onclick="osPlayTTS()">
          <span class="icon">🔊</span> Listen
        </button>

      <?php endif; ?>
    </div>
  </div>

  <!-- sentence list -->
  <div class="os-list-wrap" id="os-main">
    <h3>📋 Drag the sentences into the correct order</h3>
    <ul id="os-sortable"></ul>
  </div>

  <!-- controls -->
  <div class="os-controls">
    <button type="button" class="os-btn os-btn-check"  onclick="osCheck()">✔ Check Order</button>
    <button type="button" class="os-btn os-btn-show"   onclick="osShowAnswer()">💡 Show Answer</button>
    <button type="button" class="os-btn os-btn-retry"  onclick="osReset()">🔄 Try Again</button>
  </div>

  <div id="os-feedback"></div>

  <!-- completed -->
  <div class="os-completed" id="os-completed">
    <div class="os-completed-icon">🎉</div>
    <h3 id="os-completed-title"></h3>
    <p id="os-completed-msg"></p>
    <button type="button" class="os-btn os-btn-retry" onclick="osReset()">🔄 Play Again</button>
  </div>
</div>
<?php endif; ?>

<audio id="os-win"  src="../../hangman/assets/win.mp3"       preload="auto"></audio>
<audio id="os-lose" src="../../hangman/assets/lose.mp3"      preload="auto"></audio>
<audio id="os-done" src="../../hangman/assets/win (1).mp3"   preload="auto"></audio>

<script>
const OS_SENTENCES = <?= json_encode(array_values($sentences), JSON_UNESCAPED_UNICODE) ?>;
const OS_TITLE     = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
const OS_TTS_TEXT  = <?= json_encode($activity['tts_text'], JSON_UNESCAPED_UNICODE) ?>;
const OS_MEDIA_TYPE = <?= json_encode($activity['media_type'], JSON_UNESCAPED_UNICODE) ?>;
const OS_RETURN_TO  = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const OS_ACTIVITY_ID = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;

/* ─────────── state ─────────── */
var osCurrent   = [];   // shuffled order
var osChecked   = false;
var osAttempts  = 0;

/* ─────────── TTS ─────────── */
var osTTSut  = null;
var osTTSspk = false;

function osPlayTTS() {
  var text = OS_TTS_TEXT || OS_SENTENCES.map(function(s){ return s.text; }).join('. ');
  if (!text.trim()) return;

  if (window.speechSynthesis.speaking) {
    window.speechSynthesis.cancel();
    osTTSspk = false;
    var btn = document.getElementById('os-listen-btn');
    if (btn) btn.querySelector('.icon').textContent = '🔊';
    return;
  }

  var btn = document.getElementById('os-listen-btn');
  if (btn) btn.querySelector('.icon').textContent = '⏹';

  osTTSut = new SpeechSynthesisUtterance(text);
  osTTSut.lang  = 'en-US';
  osTTSut.rate  = 0.75;
  osTTSut.pitch = 1;
  osTTSut.onend = function() {
    osTTSspk = false;
    if (btn) btn.querySelector('.icon').textContent = '🔊';
  };
  osTTSspk = true;
  window.speechSynthesis.speak(osTTSut);
}

/* ─────────── shuffle ─────────── */
function shuffle(arr) {
  var a = arr.slice();
  for (var i = a.length - 1; i > 0; i--) {
    var j = Math.floor(Math.random() * (i + 1));
    var t = a[i]; a[i] = a[j]; a[j] = t;
  }
  // Ensure it's not already sorted
  if (a.length > 1 && JSON.stringify(a.map(function(x){return x.id;})) ===
      JSON.stringify(OS_SENTENCES.map(function(x){return x.id;}))) {
    var tmp = a[0]; a[0] = a[1]; a[1] = tmp;
  }
  return a;
}

/* ─────────── render list ─────────── */
function osRender() {
  var ul = document.getElementById('os-sortable');
  ul.innerHTML = '';
  osCurrent.forEach(function(s, i) {
    var li = document.createElement('li');
    li.className = 'os-item';
    li.dataset.id = s.id;
    li.draggable = true;
    li.innerHTML =
      '<span class="pos-badge">' + (i + 1) + '</span>' +
      '<span class="drag-handle">⠿</span>' +
      '<span class="sentence-text">' + escHtml(s.text) + '</span>' +
      '<span class="check-icon"></span>';
    ul.appendChild(li);
  });
  updatePosBadges();
  attachDragListeners();
}

function updatePosBadges() {
  var items = document.querySelectorAll('#os-sortable .os-item');
  items.forEach(function(el, i) {
    var badge = el.querySelector('.pos-badge');
    if (badge) badge.textContent = i + 1;
  });
}

function escHtml(str) {
  return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ─────────── drag & drop ─────────── */
var osDragSrc = null;

function attachDragListeners() {
  var items = document.querySelectorAll('#os-sortable .os-item');
  items.forEach(function(el) {
    el.addEventListener('dragstart', onDragStart);
    el.addEventListener('dragend',   onDragEnd);
    el.addEventListener('dragover',  onDragOver);
    el.addEventListener('drop',      onDrop);
    el.addEventListener('dragenter', onDragEnter);
    el.addEventListener('dragleave', onDragLeave);
  });
}

function onDragStart(e) {
  if (osChecked) return;
  osDragSrc = this;
  e.dataTransfer.effectAllowed = 'move';
  this.classList.add('dragging');
}

function onDragEnd() {
  this.classList.remove('dragging');
  document.querySelectorAll('#os-sortable .os-item').forEach(function(el){
    el.classList.remove('over');
  });
  syncCurrentFromDOM();
  updatePosBadges();
  clearFeedback();
}

function onDragEnter(e) {
  if (osChecked) return;
  e.preventDefault();
  this.classList.add('over');
}

function onDragLeave() {
  this.classList.remove('over');
}

function onDragOver(e) {
  if (osChecked) return;
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  var ul = document.getElementById('os-sortable');
  var after = getDragAfterEl(ul, e.clientY);
  if (after == null) {
    ul.appendChild(osDragSrc);
  } else if (after !== osDragSrc) {
    ul.insertBefore(osDragSrc, after);
  }
}

function onDrop(e) {
  e.stopPropagation();
  this.classList.remove('over');
}

function getDragAfterEl(ul, y) {
  var items = Array.from(ul.querySelectorAll('.os-item:not(.dragging)'));
  return items.reduce(function(closest, child) {
    var box = child.getBoundingClientRect();
    var offset = y - box.top - box.height / 2;
    if (offset < 0 && offset > closest.offset) {
      return { offset: offset, element: child };
    }
    return closest;
  }, { offset: Number.NEGATIVE_INFINITY }).element;
}

/* Touch support */
(function() {
  var touchDrag = null;
  var touchGhost = null;

  document.addEventListener('touchstart', function(e) {
    var el = e.target.closest('.os-item');
    if (!el || osChecked) return;
    touchDrag = el;
    el.classList.add('dragging');
    touchGhost = el.cloneNode(true);
    touchGhost.style.cssText = 'position:fixed;opacity:.65;pointer-events:none;z-index:9999;width:' + el.offsetWidth + 'px;';
    document.body.appendChild(touchGhost);
  }, { passive: true });

  document.addEventListener('touchmove', function(e) {
    if (!touchDrag) return;
    e.preventDefault();
    var t = e.touches[0];
    touchGhost.style.left = (t.clientX - touchGhost.offsetWidth / 2) + 'px';
    touchGhost.style.top  = (t.clientY - touchGhost.offsetHeight / 2) + 'px';

    var ul = document.getElementById('os-sortable');
    var after = getDragAfterEl(ul, t.clientY);
    if (after == null) {
      ul.appendChild(touchDrag);
    } else if (after !== touchDrag) {
      ul.insertBefore(touchDrag, after);
    }
    updatePosBadges();
  }, { passive: false });

  document.addEventListener('touchend', function() {
    if (!touchDrag) return;
    touchDrag.classList.remove('dragging');
    if (touchGhost) { touchGhost.remove(); touchGhost = null; }
    syncCurrentFromDOM();
    updatePosBadges();
    clearFeedback();
    touchDrag = null;
  });
})();

function syncCurrentFromDOM() {
  var ids = Array.from(document.querySelectorAll('#os-sortable .os-item'))
                 .map(function(el){ return el.dataset.id; });
  osCurrent = ids.map(function(id) {
    return OS_SENTENCES.find(function(s){ return s.id === id; }) || { id: id, text: '' };
  });
}

/* ─────────── check ─────────── */
function osCheck() {
  if (osChecked) return;
  osAttempts++;

  var items    = document.querySelectorAll('#os-sortable .os-item');
  var allRight = true;
  var correct  = 0;

  items.forEach(function(el, i) {
    var id = el.dataset.id;
    var expectedId = OS_SENTENCES[i].id;
    var isOk = (id === expectedId);
    if (isOk) {
      el.classList.add('correct');
      el.classList.remove('incorrect');
      el.querySelector('.check-icon').textContent = '✅';
      correct++;
    } else {
      el.classList.add('incorrect');
      el.classList.remove('correct');
      el.querySelector('.check-icon').textContent = '❌';
      allRight = false;
    }
  });

  var fb = document.getElementById('os-feedback');

  if (allRight) {
    fb.textContent = '🎉 Perfect order!';
    fb.className = 'os-good';
    osChecked = true;
    playSound('os-win');
    setTimeout(function(){ osShowCompleted(correct, OS_SENTENCES.length); }, 900);
  } else {
    if (osAttempts < 2) {
      fb.textContent = '✖ Not quite — try again!';
      fb.className = 'os-bad';
      playSound('os-lose');
      setTimeout(function() {
        if (!osChecked) clearMarks();
      }, 1200);
    } else {
      fb.textContent = '✖ Not quite — ' + correct + '/' + OS_SENTENCES.length + ' correct.';
      fb.className = 'os-bad';
      playSound('os-lose');
      osChecked = true;
      setTimeout(function(){ osShowCompleted(correct, OS_SENTENCES.length); }, 900);
    }
  }
}

function clearMarks() {
  document.querySelectorAll('#os-sortable .os-item').forEach(function(el) {
    el.classList.remove('correct','incorrect');
    el.querySelector('.check-icon').textContent = '';
  });
}

function clearFeedback() {
  var fb = document.getElementById('os-feedback');
  fb.textContent = '';
  fb.className = '';
  if (!osChecked) clearMarks();
}

function osCountCorrectPositions() {
  var items = document.querySelectorAll('#os-sortable .os-item');
  var correct = 0;
  items.forEach(function(el, i) {
    if (OS_SENTENCES[i] && el.dataset.id === OS_SENTENCES[i].id) {
      correct++;
    }
  });
  return correct;
}

function osPersistScoreAndReturn(correct, total) {
  var safeTotal = Math.max(0, Number(total || 0));
  var safeCorrect = Math.max(0, Math.min(safeTotal, Number(correct || 0)));
  var errors = Math.max(0, safeTotal - safeCorrect);
  var pct = safeTotal > 0 ? Math.round((safeCorrect / safeTotal) * 100) : 0;

  if (!OS_RETURN_TO || !OS_ACTIVITY_ID) {
    return Promise.resolve(false);
  }

  var joiner = OS_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
  var saveUrl = OS_RETURN_TO
    + joiner + 'activity_percent=' + encodeURIComponent(String(pct))
    + '&activity_errors=' + encodeURIComponent(String(errors))
    + '&activity_total=' + encodeURIComponent(String(safeTotal))
    + '&activity_id=' + encodeURIComponent(String(OS_ACTIVITY_ID))
    + '&activity_type=order_sentences';

  return fetch(saveUrl, {
    method: 'GET',
    credentials: 'same-origin',
    cache: 'no-store'
  }).then(function (response) {
    if (!(response && response.ok)) {
      throw new Error('save failed');
    }
    return true;
  }).catch(function () {
    try {
      if (window.top && window.top !== window) {
        window.top.location.href = saveUrl;
      } else {
        window.location.href = saveUrl;
      }
    } catch (e) {
      window.location.href = saveUrl;
    }
    return false;
  });
}

/* ─────────── show answer ─────────── */
function osShowAnswer() {
  if (document.getElementById('os-completed').classList.contains('active')) return;
  var currentCorrect = osCountCorrectPositions();
  osChecked = true;
  var ul = document.getElementById('os-sortable');
  ul.innerHTML = '';
  OS_SENTENCES.forEach(function(s, i) {
    var li = document.createElement('li');
    li.className = 'os-item correct';
    li.dataset.id = s.id;
    li.draggable = false;
    li.innerHTML =
      '<span class="pos-badge">' + (i + 1) + '</span>' +
      '<span class="drag-handle">⠿</span>' +
      '<span class="sentence-text">' + escHtml(s.text) + '</span>' +
      '<span class="check-icon">✅</span>';
    ul.appendChild(li);
  });
  var fb = document.getElementById('os-feedback');
  fb.textContent = '👆 Correct order shown';
  fb.className = 'os-good';
  setTimeout(function(){ osShowCompleted(currentCorrect, OS_SENTENCES.length); }, 700);
}

/* ─────────── reset ─────────── */
function osReset() {
  window.speechSynthesis && window.speechSynthesis.cancel();
  osChecked  = false;
  osAttempts = 0;
  var fb = document.getElementById('os-feedback');
  fb.textContent = ''; fb.className = '';
  document.getElementById('os-completed').classList.remove('active');
  document.getElementById('os-main').style.display = '';
  var controls = document.querySelector('.os-controls');
  if (controls) controls.style.display = '';
  osCurrent = shuffle(OS_SENTENCES);
  osRender();
}

/* ─────────── completed screen ─────────── */
function osShowCompleted(correct, total) {
  document.getElementById('os-main').style.display = 'none';
  var controls = document.querySelector('.os-controls');
  if (controls) controls.style.display = 'none';
  var el = document.getElementById('os-completed');
  el.classList.add('active');
  document.getElementById('os-completed-title').textContent = OS_TITLE;
  var safeTotal = Math.max(0, Number(total || OS_SENTENCES.length));
  var safeCorrect = Math.max(0, Math.min(safeTotal, Number(correct || 0)));
  var pct = safeTotal > 0 ? Math.round((safeCorrect / safeTotal) * 100) : 0;
  document.getElementById('os-completed-msg').textContent =
    "Score: " + safeCorrect + " / " + safeTotal + " (" + pct + "%).";
  playSound('os-done');
  osPersistScoreAndReturn(safeCorrect, safeTotal);
}

/* ─────────── audio helpers ─────────── */
function playSound(id) {
  var el = document.getElementById(id);
  if (!el) return;
  try { el.pause(); el.currentTime = 0; el.play(); } catch(e){}
}

/* ─────────── init ─────────── */
osCurrent = shuffle(OS_SENTENCES);
osRender();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '📋', $content);
