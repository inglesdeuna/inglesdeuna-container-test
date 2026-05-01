document.addEventListener('DOMContentLoaded', function () {

    var payload       = window.DOT_TO_DOT_DATA || {};
    var rawPoints     = Array.isArray(payload.points) ? payload.points : [];
    var labelSettings = payload.labelSettings || {};

    var stage           = document.getElementById('d2dvStage');
    var canvas          = document.getElementById('d2dvCanvas');
    var image           = document.getElementById('d2dvImg');
    var progressEl      = document.getElementById('d2dvProgress');
    var counterEl       = document.getElementById('d2dvCounter');
    var statusEl        = document.getElementById('d2dvStatus');
    var resetBtn        = document.getElementById('d2dvResetBtn');
    var hintBtn         = document.getElementById('d2dvHintBtn');
    var revealBtn       = document.getElementById('d2dvRevealBtn');
    var continueBtn     = document.getElementById('d2dvContinueBtn');
    var completionPanel = document.getElementById('d2dvCompletionPanel');
    var completionScore = document.getElementById('d2dvCompletionScore');

    if (!stage || !canvas || !image || rawPoints.length < 3) return;

    var ctx = canvas.getContext('2d');

    /* ── Normalise points ─────────────────────────────────────── */
    var pts = rawPoints
        .map(function (p) {
            return {
                x: Number(p.x),
                y: Number(p.y),
                label: (p && p.label !== undefined) ? String(p.label) : ''
            };
        })
        .filter(function (p) {
            return isFinite(p.x) && isFinite(p.y)
                && p.x >= 0 && p.x <= 1
                && p.y >= 0 && p.y <= 1;
        });

    if (pts.length < 3) return;

    /* ── Label helpers ─────────────────────────────────────────── */
    function toLetters(n) {
        if (n < 1) return String(n);
        var s = '';
        while (n > 0) {
            n -= 1;
            s = String.fromCharCode(65 + (n % 26)) + s;
            n = Math.floor(n / 26);
        }
        return s;
    }

    function toWords(n) {
        var ones = ['zero','one','two','three','four','five','six','seven','eight','nine',
                    'ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen',
                    'seventeen','eighteen','nineteen'];
        var tens = ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
        if (n < 20)  return ones[n] || String(n);
        if (n < 100) { var t = Math.floor(n / 10), r = n % 10; return r ? tens[t] + '-' + ones[r] : tens[t]; }
        if (n < 1000) { var h = Math.floor(n / 100), r2 = n % 100; return r2 ? ones[h] + ' hundred ' + toWords(r2) : ones[h] + ' hundred'; }
        return String(n);
    }

    function label(i) {
        var p = pts[i];
        if (p && p.label) return p.label;
        var mode  = String(labelSettings.mode  || 'number');
        var start = Math.max(1, Number(labelSettings.start || 1));
        var step  = Math.max(1, Number(labelSettings.step  || 1));
        var val   = start + i * step;
        if (mode === 'letter') return toLetters(val);
        if (mode === 'word')   return toWords(val);
        return String(val);
    }

    /* ── Audio ─────────────────────────────────────────────────── */
    function mkAudio(src, vol) {
        var a = new Audio(src);
        if (vol !== undefined) a.volume = vol;
        return a;
    }
    var sndLine = mkAudio('../../hangman/assets/correct.wav', 0.25);
    var sndOk   = mkAudio('../../hangman/assets/correct.wav', 0.25);
    var sndFail = mkAudio('../../hangman/assets/lose.mp3');
    var sndWin  = mkAudio('../../hangman/assets/win.mp3');

    function play(a) {
        try { a.pause(); a.currentTime = 0; a.play(); } catch (e) {}
    }

    /* ── Embedded detection ────────────────────────────────────── */
    var sp = new URLSearchParams(window.location.search || '');
    var isEmbedded = sp.get('embedded') === '1'
        || sp.get('from') === 'teacher_course'
        || sp.get('from') === 'student_course';

    /* ── State ─────────────────────────────────────────────────── */
    var idx           = 0;      // next dot to connect FROM
    var errors        = 0;
    var dragging      = false;
    var dragPt        = null;
    var done          = false;
    var scoreSaved    = false;
    var completionUrl = '';

    /* ── Geometry ──────────────────────────────────────────────── */
    function canvasRect() {
        return canvas.getBoundingClientRect();
    }

    function resizeCanvas() {
        var r = canvasRect();
        if (!r.width || !r.height) return;
        var dpr = window.devicePixelRatio || 1;
        canvas.width  = Math.round(r.width  * dpr);
        canvas.height = Math.round(r.height * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        draw();
    }

    function ptPx(i) {
        var r = canvasRect();
        return { x: pts[i].x * r.width, y: pts[i].y * r.height };
    }

    function dist(a, b) {
        var dx = a.x - b.x, dy = a.y - b.y;
        return Math.sqrt(dx * dx + dy * dy);
    }

    function toLocal(e) {
        var r = canvasRect();
        return { x: e.clientX - r.left, y: e.clientY - r.top };
    }

    function hitR() {
        var r = canvasRect();
        return Math.max(16, Math.min(28, Math.min(r.width, r.height) * 0.04));
    }

    /* ── Drawing ───────────────────────────────────────────────── */
    function draw() {
        var r = canvasRect();
        if (!r.width || !r.height) return;

        ctx.clearRect(0, 0, r.width, r.height);

        /* White drawing surface */
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, r.width, r.height);

        /* Completed segments */
        if (idx > 0) {
            ctx.strokeStyle = '#1d4ed8';
            ctx.lineWidth   = 5;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
            ctx.beginPath();
            var p0 = ptPx(0);
            ctx.moveTo(p0.x, p0.y);
            for (var i = 1; i <= idx; i++) {
                var pi = ptPx(i);
                ctx.lineTo(pi.x, pi.y);
            }
            ctx.stroke();
        }

        /* Active drag line */
        if (dragging && dragPt) {
            var src = ptPx(idx);
            ctx.strokeStyle = '#0ea5e9';
            ctx.lineWidth   = 4;
            ctx.lineCap     = 'round';
            ctx.beginPath();
            ctx.moveTo(src.x, src.y);
            ctx.lineTo(dragPt.x, dragPt.y);
            ctx.stroke();
        }

        /* Dots and labels */
        pts.forEach(function (_, i) {
            var p         = ptPx(i);
            var connected = i <= idx;
            var current   = i === idx && !done;

            ctx.beginPath();
            ctx.fillStyle = current ? '#0ea5e9' : (connected ? '#1d4ed8' : '#111827');
            ctx.arc(p.x, p.y, 6, 0, Math.PI * 2);
            ctx.fill();

            ctx.fillStyle = '#111827';
            ctx.font      = '700 14px Nunito, sans-serif';
            ctx.fillText(label(i), p.x + 8, p.y - 8);
        });
    }

    /* ── Status UI ─────────────────────────────────────────────── */
    function updateStatus() {
        var total = pts.length - 1;

        if (done) {
            if (stage.classList.contains('revealed')) {
                var safe    = Math.min(errors, total);
                var correct = Math.max(0, total - safe);
                var pct     = total > 0 ? Math.round(correct / total * 100) : 0;
                progressEl.textContent = 'Score: ' + correct + ' / ' + total;
                counterEl.textContent  = pct + '%';
                statusEl.textContent   = 'You completed the picture!';
            } else {
                progressEl.textContent = 'Great job!';
                counterEl.textContent  = total + ' / ' + total + ' lines';
                statusEl.textContent   = 'All dots connected! Click Reveal Image to see the picture.';
            }
            return;
        }

        progressEl.textContent   = 'Connect ' + label(idx) + ' to ' + label(idx + 1);
        counterEl.textContent    = idx + ' / ' + total + ' lines';
        statusEl.textContent     = 'Draw from point ' + label(idx) + ' to point ' + label(idx + 1) + '.';
        continueBtn.style.display = 'none';
    }

    /* ── Score / navigation ────────────────────────────────────── */
    function buildUrl(pct, err, total) {
        var base = typeof payload.returnTo === 'string' ? payload.returnTo : '';
        if (!base) {
            var unit   = sp.get('unit')       || '';
            var asgn   = sp.get('assignment') || '';
            var src    = sp.get('source')     || '';
            var from   = sp.get('from')       || '';
            if (asgn && unit && from === 'teacher_course') {
                base = '../../academic/teacher_course.php?assignment=' + encodeURIComponent(asgn) + '&unit=' + encodeURIComponent(unit);
            } else if (asgn && unit) {
                base = '../../academic/student_course.php?assignment=' + encodeURIComponent(asgn) + '&unit=' + encodeURIComponent(unit);
            } else if (unit) {
                base = '../../academic/unit_view.php?unit=' + encodeURIComponent(unit);
                if (src) base += '&source=' + encodeURIComponent(src);
            }
        }
        if (!base) return '';
        var j = base.indexOf('?') === -1 ? '?' : '&';
        return base + j
            + 'activity_percent=' + encodeURIComponent(String(pct))
            + '&activity_errors=' + encodeURIComponent(String(err))
            + '&activity_total='  + encodeURIComponent(String(total))
            + '&activity_id='     + encodeURIComponent(String(payload.activityId || ''))
            + '&activity_type=dot_to_dot';
    }

    function persistScore(url) {
        if (!url) return;
        fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
            .catch(function () {});
    }

    function navigate(url) {
        if (!url) return;
        try {
            if (window.top && window.top !== window.self) { window.top.location.href = url; return; }
        } catch (e) {}
        window.location.href = url;
    }

    /* ── Activity lifecycle ────────────────────────────────────── */
    function complete() {
        done     = true;
        dragging = false;
        dragPt   = null;
        play(sndWin);

        if (!scoreSaved) {
            var total = pts.length - 1;
            completionUrl = buildUrl(100, errors, total);
            if (completionUrl) {
                scoreSaved = true;
                persistScore(completionUrl);
            }
        }

        revealBtn.style.display = '';
        updateStatus();
        draw();
    }

    function reset() {
        idx           = 0;
        errors        = 0;
        dragging      = false;
        dragPt        = null;
        done          = false;
        scoreSaved    = false;
        completionUrl = '';
        stage.classList.remove('revealed');
        revealBtn.style.display   = 'none';
        continueBtn.style.display = 'none';
        if (completionPanel) completionPanel.style.display = 'none';
        updateStatus();
        draw();
    }

    /* ── Pointer interaction ───────────────────────────────────── */
    function startDrag(pt) {
        if (done) return;
        if (dist(ptPx(idx), pt) > hitR()) return;
        dragging = true;
        dragPt   = pt;
        play(sndLine);
    }

    function endDrag(pt) {
        if (!dragging || done) return;
        dragging = false;
        dragPt   = null;

        if (dist(ptPx(idx + 1), pt) <= hitR()) {
            idx++;
            play(sndOk);
            if (idx >= pts.length - 1) { complete(); return; }
        } else {
            errors++;
            play(sndFail);
            statusEl.textContent = 'Try again. Start from point ' + label(idx) + '.';
        }
        updateStatus();
        draw();
    }

    canvas.addEventListener('pointerdown',  function (e) { e.preventDefault(); startDrag(toLocal(e)); draw(); });
    canvas.addEventListener('pointermove',  function (e) { if (!dragging || done) return; dragPt = toLocal(e); draw(); });
    canvas.addEventListener('pointerup',    function (e) { e.preventDefault(); endDrag(toLocal(e)); });
    canvas.addEventListener('pointercancel', function ()  { dragging = false; dragPt = null; draw(); });

    /* ── Button handlers ───────────────────────────────────────── */
    resetBtn.addEventListener('click', reset);

    hintBtn.addEventListener('click', function () {
        if (done) return;
        errors++;
        statusEl.textContent = 'Hint: connect ' + label(idx) + ' to ' + label(idx + 1) + '.';
    });

    revealBtn.addEventListener('click', function () {
        stage.classList.add('revealed');
        revealBtn.style.display = 'none';

        var total   = pts.length - 1;
        var safe    = Math.min(errors, total);
        var correct = Math.max(0, total - safe);
        var pct     = total > 0 ? Math.round(correct / total * 100) : 0;

        if (completionScore) completionScore.textContent = 'Score: ' + correct + ' / ' + total + ' (' + pct + '%)';
        if (completionPanel) completionPanel.style.display = '';

        continueBtn.style.display = (!isEmbedded && completionUrl) ? '' : 'none';
        updateStatus();
    });

    continueBtn.addEventListener('click', function () {
        if (completionUrl) navigate(completionUrl);
    });

    /* ── Resize: ResizeObserver on stage covers everything ─────── */
    if (typeof ResizeObserver !== 'undefined') {
        new ResizeObserver(function () { resizeCanvas(); }).observe(stage);
    } else {
        window.addEventListener('resize', resizeCanvas);
    }

    /* Belt-and-suspenders: also catch the fullscreen-embedded event */
    document.addEventListener('fullscreen-embedded', function () {
        requestAnimationFrame(function () { requestAnimationFrame(resizeCanvas); });
    });

    /* Initial size once image is loaded */
    if (image.complete) {
        resizeCanvas();
    } else {
        image.addEventListener('load', resizeCanvas);
    }

    updateStatus();
    draw();
});
