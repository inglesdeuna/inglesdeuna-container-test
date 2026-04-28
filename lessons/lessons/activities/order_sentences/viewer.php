<?php
// Order the Sentences – Activity Viewer
//
// This script displays an "Order the Sentences" activity to students.  It loads
// the activity data from the database, shuffles the sentences for the user
// to reorder, and provides immediate feedback on whether the order is
// correct.  It also supports video, audio, or text‑to‑speech media as
// configured in the activity editor.  A back button uses the browser
// history to return to the previous page.

// Start output buffering and session
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once __DIR__ . '/../../config/db.php';

// Get the activity ID from the query string
$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';
if ($activityId === '') {
    die('Activity not specified');
}

// Load the activity from the database
$stmt = $pdo->prepare("SELECT data FROM activities WHERE id=:id AND type='order_sentences' LIMIT 1");
$stmt->execute(['id' => $activityId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    die('Activity not found');
}

// Decode the JSON payload
$payload = json_decode($row['data'], true) ?: [];
$sentences = (array) ($payload['sentences'] ?? []);

// Keep the correct order of sentence IDs for comparison
$correctOrder = array_column($sentences, 'id');

// Shuffle sentences for display; do not alter the original order array
$shuffled = $sentences;
shuffle($shuffled);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($payload['title'] ?? 'Order the Sentences') ?></title>
<style>
/* Basic styling for the activity viewer */
body {
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    background: #f8fafc;
    padding: 20px;
    margin: 0;
}
.container {
    max-width: 800px;
    margin: 0 auto;
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}
.media {
    margin-top: 15px;
}
.sentence-item {
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    background: #f9fafb;
    margin-bottom: 10px;
    padding: 8px;
    cursor: grab;
}
.sentence-item.dragging {
    opacity: 0.4;
}
.sentence-item.over {
    border: 2px dashed #2563eb;
}
.sentence-item img {
    max-width: 80px;
    max-height: 80px;
    border-radius: 8px;
}
.sentence-item span {
    flex: 1;
}
#result {
    font-size: 16px;
    font-weight: 700;
    margin-top: 12px;
}
.buttons {
    display: flex;
    gap: 10px;
    margin-top: 16px;
}
.btn {
    padding: 10px 18px;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    cursor: pointer;
}
.btn-check {
    background: #2563eb;
    color: #fff;
}
.btn-back {
    background: linear-gradient(180deg, #3d73ee 0%, #2563eb 100%);
    color: #fff;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-size: 13px;
    line-height: 1;
    box-shadow: 0 10px 22px rgba(37, 99, 235, .28);
    transition: transform .18s ease, filter .18s ease;
}
.btn-back:hover {
    filter: brightness(1.07);
    transform: translateY(-1px);
}
</style>
</head>
<body>
<div class="container">
    <h2><?= htmlspecialchars($payload['title'] ?? 'Order the Sentences') ?></h2>
    <p><?= nl2br(htmlspecialchars($payload['instructions'] ?? '')) ?></p>

    <?php if (($payload['media_type'] ?? '') === 'video' && !empty($payload['media_url'])): ?>
        <div class="media">
            <video controls src="<?= htmlspecialchars($payload['media_url']) ?>" style="max-width:100%;"></video>
        </div>
    <?php elseif (($payload['media_type'] ?? '') === 'audio' && !empty($payload['media_url'])): ?>
        <div class="media">
            <audio controls src="<?= htmlspecialchars($payload['media_url']) ?>"></audio>
        </div>
    <?php elseif (($payload['media_type'] ?? '') === 'tts'): ?>
        <div class="media">
            <button id="playTTS" class="btn">🔊 Play Audio</button>
        </div>
    <?php endif; ?>

    <div id="sentences-list">
        <?php foreach ($shuffled as $s): ?>
            <div class="sentence-item" draggable="true" data-id="<?= htmlspecialchars($s['id']) ?>">
                <?php if (!empty($s['image'])): ?>
                    <img src="<?= htmlspecialchars($s['image']) ?>" alt="sentence image">
                <?php endif; ?>
                <?php if (!empty($s['text'])): ?>
                    <span><?= htmlspecialchars($s['text']) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="buttons">
        <button id="checkOrder" class="btn btn-check">Check Order</button>
        <button type="button" onclick="history.back()" class="btn btn-back">Back</button>
    </div>
    <p id="result"></p>
</div>

<script>
// Immediately invoked function to encapsulate variables
(function(){
    var list = document.getElementById('sentences-list');
    var dragSrc = null;

    function handleDragStart(e) {
        dragSrc = this;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', this.getAttribute('data-id'));
        this.classList.add('dragging');
    }
    function handleDragEnd(e) {
        this.classList.remove('dragging');
        list.querySelectorAll('.sentence-item').forEach(function(item){
            item.classList.remove('over');
        });
    }
    function handleDragOver(e) {
        if (e.preventDefault) e.preventDefault();
        return false;
    }
    function handleDragEnter(e) {
        this.classList.add('over');
    }
    function handleDragLeave(e) {
        this.classList.remove('over');
    }
    function handleDrop(e) {
        if (e.stopPropagation) e.stopPropagation();
        var draggedId = e.dataTransfer.getData('text/plain');
        var dropId = this.getAttribute('data-id');
        if (draggedId !== dropId) {
            var dragItem = list.querySelector('[data-id="' + draggedId + '"]');
            var dropItem = list.querySelector('[data-id="' + dropId + '"]');
            if (dragItem && dropItem) {
                // Swap elements by reordering in the DOM
                var nextSibling = dragItem.nextSibling === dropItem ? dragItem : dragItem.nextSibling;
                list.insertBefore(dragItem, dropItem);
                list.insertBefore(dropItem, nextSibling);
            }
        }
        return false;
    }
    function addDnDHandlers(item) {
        item.addEventListener('dragstart', handleDragStart);
        item.addEventListener('dragenter', handleDragEnter);
        item.addEventListener('dragover', handleDragOver);
        item.addEventListener('dragleave', handleDragLeave);
        item.addEventListener('drop', handleDrop);
        item.addEventListener('dragend', handleDragEnd);
    }
    [].forEach.call(list.querySelectorAll('.sentence-item'), addDnDHandlers);

    document.getElementById('checkOrder').addEventListener('click', function(){
        var items = list.querySelectorAll('.sentence-item');
        var userOrder = [];
        items.forEach(function(item){
            userOrder.push(item.getAttribute('data-id'));
        });
        var correctOrder = <?= json_encode($correctOrder) ?>;
        if (JSON.stringify(userOrder) === JSON.stringify(correctOrder)) {
            document.getElementById('result').textContent = '✅ Correct! Well done.';
            document.getElementById('result').style.color = '#16a34a';
            var OS_RETURN_TO = <?= json_encode($returnTo) ?>;
            var OS_ACTIVITY_ID = <?= json_encode($activityId) ?>;
            var OS_TOTAL = correctOrder.length;
            if (OS_RETURN_TO && OS_ACTIVITY_ID && OS_TOTAL > 0) {
                var joiner = OS_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
                var saveUrl = OS_RETURN_TO + joiner +
                    'activity_percent=100&activity_errors=0' +
                    '&activity_total=' + encodeURIComponent(String(OS_TOTAL)) +
                    '&activity_id=' + encodeURIComponent(OS_ACTIVITY_ID) +
                    '&activity_type=order_sentences';
                fetch(saveUrl, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { if (!r.ok) throw new Error(); })
                    .catch(function () {
                        try {
                            if (window.top && window.top !== window.self) { window.top.location.href = saveUrl; return; }
                        } catch (e) {}
                        window.location.href = saveUrl;
                    });
            }
        } else {
            document.getElementById('result').textContent = '❌ Not correct. Try again.';
            document.getElementById('result').style.color = '#dc2626';
        }
    });

    <?php if (($payload['media_type'] ?? '') === 'tts'): ?>
    // Play Text‑to‑Speech when the button is clicked.  Uses the Web Speech API.
    document.getElementById('playTTS').addEventListener('click', function(){
        var txt = <?= json_encode($payload['tts_text'] ?: implode('. ', array_column($sentences, 'text'))) ?>;
        var msg = new SpeechSynthesisUtterance(txt);
        speechSynthesis.speak(msg);
    });
    <?php endif; ?>
})();
</script>
</body>
</html>
<?php
// Output the buffered content
echo ob_get_clean();
?>
