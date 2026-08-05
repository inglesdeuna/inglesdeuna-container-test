<link href="https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@600;700;800&family=Lora:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">

<style>
:root {
    --mc-orange:#F97316;
    --mc-purple:#7F77DD;
    --mc-purple-soft:#EEEDFE;
    --mc-lila:#EDE9FA;
    --mc-muted:#9B94BE;
    --mc-bg:#F8F7FE;
    --mc-green:#16a34a;
    --mc-green-soft:#f0fdf4;
    --mc-green-dark:#15803d;
    --mc-red:#ef4444;
    --mc-red-soft:#fef2f2;
    --mc-red-dark:#b91c1c;
}

html, body {
    width:100%;
    min-height:100%;
}

body {
    margin:0!important;
    padding:0!important;
    background:var(--mc-bg)!important;
    font-family:'Nunito',sans-serif!important;
}

.activity-wrapper {
    max-width:100%!important;
    margin:0!important;
    padding:0!important;
    min-height:0;
    display:flex!important;
    flex-direction:column!important;
    background:transparent!important;
}

.top-row,
.activity-header,
.activity-title,
.activity-subtitle {
    display:none!important;
}

.viewer-content {
    flex:1!important;
    display:flex!important;
    flex-direction:column!important;
    min-height:0!important;
    padding:0!important;
    margin:0!important;
    background:transparent!important;
    border:none!important;
    box-shadow:none!important;
    border-radius:0!important;
}

.mc-page {
    width:100%;
    flex:1;
    min-height:0;
    overflow-y:auto;
    padding:clamp(14px,2.2vw,30px);
    display:flex;
    align-items:flex-start;
    justify-content:center;
    background:var(--mc-bg);
    box-sizing:border-box;
}

.mc-app {
    width:min(940px,100%);
    margin:0 auto;
}

.mc-hero {
    text-align:center;
    margin-bottom:16px;
}

.mc-kicker {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:6px 14px;
    border-radius:999px;
    background:#FFF0E6;
    color:var(--mc-orange);
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.08em;
    margin-bottom:10px;
}

.mc-hero h1 {
    margin:0;
    font-family:'Fredoka One',sans-serif;
    font-size:clamp(30px,4.8vw,44px);
    color:var(--mc-orange);
    line-height:1.06;
    font-weight:400;
}

.mc-hero p {
    margin:8px 0 0;
    color:var(--mc-muted);
    font-size:14px;
    font-weight:700;
}

.mc-stage-shell {
    width:min(860px,100%);
    margin:0 auto;
    background:#fff;
    border:1px solid var(--mc-lila);
    border-radius:24px;
    box-shadow:0 8px 40px rgba(127,119,221,.13);
    padding:18px;
}

.mc-progress-row {
    display:grid;
    grid-template-columns:auto 1fr auto;
    align-items:center;
    gap:12px;
    margin-bottom:14px;
}

.mc-progress-label {
    color:var(--mc-purple);
    font-size:13px;
    font-weight:800;
}

.mc-progress-track {
    height:7px;
    border-radius:99px;
    background:var(--mc-lila);
    overflow:hidden;
}

.mc-progress-fill {
    height:100%;
    width:0%;
    border-radius:99px;
    background:linear-gradient(90deg,var(--mc-orange),var(--mc-purple));
    transition:width .2s ease;
}

.mc-progress-badge {
    background:var(--mc-purple);
    color:#fff;
    border-radius:999px;
    padding:5px 12px;
    font-size:12px;
    font-weight:800;
    white-space:nowrap;
}

.mc-card {
    background:#fff;
    border:1.5px solid var(--mc-lila);
    border-radius:24px;
    padding:18px;
}

.mc-listen-wrap {
    display:flex;
    justify-content:center;
    margin-bottom:12px;
}

.mc-listen-btn {
    border:none;
    border-radius:8px;
    background:var(--mc-orange);
    color:#fff;
    padding:10px 18px;
    font-size:14px;
    font-weight:700;
    font-family:'Nunito',sans-serif;
    cursor:pointer;
}

.mc-listen-btn:disabled {
    opacity:.45;
    cursor:not-allowed;
}

.mc-question {
    margin:0 0 10px;
    text-align:center;
    color:#666;
    font-size:15px;
    font-weight:700;
}

.mc-image-box {
    background:var(--mc-bg);
    border:1.5px solid var(--mc-lila);
    border-radius:16px;
    min-height:140px;
    padding:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom:14px;
}

.mc-image-box.is-empty {
    display:none;
}

.mc-image {
    max-width:100%;
    max-height:220px;
    object-fit:contain;
    border-radius:12px;
    display:block;
}

.mc-options {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:10px;
}

.mc-option {
    min-height:96px;
    border:1.5px solid #CDC7F3;
    border-bottom-width:4px;
    border-radius:10px;
    background:#fff;
    color:#4338CA;
    font-family:'Nunito',sans-serif;
    font-size:16px;
    font-weight:800;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    cursor:pointer;
    padding:10px;
    box-shadow:0 2px 0 rgba(127,119,221,.18);
    transition:transform .12s,box-shadow .12s,border-color .12s,background .12s,color .12s;
}

