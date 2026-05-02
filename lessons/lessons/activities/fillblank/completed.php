<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])      ? trim((string) $_GET['id'])   : '';
$unit       = isset($_GET['unit'])    ? trim((string) $_GET['unit'])  : '';
$correct    = isset($_GET['correct']) ? intval($_GET['correct'])       : 0;
$total      = isset($_GET['total'])   ? intval($_GET['total'])         : 0;
$percent    = $total > 0 ? round($correct * 100 / $total) : 0;

ob_start();
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --t50:  #E1F5EE; --t100: #9FE1CB; --t200: #5DCAA5;
    --t400: #1D9E75; --t600: #0F6E56; --t800: #085041; --t900: #04342C;
    --purple: #7F77DD; --purple-d: #534AB7; --purple-l: #EEEDFE; --purple-b: #AFA9EC;
}
body { margin:0!important; padding:0!important; background:#f0faf6!important;
    font-family:'Nunito','Segoe UI',sans-serif!important; }
.activity-wrapper { max-width:100%!important; margin:0!important; padding:0!important; background:transparent!important; }
.top-row { display:none!important; }
.viewer-content { padding:0!important; margin:0!important; background:transparent!important;
    border:none!important; box-shadow:none!important; }

.fb-done-page { min-height:100vh; background:#f0faf6; display:flex; flex-direction:column; }
.fb-done-topbar { height:38px; background:var(--t50); border-bottom:1px solid var(--t100);
    display:flex; align-items:center; justify-content:center; }
.fb-done-topbar span { font-size:12px; font-weight:800; color:var(--t600);
    letter-spacing:.1em; text-transform:uppercase; font-family:'Nunito',sans-serif; }
.fb-done-body { flex:1; display:flex; align-items:center; justify-content:center; padding:24px 16px; }
.fb-done-card { background:#fff; border:1px solid var(--t100); border-radius:16px;
    box-shadow:0 4px 24px rgba(29,158,117,.09); padding:36px 28px;
    max-width:420px; width:100%; display:flex; flex-direction:column;
    align-items:center; gap:12px; text-align:center; }
.fb-done-icon { font-size:60px; line-height:1; margin-bottom:4px; }
.fb-done-title { font-family:'Fredoka',sans-serif; font-size:28px; font-weight:700;
    color:var(--t800); margin:0; }
.fb-done-msg { font-size:14px; font-weight:600; color:#5a7a6a; margin:0; }
.fb-score-ring { width:88px; height:88px; border-radius:50%; background:var(--purple-l);
    border:3px solid var(--purple-b); display:flex; flex-direction:column;
    align-items:center; justify-content:center; }
.fb-score-pct { font-family:'Fredoka',sans-serif; font-size:24px; font-weight:700;
    color:var(--purple-d); line-height:1; }
.fb-score-lbl { font-size:10px; font-weight:800; color:var(--purple); letter-spacing:.04em; }
.fb-score-frac { font-size:15px; font-weight:800; color:var(--purple-d); }
.fb-done-btns { display:flex; gap:12px; flex-wrap:wrap; justify-content:center; margin-top:4px; }
.fb-done-btn { display:inline-flex; align-items:center; justify-content:center;
    padding:10px 22px; border:none; border-radius:20px; font-family:'Nunito',sans-serif;
    font-size:13px; font-weight:800; color:#fff; cursor:pointer; text-decoration:none;
    background:var(--purple); box-shadow:0 3px 10px rgba(127,119,221,.3);
    transition:transform .18s cubic-bezier(.34,1.4,.64,1), box-shadow .15s, filter .15s; }
.fb-done-btn:hover { transform:translateY(-3px) scale(1.05);
    box-shadow:0 8px 22px rgba(127,119,221,.45); filter:brightness(1.08); }
.fb-done-bottombar { height:40px; background:var(--t50); border-top:1px solid var(--t100); }

@media (max-width:600px) {
    .fb-done-card { padding:24px 16px; }
    .fb-done-title { font-size:22px; }
}
</style>

<div class="fb-done-page">
    <div class="fb-done-topbar">
        <span>Activity completed</span>
    </div>

    <div class="fb-done-body">
        <div class="fb-done-card">

            <div class="fb-done-icon">
                <?= $percent >= 80 ? '🎉' : ($percent >= 50 ? '👍' : '💪') ?>
            </div>

            <h2 class="fb-done-title">Fill-in-the-Blank</h2>

            <p class="fb-done-msg">You've completed the activity. Great job practicing!</p>

            <div class="fb-score-ring">
                <span class="fb-score-pct"><?= $percent ?>%</span>
                <span class="fb-score-lbl">SCORE</span>
            </div>

            <div class="fb-score-frac"><?= $correct ?> / <?= $total ?> correct</div>

            <div class="fb-done-btns">
                <a href="viewer.php?id=<?= urlencode($activityId) ?>&unit=<?= urlencode($unit) ?>"
                   class="fb-done-btn">↺ Try Again</a>
                <button onclick="history.back()" class="fb-done-btn">← Back</button>
            </div>

        </div>
    </div>

    <div class="fb-done-bottombar"></div>
</div>

<?php
$content = ob_get_clean();
render_activity_viewer('Activity completed', 'fa-solid fa-circle-check', $content);
