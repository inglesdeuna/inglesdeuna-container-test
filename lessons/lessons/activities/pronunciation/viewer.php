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

        $word = isset($item['word']) ? trim((string) $item['word']) : '';
        $audio = '';

        if (isset($item['audio'])) {
            $audio = trim((string) $item['audio']);
        } elseif (isset($item['audio_url'])) {
            $audio = trim((string) $item['audio_url']);
        }

        if ($word === '' && $audio === '') {
            continue;
        }

        $normalized[] = array(
            'word' => $word,
            'audio' => $audio,
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
.pron-view{max-width:640px;margin:0 auto;text-align:center}
.pron-box{background:#fff;padding:28px;border-radius:16px;box-shadow:0 4px 10px rgba(0,0,0,.08)}
.pron-word{font-size:34px;font-weight:700;margin:16px 0;color:#0f172a}
.pron-btn{background:#0b5ed7;color:#fff;border:none;padding:11px 20px;border-radius:12px;font-weight:700;cursor:pointer;margin:4px}
.pron-btn:hover{background:#084298}
.pron-feedback{margin-top:12px;color:#475569;min-height:22px}
</style>

<div class="pron-view">
  <div class="pron-box">
    <?php if (!empty($items)) { ?>
      <div id="pron-word" class="pron-word"></div>
      <div>
        <button class="pron-btn" type="button" onclick="playAudioOrSpeech()">🔊 Listen</button>
        <button class="pron-btn" type="button" onclick="nextWord()">➡️ Next</button>
      </div>
      <div class="pron-feedback" id="pron-feedback"></div>
    <?php } else { ?>
      <p>No pronunciation data available.</p>
    <?php } ?>
  </div>
</div>

<script>
window.PRONUNCIATION_DATA = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?>;

var current = 0;

function renderWord() {
  var list = Array.isArray(window.PRONUNCIATION_DATA) ? window.PRONUNCIATION_DATA : [];
  if (!list.length) {
    return;
  }

  var item = list[current] || {};
  var wordEl = document.getElementById('pron-word');
  if (wordEl) {
    wordEl.textContent = item.word || '';
  }
}

function playAudioOrSpeech() {
  var list = Array.isArray(window.PRONUNCIATION_DATA) ? window.PRONUNCIATION_DATA : [];
  if (!list.length) {
    return;
  }

  var item = list[current] || {};
  var feedback = document.getElementById('pron-feedback');

  if (item.audio) {
    var audio = new Audio(item.audio);
    audio.play().then(function(){
      if (feedback) feedback.textContent = 'Playing uploaded audio...';
    }).catch(function(){
      if (feedback) feedback.textContent = 'No se pudo reproducir el audio.';
    });
    return;
  }

  if ('speechSynthesis' in window && item.word) {
    var utter = new SpeechSynthesisUtterance(item.word);
    utter.lang = 'en-US';
    utter.rate = 0.85;
    speechSynthesis.cancel();
    speechSynthesis.speak(utter);
    if (feedback) feedback.textContent = 'Playing browser voice...';
    return;
  }

  if (feedback) feedback.textContent = 'No audio available for this word.';
}

function nextWord() {
  var list = Array.isArray(window.PRONUNCIATION_DATA) ? window.PRONUNCIATION_DATA : [];
  if (!list.length) {
    return;
  }

  current += 1;
  if (current >= list.length) {
    current = 0;
  }

  renderWord();

  var feedback = document.getElementById('pron-feedback');
  if (feedback) {
    feedback.textContent = '';
  }
}

renderWord();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer('Pronunciation', '🔊', $content);