.mc-option:hover {
    transform:translateY(-1px);
    border-color:#AFA6EA;
    border-bottom-color:#6A60D4;
    background:#F8F7FF;
    box-shadow:0 6px 16px rgba(127,119,221,.18);
}

.mc-option.selected {
    border-color:#BDB5EE;
    border-bottom-color:#7F77DD;
    background:#F8F7FF;
    color:#4338CA;
}

.mc-option.correct {
    border-color:var(--mc-green);
    border-bottom-color:var(--mc-green);
    background:var(--mc-green-soft);
    color:var(--mc-green-dark);
    box-shadow:none;
}

.mc-option.wrong {
    border-color:var(--mc-red);
    border-bottom-color:var(--mc-red);
    background:var(--mc-red-soft);
    color:var(--mc-red-dark);
    box-shadow:none;
}

.mc-option img {
    max-width:100%;
    max-height:110px;
    object-fit:contain;
    border-radius:10px;
    display:block;
}

.mc-option {
    position:relative;
}

.mc-option-thumb {
    max-width:100%;
    max-height:70px;
    object-fit:contain;
    border-radius:8px;
    display:block;
    margin:0 auto 6px;
}

.mc-option-text {
    display:block;
}

.mc-option-listen-mode .mc-option-text {
    padding-right:22px;
}

.mc-option-listen {
    position:absolute;
    top:6px;
    right:6px;
    width:26px;
    height:26px;
    border-radius:50%;
    border:none;
    background:var(--mc-lila,#7F77DD);
    color:#fff;
    font-size:13px;
    line-height:26px;
    padding:0;
    cursor:pointer;
    box-shadow:0 2px 6px rgba(0,0,0,.18);
}

.mc-option-listen:hover {
    filter:brightness(1.08);
}

.mc-option-listen:disabled {
    opacity:.45;
    cursor:not-allowed;
}

.mc-controls {
    margin-top:14px;
    display:flex;
    justify-content:center;
    gap:10px;
    flex-wrap:wrap;
}

.mc-btn,
.mc-completed-button {
    border:none;
    border-radius:999px;
    color:#fff;
    min-width:128px;
    padding:11px 20px;
    font-size:14px;
    font-weight:700;
    font-family:'Nunito',sans-serif;
    cursor:pointer;
}

.mc-btn-show,
.mc-completed-button {
    background:var(--mc-purple);
}

.mc-btn-next {
    background:var(--mc-orange);
}

.mc-feedback {
    min-height:18px;
    margin-top:8px;
    text-align:center;
    color:var(--mc-muted);
    font-size:13px;
    font-weight:800;
}

.mc-feedback.good {
    color:var(--mc-green-dark);
}

.mc-feedback.bad {
    color:var(--mc-red-dark);
}

.mc-score-grid {
    display:none;
    grid-template-columns:repeat(3,1fr);
    gap:10px;
    margin-top:12px;
}

.mc-score-grid.visible {
    display:grid;
}

.mc-score-card {
    background:#FAFAFE;
    border:1px solid var(--mc-lila);
    border-radius:14px;
    padding:12px;
    text-align:center;
}

.mc-score-num {
    font-family:'Fredoka One',sans-serif;
    font-size:26px;
    line-height:1;
    font-weight:400;
}

.mc-score-num.c {
    color:var(--mc-green);
}

.mc-score-num.w {
    color:var(--mc-red);
}

.mc-score-num.p {
    color:var(--mc-purple);
}

.mc-score-lbl {
    margin-top:5px;
    font-size:10px;
    font-weight:900;
    color:var(--mc-muted);
    text-transform:uppercase;
    letter-spacing:.08em;
}

.mc-completed-screen {
    display:none;
    text-align:center;
    padding:24px 12px;
}

.mc-completed-screen.active {
    display:block;
}

.mc-completed-title {
    margin:0;
    color:var(--mc-orange);
    font-family:'Fredoka One',sans-serif;
    font-size:32px;
    font-weight:400;
}

.mc-completed-text {
    color:var(--mc-muted);
    font-size:14px;
    font-weight:700;
}

#mc-score-text {
    color:#666;
    font-size:14px;
    font-weight:800;
}

