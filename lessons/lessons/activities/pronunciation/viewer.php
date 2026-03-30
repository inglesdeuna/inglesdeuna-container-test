<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

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
$cssVersion = file_exists(__DIR__ . '/../multiple_choice/multiple_choice.css') ? (string) filemtime(__DIR__ . '/../multiple_choice/multiple_choice.css') : (string) time();

ob_start();
?>

<style>
.pron-prompt,
.pron-hint,
.pron-captured,
.pron-answer{
    text-align: center;
}

.pron-prompt{
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(20px, 2.1vw, 30px);
    font-weight: 800;
    color: #0f172a;
    line-height: 1.12;
    margin-bottom: 8px;
}

.pron-hint{
    color: #5b516f;
    font-weight: 700;
    margin-bottom: 10px;
}

.pron-image{
    display: none;
    width: min(100%, 172px);
    max-width: 100%;
    border-radius: 18px;
    margin: 8px auto 12px auto;
    background: #fff;
    box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
}

.pron-captured{
    margin-top: 10px;
    max-width: 620px;
    width: 100%;
    margin-left: auto;
    margin-right: auto;
    border: 2px solid #ead8ff;
    border-radius: 14px;
    padding: 10px;
    background: #fff;
    color: #312e81;
    font-weight: 700;
}

.pron-captured:empty{
    display:none;
}

.pron-captured.ok{
    border-color: #166534;
    background: #dcfce7;
}

.pron-captured.bad{
    border-color: #b91c1c;
    background: #fee2e2;
}

.pron-answer{
    display: none;
    margin-top: 10px;
    max-width: 620px;
    width: 100%;
    margin-left: auto;
    margin-right: auto;
    border-radius: 14px;
    border: 1px solid #fecdd3;
    background: #fff1f2;
    color: #9f1239;
    padding: 10px;
    font-weight: 700;
}

.pron-answer.show{
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

.pron-listen-row{
    display:flex;
    justify-content:center;
    margin: 2mm 0 4mm 0;
    gap:10px;
}

.pron-listen-row .mc-btn{
    min-width:140px;
}

#pron-viewer{
    width:100%;
    max-width:100%;
}

#pron-card{
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:flex-start;
}

#pron-controls{
    margin-top: 3mm;
    margin-bottom: 4mm;
}

