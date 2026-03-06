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

function normalize_pronunciation_items($rawData): array
{
    if ($rawData === null || $rawData === '') {
        return array();
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return array();
    }

    if (isset($decoded['items']) && is_array($decoded['items'])) {
        $decoded = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $decoded = $decoded['data'];
    } elseif (isset($decoded['words']) && is_array($decoded['words'])) {
        $decoded = $decoded['words'];
    }

    $normalized = array();

    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $en = isset($item['en']) ? trim((string) $item['en']) : '';
        if ($en === '' && isset($item['word'])) {
            $en = trim((string) $item['word']);
        }

        $normalized[] = array(
            'img' => isset($item['img']) ? trim((string) $item['img']) : (isset($item['image']) ? trim((string) $item['image']) : ''),
            'en' => $en,
            'ph' => isset($item['ph']) ? trim((string) $item['ph']) : '',
            'es' => isset($item['es']) ? trim((string) $item['es']) : '',
            'audio' => isset($item['audio']) ? trim((string) $item['audio']) : '',
        );
    }

    return $normalized;
}

function load_pronunciation_items(PDO $pdo, string $activityId, string $unit): array
{
    $columns = activities_columns($pdo);

    if ($activityId !== '' && in_array('data', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT data
             FROM activities
             WHERE id = :id
               AND type = 'pronunciation'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['data'])) {
            return normalize_pronunciation_items($row['data']);
        }
    }

    if ($unit !== '' && in_array('unit_id', $columns, true) && in_array('data', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT data
             FROM activities
             WHERE unit_id = :unit
               AND type = 'pronunciation'
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['data'])) {
            return normalize_pronunciation_items($row['data']);
        }
    }

    if ($unit !== '' && in_array('unit', $columns, true) && in_array('content_json', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT content_json
             FROM activities
             WHERE unit = :unit
               AND type = 'pronunciation'
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['content_json'])) {
            return normalize_pronunciation_items($row['content_json']);
        }
    }

    return array();
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$items = load_pronunciation_items($pdo, $activityId, $unit);

ob_start();
?>

<style>
body{font-family:Arial,sans-serif;background:#eef6ff;padding:20px}
h1{text-align:center;color:#0b5ed7;margin:0 0 18px 0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
.card{background:#fff;border-radius:18px;padding:14px;text-align:center;box-shadow:0 4px 8px rgba(0,0,0,.1)}
.image{width:100%;height:130px;object-fit:contain;margin-bottom:6px}
.command{font-size:28px;font-weight:700;line-height:1.1}
.phonetic{font-size:18px;color:#555;line-height:1.1}
.spanish{font-size:18px;margin-bottom:8px;line-height:1.1}
button{margin:4px;padding:7px 12px;border:none;border-radius:10px;background:#0b5ed7;color:#fff;cursor:pointer;font-size:13px}
.feedback{font-size:15px;font-weight:700;min-height:20px}
.good{color:green}.try{color:orange}.muted{color:#666}
</style>

<h1>Pronunciation</h1>
<div class="grid" id="cards"></div>

<script>
window.PRONUNCIATION_DATA = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?>;

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
    container.innerHTML = '<div class="card"><div class="muted">No pronunciation data available.</div></div>';
    return;
  }

  container.innerHTML = data.map(function (item, i) {
    var img = item.img ? '<img class="image" src="' + escapeHtml(item.img) + '" alt="' + escapeHtml(item.en || '') + '">' : '';
    return '' +
      '<div class="card">' +
        img +
        '<div class="command">' + escapeHtml(item.en || '') + '</div>' +
        '<div class="phonetic">' + escapeHtml(item.ph || '') + '</div>' +
        '<div class="spanish">' + escapeHtml(item.es || '') + '</div>' +
        '<button type="button" onclick="speak(' + i + ')">🔊 Listen</button>' +
        '<button type="button" onclick="record(' + i + ')">🎤 Speak</button>' +
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

  if (!data[index]) {
    return;
  }

  if (!recognition) {
    if (fb) {
      fb.innerHTML = '⚠️ Speech recognition not available in this browser.';
      fb.className = 'feedback try';
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
      fb.innerHTML = '🌟 Good job!';
      fb.className = 'feedback good';
    } else {
      fb.innerHTML = '🔁 Try again!';
      fb.className = 'feedback try';
    }
  };

  recognition.onerror = function () {
    if (fb) {
      fb.innerHTML = '🔁 Try again!';
      fb.className = 'feedback try';
    }
  };

  recognition.start();
}

renderCards();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer('Pronunciation', '🔊', $content);
