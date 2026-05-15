<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function fc_load(PDO $pdo, string $activityId): array
{
    $stmt = $pdo->prepare("SELECT * FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) return [];

    $raw     = $activity['data'] ?? $activity['content_json'] ?? '[]';
    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) return [];

    if (isset($decoded['cards']) && is_array($decoded['cards'])) {
        return $decoded['cards'];
    }

    return $decoded;
}

$rawCards = fc_load($pdo, $activityId);

if (!$rawCards || !count($rawCards)) {
    die('No flashcards found');
}

/* Map to {front, back} format expected by flashcards.js */
$jsCards = array_values(array_map(function ($card) {
    return [
        'front' => (string) ($card['english_text'] ?? $card['text'] ?? ''),
        'back'  => (string) ($card['spanish_text'] ?? ''),
    ];
}, $rawCards));

$viewerTitle = 'Flashcards';

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Nunito:wght@500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --orange: #F97316;
    --purple: #7F77DD;
    --purple-dark: #534AB7;
    --muted: #9B94BE;
    --soft: #F4F2FD;
    --border: #ECE9FA;
}
* { box-sizing: border-box; }
html, body { width: 100%; min-height: 100%; margin: 0; padding: 0; background: #fff; font-family: 'Nunito', sans-serif; }
body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
.activity-wrapper { max-width: 100% !important; margin: 0 !important; padding: 0 !important; display: flex !important; flex-direction: column !important; background: transparent !important; }
.top-row, .activity-header { display: none !important; }
.viewer-content { flex: 1 !important; display: flex !important; flex-direction: column !important; padding: 0 !important; margin: 0 !important; background: transparent !important; border: none !important; box-shadow: none !important; border-radius: 0 !important; }

.fc-page {
    width: 100%;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: clamp(14px, 2.5vw, 34px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: #fff;
    box-sizing: border-box;
}
.fc-app {
    width: min(760px, 100%);
    margin: 0 auto;
}
.fc-hero {
    text-align: center;
    margin-bottom: clamp(14px, 2vw, 22px);
}
.fc-kicker {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 14px;
    border-radius: 999px;
    background: #FFF0E6;
    border: 1px solid #FCDDBF;
    color: #C2580A;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 10px;
}
.fc-hero h1 {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(30px, 5.5vw, 54px);
    font-weight: 600;
    color: var(--orange);
    margin: 0;
    line-height: 1;
}
.fc-hero p {
    font-size: clamp(13px, 1.8vw, 15px);
    font-weight: 700;
    color: var(--muted);
    margin: 8px 0 0;
}

/* Progress */
.fc-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}
.fc-progress-label {
    font-size: 12px;
    font-weight: 900;
    color: var(--muted);
    min-width: 48px;
}
.fc-track {
    flex: 1;
    height: 12px;
    background: var(--soft);
    border-radius: 999px;
    overflow: hidden;
}
.fc-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, var(--orange), var(--purple));
    border-radius: 999px;
    transition: width .35s;
}
.fc-badge {
    min-width: 74px;
    text-align: center;
    padding: 7px 10px;
    border-radius: 999px;
    background: var(--purple);
    color: #fff;
    font-size: 12px;
    font-weight: 900;
}

/* Card */
.fc-card-shell {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 32px;
    padding: clamp(16px, 2.6vw, 26px);
    box-shadow: 0 8px 40px rgba(127,119,221,.12);
    margin-bottom: 16px;
}
.fc-card {
    perspective: 1000px;
    min-height: clamp(200px, 30vh, 320px);
    margin-bottom: 16px;
    cursor: pointer;
}
.fc-card-inner {
    position: relative;
    width: 100%;
    height: 100%;
    min-height: inherit;
    transform-style: preserve-3d;
    transition: transform .45s ease;
}
.fc-card.is-flipped .fc-card-inner {
    transform: rotateY(180deg);
}
.fc-face {
    position: absolute;
    inset: 0;
    backface-visibility: hidden;
    border-radius: 24px;
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: clamp(16px, 2.4vw, 28px);
    text-align: center;
    min-height: inherit;
    background: #fff;
}
.fc-face-back {
    transform: rotateY(180deg);
}
#fc-front {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(26px, 4vw, 44px);
    font-weight: 600;
    color: var(--purple-dark);
    line-height: 1.2;
}
#fc-back {
    font-size: clamp(20px, 3vw, 34px);
    font-weight: 700;
    color: var(--muted);
    line-height: 1.3;
}

