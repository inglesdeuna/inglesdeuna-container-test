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
$cssVersion = file_exists(__DIR__ . '/../multiple_choice/multiple_choice.css') ? (string) filemtime(__DIR__ . '/../multiple_choice/multiple_choice.css') : (string) time();

ob_start();
?>

<style>
.dict-prompt,
.dict-hint,
.dict-answer-reveal,
.dict-transcript{
    text-align: center;
}

.dict-prompt{
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(20px, 2.1vw, 30px);
    font-weight: 800;
    color: #0f172a;
    line-height: 1.12;
    margin-bottom: 8px;
}

.dict-hint{
    color: #5b516f;
    font-weight: 700;
    margin-bottom: 10px;
}

.dict-image{
    display: none;
    width: min(100%, 172px);
    max-width: 100%;
    border-radius: 18px;
    margin: 8px auto 12px auto;
    background: #fff;
    box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
}

.dict-prompt,
.dict-hint{
    display:none;
}

.dict-answer-box{
    width: 100%;
    max-width: 620px;
    min-height: 120px;
    padding: 12px;
    border: 2px solid #ead8ff;
    background: #fff;
    border-radius: 16px;
    font: 700 16px/1.35 'Nunito', 'Segoe UI', sans-serif;
    color: #312e81;
    resize: vertical;
    transition: border-color .15s ease, background-color .15s ease;
    box-sizing: border-box;
    margin: 0 auto;
    display: block;
}

.dict-answer-box.ok{
    border-color: #166534;
    background: #dcfce7;
}

.dict-answer-box.bad{
    border-color: #b91c1c;
    background: #fee2e2;
}

.dict-answer-reveal{
    display: none;
    margin-top: 10px;
    border-radius: 14px;
    border: 1px solid #fecdd3;
    background: #fff1f2;
    color: #9f1239;
    padding: 10px;
    font-weight: 700;
}

.dict-answer-reveal.show{
    display: block;
}

