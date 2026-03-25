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
    $default = array(
        'title' => default_pronunciation_title(),
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

function load_pronunciation_activity(PDO $pdo, string $activityId, string $unit): array
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
        'title' => default_pronunciation_title(),
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
               AND type = 'pronunciation'
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
               AND type = 'pronunciation'
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
               AND type = 'pronunciation'
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

ob_start();
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap');

body{
    font-family:'Nunito', 'Segoe UI', sans-serif;
    background:#eef6ff;
    padding:20px;
}

.pron-stage{
    max-width:1060px;
    margin:0 auto;
}

.pron-intro{
    background:linear-gradient(135deg, #fff8df 0%, #eef8ff 52%, #f8fbff 100%);
    border:1px solid #dbe7f5;
    border-radius:26px;
    padding:24px 26px;
    box-shadow:0 16px 34px rgba(15, 23, 42, .09);
    margin-bottom:18px;
}

.pron-intro h2{
    margin:0 0 8px;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    font-size:30px;
    font-weight:700;
    color:#0f172a;
    letter-spacing:.3px;
}

.pron-intro p{
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
    background:linear-gradient(180deg, #f8fdff 0%, #ffffff 100%);
    border:1px solid #dbe7f5;
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
    background:linear-gradient(90deg, #38bdf8 0%, #0ea5e9 100%);
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

.command{
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
    box-shadow:0 6px 14px rgba(37, 99, 235, .25);
    transition:transform .15s ease, filter .15s ease;
}

.btn:hover{
    filter:brightness(1.06);
    transform:translateY(-1px);
}

.btn-listen{
    background:linear-gradient(180deg, #2563eb, #1d4ed8);
    box-shadow:0 6px 14px rgba(37, 99, 235, .25);
}

.btn-speak{
    background:linear-gradient(180deg, #2563eb, #1d4ed8);
    box-shadow:0 6px 14px rgba(37, 99, 235, .25);
}

.btn-tryagain{
    background:linear-gradient(180deg, #f59e0b, #d97706);
    box-shadow:0 6px 14px rgba(245, 158, 11, .25);
}

.btn-show{
    background:linear-gradient(180deg, #6366f1, #4f46e5);
    box-shadow:0 6px 14px rgba(99, 102, 241, .25);
}

.feedback{
    margin-top:10px;
    font-size:14px;
    font-weight:800;
    min-height:21px;
    line-height:1.2;
}

.answer-reveal{
    display:none;
    margin-top:10px;
    padding:14px 16px;
    border-radius:16px;
    background:linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
    border:1px solid #93c5fd;
    color:#1e3a8a;
    box-shadow:0 10px 22px rgba(37, 99, 235, .14);
}

.answer-reveal.show{
    display:block;
}

.answer-reveal-label{
    font-size:12px;
    font-weight:800;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:#1d4ed8;
}

.answer-reveal-word{
    margin-top:6px;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    font-size:28px;
    font-weight:700;
    line-height:1.05;
    color:#0f172a;
}

.answer-reveal-phonetic{
    margin-top:4px;
    font-size:18px;
    font-weight:700;
    color:#334155;
}

.correct{color:#15803d}
.incorrect{color:#dc2626}

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
    .pron-intro h2{font-size:26px}
    .pron-intro p{font-size:15px}
    .command{font-size:30px}
    .phonetic,.spanish{font-size:18px}
}
</style>

<div class="pron-stage">
    <section class="pron-intro">
        <h2>Pronunciation Practice</h2>
        <p>Listen to each word and then use Speak to practice your pronunciation. Repeat until you get a positive result.</p>
    </section>
    <div class="grid" id="cards"></div>
</div>

<div class="completion-modal" id="completionModal">
    <div class="completion-content">
        <h2>Completed!</h2>
        <p>Excellent! You've completed all the pronunciation exercises.</p>
    </div>
</div>

<script>
window.PRONUNCIATION_DATA = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?>;
window.pronunciationStates = {};

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

function renderCards() {
  var data = Array.isArray(window.PRONUNCIATION_DATA) ? window.PRONUNCIATION_DATA : [];
  var container = document.getElementById('cards');

  if (!container) {
    return;
  }

  if (!data.length) {
        container.innerHTML = '<div class="empty-state">No pronunciation data available.</div>';
    return;
  }

  container.innerHTML = data.map(function (item, i) {
      window.pronunciationStates[i] = { correct: false };
        var img = item.img
            ? '<div class="image-wrap"><img class="image" src="' + escapeHtml(item.img) + '" alt="' + escapeHtml(item.en || '') + '"></div>'
            : '<div class="image-wrap"></div>';
    return '' +
      '<div class="card">' +
        img +
        '<div class="command">' + escapeHtml(item.en || '') + '</div>' +
        '<div class="phonetic">' + escapeHtml(item.ph || '') + '</div>' +
        '<div class="spanish">' + escapeHtml(item.es || '') + '</div>' +
                '<div class="actions" id="actions' + i + '">' +
                    '<button class="btn btn-listen" type="button" onclick="speak(' + i + ')">🔊 Listen</button>' +
                    '<button class="btn btn-speak" type="button" onclick="record(' + i + ')">🎤 Speak</button>' +
                '</div>' +
                '<div id="answer' + i + '" class="answer-reveal">' +
                        '<div class="answer-reveal-label">Correct Answer</div>' +
                        '<div class="answer-reveal-word">' + escapeHtml(item.en || '') + '</div>' +
                        '<div class="answer-reveal-phonetic">' + escapeHtml(item.ph || '') + '</div>' +
                '</div>' +
        '<div id="f' + i + '" class="feedback"></div>' +
      '</div>';
  }).join('');
}

function speak(index) {
  var data = Array.isArray(window.PRONUNCIATION_DATA) ? window.PRONUNCIATION_DATA : [];
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

function record(index) {
  var data = Array.isArray(window.PRONUNCIATION_DATA) ? window.PRONUNCIATION_DATA : [];
  var fb = document.getElementById('f' + index);
    var actions = document.getElementById('actions' + index);
    var answer = document.getElementById('answer' + index);

  if (!data[index]) {
    return;
  }

  if (!recognition) {
    if (fb) {
            fb.innerHTML = 'Speech recognition is not available in this browser.';
            fb.className = 'feedback incorrect';
    }
    return;
  }

  recognition.onresult = function (event) {
    var said = String(event.results[0][0].transcript || '').toLowerCase();
    var correct = String(data[index].en || '').toLowerCase();

    if (!fb) {
      return;
    }

    if (correct !== '' && (said === correct || said.indexOf(correct.split(' ')[0]) !== -1)) {
            window.pronunciationStates[index].correct = true;
            fb.innerHTML = 'Correct!';
            fb.className = 'feedback correct';

            if (actions) {
                actions.innerHTML = '<span style="color:#15803d; font-weight:700; font-size:16px;">✓ Correct</span>';
            }

            if (answer) {
                answer.classList.remove('show');
            }

            checkCompletion();
    } else {
            window.pronunciationStates[index].correct = false;
            fb.innerHTML = 'Try again!';
            fb.className = 'feedback incorrect';

            if (actions) {
                actions.innerHTML = '' +
                    '<button class="btn btn-listen" type="button" onclick="speak(' + index + ')">🔊 Listen</button>' +
                    '<button class="btn btn-tryagain" type="button" onclick="resetPronunciation(' + index + ')">🔄 Try Again</button>' +
                    '<button class="btn btn-show" type="button" onclick="showAnswer(' + index + ')">👁️ Show Answer</button>';
            }
    }
  };

  recognition.onerror = function () {
    if (fb) {
            fb.innerHTML = 'Try again!';
            fb.className = 'feedback incorrect';
        }

        if (actions) {
            actions.innerHTML = '' +
                '<button class="btn btn-listen" type="button" onclick="speak(' + index + ')">🔊 Listen</button>' +
                                '<button class="btn btn-tryagain" type="button" onclick="resetPronunciation(' + index + ')">🔄 Try Again</button>' +
                                '<button class="btn btn-show" type="button" onclick="showAnswer(' + index + ')">👁️ Show Answer</button>';
    }
  };

  recognition.start();
}

function resetPronunciation(index) {
    var fb = document.getElementById('f' + index);
    var actions = document.getElementById('actions' + index);
    var answer = document.getElementById('answer' + index);

    window.pronunciationStates[index] = { correct: false };

    if (fb) {
        fb.innerHTML = '';
        fb.className = 'feedback';
    }

    if (actions) {
        actions.innerHTML = '' +
            '<button class="btn btn-listen" type="button" onclick="speak(' + index + ')">🔊 Listen</button>' +
            '<button class="btn btn-speak" type="button" onclick="record(' + index + ')">🎤 Speak</button>';
    }

    if (answer) {
        answer.classList.remove('show');
    }
}

function showAnswer(index) {
    var answer = document.getElementById('answer' + index);

    if (answer) {
        answer.classList.toggle('show');
    }
}

function checkCompletion() {
    var data = Array.isArray(window.PRONUNCIATION_DATA) ? window.PRONUNCIATION_DATA : [];
    var allCorrect = data.length > 0 && Object.keys(window.pronunciationStates).length === data.length;

    if (allCorrect) {
        for (var i = 0; i < data.length; i++) {
            if (!window.pronunciationStates[i] || !window.pronunciationStates[i].correct) {
                allCorrect = false;
                break;
            }
        }
    }

    if (allCorrect) {
        showCompletion();
    }
}

function showCompletion() {
    var modal = document.getElementById('completionModal');

    if (modal) {
        modal.classList.add('show');
        setTimeout(function () {
            modal.classList.remove('show');
        }, 3000);
    }

    var utter = new SpeechSynthesisUtterance('Excellent! You have completed all exercises!');
    utter.lang = 'en-US';
    utter.rate = 0.95;
    speechSynthesis.cancel();
    speechSynthesis.speak(utter);
}

renderCards();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔊', $content);
