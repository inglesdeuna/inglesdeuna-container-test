<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
    --dd-orange: #F97316;
    --dd-orange-dark: #C2580A;
    --dd-orange-soft: #FFF0E6;
    --dd-purple: #7F77DD;
    --dd-purple-dark: #534AB7;
    --dd-purple-soft: #EEEDFE;
    --dd-white: #FFFFFF;
    --dd-lila-border: #EDE9FA;
    --dd-muted: #9B94BE;
    --dd-ink: #271B5D;
    --dd-bg: #F8F7FE;
    --dd-green: #16a34a;
    --dd-red: #dc2626;
}

* { box-sizing: border-box; }
html, body { width: 100%; min-height: 100%; margin: 0; padding: 0; }

body {
    margin: 0 !important;
    padding: 0 !important;
    background: var(--dd-bg) !important;
    font-family: 'Nunito', sans-serif !important;
}

.activity-wrapper {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    min-height: 0;
    display: flex !important;
    flex-direction: column !important;
    background: transparent !important;
}

.top-row, .activity-header { display: none !important; }

.viewer-content {
    flex: 1 !important;
    display: flex !important;
    flex-direction: column !important;
    min-height: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    border-radius: 0 !important;
}

.dd-page {
    width: 100%;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: clamp(14px, 2.5vw, 34px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: var(--dd-bg);
}

.dd-app {
    width: min(940px, 100%);
    margin: 0 auto;
}

#dd-activity {
    width: min(860px, 100%);
    margin: 0 auto;
    background: #fff;
    border: 1px solid var(--dd-lila-border);
    border-radius: 24px;
    box-shadow: 0 8px 40px rgba(127,119,221,.13);
    padding: 18px;
}

.dd-hero {
    text-align: center;
    margin-bottom: clamp(14px, 2vw, 22px);
}

.dd-kicker {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 14px;
    border-radius: 999px;
    background: var(--dd-orange-soft);
    border: 1px solid #FCDDBF;
    color: var(--dd-orange-dark);
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 10px;
}

.dd-hero h1 {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(30px, 5.5vw, 54px);
    font-weight: 700;
    color: var(--dd-orange);
    margin: 0;
    line-height: 1;
}

.dd-hero p {
    font-size: clamp(13px, 1.8vw, 15px);
    font-weight: 700;
    color: var(--dd-muted);
    margin: 8px 0 0;
}

.dd-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}

.dd-progress-label {
    font-size: 12px;
    font-weight: 900;
    color: var(--dd-muted);
    min-width: 48px;
}

.dd-track {
    flex: 1;
    height: 12px;
    background: var(--dd-purple-soft);
    border-radius: 999px;
    overflow: hidden;
}

.dd-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, var(--dd-orange), var(--dd-purple));
    border-radius: 999px;
    transition: width .35s ease;
}

.dd-badge {
    min-width: 84px;
    text-align: center;
    padding: 7px 10px;
    border-radius: 999px;
    background: var(--dd-purple);
    color: #fff;
    font-size: 12px;
    font-weight: 900;
}

.dd-card-shell {
    background: #fff;
    border: 1px solid #F0EEF8;
    border-radius: 34px;
    padding: clamp(16px, 2.6vw, 26px);
    box-shadow: 0 8px 40px rgba(127,119,221,.13);
}

.dd-prompt-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 14px;
    align-items: stretch;
    margin-bottom: 16px;
}

.dd-prompt-row.dd-prompt-row--with-image {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
}

#dd-instruction {
    font-size: clamp(16px, 2.2vw, 20px);
    font-weight: 700;
    color: var(--dd-purple-dark);
    text-align: center;
    line-height: 2.2;
    background: var(--dd-purple-soft);
    border-radius: 16px;
    padding: 20px;
}

.dd-media {
    background: #fff;
    border: 1px solid var(--dd-lila-border);
    border-radius: 16px;
    padding: 8px;
    min-height: 0;
    height: 100%;
    display: none;
}

.dd-media img {
    width: 100%;
    height: 100%;
    max-height: none;
    object-fit: contain;
    border-radius: 10px;
    display: block;
}

.dd-media-note {
    display: none;
    font-size: 12px;
    font-weight: 800;
    color: var(--dd-muted);
    text-align: center;
    padding: 10px 6px;
}

.dd-inline-drop {
    display: inline-block;
    min-width: 96px;
    height: 38px;
    line-height: 34px;
    border: 2px dashed #d8d3f5;
    border-radius: 10px;
    padding: 0 10px;
    text-align: center;
    vertical-align: middle;
    font-size: 14px;
    font-weight: 800;
    color: var(--dd-muted);
    background: #fff;
    transition: border-color .15s, background .15s;
    margin: 0 3px;
    cursor: default;
}

.dd-inline-drop--over { border-color: var(--dd-purple); background: #EEEDFE; }
.dd-inline-drop--filled { border-style: solid; border-color: #d8d3f5; color: var(--dd-purple-dark); background: #EEEDFE; cursor: pointer; }
.dd-inline-drop--correct { border-color: var(--dd-green) !important; background: #f0fdf4 !important; color: var(--dd-green) !important; }
.dd-inline-drop--wrong { border-color: var(--dd-red) !important; background: #fef2f2 !important; color: var(--dd-red) !important; }
.dd-inline-drop--revealed { border-color: var(--dd-orange) !important; background: #FFF9F5 !important; color: var(--dd-orange-dark) !important; }

#dd-words {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    justify-content: center;
    margin-bottom: 16px;
    min-height: 56px;
}

.dd-touch-hint {
    text-align: center;
    font-size: 12px;
    font-weight: 800;
    color: var(--dd-muted);
    margin: -6px 0 16px;
}
.dd-touch-hint.hidden { display: none; }

.dd-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 12px 22px;
    border-radius: 14px;
    background: #EEEDFE;
    border: 2px solid #AFA9EC;
    color: var(--dd-purple-dark);
    font-family: 'Fredoka', sans-serif;
    font-size: 17px;
    font-weight: 700;
    text-align: center;
    cursor: grab;
    user-select: none;
    box-shadow: 0 4px 14px rgba(127,119,221,.22);
    transition: opacity .12s ease, border-color .12s ease, background .12s ease, transform .12s ease, box-shadow .12s ease;
}

.dd-chip:active { cursor: grabbing; }
.dd-chip:hover {
    background: #E7E4FB;
    border-color: var(--dd-purple);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(127,119,221,.32);
}
.dd-chip.dd-chip--dragging { opacity: .45; transform: none; }
.dd-chip.dd-chip--selected {
    background: var(--dd-purple);
    border-color: var(--dd-purple);
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(127,119,221,.4);
}

.dd-actions {
    display: grid;
    grid-template-columns: repeat(4, minmax(110px, 1fr));
    gap: 10px;
    border-top: 1px solid var(--dd-lila-border);
    padding-top: 16px;
    margin-top: 8px;
}

.dd-actions--quiz {
    grid-template-columns: repeat(3, minmax(110px, 1fr));
}

.dd-btn {
    border: 0;
    border-radius: 999px;
    padding: 13px 16px;
    min-width: clamp(104px, 16vw, 146px);
    color: #fff;
    cursor: pointer;
    font-family: 'Nunito', sans-serif;
    font-size: 14px;
    font-weight: 900;
    transition: transform .18s ease, filter .18s ease;
    box-shadow: 0 6px 18px rgba(127,119,221,.15);
}

.dd-btn:hover { transform: translateY(-1px); }
.dd-btn:disabled { opacity: .45; cursor: default; transform: none; }
.dd-btn-orange { background: var(--dd-orange); box-shadow: 0 6px 18px rgba(249,115,22,.22); }
.dd-btn-purple { background: var(--dd-purple); }

#dd-feedback { margin-top: 8px; }

.dd-score-grid {
    display: none;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-top: 14px;
}

.dd-score-grid.visible {
    display: grid;
}

.dd-score-card {
    background: #FAFAFE;
    border: 1px solid var(--dd-lila-border);
    border-radius: 14px;
    padding: 12px;
    text-align: center;
}

.dd-score-num {
    font-family: 'Fredoka', sans-serif;
    font-size: 28px;
    line-height: 1;
    font-weight: 700;
}

.dd-score-num.c { color: var(--dd-green); }
.dd-score-num.w { color: var(--dd-red); }
.dd-score-num.p { color: var(--dd-purple); }

.dd-score-lbl {
    margin-top: 6px;
    font-size: 10px;
    font-weight: 900;
    color: var(--dd-muted);
    text-transform: uppercase;
    letter-spacing: .08em;
}

.dd-completed-screen {
    display: none;
    text-align: center;
    padding: 18px 8px;
}

.dd-completed-screen.active {
    display: block;
}

.dd-completed-icon {
    font-size: 30px;
    line-height: 1;
    margin-bottom: 6px;
}

.dd-completed-title {
    margin: 0;
    color: var(--dd-orange);
    font-family: 'Fredoka', sans-serif;
    font-size: 40px;
    font-weight: 700;
}

.dd-completed-text {
    margin: 8px 0 0;
    color: var(--dd-muted);
    font-size: 14px;
    font-weight: 700;
}

.dd-score-text {
    margin: 10px 0 0;
    color: var(--dd-purple-dark);
    font-size: 15px;
    font-weight: 900;
}

.dd-restart-btn {
    margin-top: 12px;
    border: 0;
    border-radius: 999px;
    padding: 13px 22px;
    background: var(--dd-purple);
    color: #fff;
    font-family: 'Nunito', sans-serif;
    font-size: 14px;
    font-weight: 900;
    cursor: pointer;
}

.dd-restart-btn:hover {
    filter: brightness(1.06);
}

@media (max-width: 640px) {
    .dd-page { padding: 12px; }
    .dd-actions { grid-template-columns: 1fr; }
    .dd-btn { width: 100%; }
    .dd-prompt-row.dd-prompt-row--with-image { grid-template-columns: 1fr; }
    .dd-media { max-width: 260px; margin: 0 auto; }
    .dd-score-grid { grid-template-columns: 1fr; }
}
</style>

<div class="dd-page">
    <div class="dd-app">
        <?php if (empty($ddQuizMode)): ?>
        <div class="dd-hero">
            <div class="dd-kicker">Activity</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Drag the words into the correct blanks.</p>
        </div>
        <?php endif; ?>

        <div id="dd-activity" data-az-zoom>
            <div class="dd-progress">
                <span class="dd-progress-label" id="dd-progress-label"></span>
                <div class="dd-track"><div class="dd-fill" id="dd-progress-fill"></div></div>
                <div class="dd-badge" id="dd-progress-badge"></div>
            </div>

            <div class="dd-card-shell" id="dd-card-shell">
                <div id="dd-card-body">
                    <div class="dd-prompt-row" id="dd-prompt-row">
                        <div id="dd-instruction"></div>
                        <div class="dd-media" id="dd-media" aria-hidden="true">
                            <img id="dd-image" alt="Question image">
                            <div class="dd-media-note" id="dd-media-note">Image unavailable</div>
                        </div>
                    </div>

                    <div id="dd-words"></div>
                    <div id="dd-touch-hint" class="dd-touch-hint hidden">Tap a word, then tap a blank to place it.</div>
                </div>

                <div class="dd-actions<?php echo !empty($ddQuizMode) ? ' dd-actions--quiz' : ''; ?>">
                    <button class="dd-btn dd-btn-purple" id="dd-listen">Listen</button>
                    <?php if (empty($ddQuizMode)): ?>
                    <button class="dd-btn dd-btn-orange" id="dd-check">Check</button>
                    <button class="dd-btn dd-btn-purple" id="dd-show">Show Answer</button>
                    <?php endif; ?>
                    <button class="dd-btn dd-btn-orange" id="dd-next">Next</button>
                    <?php if (!empty($ddQuizMode)): ?>
                    <button class="dd-btn dd-btn-purple" id="dd-quiz-skip">Skip</button>
                    <?php endif; ?>
                </div>
            </div>

            <div id="dd-feedback"></div>

            <div id="dd-score-grid" class="dd-score-grid">
                <div class="dd-score-card">
                    <div class="dd-score-num c" id="dd-s-correct">0</div>
                    <div class="dd-score-lbl">Correct</div>
                </div>
                <div class="dd-score-card">
                    <div class="dd-score-num w" id="dd-s-wrong">0</div>
                    <div class="dd-score-lbl">Wrong</div>
                </div>
                <div class="dd-score-card">
                    <div class="dd-score-num p" id="dd-s-pct">0%</div>
                    <div class="dd-score-lbl">Score</div>
                </div>
            </div>

            <div id="dd-completed" class="dd-completed-screen">
                <div class="dd-completed-icon">✅</div>
                <h2 class="dd-completed-title" id="dd-completed-title"></h2>
                <p class="dd-completed-text" id="dd-completed-text"></p>
                <p class="dd-score-text" id="dd-score-text"></p>
                <button type="button" class="dd-restart-btn" id="dd-restart">Restart</button>
            </div>
        </div>
    </div>
</div>