.mc-btn-listen{
    background:linear-gradient(180deg, #38bdf8 0%, #0ea5e9 100%);
}

.mc-btn-check{background:linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%)}
.mc-btn-show{background:linear-gradient(180deg, #f9a8d4 0%, #ec4899 100%)}
.mc-btn-next{background:linear-gradient(180deg, #2dd4bf 0%, #0f766e 100%)}

.mc-status{
    font-size:14px;
    margin-bottom:8px;
}

.dict-listen-row{
    display:flex;
    justify-content:center;
    margin: 2mm 0 4mm 0;
}

.dict-listen-row .mc-btn{
    min-width:160px;
}

#dict-viewer{
    width:100%;
    max-width:100%;
}

#dict-card{
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:flex-start;
}

#dict-controls{
    margin-top: 3mm;
    margin-bottom: 4mm;
}

.completed-screen{
    display:none;
    text-align:center;
    max-width:600px;
    margin:0 auto;
    padding:40px 20px;
}

.completed-screen.active{
    display:block;
}

.completed-icon{
    font-size:80px;
    margin-bottom:20px;
}

.completed-title{
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    font-size:36px;
    font-weight:700;
    color:#0f766e;
    margin:0 0 16px;
    line-height:1.2;
}

.completed-text{
    font-size:16px;
    color:#1f2937;
    line-height:1.6;
    margin:0 0 32px;
}

.completed-button{
    display:inline-block;
    padding:12px 24px;
    border:none;
    border-radius:999px;
    background:linear-gradient(180deg, #14b8a6 0%, #0f766e 100%);
    color:#fff;
    font-weight:700;
    font-size:16px;
    cursor:pointer;
    box-shadow:0 10px 24px rgba(0,0,0,.14);
    transition:transform .18s ease, filter .18s ease;
}

.completed-button:hover{
    transform:scale(1.05);
    filter:brightness(1.07);
}
</style>

<div class="mc-viewer" id="dict-viewer">
        <section class="mc-intro">
                <h2>Dictation Practice</h2>
                <p>One card at a time. Use Listen, type your answer, then Check Answer. Use Show Answer if you need support.</p>
        </section>

        <div class="mc-status" id="dict-status"></div>

        <div class="mc-card" id="dict-card">
            <div class="dict-listen-row" id="dict-listen-row">
                <button type="button" class="mc-btn mc-btn-listen" id="dict-listen">Listen</button>
            </div>
                <div class="dict-prompt" id="dict-prompt"></div>
                <div class="dict-hint" id="dict-hint"></div>
                <img id="dict-image" class="dict-image" alt="">
                <textarea id="dict-answer" class="dict-answer-box" placeholder="Write what you hear..."></textarea>
                <div id="dict-reveal" class="dict-answer-reveal"></div>
        </div>

        <div class="mc-controls" id="dict-controls">
                <button type="button" class="mc-btn mc-btn-check" id="dict-check">Check Answer</button>
                <button type="button" class="mc-btn mc-btn-show" id="dict-show">Show Answer</button>
                <button type="button" class="mc-btn mc-btn-next" id="dict-next">Next</button>
        </div>

        <div class="mc-feedback" id="dict-feedback"></div>

        <div id="dict-completed" class="completed-screen">
            <div class="completed-icon">✅</div>
            <h2 class="completed-title" id="dict-completed-title"></h2>
            <p class="completed-text" id="dict-completed-text"></p>
            <button type="button" class="completed-button" id="dict-restart">Restart</button>
        </div>
</div>

<link rel="stylesheet" href="../multiple_choice/multiple_choice.css?v=<?php echo urlencode($cssVersion); ?>">
<script>
document.addEventListener('DOMContentLoaded', function () {
    var data = Array.isArray(<?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?>) ? <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?> : [];
    var activityTitle = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;

    var statusEl = document.getElementById('dict-status');
    var promptEl = document.getElementById('dict-prompt');
    var hintEl = document.getElementById('dict-hint');
    var imageEl = document.getElementById('dict-image');
    var answerEl = document.getElementById('dict-answer');
    var revealEl = document.getElementById('dict-reveal');
    var feedbackEl = document.getElementById('dict-feedback');
    var cardEl = document.getElementById('dict-card');
    var listenRowEl = document.getElementById('dict-listen-row');
    var controlsEl = document.getElementById('dict-controls');
    var completedEl = document.getElementById('dict-completed');
    var completedTitleEl = document.getElementById('dict-completed-title');
    var completedTextEl = document.getElementById('dict-completed-text');

    var listenBtn = document.getElementById('dict-listen');
    var checkBtn = document.getElementById('dict-check');
    var showBtn = document.getElementById('dict-show');
    var nextBtn = document.getElementById('dict-next');
    var restartBtn = document.getElementById('dict-restart');

    var correctSound = new Audio('../../hangman/assets/win.mp3');
    var wrongSound = new Audio('../../hangman/assets/lose.mp3');
    var doneSound = new Audio('../../hangman/assets/win (1).mp3');

    var index = 0;
    var finished = false;

    if (completedTitleEl) {
        completedTitleEl.textContent = activityTitle || 'Dictation Practice';
    }

    if (completedTextEl) {
        completedTextEl.textContent = "You've completed " + (activityTitle || 'this activity') + '. Great job practicing.';
    }

    function normalizeText(text) {
        return String(text || '')
            .toLowerCase()
            .trim()
            .replace(/[.,!?;:]/g, '')
            .replace(/\s+/g, ' ');
    }

    function playSound(sound) {
        try {
            sound.pause();
            sound.currentTime = 0;
            sound.play();
        } catch (e) {}
    }

    function speakCurrent() {
        if (!data[index]) {
            return;
        }

        if (data[index].audio) {
            try {
                var audio = new Audio(data[index].audio);
                audio.play();
            } catch (e) {}
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

    function loadCard() {
        var item = data[index] || {};

        finished = false;
        completedEl.classList.remove('active');
        cardEl.style.display = 'block';
        listenRowEl.style.display = 'flex';
        controlsEl.style.display = 'flex';

        statusEl.textContent = (index + 1) + ' / ' + data.length;
        promptEl.textContent = '';
        hintEl.textContent = '';
        answerEl.value = '';
        answerEl.className = 'dict-answer-box';
        feedbackEl.textContent = '';
        feedbackEl.className = 'mc-feedback';
        revealEl.classList.remove('show');
        revealEl.textContent = item.en || '';

        if (item.img) {
            imageEl.style.display = 'block';
            imageEl.src = item.img;
            imageEl.alt = item.en || '';
        } else {
            imageEl.style.display = 'none';
            imageEl.removeAttribute('src');
            imageEl.alt = '';
        }

        nextBtn.disabled = false;
        nextBtn.textContent = index < data.length - 1 ? 'Next' : 'Finish';
    }

    function checkAnswer() {
        if (!data[index]) {
            return;
        }

        var answer = normalizeText(answerEl.value);
        var expected = normalizeText(data[index].en || '');

        if (answer === '') {
            feedbackEl.textContent = 'Write an answer first.';
            feedbackEl.className = 'mc-feedback bad';
            return;
        }

        if (answer === expected) {
            feedbackEl.textContent = 'Correct!';
            feedbackEl.className = 'mc-feedback good';
            answerEl.className = 'dict-answer-box ok';
            playSound(correctSound);
        } else {
            feedbackEl.textContent = 'Try Again';
            feedbackEl.className = 'mc-feedback bad';
            answerEl.className = 'dict-answer-box bad';
            playSound(wrongSound);
        }
    }

    function showAnswer() {
        if (!data[index]) {
            return;
        }

        revealEl.textContent = data[index].en || '';
        revealEl.classList.add('show');
        feedbackEl.textContent = 'Show The Answer';
        feedbackEl.className = 'mc-feedback good';
    }

    function showCompleted() {
        finished = true;
        cardEl.style.display = 'none';
        listenRowEl.style.display = 'none';
        controlsEl.style.display = 'none';
        statusEl.textContent = 'Completed';
        feedbackEl.textContent = '';
        completedEl.classList.add('active');
        playSound(doneSound);
    }

    function goNext() {
        if (finished) {
            return;
        }

        if (index < data.length - 1) {
            index += 1;
            loadCard();
        } else {
            showCompleted();
        }
    }

    function restart() {
        index = 0;
        loadCard();
    }

    if (!data.length) {
        promptEl.textContent = 'No dictation data available.';
        hintEl.textContent = '';
        listenRowEl.style.display = 'none';
        controlsEl.style.display = 'none';
        statusEl.textContent = '';
        answerEl.style.display = 'none';
        imageEl.style.display = 'none';
        return;
    }

    listenBtn.addEventListener('click', speakCurrent);
    checkBtn.addEventListener('click', checkAnswer);
    showBtn.addEventListener('click', showAnswer);
    nextBtn.addEventListener('click', goNext);
    restartBtn.addEventListener('click', restart);

    loadCard();
});
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✍️', $content);