@media(max-width:760px) {
    .mc-stage-shell { padding:14px; }
    .mc-progress-row { grid-template-columns:1fr; gap:8px; }
    .mc-options { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .mc-score-grid { grid-template-columns:1fr; }
}

@media(max-width:480px) {
    .mc-options { grid-template-columns:1fr; }
    .mc-btn,
    .mc-completed-button { width:100%; }
}

/* ---- Passage section (text / listening modes) ---- */
.mc-passage-section {
    width:min(860px,100%);
    margin:0 auto 24px;
}

.mc-passage-card {
    background:#fff;
    border:1px solid var(--mc-lila);
    border-radius:24px;
    box-shadow:0 8px 40px rgba(127,119,221,.13);
    padding:28px 32px;
}

.mc-passage-label {
    display:inline-flex;
    align-items:center;
    gap:6px;
    font-size:12px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:var(--mc-purple);
    background:var(--mc-purple-soft);
    padding:5px 14px;
    border-radius:999px;
    margin-bottom:18px;
}

.mc-passage-body {
    font-family:'Nunito', sans-serif;
    font-size:19px;
    line-height:1.9;
    color:#1a1a2e;
    white-space:pre-wrap;
    word-break:break-word;
    border-left:4px solid var(--mc-purple);
    padding-left:18px;
    background:var(--mc-bg);
    border-radius:0 12px 12px 0;
    padding:14px 18px;
    border-left:4px solid var(--mc-purple);
}

.mc-passage-audio-bar {
    display:flex;
    justify-content:center;
    padding-top:16px;
    border-top:1px solid var(--mc-lila);
    margin-top:18px;
    gap:10px;
    flex-wrap:wrap;
}

.mc-passage-play-btn {
    border:none;
    border-radius:999px;
    background:var(--mc-purple);
    color:#fff;
    padding:11px 26px;
    font-size:15px;
    font-weight:700;
    font-family:'Nunito',sans-serif;
    cursor:pointer;
    transition:opacity .15s;
}

.mc-passage-play-btn:disabled {
    opacity:.45;
    cursor:not-allowed;
}

@media(max-width:760px) {
    .mc-passage-card { padding:18px 16px; }
    .mc-passage-body { font-size:17px; }
}

@media(max-width:480px) {
    .mc-passage-body { font-size:16px; }
}
</style>

<div class="mc-page">
    <div class="mc-app">
        <?php if (empty($mcQuizMode)): ?>
        <div class="mc-hero">
            <div class="mc-kicker">Activity</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Choose the correct answer.</p>
        </div>
        <?php endif; ?>

        <!-- Passage section: shown for text / listening activity modes -->
        <div id="mc-passage-section" class="mc-passage-section" style="display:none">
            <div class="mc-passage-card">
                <div id="mc-passage-text-block">
                    <div class="mc-passage-label">📖 Reading Passage</div>
                    <div id="mc-passage-body" class="mc-passage-body"><?php echo nl2br(htmlspecialchars($passage, ENT_QUOTES, 'UTF-8')); ?></div>
                </div>
                <div id="mc-passage-audio-bar" class="mc-passage-audio-bar" style="display:none">
                    <button type="button" id="mc-passage-play-btn" class="mc-passage-play-btn">🔊 Listen to Passage</button>
                </div>
            </div>
        </div>

        <div class="mc-stage-shell">
            <div class="mc-viewer" id="mc-container" data-az-zoom>
                <div class="mc-progress-row">
                    <div class="mc-progress-label" id="mc-progress-label"></div>
                    <div class="mc-progress-track"><div class="mc-progress-fill" id="mc-progress-fill"></div></div>
                    <div class="mc-progress-badge" id="mc-progress-badge"></div>
                </div>

                <div class="mc-card">
                    <div class="mc-listen-wrap" id="mc-listen-wrap" style="display:none"><button type="button" class="mc-listen-btn" id="mc-listen">🔊 Listen</button></div>
                    <div class="mc-question" id="mc-question"></div>
                    <div class="mc-image-box" id="mc-image-box"><img id="mc-image" class="mc-image" alt=""></div>
                    <div class="mc-options" id="mc-options"></div>
                </div>

                <div class="mc-controls">
                    <?php if (empty($mcQuizMode)): ?>
                    <button type="button" class="mc-btn mc-btn-show" id="mc-show">Show Answer</button>
                    <?php endif; ?>
                    <button type="button" class="mc-btn mc-btn-next" id="mc-next">Next →</button>
                    <?php if (!empty($mcQuizMode)): ?>
                    <button type="button" class="mc-btn mc-btn-show" id="mc-quiz-skip">Skip</button>
                    <?php endif; ?>
                </div>

                <div class="mc-feedback" id="mc-feedback"></div>

                <div id="mc-score-grid" class="mc-score-grid">
                    <div class="mc-score-card">
                        <div class="mc-score-num c" id="mc-s-correct">0</div>
                        <div class="mc-score-lbl">Correct</div>
                    </div>
                    <div class="mc-score-card">
                        <div class="mc-score-num w" id="mc-s-wrong">0</div>
                        <div class="mc-score-lbl">Wrong</div>
                    </div>
                    <div class="mc-score-card">
                        <div class="mc-score-num p" id="mc-s-pct">0%</div>
                        <div class="mc-score-lbl">Score</div>
                    </div>
                </div>

                <div id="mc-completed" class="mc-completed-screen">
                    <div class="mc-completed-icon">✅</div>
                    <h2 class="mc-completed-title" id="mc-completed-title"></h2>
                    <p class="mc-completed-text" id="mc-completed-text"></p>
                    <p class="mc-completed-text" id="mc-score-text" style="font-weight:900;font-size:15px;color:#534AB7;"></p>
                    <button type="button" class="mc-completed-button" id="mc-restart">Restart</button>
                </div>
            </div>
        </div>

    </div>
</div>
