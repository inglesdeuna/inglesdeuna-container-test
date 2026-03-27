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

function default_memory_cards_title(): string
{
    return 'Memory Cards';
}

function normalize_memory_side($raw): array
{
    $side = is_array($raw) ? $raw : array();

    $type = strtolower(trim((string) ($side['type'] ?? 'text')));
    $text = trim((string) ($side['text'] ?? ''));
    $image = trim((string) ($side['image'] ?? ''));

    if ($type !== 'text' && $type !== 'image') {
        $type = $image !== '' ? 'image' : 'text';
    }

    if ($type === 'text' && $text === '' && $image !== '') {
        $type = 'image';
    }

    if ($type === 'image' && $image === '' && $text !== '') {
        $type = 'text';
    }

    return array(
        'type' => $type,
        'text' => $text,
        'image' => $image,
    );
}

function pair_side_is_valid(array $side): bool
{
    $type = strtolower((string) ($side['type'] ?? 'text'));
    if ($type === 'image') {
        return trim((string) ($side['image'] ?? '')) !== '';
    }

    return trim((string) ($side['text'] ?? '')) !== '';
}

function normalize_memory_cards_payload($rawData): array
{
    $default = array(
        'title' => default_memory_cards_title(),
        'pairs' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = trim((string) ($decoded['title'] ?? ''));
    if ($title === '') {
        $title = default_memory_cards_title();
    }

    $pairsSource = isset($decoded['pairs']) && is_array($decoded['pairs'])
        ? $decoded['pairs']
        : array();

    $pairs = array();

    foreach ($pairsSource as $index => $pairRaw) {
        if (!is_array($pairRaw)) {
            continue;
        }

        $left = normalize_memory_side(isset($pairRaw['left']) ? $pairRaw['left'] : array());
        $right = normalize_memory_side(isset($pairRaw['right']) ? $pairRaw['right'] : array());

        if (!pair_side_is_valid($left) || !pair_side_is_valid($right)) {
            continue;
        }

        $pairId = trim((string) ($pairRaw['id'] ?? ''));
        if ($pairId === '') {
            $pairId = 'pair_' . ($index + 1) . '_' . mt_rand(1000, 9999);
        }

        $pairs[] = array(
            'id' => $pairId,
            'left' => $left,
            'right' => $right,
        );
    }

    return array(
        'title' => $title,
        'pairs' => $pairs,
    );
}

function load_memory_cards_activity(PDO $pdo, string $unit, string $activityId): array
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

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'memory_cards'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit_id = :unit
               AND type = 'memory_cards'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit = :unit
               AND type = 'memory_cards'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return array(
            'title' => default_memory_cards_title(),
            'pairs' => array(),
        );
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = normalize_memory_cards_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') {
        $columnTitle = trim((string) $row['title']);
    } elseif (isset($row['name']) && trim((string) $row['name']) !== '') {
        $columnTitle = trim((string) $row['name']);
    }

    if ($columnTitle !== '') {
        $payload['title'] = $columnTitle;
    }

    return $payload;
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_memory_cards_activity($pdo, $unit, $activityId);
$viewerTitle = (string) ($activity['title'] ?? default_memory_cards_title());
$pairs = isset($activity['pairs']) && is_array($activity['pairs']) ? $activity['pairs'] : array();

$cards = array();

foreach ($pairs as $pair) {
    if (!is_array($pair)) {
        continue;
    }

    $pairId = trim((string) ($pair['id'] ?? ''));
    if ($pairId === '') {
        continue;
    }

    $left = normalize_memory_side(isset($pair['left']) ? $pair['left'] : array());
    $right = normalize_memory_side(isset($pair['right']) ? $pair['right'] : array());

    if (!pair_side_is_valid($left) || !pair_side_is_valid($right)) {
        continue;
    }

    $cards[] = array(
        'id' => $pairId . '_a',
        'pairId' => $pairId,
        'type' => $left['type'],
        'text' => $left['text'],
        'image' => $left['image'],
    );

    $cards[] = array(
        'id' => $pairId . '_b',
        'pairId' => $pairId,
        'type' => $right['type'],
        'text' => $right['text'],
        'image' => $right['image'],
    );
}

$totalPairs = (int) floor(count($cards) / 2);

