/* ── Per-Panel Fullscreen ─────────────────────────────────────────────────
   Usage:
     1. Include panel_fullscreen.css and this file (via the activity template).
     2. Call  window.initPanelFullscreen(panelEl, options)  for each panel that
        should have its own expand button.
     3. The expand button is injected in a sticky bar at the top of the panel.
     4. When clicked, the panel expands to cover the full viewport with its own
        zoom controls (−  100%  +) and a "Salir" exit button.
     5. Pinch-to-zoom and double-tap reset work inside the fullscreen overlay.

   Options:
     label  {string}   Aria label / tooltip for the expand button.
     onOpen  {fn}      Called after entering fullscreen. Receives panelEl.
     onClose {fn}      Called after exiting fullscreen. Receives panelEl.

   Exposed on panelEl:
     panelEl._pfClose()      Programmatically exit fullscreen.
     panelEl._pfIsOpen()     Returns true when fullscreen is active.
   ──────────────────────────────────────────────────────────────────────── */
(function () {
    'use strict';

    var ZOOM_STEP = 0.2;
    var ZOOM_MIN  = 0.5;
    var ZOOM_MAX  = 4.0;

    /* ── Expand icon (resize-full / arrows-out) ── */
    function pfExpandIcon() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"' +
               ' fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"' +
               ' stroke-linejoin="round" aria-hidden="true">' +
               '<polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/>' +
               '<line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>';
    }

    /* ── Collapse icon (resize-small / arrows-in) ── */
    function pfCollapseIcon() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"' +
               ' fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"' +
               ' stroke-linejoin="round" aria-hidden="true">' +
               '<polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/>' +
               '<line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/></svg>';
    }

    function initPanelFullscreen(panelEl, options) {
        if (!panelEl || panelEl._pfDone) return;
        panelEl._pfDone = true;
        options = options || {};

        /* ── Ensure panel is a positioning context (needed for sticky child) ── */
        if (window.getComputedStyle(panelEl).position === 'static') {
            panelEl.style.position = 'relative';
        }

        /* ── Sticky bar at top of panel containing the expand button ── */
        var panelBar = document.createElement('div');
        panelBar.className = 'pf-panel-bar';

        var expandBtn = document.createElement('button');
        expandBtn.className = 'pf-expand-btn';
        expandBtn.setAttribute('type', 'button');
        expandBtn.setAttribute('aria-label', options.label || 'Pantalla completa');
        expandBtn.setAttribute('title',      options.label || 'Pantalla completa');
        expandBtn.innerHTML = pfExpandIcon();

        panelBar.appendChild(expandBtn);
        panelEl.insertBefore(panelBar, panelEl.firstChild);

        /* ── State ── */
        var scale          = 1;
        var isOpen         = false;
        var controlsEl     = null;
        var zoomLayerEl    = null;
        var zoomContentEl  = null;
        var zoomLabelEl    = null;
        var pinchActive    = false;
        var pinchStartDist = 0;
        var pinchStartScale = 1;

        expandBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            openFS();
        });

        /* ────────────────────────────────────── Open ── */
        function openFS() {
            if (isOpen) return;
            isOpen = true;
            scale  = 1;

            /* ── Controls bar (zoom + exit) ── */
            controlsEl = document.createElement('div');
            controlsEl.className = 'pf-controls';

            var outBtn = document.createElement('button');
            outBtn.className = 'pf-zoom-btn';
            outBtn.setAttribute('type', 'button');
            outBtn.setAttribute('aria-label', 'Reducir zoom');
            outBtn.textContent = '\u2212';

            zoomLabelEl = document.createElement('span');
            zoomLabelEl.className = 'pf-zoom-label';
            zoomLabelEl.textContent = '100%';

            var inBtn = document.createElement('button');
            inBtn.className = 'pf-zoom-btn';
            inBtn.setAttribute('type', 'button');
            inBtn.setAttribute('aria-label', 'Aumentar zoom');
            inBtn.textContent = '+';

            var exitBtn = document.createElement('button');
            exitBtn.className = 'pf-exit-btn';
            exitBtn.setAttribute('type', 'button');
            exitBtn.setAttribute('aria-label', 'Salir de pantalla completa');
            exitBtn.innerHTML = pfCollapseIcon() + '<span class="pf-exit-label">Salir</span>';

            outBtn.addEventListener('click',  function (e) { e.stopPropagation(); zoomBy(-ZOOM_STEP); });
            inBtn.addEventListener('click',   function (e) { e.stopPropagation(); zoomBy(ZOOM_STEP);  });
            exitBtn.addEventListener('click', function (e) { e.stopPropagation(); closeFS();          });

            controlsEl.appendChild(outBtn);
            controlsEl.appendChild(zoomLabelEl);
            controlsEl.appendChild(inBtn);
            controlsEl.appendChild(exitBtn);

            /* ── Zoom layer (scroll container) + zoom content (zoom target) ── */
            zoomLayerEl   = document.createElement('div');
            zoomLayerEl.className   = 'pf-zoom-layer';
            zoomContentEl = document.createElement('div');
            zoomContentEl.className = 'pf-zoom-content';

            /* Move ALL current panel children into zoomContentEl */
            Array.from(panelEl.childNodes).forEach(function (ch) {
                zoomContentEl.appendChild(ch);
            });

            zoomLayerEl.appendChild(zoomContentEl);
            panelEl.appendChild(controlsEl);
            panelEl.appendChild(zoomLayerEl);

            /* ── Activate fullscreen ── */
            panelEl.classList.add('pf-active');
            document.body.classList.add('pf-body-lock');

            /* Pinch-to-zoom on zoom layer in CAPTURE phase so we take priority
               over any activity_zoom.js listeners on child elements. */
            zoomLayerEl.addEventListener('touchstart', onTouchStart, { passive: false, capture: true });
            zoomLayerEl.addEventListener('touchmove',  onTouchMove,  { passive: false, capture: true });
            zoomLayerEl.addEventListener('touchend',   onTouchEnd,   { passive: true,  capture: true });

            /* Double-tap zoom reset */
            var lastTap = 0;
            zoomContentEl._pfDblTap = function (e) {
                if (e.touches.length > 0) return;
                var now = Date.now();
                if (now - lastTap < 300) { scale = 1; applyZoom(); }
                lastTap = now;
            };
            zoomContentEl.addEventListener('touchend', zoomContentEl._pfDblTap, { passive: true });

            document.addEventListener('keydown', onKeydown);

            if (options.onOpen) options.onOpen(panelEl);
        }

        /* ────────────────────────────────────── Close ── */
        function closeFS() {
            if (!isOpen) return;
            isOpen = false;

            /* Restore original children from zoomContentEl back to panelEl */
            Array.from(zoomContentEl.childNodes).forEach(function (ch) {
                panelEl.appendChild(ch);
            });

            zoomLayerEl.remove();
            controlsEl.remove();
            zoomLayerEl   = null;
            controlsEl    = null;
            zoomContentEl = null;
            zoomLabelEl   = null;
            scale         = 1;

            panelEl.classList.remove('pf-active');
            document.body.classList.remove('pf-body-lock');
            document.removeEventListener('keydown', onKeydown);

            if (options.onClose) options.onClose(panelEl);
        }

        /* ────────────────────────────────────── Zoom helpers ── */
        function zoomBy(delta) {
            scale = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN,
                parseFloat((scale + delta).toFixed(2))));
            applyZoom();
        }

        function applyZoom() {
            if (!zoomContentEl) return;
            zoomContentEl.style.zoom = scale === 1 ? '' : String(scale);
            if (zoomLabelEl) zoomLabelEl.textContent = Math.round(scale * 100) + '%';
        }

        /* ────────────────────────────────────── Touch (pinch) ── */
        function pinchDist(e) {
            var dx = e.touches[0].clientX - e.touches[1].clientX;
            var dy = e.touches[0].clientY - e.touches[1].clientY;
            return Math.sqrt(dx * dx + dy * dy);
        }

        function onTouchStart(e) {
            if (e.touches.length === 2) {
                pinchActive     = true;
                pinchStartDist  = pinchDist(e);
                pinchStartScale = scale;
                e.preventDefault();
                e.stopPropagation(); /* prevent child activity_zoom.js from also handling */
            }
        }

        function onTouchMove(e) {
            if (!pinchActive || e.touches.length !== 2) return;
            scale = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN,
                parseFloat((pinchStartScale * pinchDist(e) / pinchStartDist).toFixed(2))));
            applyZoom();
            e.preventDefault();
            e.stopPropagation();
        }

        function onTouchEnd(e) {
            if (e.touches.length < 2) pinchActive = false;
        }

        function onKeydown(e) {
            if (e.key === 'Escape') closeFS();
        }

        /* ── Expose API on element ── */
        panelEl._pfClose  = closeFS;
        panelEl._pfIsOpen = function () { return isOpen; };
    }

    /* ── Auto-initialise elements with data-pf-fullscreen attribute ── */
    function autoInit() {
        var els = document.querySelectorAll('[data-pf-fullscreen]');
        for (var i = 0; i < els.length; i++) {
            initPanelFullscreen(els[i], {
                label: els[i].dataset.pfLabel || 'Pantalla completa'
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInit);
    } else {
        autoInit();
    }

    window.initPanelFullscreen = initPanelFullscreen;
}());
