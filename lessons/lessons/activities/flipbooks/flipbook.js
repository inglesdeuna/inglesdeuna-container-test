document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('flipbook-viewer');
    if (!root) return;

    const pdfUrl = root.dataset.pdfUrl || '';
    const voiceLang = root.dataset.language || 'en-US';
    const listenEnabled = root.dataset.listenEnabled === '1';

    const pageCountRaw = parseInt(root.dataset.pageCount || '1', 10);
    const totalPages = Number.isInteger(pageCountRaw) && pageCountRaw > 0 ? pageCountRaw : 1;

    let pageTexts = [];
    try {
        pageTexts = JSON.parse(root.dataset.pageTexts || '[]');
        if (!Array.isArray(pageTexts)) {
            pageTexts = [];
        }
    } catch (e) {
        pageTexts = [];
    }

    while (pageTexts.length < totalPages) {
        pageTexts.push('');
    }

    if (pageTexts.length > totalPages) {
        pageTexts = pageTexts.slice(0, totalPages);
    }

    const currentPageEl = document.getElementById('current-page');
    const totalPagesEl = document.getElementById('total-pages');
    const currentPageTextEl = document.getElementById('current-page-text');
    const pdfFrame = document.getElementById('pdf-frame');

    const prevBtn = document.getElementById('prev-btn');
    const nextBtn = document.getElementById('next-btn');
    const listenBtn = document.getElementById('listen-btn');
    const stopListenBtn = document.getElementById('stop-listen-btn');
    const openPdfBtn = document.getElementById('open-pdf-btn');
    const fullScreenBtn = document.getElementById('full-screen-btn');
    const stage = document.getElementById('flipbook-stage');

    let currentPage = 1;

    function stopSpeaking() {
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }
    }

    function getPdfSrc(page) {
        const safePage = Math.min(Math.max(page, 1), totalPages);
        return pdfUrl + '#page=' + safePage + '&toolbar=1&navpanes=0&scrollbar=1';
    }

    function getPageText(page) {
        const text = (pageTexts[page - 1] || '').trim();
        return text !== '' ? text : 'No hay texto definido para esta página.';
    }

    function updateButtons() {
        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
    }

    function renderPage() {
        if (currentPageEl) currentPageEl.textContent = String(currentPage);
        if (totalPagesEl) totalPagesEl.textContent = String(totalPages);
        if (currentPageTextEl) currentPageTextEl.textContent = getPageText(currentPage);
        if (pdfFrame && pdfUrl) {
            pdfFrame.src = getPdfSrc(currentPage);
        }
        updateButtons();
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            if (currentPage <= 1) return;
            currentPage--;
            stopSpeaking();
            renderPage();
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            if (currentPage >= totalPages) return;
            currentPage++;
            stopSpeaking();
            renderPage();
        });
    }

    if (listenBtn && listenEnabled) {
        listenBtn.addEventListener('click', function () {
            stopSpeaking();

            const text = (pageTexts[currentPage - 1] || '').trim();
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
    }

    if (stopListenBtn) {
        stopListenBtn.addEventListener('click', function () {
            stopSpeaking();
        });
    }

    if (openPdfBtn) {
        openPdfBtn.addEventListener('click', function () {
            if (!pdfUrl) return;
            window.open(getPdfSrc(currentPage), '_blank', 'noopener');
        });
    }

    if (fullScreenBtn && stage) {
        fullScreenBtn.addEventListener('click', function () {
            if (!document.fullscreenElement) {
                if (stage.requestFullscreen) {
                    stage.requestFullscreen();
                }
            } else if (document.exitFullscreen) {
                document.exitFullscreen();
            }
        });
    }

    window.addEventListener('beforeunload', stopSpeaking);

    renderPage();
});
