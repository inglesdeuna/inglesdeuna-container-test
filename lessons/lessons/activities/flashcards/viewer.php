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

/* Map to {image, text, audio, voice_id} — learning activity (no score) */
$jsCards = array_values(array_map(function ($card) {
    return [
        'image'    => (string) ($card['image']        ?? ''),
        'text'     => (string) ($card['english_text'] ?? $card['text'] ?? ''),
        'audio'    => (string) ($card['audio']        ?? ''),
        'voice_id' => (string) ($card['voice_id']     ?? ''),
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
html, body { width: 100%; margin: 0; padding: 0; background: #fff; font-family: 'Nunito', sans-serif; }

.fc-page {
    width: 100%;
    min-height: 100vh;
    padding: clamp(14px, 2.5vw, 34px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: #fff;
}
.fc-app {
    width: min(700px, 100%);
    margin: 0 auto;
}

/* Hero */
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
    font-size: clamp(28px, 5vw, 48px);
    font-weight: 600;
    color: var(--orange);
    margin: 0;
    line-height: 1;
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
    min-width: 44px;
}
.fc-track {
    flex: 1;
    height: 10px;
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
    min-width: 72px;
    text-align: center;
    padding: 6px 10px;
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
    border-radius: 28px;
    padding: clamp(16px, 2.4vw, 24px);
    box-shadow: 0 8px 40px rgba(127,119,221,.12);
    margin-bottom: 14px;
}
.fc-image-wrap {
    width: 100%;
    aspect-ratio: 16/9;
    border-radius: 18px;
    overflow: hidden;
    background: var(--soft);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 14px;
}
.fc-image-wrap img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.fc-image-placeholder {
    color: #D5D0F0;
}
.fc-image-placeholder svg { width: 64px; height: 64px; }

.fc-word {
    text-align: center;
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(28px, 5vw, 48px);
    font-weight: 600;
    color: var(--purple-dark);
    min-height: 1.2em;
    line-height: 1.2;
    display: none; /* shown by JS after Listen or flip */
}

/* Buttons */
.fc-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 12px;
}
.fc-btn {
    border: 0;
    border-radius: 999px;
    padding: 12px 22px;
    min-width: 110px;
    color: #fff;
    cursor: pointer;
    font-family: 'Nunito', sans-serif;
    font-size: 14px;
    font-weight: 900;
    transition: .18s;
    box-shadow: 0 6px 18px rgba(127,119,221,.15);
}
.fc-btn:hover { transform: translateY(-1px); }
.fc-btn:disabled { opacity: .45; cursor: default; transform: none; }
.fc-btn-purple { background: var(--purple); }
.fc-btn-orange { background: var(--orange); }

#fc-feedback { margin-top: 8px; }
#fc-completed { margin-top: 16px; }

.passive-done {
    display: none;
    width: min(680px, 100%);
    margin: 24px auto 0;
    text-align: center;
    padding: clamp(28px, 5vw, 54px);
    border-radius: 34px;
    background: #fff;
    border: 1px solid #E2F7EF;
    box-shadow: 0 8px 40px rgba(8,80,65,.12);
}
.passive-done.active { display: block; animation: passivePop .45s cubic-bezier(.2,.9,.2,1); }
@keyframes passivePop { from { opacity:0; transform:scale(.92); } to { opacity:1; transform:scale(1); } }
.passive-done-icon { font-size: clamp(66px,12vw,100px); margin-bottom: 12px; }
.passive-done-title { margin: 0 0 10px; font-family: 'Fredoka', sans-serif; font-size: clamp(34px,6vw,60px); color: #085041; line-height: 1; }
.passive-done-text { margin: 0 auto 22px; max-width: 520px; color: #7C739B; font-size: clamp(14px,2vw,17px); font-weight: 800; line-height: 1.5; }
.passive-done-track { height: 14px; max-width: 420px; margin: 0 auto 18px; border-radius: 999px; background: #E2F7EF; overflow: hidden; }
.passive-done-fill { height: 100%; width: 0%; border-radius: 999px; background: linear-gradient(90deg, #1D9E75, #7F77DD, #EC4899); transition: width .8s cubic-bezier(.2,.9,.2,1); }
.passive-done-btn { display: inline-flex; align-items: center; gap: 8px; padding: 13px 28px; border-radius: 999px; border: 0; background: #1D9E75; color: #fff; font-family: 'Nunito', sans-serif; font-size: 15px; font-weight: 900; cursor: pointer; box-shadow: 0 6px 18px rgba(29,158,117,.30); transition: .18s; }
.passive-done-btn:hover { transform: translateY(-2px); }

@media (max-width: 560px) {
    .fc-page { padding: 12px; }
    .fc-actions { grid-template-columns: 1fr 1fr; display: grid; }
    .fc-btn { width: 100%; }
}
</style>

<div class="fc-page">
    <div class="fc-app">

        <div class="fc-hero">
            <div class="fc-kicker">Flashcards</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        </div>

        <div id="fc-activity">
            <div class="fc-progress">
                <span class="fc-progress-label" id="fc-progress-label"></span>
                <div class="fc-track">
                    <div class="fc-fill" id="fc-progress-fill"></div>
                </div>
                <div class="fc-badge" id="fc-progress-badge"></div>
            </div>

            <div class="fc-card-shell">
                <div class="fc-image-wrap" id="fc-image-wrap">
                    <div class="fc-image-placeholder" id="fc-image-placeholder">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4-4 4 4 8-8"/>
                        </svg>
                    </div>
                    <img id="fc-img" src="" alt="" style="display:none;">
                </div>

                <div class="fc-word" id="fc-word"></div>
            </div>

            <div class="fc-actions">
                <button class="fc-btn fc-btn-purple" id="fc-listen">🔊 Listen</button>
                <button class="fc-btn fc-btn-orange" id="fc-show">Show Word</button>
                <button class="fc-btn fc-btn-orange" id="fc-next">Next →</button>
            </div>

            <div id="fc-feedback"></div>
        </div>

        <div id="fc-completed"></div>

    </div>
</div>

<script src="../../core/_activity_feedback.js"></script>
<script>
window.FLASHCARD_DATA        = <?php echo json_encode($jsCards,     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
window.FLASHCARD_TITLE       = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
window.FLASHCARD_RETURN_TO   = <?php echo json_encode($returnTo,    JSON_UNESCAPED_UNICODE); ?>;
window.FLASHCARD_ACTIVITY_ID = <?php echo json_encode($activityId,  JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="flashcards.js"></script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-layer-group', $content);
