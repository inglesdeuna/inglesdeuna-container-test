<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Actividad no especificada');
}

function activities_columns(PDO $pdo): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = array();

    $stmt = $pdo->query(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'activities'"
    );

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
        $stmt = $pdo->prepare(
            "SELECT unit_id
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit_id'])) {
            return (string) $row['unit_id'];
        }
    }

    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit'])) {
            return (string) $row['unit'];
        }
    }

    return '';
}

function default_dictation_title(): string
{
    return 'Dictation';
}

function normalize_activity_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_dictation_title();
}

function normalize_dictation_payload($rawData): array
{
    $default = array(
        'title' => default_dictation_title(),
        'items' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;

    if (!is_array($decoded)) {
        return $default;
    }

    $title = '';
    $itemsSource = $decoded;

    if (isset($decoded['title'])) {
        $title = trim((string) $decoded['title']);
    }

    if (isset($decoded['items']) && is_array($decoded['items'])) {
        $itemsSource = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $itemsSource = $decoded['data'];
    } elseif (isset($decoded['words']) && is_array($decoded['words'])) {
        $itemsSource = $decoded['words'];
    }

    $normalizedItems = array();

    if (is_array($itemsSource)) {
        foreach ($itemsSource as $item) {
            if (!is_array($item)) {
                continue;
            }

            $en = isset($item['en']) ? trim((string) $item['en']) : '';
            if ($en === '' && isset($item['word'])) {
                $en = trim((string) $item['word']);
            }
            if ($en === '' && isset($item['sentence'])) {
                $en = trim((string) $item['sentence']);
            }

            $normalizedItems[] = array(
                'img' => isset($item['img']) ? trim((string) $item['img']) : (isset($item['image']) ? trim((string) $item['image']) : ''),
                'en' => $en,
                'ph' => isset($item['ph']) ? trim((string) $item['ph']) : '',
                'es' => isset($item['es']) ? trim((string) $item['es']) : '',
                'audio' => isset($item['audio']) ? trim((string) $item['audio']) : '',
            );
        }
    }

    return array(
        'title' => normalize_activity_title($title),
        'items' => $normalizedItems,
    );
}

function load_dictation_activity(PDO $pdo, string $activityId, string $unit): array
{
    $columns = activities_columns($pdo);
    $selectFields = array('id');

    if (in_array('data', $columns, true)) {
        $selectFields[] = 'data';
    }
    if (in_array('content_json', $columns, true)) {
        $selectFields[] = 'content_json';
    }
    if (in_array('title', $columns, true)) {
        $selectFields[] = 'title';
    }
    if (in_array('name', $columns, true)) {
        $selectFields[] = 'name';
    }

    $fallback = array(
        'id' => '',
        'title' => default_dictation_title(),
        'items' => array(),
    );

    $findById = function (string $id) use ($pdo, $selectFields): ?array {
        if ($id === '') {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'dictation'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    };

    $findByUnitId = function (string $unitId) use ($pdo, $selectFields, $columns): ?array {
        if ($unitId === '' || !in_array('unit_id', $columns, true)) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit_id = :unit
               AND type = 'dictation'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unitId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    };

    $findByUnitLegacy = function (string $unitValue) use ($pdo, $selectFields, $columns): ?array {
        if ($unitValue === '' || !in_array('unit', $columns, true)) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit = :unit
               AND type = 'dictation'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unitValue));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    };

    $row = null;
    if ($activityId !== '') {
        $row = $findById($activityId);
    }
    if (!$row && $unit !== '') {
        $row = $findByUnitId($unit);
    }
    if (!$row && $unit !== '') {
        $row = $findByUnitLegacy($unit);
    }

    if (!$row) {
        return $fallback;
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = normalize_dictation_payload($rawData);

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

$activity = load_dictation_activity($pdo, $activityId, $unit);
$items = isset($activity['items']) && is_array($activity['items']) ? $activity['items'] : array();
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : default_dictation_title();

ob_start();
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap');

body{
    font-family:'Nunito', 'Segoe UI', sans-serif;
    background:#f0fdf4;
    padding:20px;
}

.dict-stage{
    max-width:1060px;
    margin:0 auto;
}

.dict-intro{
    background:linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 52%, #f0fdf4 100%);
    border:1px solid #ccfbf1;
    border-radius:26px;
    padding:24px 26px;
    box-shadow:0 16px 34px rgba(15, 23, 42, .09);
    margin-bottom:18px;
}

.dict-intro h2{
    margin:0 0 8px;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    font-size:30px;
    font-weight:700;
    color:#0f172a;
    letter-spacing:.3px;
}

.dict-intro p{
    margin:0;
    color:#334155;
    font-size:16px;
    line-height:1.6;
}

.grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(250px, 1fr));
    gap:16px;
}