ob_start();
?>
<style>
.mc-viewer{max-width:1160px;margin:0 auto}
.mc-intro{margin-bottom:16px;padding:24px 26px;border-radius:26px;border:1px solid #dbeafe;background:linear-gradient(135deg,#eff6ff 0%,#f0fdf4 45%,#fff7ed 100%);box-shadow:0 16px 34px rgba(15,23,42,.08)}
.mc-intro h2{margin:0 0 8px;color:#1d4ed8;font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:30px;line-height:1.1}
.mc-intro p{margin:0;color:#475569;font-size:15px;line-height:1.6}
.mc-shell{background:#fff;border:1px solid #dbeafe;border-radius:24px;box-shadow:0 14px 30px rgba(15,23,42,.08);padding:18px}
.mc-status{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.mc-pill{display:inline-flex;align-items:center;gap:6px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;font-weight:800;font-size:13px;padding:8px 12px;border-radius:999px}
.mc-board{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.mc-card{position:relative;perspective:900px;border:none;background:transparent;padding:0;cursor:pointer;min-height:180px}
.mc-card:disabled{cursor:default}
.mc-card-inner{position:relative;width:100%;height:100%;min-height:180px;transform-style:preserve-3d;transition:transform .4s ease}
.mc-card.is-flipped .mc-card-inner{transform:rotateY(180deg)}
.mc-card-face{position:absolute;inset:0;backface-visibility:hidden;border-radius:16px;border:1px solid #dbeafe;display:flex;align-items:center;justify-content:center;overflow:hidden}
.mc-card-front{background:linear-gradient(145deg,#3b82f6,#1d4ed8);color:#dbeafe;font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:46px;box-shadow:0 14px 24px rgba(37,99,235,.35)}
.mc-card-back{transform:rotateY(180deg);background:#fff;padding:12px;box-shadow:0 10px 20px rgba(15,23,42,.08)}
.mc-card-back p{margin:0;text-align:center;color:#1e293b;font-weight:800;font-size:18px;line-height:1.35;word-break:break-word}
.mc-card-back img{width:100%;height:100%;object-fit:cover;border-radius:12px}
.mc-card.is-matched .mc-card-face{border-color:#34d399}
.mc-controls{display:flex;justify-content:center;margin-top:18px}
.mc-btn{border:none;border-radius:999px;padding:10px 16px;font-weight:800;font-size:14px;cursor:pointer;box-shadow:0 8px 18px rgba(15,23,42,.1);background:linear-gradient(180deg,#fbbf24,#f59e0b);color:#7c2d12}
.mc-empty{text-align:center;padding:28px;font-weight:800;color:#b91c1c}
.completed-screen{display:none;text-align:center;max-width:600px;margin:0 auto;padding:40px 20px}
.completed-screen.active{display:block}
.completed-icon{font-size:80px;margin-bottom:20px}
.completed-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:36px;font-weight:700;color:#1d4ed8;margin:0 0 16px;line-height:1.2}
.completed-text{font-size:16px;color:#475569;line-height:1.6;margin:0 0 32px}
.completed-button{display:inline-block;padding:12px 24px;border:none;border-radius:999px;background:linear-gradient(180deg,#3b82f6,#1d4ed8);color:#fff;font-weight:700;font-size:16px;cursor:pointer;box-shadow:0 10px 24px rgba(0,0,0,.14);transition:transform .18s ease,filter .18s ease}
.completed-button:hover{transform:scale(1.05);filter:brightness(1.07)}
.mc-activity.is-hidden{display:none}
@media (max-width:900px){
    .mc-intro{padding:20px 18px}
    .mc-intro h2{font-size:26px}
    .mc-card{min-height:150px}
    .mc-card-inner{min-height:150px}
}
</style>

<div class="mc-viewer" id="mc-app">
    <section class="mc-intro">
        <h2>Memory Cards</h2>
        <p>Flip cards and find each matching pair. Pairs can be text-text, image-image, or image-text.</p>
    </section>

    <?php if (empty($cards)): ?>
        <div class="mc-shell">
            <div class="mc-empty">No pairs configured yet for this activity.</div>
        </div>
    <?php else: ?>
        <section class="mc-shell mc-activity" id="mc-activity">
            <div class="mc-status">
                <div class="mc-pill">Pairs: <span id="mc-total"><?= (int) $totalPairs ?></span></div>
                <div class="mc-pill">Matched: <span id="mc-matched">0</span></div>
                <div class="mc-pill">Moves: <span id="mc-moves">0</span></div>
            </div>

            <div id="mc-board" class="mc-board"></div>

            <div class="mc-controls">
                <button type="button" class="mc-btn" id="mc-restart">Restart</button>
            </div>
        </section>

        <div id="mc-complete" class="completed-screen">
            <div class="completed-icon">✅</div>
            <h2 class="completed-title" id="mc-completed-title"></h2>
            <p class="completed-text" id="mc-completed-text"></p>
            <button type="button" class="completed-button" id="mc-completed-restart">Play Again</button>
        </div>

        <script>
        (function () {
            const seedCards = <?= json_encode($cards, JSON_UNESCAPED_UNICODE) ?>;
            const totalPairs = <?= (int) $totalPairs ?>;
            const activityTitle = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;

            const board = document.getElementById('mc-board');
            const matchedEl = document.getElementById('mc-matched');
            const movesEl = document.getElementById('mc-moves');
            const restartBtn = document.getElementById('mc-restart');
            const completeEl = document.getElementById('mc-complete');
            const activityEl = document.getElementById('mc-activity');
            const completedTitleEl = document.getElementById('mc-completed-title');
            const completedTextEl = document.getElementById('mc-completed-text');
            const completedRestartBtn = document.getElementById('mc-completed-restart');

            if (!Array.isArray(seedCards) || seedCards.length < 2) {
                return;
            }

            let deck = [];
            let selected = [];
            let matched = new Set();
            let lockBoard = false;
            let moves = 0;

            function shuffle(list) {
                const copy = list.slice();
                for (let i = copy.length - 1; i > 0; i -= 1) {
                    const j = Math.floor(Math.random() * (i + 1));
                    const temp = copy[i];
                    copy[i] = copy[j];
                    copy[j] = temp;
                }
                return copy;
            }

            function escapeHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function cardBackMarkup(card) {
                if ((card.type || '') === 'image' && (card.image || '').trim() !== '') {
                    return '<img src="' + escapeHtml(card.image) + '" alt="Memory card image">';
                }
                return '<p>' + escapeHtml(card.text || '') + '</p>';
            }

            function updateStats() {
                if (matchedEl) matchedEl.textContent = String(Math.floor(matched.size / 2));
                if (movesEl) movesEl.textContent = String(moves);
            }

            function showCompleted() {
                if (completedTitleEl) completedTitleEl.textContent = activityTitle || 'Memory Cards';
                if (completedTextEl) completedTextEl.textContent = 'Great job. You matched all pairs in ' + String(moves) + ' moves.';

                if (activityEl) activityEl.classList.add('is-hidden');
                if (completeEl) completeEl.classList.add('active');
            }

            function handleCardClick(index) {
                if (lockBoard || matched.has(index)) {
                    return;
                }

                if (selected.indexOf(index) !== -1) {
                    return;
                }

                selected.push(index);
                const cardNode = board.querySelector('[data-card-index="' + index + '"]');
                if (cardNode) {
                    cardNode.classList.add('is-flipped');
                }

                if (selected.length < 2) {
                    return;
                }

                moves += 1;
                updateStats();

                const firstIndex = selected[0];
                const secondIndex = selected[1];
                const first = deck[firstIndex];
                const second = deck[secondIndex];

                if (first && second && first.pairId === second.pairId) {
                    matched.add(firstIndex);
                    matched.add(secondIndex);

                    const firstNode = board.querySelector('[data-card-index="' + firstIndex + '"]');
                    const secondNode = board.querySelector('[data-card-index="' + secondIndex + '"]');
                    if (firstNode) {
                        firstNode.classList.add('is-matched');
                        firstNode.disabled = true;
                    }
                    if (secondNode) {
                        secondNode.classList.add('is-matched');
                        secondNode.disabled = true;
                    }

                    selected = [];
                    updateStats();

                    if (matched.size === deck.length) {
                        showCompleted();
                    }
                    return;
                }

                lockBoard = true;
                window.setTimeout(function () {
                    const firstNode = board.querySelector('[data-card-index="' + firstIndex + '"]');
                    const secondNode = board.querySelector('[data-card-index="' + secondIndex + '"]');

                    if (firstNode) firstNode.classList.remove('is-flipped');
                    if (secondNode) secondNode.classList.remove('is-flipped');

                    selected = [];
                    lockBoard = false;
                }, 850);
            }

            function renderBoard() {
                board.innerHTML = '';

                deck.forEach(function (card, index) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'mc-card';
                    btn.dataset.cardIndex = String(index);
                    btn.innerHTML =
                        '<span class="mc-card-inner">' +
                            '<span class="mc-card-face mc-card-front">?</span>' +
                            '<span class="mc-card-face mc-card-back">' + cardBackMarkup(card) + '</span>' +
                        '</span>';

                    btn.addEventListener('click', function () {
                        handleCardClick(index);
                    });

                    board.appendChild(btn);
                });
            }

            function restart() {
                deck = shuffle(seedCards);
                selected = [];
                matched = new Set();
                lockBoard = false;
                moves = 0;

                if (activityEl) activityEl.classList.remove('is-hidden');
                if (completeEl) completeEl.classList.remove('active');

                renderBoard();
                updateStats();
            }

            if (restartBtn) {
                restartBtn.addEventListener('click', restart);
            }
            if (completedRestartBtn) {
                completedRestartBtn.addEventListener('click', restart);
            }

            restart();
        })();
        </script>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fas fa-clone', $content);