/* Buttons */
.fc-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
}
.fc-btn {
    border: 0;
    border-radius: 999px;
    padding: 13px 20px;
    min-width: 120px;
    color: #fff;
    cursor: pointer;
    font-family: 'Nunito', sans-serif;
    font-size: 14px;
    font-weight: 900;
    transition: .18s;
    box-shadow: 0 6px 18px rgba(127,119,221,.15);
}
.fc-btn:hover { transform: translateY(-1px); }
.fc-btn-purple { background: var(--purple); }
.fc-btn-orange { background: var(--orange); }
.fc-btn-outline {
    background: transparent;
    border: 2px solid var(--border);
    color: var(--purple);
    box-shadow: none;
}
.fc-btn-outline:hover { border-color: var(--purple); }

#fc-feedback { margin-top: 8px; }
#fc-completed { }

@media (max-width: 640px) {
    .fc-page { padding: 12px; }
    .fc-actions { display: grid; grid-template-columns: 1fr; }
    .fc-btn { width: 100%; }
}
</style>

<div class="fc-page">
    <div class="fc-app">

        <div class="fc-hero">
            <div class="fc-kicker">Flashcards</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Tap the card to flip it. Mark what you know!</p>
        </div>

        <div id="fc-activity">
            <div class="fc-card-shell">
                <div class="fc-progress">
                    <span class="fc-progress-label" id="fc-progress-label">1 / <?php echo count($jsCards); ?></span>
                    <div class="fc-track">
                        <div class="fc-fill" id="fc-progress-fill"></div>
                    </div>
                    <div class="fc-badge" id="fc-progress-badge">Card 1 of <?php echo count($jsCards); ?></div>
                </div>

                <div class="fc-card" id="fc-card">
                    <div class="fc-card-inner">
                        <div class="fc-face fc-face-front" id="fc-front"></div>
                        <div class="fc-face fc-face-back" id="fc-back"></div>
                    </div>
                </div>

                <div class="fc-actions">
                    <button class="fc-btn fc-btn-outline" id="fc-show">Show Answer</button>
                    <button class="fc-btn fc-btn-purple" id="fc-flip">Flip</button>
                    <button class="fc-btn fc-btn-orange" id="fc-next">Next</button>
                </div>
            </div>

            <div class="fc-actions" style="justify-content:center;gap:10px;flex-wrap:wrap;">
                <button class="fc-btn fc-btn-purple" id="fc-review" style="display:none;">Review</button>
                <button class="fc-btn fc-btn-orange" id="fc-knew" style="display:none;">I knew this!</button>
            </div>

            <div id="fc-feedback"></div>
        </div>

        <div id="fc-completed"></div>

    </div>
</div>

<script src="../../core/_activity_feedback.js"></script>
<script>
window.FLASHCARD_DATA       = <?php echo json_encode($jsCards,      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
window.FLASHCARD_TITLE      = <?php echo json_encode($viewerTitle,  JSON_UNESCAPED_UNICODE); ?>;
window.FLASHCARD_RETURN_TO  = <?php echo json_encode($returnTo,     JSON_UNESCAPED_UNICODE); ?>;
window.FLASHCARD_ACTIVITY_ID= <?php echo json_encode($activityId,   JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="flashcards.js"></script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-layer-group', $content);
