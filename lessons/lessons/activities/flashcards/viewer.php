<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function fc_first_nonempty(array $card, array $keys): string
{
    foreach ($keys as $k) {
        if (isset($card[$k]) && trim((string) $card[$k]) !== '') {
            return trim((string) $card[$k]);
        }
    }
    return '';
}

function fc_load(PDO $pdo, string $activityId, string $unit): array
{
    // Detect available columns
    static $cols = null;
    if ($cols === null) {
        $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='activities'");
        $cols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'column_name');
    }

    $fields = ['id'];
    foreach (['data', 'content_json', 'title', 'name'] as $c) {
        if (in_array($c, $cols, true)) $fields[] = $c;
    }
    $sel = implode(', ', $fields);

    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT {$sel} FROM activities WHERE id=:id AND type='flashcards' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // Fallback: fetch by ID regardless of type (handles legacy activities with wrong type stored)
        if (!$row) {
            $stmt = $pdo->prepare("SELECT {$sel} FROM activities WHERE id=:id LIMIT 1");
            $stmt->execute(['id' => $activityId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    if (!$row && $unit !== '') {
        foreach (['unit_id', 'unit'] as $col) {
            if (!in_array($col, $cols, true)) continue;
            $stmt = $pdo->prepare("SELECT {$sel} FROM activities WHERE {$col}=:unit AND type='flashcards' ORDER BY id ASC LIMIT 1");
            $stmt->execute(['unit' => $unit]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) break;
        }
    }

    if (!$row) return ['title' => 'Flashcards', 'cards' => []];

    $raw     = $row['data'] ?? $row['content_json'] ?? null;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) $decoded = [];

    $cardsRaw = (isset($decoded['cards']) && is_array($decoded['cards'])) ? $decoded['cards'] : (array_is_list($decoded) ? $decoded : []);

    // Title: prefer DB column, then JSON field
    $title = '';
    if (isset($row['title']) && trim((string)$row['title']) !== '') $title = trim((string)$row['title']);
    elseif (isset($row['name']) && trim((string)$row['name']) !== '') $title = trim((string)$row['name']);
    elseif (isset($decoded['title']) && trim((string)$decoded['title']) !== '') $title = trim((string)$decoded['title']);
    if ($title === '') $title = 'Flashcards';

    return ['title' => $title, 'cards' => $cardsRaw];
}

$loaded   = fc_load($pdo, $activityId, $unit);
$rawCards = $loaded['cards'];
$viewerTitle = $loaded['title'];

if (!$rawCards || !count($rawCards)) {
    die('No flashcards found');
}

/* Map to viewer fields — handles both new format (front_text/back_text) and
   legacy formats (text, english_text, spanish_text, meaning, image, audio).
   Uses fc_first_nonempty() instead of ?? to skip empty-string keys that the
   old normalizer always wrote (e.g. text:"") before reaching the real value. */
$jsCards = array_values(array_map(function ($card) {
    $frontImg  = fc_first_nonempty($card, ['front_image', 'image']);
    $backImg   = fc_first_nonempty($card, ['back_image']);
    $frontText = fc_first_nonempty($card, ['front_text', 'english_text', 'text']);
    $backText  = fc_first_nonempty($card, ['back_text', 'spanish_text', 'meaning']);
    $frontAud  = fc_first_nonempty($card, ['front_audio', 'audio']);
    $backAud   = fc_first_nonempty($card, ['back_audio']);
    $frontAudText = isset($card['front_audio_text']) ? trim((string)$card['front_audio_text']) : '';
    $backAudText  = isset($card['back_audio_text'])  ? trim((string)$card['back_audio_text'])  : '';
    $hasFlags  = array_key_exists('front_show_image', $card);
    return [
        'front_image'       => $frontImg,
        'back_image'        => $backImg,
        'front_text'        => $frontText,
        'back_text'         => $backText,
        'front_audio'       => $frontAud,
        'back_audio'        => $backAud,
        'front_audio_text'  => $frontAudText,
        'back_audio_text'   => $backAudText,
        'voice_id'          => fc_first_nonempty($card, ['voice_id']),
        // Visibility flags — default: show element if it has content (legacy cards)
        'front_show_image'  => array_key_exists('front_show_image', $card)  ? (bool)$card['front_show_image']  : ($hasFlags ? false : $frontImg  !== ''),
        'front_show_listen' => array_key_exists('front_show_listen', $card) ? (bool)$card['front_show_listen'] : ($hasFlags ? false : ($frontAud !== '' || $frontText !== '')),
        'front_show_text'   => array_key_exists('front_show_text', $card)   ? (bool)$card['front_show_text']   : ($hasFlags ? false : $frontText !== ''),
        'back_show_image'   => array_key_exists('back_show_image', $card)   ? (bool)$card['back_show_image']   : ($hasFlags ? false : $backImg   !== ''),
        'back_show_listen'  => array_key_exists('back_show_listen', $card)  ? (bool)$card['back_show_listen']  : ($hasFlags ? false : ($backAud  !== '' || $backText !== '')),
        'back_show_text'    => array_key_exists('back_show_text', $card)    ? (bool)$card['back_show_text']    : ($hasFlags ? false : $backText  !== ''),
    ];
}, $rawCards));

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
    cursor: pointer;
    perspective: 900px;
}
.fc-card-shell.fc-flipping {
    animation: fc-card-flip .45s ease;
}
@keyframes fc-card-flip {
    0% { transform: rotateY(0); }
    50% { transform: rotateY(90deg); }
    100% { transform: rotateY(0); }
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

.fc-word {
    text-align: center;
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(28px, 5vw, 48px);
    font-weight: 600;
    color: var(--purple-dark);
    min-height: 1.2em;
    line-height: 1.2;
}
.fc-side-listen {
    display: none;
    background: var(--purple);
}
.fc-side-listen.visible { display: block; }
.fc-side-listen:disabled { opacity: .55; cursor: wait; }

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
    border-radius: 10px;
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
#fc-completed { margin-top: 0; }

@media (max-width: 560px) {
    .fc-page { padding: 12px; }
    .fc-actions { gap: 8px; }
    .fc-btn { min-width: 0; flex: 1; }
}

/* ── Unified unscored completed screen ── */
.af-unscored__card{background:#fff;border:1.5px solid #EDE9FA;border-radius:14px;padding:28px 32px;width:100%;max-width:100%;box-sizing:border-box;font-family:'Nunito','Segoe UI',sans-serif;}
.af-unscored__prog-label{font-size:11px;color:#9B8FCC;font-weight:700;letter-spacing:.06em;text-align:center;margin-bottom:6px;text-transform:uppercase;}
.af-unscored__prog-track{background:#EDE9FA;border-radius:99px;height:9px;overflow:hidden;margin-bottom:4px;}
.af-unscored__prog-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#F97316,#7F77DD);transition:width .4s ease;}
.af-unscored__prog-nums{display:flex;justify-content:space-between;font-size:11px;color:#9B8FCC;margin-bottom:16px;}
.af-unscored__prog-nums strong{color:#7F77DD;}
.af-unscored__icon{width:48px;height:48px;border-radius:50%;background:#EDE9FA;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;}
.af-unscored__title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:20px;font-weight:600;color:#7F77DD;text-align:center;margin:0 0 3px;}
.af-unscored__sub{font-size:13px;color:#9B8FCC;font-weight:600;text-align:center;margin:0 0 16px;}
.af-unscored__chips{display:grid;gap:8px;margin-bottom:16px;}
.af-unscored__chips--2{grid-template-columns:1fr 1fr;}
.af-unscored__chips--3{grid-template-columns:1fr 1fr 1fr;}
.af-unscored__chip{background:#F9F8FF;border:1.5px solid #EDE9FA;border-radius:12px;padding:10px 6px;text-align:center;}
.af-unscored__chip-val{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:24px;color:#7F77DD;line-height:1;}
.af-unscored__chip-val--orange{color:#F97316;}
.af-unscored__chip-lbl{font-size:10px;color:#9B8FCC;font-weight:700;letter-spacing:.05em;margin-top:2px;text-transform:uppercase;}
.af-unscored__banner{border-radius:12px;padding:9px 14px;display:flex;align-items:center;gap:10px;margin-bottom:16px;}
.af-unscored__banner--orange{background:#FFF0E6;}
.af-unscored__banner--purple{background:#F5F3FF;}
.af-unscored__banner--green{background:#F0FDF4;}
.af-unscored__banner-icon{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.af-unscored__banner-icon--orange{background:#F97316;}
.af-unscored__banner-icon--purple{background:#7F77DD;}
.af-unscored__banner-icon--green{background:#22c55e;}
.af-unscored__banner-text{font-size:12px;font-weight:600;}
.af-unscored__banner-text--orange{color:#b85a10;}
.af-unscored__banner-text--purple{color:#5046a6;}
.af-unscored__banner-text--green{color:#166534;}
.af-unscored__banner-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:15px;display:block;}
.af-unscored__btns{display:flex;gap:8px;}
.af-unscored__btn-primary{flex:1;background:#F97316;color:#fff;border:none;border-radius:10px;padding:11px 0;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}
.af-unscored__btn-secondary{flex:1;background:#fff;color:#7F77DD;border:1.5px solid #EDE9FA;border-radius:10px;padding:11px 0;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}
</style>

<div class="fc-page">
    <div class="fc-app">

        <div class="fc-hero">
            <div class="fc-kicker">Flashcards</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        </div>

        <div id="fc-activity" data-az-zoom>
            <div class="fc-progress">
                <span class="fc-progress-label" id="fc-progress-label"></span>
                <div class="fc-track">
                    <div class="fc-fill" id="fc-progress-fill"></div>
                </div>
                <div class="fc-badge" id="fc-progress-badge"></div>
            </div>

            <div class="fc-card-shell">
                <div class="fc-image-wrap" id="fc-image-wrap">
                    <img id="fc-img" src="" alt="" style="display:none;">
                    <img id="fc-back-img" src="" alt="" style="display:none;">
                </div>

                <div class="fc-word" id="fc-word"></div>
            </div>

            <div class="fc-actions">
                <button type="button" class="fc-side-listen fc-btn" id="fc-front-listen"
                        aria-label="Play front audio">🔊 Listen</button>
                <button type="button" class="fc-side-listen fc-btn" id="fc-back-listen"
                        aria-label="Play back audio">🔊 Listen</button>
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