.mc-btn-speak{background:linear-gradient(180deg, #f97316 0%, #c2410c 100%)}

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
    color:#c2410c;
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
    background:linear-gradient(180deg, #f97316 0%, #c2410c 100%);
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

<div class="mc-viewer" id="pron-viewer">
        <section class="mc-intro">
            <h2>Pronunciation Practice</h2>
            <p>One card at a time. Listen first, then press Check Answer to record and validate your pronunciation. Use Show Answer if you need help.</p>
        </section>

        <div class="mc-status" id="pron-status"></div>

        <div class="mc-card" id="pron-card">
            <div class="pron-listen-row" id="pron-listen-row">
                <button type="button" class="mc-btn mc-btn-listen" id="pron-listen">Listen</button>
                <button type="button" class="mc-btn mc-btn-speak" id="pron-speak">Speaker</button>
            </div>
                <img id="pron-image" class="pron-image" alt="">
            <div class="pron-prompt" id="pron-prompt"></div>
            <div class="pron-hint" id="pron-hint"></div>
            <div class="pron-captured" id="pron-captured"></div>
                <div class="pron-answer" id="pron-answer"></div>
        </div>

        <div class="mc-controls" id="pron-controls">
                <button type="button" class="mc-btn mc-btn-check" id="pron-check">Check Answer</button>
                <button type="button" class="mc-btn mc-btn-show" id="pron-show">Show Answer</button>
                <button type="button" class="mc-btn mc-btn-next" id="pron-next">Next</button>
        </div>

        <div class="mc-feedback" id="pron-feedback"></div>

        <div id="pron-completed" class="completed-screen">
            <div class="completed-icon">✅</div>
            <h2 class="completed-title" id="pron-completed-title"></h2>
            <p class="completed-text" id="pron-completed-text"></p>
            <p class="completed-text" id="pron-score-text" style="font-weight:700;font-size:18px;color:#c2410c;"></p>
            <button type="button" class="completed-button" id="pron-restart">Restart</button>
        </div>
</div>

<link rel="stylesheet" href="../multiple_choice/multiple_choice.css?v=<?php echo urlencode($cssVersion); ?>">
<script>
document.addEventListener('DOMContentLoaded', function () {
    var data = Array.isArray(<?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?>) ? <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?> : [];
    var activityTitle = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
    var PRON_ACTIVITY_ID = <?php echo json_encode($activity['id'] ?? '', JSON_UNESCAPED_UNICODE); ?>;
    var PRON_RETURN_TO = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;

    var statusEl = document.getElementById('pron-status');
    var promptEl = document.getElementById('pron-prompt');
    var hintEl = document.getElementById('pron-hint');
    var imageEl = document.getElementById('pron-image');
    var capturedEl = document.getElementById('pron-captured');
    var answerEl = document.getElementById('pron-answer');
    var feedbackEl = document.getElementById('pron-feedback');
    var cardEl = document.getElementById('pron-card');
    var listenRowEl = document.getElementById('pron-listen-row');
    var controlsEl = document.getElementById('pron-controls');
    var completedEl = document.getElementById('pron-completed');
    var completedTitleEl = document.getElementById('pron-completed-title');
    var completedTextEl = document.getElementById('pron-completed-text');
    var scoreTextEl = document.getElementById('pron-score-text');

    var listenBtn = document.getElementById('pron-listen');
    var speakBtn = document.getElementById('pron-speak');
    var checkBtn = document.getElementById('pron-check');
    var showBtn = document.getElementById('pron-show');
    var nextBtn = document.getElementById('pron-next');
    var restartBtn = document.getElementById('pron-restart');

    var recognition = null;
    if ('webkitSpeechRecognition' in window) {
        recognition = new webkitSpeechRecognition();
        recognition.lang = 'en-US';
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;
    }

    var correctSound = new Audio('../../hangman/assets/win.mp3');
    var wrongSound = new Audio('../../hangman/assets/lose.mp3');
    var doneSound = new Audio('../../hangman/assets/win (1).mp3');

    var index = 0;
    var finished = false;
    var capturedText = '';
    var recognitionBusy = false;
    var correctCount = 0;
    var totalCount = 0;
    var checkedCards = {};

    if (completedTitleEl) {
        completedTitleEl.textContent = activityTitle || 'Pronunciation Practice';
    }

    if (completedTextEl) {
        completedTextEl.textContent = "You've completed " + (activityTitle || 'this activity') + '. Great job practicing.';
    }

    function persistScoreSilently(targetUrl) {
        try {
            fetch(targetUrl, { method: 'GET', credentials: 'same-origin' });
        } catch (e) {}
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
        capturedText = '';

        completedEl.classList.remove('active');
        cardEl.style.display = 'block';
        listenRowEl.style.display = 'flex';
        controlsEl.style.display = 'flex';

        statusEl.textContent = (index + 1) + ' / ' + data.length;
        promptEl.textContent = item.en || 'Listen and pronounce the word.';
        hintEl.textContent = item.ph || '';
        capturedEl.textContent = '';
        capturedEl.className = 'pron-captured';
        answerEl.classList.remove('show');
        answerEl.textContent = 'Correct answer: ' + (item.en || '');
        feedbackEl.textContent = '';
        feedbackEl.className = 'mc-feedback';

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

    function recordPronunciation() {
        if (!data[index]) {
            return;
        }

        if (recognitionBusy) {
            return;
        }

        if (!recognition) {
            feedbackEl.textContent = 'Speech recognition is not available in this browser.';
            feedbackEl.className = 'mc-feedback bad';
            return;
        }

        recognitionBusy = true;
        feedbackEl.textContent = 'Listening...';
        feedbackEl.className = 'mc-feedback';

        recognition.onresult = function (event) {
            capturedText = String(event.results[0][0].transcript || '');
            capturedEl.textContent = 'You said: ' + capturedText;
            recognitionBusy = false;
            feedbackEl.textContent = 'Now press Check Answer.';
            feedbackEl.className = 'mc-feedback';
        };

        recognition.onerror = function () {
            capturedText = '';
            capturedEl.textContent = 'Could not capture voice. Try again.';
            feedbackEl.textContent = 'Try Again';
            feedbackEl.className = 'mc-feedback bad';
            recognitionBusy = false;
        };

        try {
            recognition.start();
        } catch (e) {
            recognitionBusy = false;
        }
    }

    function checkAnswer() {
        if (!data[index]) {
            return;
        }

        var said = normalizeText(capturedText);
        var expected = normalizeText(data[index].en || '');

        if (said === '') {
            feedbackEl.textContent = 'Press Speaker first.';
            feedbackEl.className = 'mc-feedback bad';
            return;
        }

        var isCorrect = (said === expected || said.indexOf(expected) !== -1 || expected.indexOf(said) !== -1);

        if (isCorrect) {
            feedbackEl.textContent = 'Correct!';
            feedbackEl.className = 'mc-feedback good';
            capturedEl.className = 'pron-captured ok';
            playSound(correctSound);
        } else {
            feedbackEl.textContent = 'Try Again';
            feedbackEl.className = 'mc-feedback bad';
            capturedEl.className = 'pron-captured bad';
            playSound(wrongSound);
        }

        if (!checkedCards[index]) {
            checkedCards[index] = true;
            totalCount++;
            if (isCorrect) {
                correctCount++;
            }
        }
    }

    function showAnswer() {
        if (!data[index]) {
            return;
        }

        answerEl.textContent = 'Correct answer: ' + (data[index].en || '');
        answerEl.classList.add('show');
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

        var pct = totalCount > 0 ? Math.round((correctCount / totalCount) * 100) : 0;
        var errors = totalCount - correctCount;

        if (completedTextEl) {
            completedTextEl.textContent = "You've completed " + (activityTitle || 'this activity') + '. Great job practicing.';
        }
        if (scoreTextEl) {
            scoreTextEl.textContent = 'Score: ' + correctCount + ' / ' + totalCount + ' (' + pct + '%)';
        }

        if (PRON_ACTIVITY_ID && PRON_RETURN_TO) {
            persistScoreSilently(
                PRON_RETURN_TO +
                '&activity_percent=' + pct +
                '&activity_errors=' + errors +
                '&activity_total=' + totalCount +
                '&activity_id=' + encodeURIComponent(PRON_ACTIVITY_ID) +
                '&activity_type=pronunciation'
            );
        }
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
        correctCount = 0;
        totalCount = 0;
        checkedCards = {};
        index = 0;
        loadCard();
    }

    if (!data.length) {
        promptEl.textContent = 'No pronunciation data available.';
        hintEl.textContent = '';
        capturedEl.style.display = 'none';
        listenRowEl.style.display = 'none';
        controlsEl.style.display = 'none';
        statusEl.textContent = '';
        imageEl.style.display = 'none';
        return;
    }

    listenBtn.addEventListener('click', speakCurrent);
    speakBtn.addEventListener('click', recordPronunciation);
    checkBtn.addEventListener('click', checkAnswer);
    showBtn.addEventListener('click', showAnswer);
    nextBtn.addEventListener('click', goNext);
    restartBtn.addEventListener('click', restart);

    loadCard();
});
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔊', $content);
