/* Foldx 图床 · 公共交互 */
(function () {
    'use strict';

    const themeKey = 'foldx-theme';
    const root = document.documentElement;

    function getSavedTheme() {
        try { return localStorage.getItem(themeKey); } catch (e) { return null; }
    }

    function saveTheme(value) {
        try { localStorage.setItem(themeKey, value); } catch (e) { /* ignore */ }
    }

    function applyTheme(value) {
        root.dataset.theme = value;
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.content = value === 'light' ? '#eef7ff' : '#081a31';
    }

    const saved = getSavedTheme();
    applyTheme(saved === 'light' ? 'light' : 'dark');

    let toastTimer = null;
    function toast(message, tone) {
        const el = document.querySelector('[data-toast]');
        if (!el) return;
        el.textContent = message;
        el.dataset.tone = tone || 'default';
        el.classList.add('is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => el.classList.remove('is-visible'), 3200);
    }

    window.Foldx = window.Foldx || {};
    window.Foldx.toast = toast;

    document.addEventListener('click', (event) => {
        const themeButton = event.target.closest('[data-theme-toggle]');
        if (themeButton) {
            const next = root.dataset.theme === 'light' ? 'dark' : 'light';
            applyTheme(next);
            saveTheme(next);
            return;
        }

        const copyButton = event.target.closest('[data-copy]');
        if (copyButton) {
            const value = copyButton.getAttribute('data-copy') || '';
            const finish = () => toast('直链已复制', 'success');
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(value).then(finish).catch(() => fallbackCopy(value, finish));
            } else {
                fallbackCopy(value, finish);
            }
        }
    });

    function fallbackCopy(value, done) {
        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            if (done) done();
        } catch (e) {
            toast('复制失败，请手动选择链接', 'error');
        }
        document.body.removeChild(textarea);
    }

    const scrollUpload = document.querySelector('[data-scroll-upload]');
    if (scrollUpload) {
        scrollUpload.addEventListener('click', () => {
            const target = document.querySelector('#upload');
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    if (document.body.dataset.page === 'home') {
        document.querySelectorAll('[data-uploader]').forEach((node) => {
            window.FoldxUploader.init(node, {
                onComplete: () => {
                    setTimeout(() => window.location.reload(), 1200);
                },
            });
        });
    }
})();
