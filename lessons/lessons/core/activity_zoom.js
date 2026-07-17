/* ── Universal Activity Zoom ─────────────────────────────────────────────
   Usage:
     1. Include activity_zoom.css and this file.
     2. Add  data-az-zoom  attribute to the element that should zoom
        (it must already contain both the card content AND the nav buttons).
     3. The zoom bar (− 100% +) is injected automatically above that element.
     4. Optionally call  window.initActivityZoom(el)  directly for dynamic UI.
   ──────────────────────────────────────────────────────────────────────── */
(function () {
    'use strict';

    var ZOOM_STEP = 0.2;
    var ZOOM_MIN  = 0.5;
    var ZOOM_MAX  = 3.0;

    function initActivityZoom(el) {
        if (!el || el.dataset.azInitialized) return;
        el.dataset.azInitialized = 'true';
        el.classList.add('az-zoom-target');

        /* ── Inject zoom bar above the target element ── */
        var bar = document.createElement('div');
        bar.className = 'az-zoom-bar';
        bar.setAttribute('aria-label', 'Zoom controls');
        bar.innerHTML =
            '<button class="az-zoom-btn" type="button" aria-label="Zoom out" data-az-btn="out">\u2212</button>' +
            '<span   class="az-zoom-label" data-az-btn="label">100%</span>' +
            '<button class="az-zoom-btn" type="button" aria-label="Zoom in"  data-az-btn="in">+</button>';

        el.parentNode.insertBefore(bar, el);

        var labelEl = bar.querySelector('[data-az-btn="label"]');
        var scale   = 1;

        /* ── Apply transform ── */
        function applyZoom() {
            el.style.transform = scale === 1 ? '' : 'scale(' + scale + ')';
            if (scale > 1) {
                el.style.marginBottom = (el.offsetHeight * (scale - 1)) + 'px';
            } else {
                el.style.marginBottom = '';
            }
            labelEl.textContent = Math.round(scale * 100) + '%';
        }

        /* ── Zoom buttons ── */
        bar.querySelector('[data-az-btn="in"]').addEventListener('click', function () {
            scale = Math.min(ZOOM_MAX, parseFloat((scale + ZOOM_STEP).toFixed(2)));
            applyZoom();
        });
        bar.querySelector('[data-az-btn="out"]').addEventListener('click', function () {
            scale = Math.max(ZOOM_MIN, parseFloat((scale - ZOOM_STEP).toFixed(2)));
            applyZoom();
        });

        /* ── Pinch-to-zoom ── */
        var pinchActive     = false;
        var pinchStartDist  = 0;
        var pinchStartScale = 1;

        function pinchDist(e) {
            var dx = e.touches[0].clientX - e.touches[1].clientX;
            var dy = e.touches[0].clientY - e.touches[1].clientY;
            return Math.sqrt(dx * dx + dy * dy);
        }

        el.addEventListener('touchstart', function (e) {
            if (e.touches.length === 2) {
                pinchActive     = true;
                pinchStartDist  = pinchDist(e);
                pinchStartScale = scale;
                e.preventDefault();
            }
        }, { passive: false });

        el.addEventListener('touchmove', function (e) {
            if (!pinchActive || e.touches.length !== 2) return;
            var ratio = pinchDist(e) / pinchStartDist;
            scale = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN,
                parseFloat((pinchStartScale * ratio).toFixed(2))));
            applyZoom();
            e.preventDefault();
        }, { passive: false });

        el.addEventListener('touchend', function (e) {
            if (e.touches.length < 2) pinchActive = false;
        }, { passive: true });

        /* ── Double-tap to reset ── */
        var lastTap = 0;
        el.addEventListener('touchend', function (e) {
            if (e.touches.length > 0) return;
            var now = Date.now();
            if (now - lastTap < 300) { scale = 1; applyZoom(); }
            lastTap = now;
        }, { passive: true });
    }

    /* ── Auto-initialise elements with data-az-zoom attribute ── */
    function autoInit() {
        var els = document.querySelectorAll('[data-az-zoom]');
        for (var i = 0; i < els.length; i++) {
            initActivityZoom(els[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInit);
    } else {
        autoInit();
    }

    window.initActivityZoom = initActivityZoom;
}());