.card{
    position:relative;
    overflow:hidden;
    background:linear-gradient(180deg, #f0fdf4 0%, #ffffff 100%);
    border:1px solid #ccfbf1;
    border-radius:22px;
    padding:16px;
    text-align:center;
    box-shadow:0 14px 28px rgba(15, 23, 42, .07);
}

.card::before{
    content:'';
    position:absolute;
    top:0;
    left:0;
    right:0;
    height:8px;
    background:linear-gradient(90deg, #14b8a6 0%, #0d9488 100%);
}

.image-wrap{
    min-height:116px;
    display:flex;
    align-items:center;
    justify-content:center;
    margin:8px 0 4px;
}

.image{
    width:min(100%, 130px);
    height:96px;
    object-fit:contain;
    border-radius:12px;
    background:#f8fafc;
}

.sentence{
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    font-size:34px;
    font-weight:700;
    line-height:1.04;
    letter-spacing:.2px;
    color:#0f172a;
}

.phonetic{
    margin-top:2px;
    font-size:20px;
    color:#334155;
    line-height:1.1;
}

.spanish{
    margin-top:2px;
    font-size:20px;
    line-height:1.1;
    color:#0f172a;
}

.answer-box{
    margin-top:12px;
    padding:10px 12px;
    border:2px solid #ccfbf1;
    border-radius:10px;
    background:#ffffff;
    font-size:16px;
    font-weight:600;
    font-family:'Nunito', 'Segoe UI', sans-serif;
    box-sizing:border-box;
    min-height:44px;
    resize:vertical;
}

.actions{
    margin-top:12px;
    display:flex;
    justify-content:center;
    gap:8px;
    flex-wrap:wrap;
}

.btn{
    border:none;
    border-radius:999px;
    padding:8px 13px;
    color:#fff;
    cursor:pointer;
    font-size:13px;
    font-weight:700;
    font-family:'Nunito', 'Segoe UI', sans-serif;
    transition:transform .15s ease, filter .15s ease;
}

.btn:hover{
    filter:brightness(1.06);
    transform:translateY(-1px);
}

.btn-listen{
    background:linear-gradient(180deg, #14b8a6, #0d9488);
    box-shadow:0 6px 14px rgba(20, 184, 166, .25);
}

.btn-check{
    background:linear-gradient(180deg, #0d9488, #0f766e);
    box-shadow:0 6px 14px rgba(13, 148, 136, .25);
}

.btn-show{
    background:linear-gradient(180deg, #6366f1, #4f46e5);
    box-shadow:0 6px 14px rgba(99, 102, 241, .25);
}

.btn-tryagain{
    background:linear-gradient(180deg, #f59e0b, #d97706);
    box-shadow:0 6px 14px rgba(245, 158, 11, .25);
}

.feedback{
    margin-top:10px;
    font-size:14px;
    font-weight:800;
    min-height:21px;
    line-height:1.2;
}

.correct{color:#15803d}
.incorrect{color:#dc2626}
.try{color:#d97706}

.completion-modal{
    position:fixed;
    top:0;
    left:0;
    right:0;
    bottom:0;
    background:rgba(0,0,0,0.5);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:9999;
}

.completion-modal.show{
    display:flex;
}

.completion-content{
    background:#ffffff;
    border-radius:24px;
    padding:40px;
    text-align:center;
    box-shadow:0 20px 60px rgba(0,0,0,0.3);
    animation:slideIn .4s ease;
}

@keyframes slideIn{
    from{
        transform:scale(0.8);
        opacity:0;
    }
    to{
        transform:scale(1);
        opacity:1;
    }
}

.completion-content h2{
    margin:0 0 16px;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    font-size:48px;
    font-weight:700;
    color:#15803d;
}

.completion-content p{
    margin:0;
    font-size:18px;
    color:#475569;
    font-weight:600;
}

.empty-state{
    max-width:700px;
    margin:20px auto;
    background:#ffffff;
    border-radius:18px;
    padding:24px;
    text-align:center;
    box-shadow:0 8px 24px rgba(0,0,0,.08);
    color:#4b5563;
    font-size:18px;
    font-weight:700;
}

@media (max-width:760px){
    .dict-intro h2{font-size:26px}
    .dict-intro p{font-size:15px}
    .sentence{font-size:30px}
    .phonetic,.spanish{font-size:18px}
}
</style>

<div class="dict-stage">
    <section class="dict-intro">
        <h2>Dictation Practice</h2>
        <p>Listen to each sentence carefully and write what you hear. Click the Check Answer button to verify your work.</p>
    </section>
    <div class="grid" id="cards"></div>
</div>

<div class="completion-modal" id="completionModal">
    <div class="completion-content">
        <h2>🎉 Completed!</h2>
        <p>Excellent! You've completed all the dictation exercises.</p>
    </div>
</div>

<script>
window.DICTATION_DATA = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?>;
window.cardStates = {};

var recognition = null;
if ('webkitSpeechRecognition' in window) {
  recognition = new webkitSpeechRecognition();
  recognition.lang = 'en-US';
  recognition.interimResults = false;
  recognition.maxAlternatives = 1;
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function normalizeText(text) {
  return String(text || '')
    .toLowerCase()
    .trim()
    .replace(/[.,!?;:]/g, '')
    .replace(/\s+/g, ' ');
}

function renderCards() {
  var data = Array.isArray(window.DICTATION_DATA) ? window.DICTATION_DATA : [];
  var container = document.getElementById('cards');

  if (!container) {
    return;
  }

  if (!data.length) {
    container.innerHTML = '<div class="empty-state">No dictation data available.</div>';
    return;
  }

  container.innerHTML = data.map(function (item, i) {
    window.cardStates[i] = { answered: false, correct: false, showAnswer: false };
    
    var img = item.img
      ? '<div class="image-wrap"><img class="image" src="' + escapeHtml(item.img) + '" alt="' + escapeHtml(item.en || '') + '"></div>'
      : '<div class="image-wrap"></div>';
    
    return '' +
      '<div class="card" id="card' + i + '">' +
        img +
        '<div class="sentence" id="s' + i + '" style="display:none;">' + escapeHtml(item.en || '') + '</div>' +
        '<div class="phonetic">' + escapeHtml(item.ph || '') + '</div>' +
        '<div class="spanish">' + escapeHtml(item.es || '') + '</div>' +
        '<textarea class="answer-box" id="a' + i + '" placeholder="Write what you hear..."></textarea>' +
        '<div class="actions" id="actions' + i + '">' +
          '<button class="btn btn-listen" type="button" onclick="speak(' + i + ')">🔊 Listen</button>' +
          '<button class="btn btn-check" type="button" onclick="checkAnswer(' + i + ')">✓ Check Answer</button>' +
        '</div>' +
        '<div id="f' + i + '" class="feedback"></div>' +
      '</div>';
  }).join('');
}

function speak(index) {
  var data = Array.isArray(window.DICTATION_DATA) ? window.DICTATION_DATA : [];
  if (!data[index]) {
    return;
  }

  if (data[index].audio) {
    var audio = new Audio(data[index].audio);
    audio.play();
    return;
  }

  var text = data[index].en || '';
  if (!text) {
    return;
  }

  var utter = new SpeechSynthesisUtterance(text);
  utter.lang = 'en-US';
  utter.rate = 0.9;
  speechSynthesis.cancel();
  speechSynthesis.speak(utter);
}

function checkAnswer(index) {
  var data = Array.isArray(window.DICTATION_DATA) ? window.DICTATION_DATA : [];
  var answerEl = document.getElementById('a' + index);
  var feedbackEl = document.getElementById('f' + index);
  var actionsEl = document.getElementById('actions' + index);
  
  if (!answerEl || !feedbackEl || !data[index]) {
    return;
  }

  var userAnswer = normalizeText(answerEl.value);
  var correctAnswer = normalizeText(data[index].en || '');

  if (userAnswer === '') {
    feedbackEl.innerHTML = '⚠️ Please write an answer first';
    feedbackEl.className = 'feedback incorrect';
    return;
  }

  if (userAnswer === correctAnswer) {
    // Correct
    window.cardStates[index].correct = true;
    window.cardStates[index].answered = true;
    
    feedbackEl.innerHTML = '🌟 Correct!';
    feedbackEl.className = 'feedback correct';
    
    answerEl.style.borderColor = '#15803d';
    answerEl.style.backgroundColor = '#f0fdf4';
    
    playSound('success');
    
    // Replace button with just a check mark
    actionsEl.innerHTML = '<span style="color:#15803d; font-weight:700; font-size:16px;">✓ Correct</span>';
    
    checkCompletion();
  } else {
    // Incorrect
    window.cardStates[index].correct = false;
    window.cardStates[index].answered = true;
    
    feedbackEl.innerHTML = '❌ Try again';
    feedbackEl.className = 'feedback incorrect';
    
    answerEl.style.borderColor = '#dc2626';
    answerEl.style.backgroundColor = '#fef2f2';
    
    playSound('error');
    
    // Show Try Again and Show Answer buttons
    actionsEl.innerHTML = '' +
      '<button class="btn btn-tryagain" type="button" onclick="clearAnswer(' + index + ')">🔄 Try Again</button>' +
      '<button class="btn btn-show" type="button" onclick="showAnswer(' + index + ')">👁️ Show Answer</button>';
  }
}

function clearAnswer(index) {
  var answerEl = document.getElementById('a' + index);
  var feedbackEl = document.getElementById('f' + index);
  var actionsEl = document.getElementById('actions' + index);
  
  if (!answerEl) {
    return;
  }

  answerEl.value = '';
  answerEl.style.borderColor = '#ccfbf1';
  answerEl.style.backgroundColor = '#ffffff';
  
  feedbackEl.innerHTML = '';
  feedbackEl.className = 'feedback';
  
  window.cardStates[index].answered = false;
  window.cardStates[index].correct = false;
  
  actionsEl.innerHTML = '' +
    '<button class="btn btn-listen" type="button" onclick="speak(' + index + ')">🔊 Listen</button>' +
    '<button class="btn btn-check" type="button" onclick="checkAnswer(' + index + ')">✓ Check Answer</button>';
}

function showAnswer(index) {
  var sentenceEl = document.getElementById('s' + index);
  
  if (!sentenceEl) {
    return;
  }

  if (sentenceEl.style.display === 'none') {
    sentenceEl.style.display = 'block';
  } else {
    sentenceEl.style.display = 'none';
  }
}

function checkCompletion() {
  var data = Array.isArray(window.DICTATION_DATA) ? window.DICTATION_DATA : [];
  var allCorrect = data.length > 0 && Object.keys(window.cardStates).length === data.length;

  if (allCorrect) {
    for (var i = 0; i < data.length; i++) {
      if (!window.cardStates[i] || !window.cardStates[i].correct) {
        allCorrect = false;
        break;
      }
    }
  }

  if (allCorrect && data.length > 0) {
    playSound('complete');
    showCompletion();
  }
}

function showCompletion() {
  var modal = document.getElementById('completionModal');
  if (modal) {
    modal.classList.add('show');
    setTimeout(function() {
      modal.classList.remove('show');
    }, 3000);
  }
}

function playSound(type) {
  var audioUrl = '';
  if (type === 'success') {
    audioUrl = 'data:audio/wav;base64,UklGRiYAAABXQVZFZm10IBAAAAABAAEAQB8AAABABAAZGF0YQIAAAAAAA==';
  } else if (type === 'error') {
    audioUrl = 'data:audio/wav;base64,UklGRiYAAABXQVZFZm10IBAAAAABAAEAQB8AAABABAAZGF0YQIAAAAAAA==';
  } else if (type === 'complete') {
    var utter = new SpeechSynthesisUtterance('Excellent! You have completed all exercises!');
    utter.lang = 'en-US';
    utter.rate = 0.95;
    speechSynthesis.cancel();
    speechSynthesis.speak(utter);
    return;
  }
  
  if (audioUrl) {
    var audio = new Audio(audioUrl);
    audio.play();
  }
}

renderCards();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✍️', $content);
