(function ($) {
    'use strict';

    $(function () {
        const root = document.getElementById('flipbook-viewer');
        if (!root) return;

        const pdfUrl = root.dataset.pdfUrl || '';
        const voiceLang = root.dataset.language || 'en-US';
        const listenEnabled = root.dataset.listenEnabled === '1';

        let pageTexts = [];
        try {
            pageTexts = JSON.parse(root.dataset.pageTexts || '[]');
            if (!Array.isArray(pageTexts)) pageTexts = [];
        } catch (e) {
            pageTexts = [];
        }

        let currentPage = 1;
        const totalPages = pageTexts.length > 0 ? pageTexts.length : 1;

        const $currentPage = $('#current-page');
        const $totalPages = $('#total-pages');
        const $currentPageText = $('#current-page-text');
        const $pdfFrame = $('#pdf-frame');

        function getPdfSrc(page) {
            return pdfUrl + '#page=' + page + '&toolbar=1&navpanes=0&scrollbar=1';
        }

        function stopSpeaking() {
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();
            }
        }

        function getPageText(page) {
            return pageTexts[page - 1] || 'No hay texto definido para esta página.';
        }

        function renderPage() {
            $currentPage.text(currentPage);
            $totalPages.text(totalPages);
            $currentPageText.text(getPageText(currentPage));
            $pdfFrame.attr('src', getPdfSrc(currentPage));
        }

        $('#prev-btn').on('click', function () {
            if (currentPage <= 1) return;
            currentPage--;
            stopSpeaking();
            renderPage();
        });

        $('#next-btn').on('click', function () {
            if (currentPage >= totalPages) return;
            currentPage++;
            stopSpeaking();
            renderPage();
        });

        if (listenEnabled) {
            $('#listen-btn').on('click', function () {
                stopSpeaking();

                const text = pageTexts[currentPage - 1] || '';
                if (!text) {
                    alert('No hay texto configurado para esta página.');
                    return;
                }

                if (!('speechSynthesis' in window)) {
                    alert('Este navegador no soporta lectura de voz.');
                    return;
                }

                const utterance = new SpeechSynthesisUtterance(text);
                utterance.lang = voiceLang;
                window.speechSynthesis.speak(utterance);
            });

            $('#stop-listen-btn').on('click', function () {
                stopSpeaking();
            });
        }

        $('#open-pdf-btn').on('click', function () {
            window.open(pdfUrl, '_blank', 'noopener');
        });

        $('#full-screen-btn').on('click', function () {
            const container = document.getElementById('flipbook-stage');
            if (!document.fullscreenElement) {
                if (container.requestFullscreen) {
                    container.requestFullscreen();
                }
            } else if (document.exitFullscreen) {
                document.exitFullscreen();
            }
        });

        window.addEventListener('beforeunload', stopSpeaking);

        renderPage();
    });
})(jQuery);
