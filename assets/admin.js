/* Foldx 图床 · 控制台 */
(function () {
    'use strict';
    if (document.body.dataset.page !== 'admin') return;

    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    const state = { page: 1, q: '', cat: '', images: [], total: 0, loading: false };

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, (ch) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[ch]));
    }

    function svg(name, size) {
        const paths = {
            copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
            open: '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M20 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4"/>',
            trash: '<path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="m6 7 1 13h10l1-13"/><path d="M10 11v5M14 11v5"/>',
            image: '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m4 17 5-5 4 4 3-3 4 4"/>',
            search: '<circle cx="11" cy="11" r="6"/><path d="m16 16 5 5"/>',
        };
        return '<svg class="ui-icon" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[name] || paths.image) + '</svg>';
    }

    async function api(path, options) {
        const opts = Object.assign({ credentials: 'same-origin' }, options || {});
        const response = await fetch(path, opts);
        if (response.status === 401) {
            window.location.href = '/login.php?next=/admin/';
            throw new Error('需要重新登录');
        }
        let data;
        try { data = await response.json(); } catch (e) { data = { ok: false, error: '服务返回异常' }; }
        if (!response.ok || data.ok === false) {
            throw new Error(data.error || '请求失败');
        }
        return data;
    }

    function toast(message, tone) {
        if (window.Foldx && window.Foldx.toast) window.Foldx.toast(message, tone);
    }

    function updateStats(stats) {
        if (!stats) return;
        const count = document.querySelector('[data-stat="count"]');
        const bytes = document.querySelector('[data-stat="bytes"]');
        const today = document.querySelector('[data-stat="today"]');
        const sidebarBytes = document.querySelector('[data-sidebar-bytes]');
        if (count) count.textContent = stats.count;
        if (bytes) bytes.textContent = stats.bytesText;
        if (today) today.textContent = stats.today;
        if (sidebarBytes) sidebarBytes.textContent = stats.bytesText;
    }

    function renderImages(images, append) {
        const grid = document.querySelector('[data-library]');
        const empty = document.querySelector('[data-library-empty]');
        const countEl = document.querySelector('[data-library-count]');
        const summaryEl = document.querySelector('[data-library-summary]');
        if (!grid) return;

        if (!append) grid.replaceChildren();
        images.forEach((img) => {
            const card = document.createElement('article');
            card.className = 'library-card';
            card.dataset.id = img.id;
            card.innerHTML =
                '<a class="library-thumb" href="' + esc(img.url) + '" target="_blank" rel="noopener" aria-label="打开原图">' +
                    '<img src="' + esc(img.thumb) + '" alt="' + esc(img.name) + '" loading="lazy">' +
                    '<span class="library-dim">' + esc(img.width) + ' × ' + esc(img.height) + '</span>' +
                '</a>' +
                '<div class="library-body">' +
                    '<h3 title="' + esc(img.name) + '">' + esc(img.name) + '</h3>' +
                    '<p>' + esc(img.dateText) + ' · ' + esc(img.sizeText) + '</p>' +
                    '<span class="library-category">' + esc(img.category || '未分类') + '</span>' +
                    '<code title="' + esc(img.url) + '">' + esc(img.url) + '</code>' +
                '</div>' +
                '<div class="library-actions">' +
                    '<button class="icon-action" type="button" data-copy="' + esc(img.url) + '" aria-label="复制直链">' + svg('copy', 15) + '</button>' +
                    '<a class="icon-action" href="' + esc(img.url) + '" target="_blank" rel="noopener" aria-label="打开原图">' + svg('open', 15) + '</a>' +
                    '<button class="icon-action danger" type="button" data-delete="' + esc(img.id) + '" aria-label="删除图片">' + svg('trash', 15) + '</button>' +
                '</div>';
            grid.append(card);
        });

        if (countEl) countEl.textContent = (state.q || state.cat) ? ('筛选到 ' + state.total + ' 张图片') : ('共 ' + state.total + ' 张图片');
        if (summaryEl) summaryEl.textContent = state.images.length ? ('已显示 ' + state.images.length + ' / ' + state.total) : '';
        if (empty) empty.hidden = state.total !== 0;
        if (loadMore) loadMore.hidden = state.images.length >= state.total;
    }

    async function loadStats() {
        try {
            const data = await api('/api/stats.php');
            updateStats(data.stats);
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    async function loadImages(reset) {
        if (state.loading) return;
        state.loading = true;
        if (reset) {
            state.page = 1;
            state.images = [];
        }
        const grid = document.querySelector('[data-library]');
        if (grid && reset) grid.innerHTML = '<div class="library-loading"><span class="spinner"></span>读取图片中</div>';
        try {
            const params = new URLSearchParams({ limit: '24', page: String(state.page) });
            if (state.q) params.set('q', state.q);
            if (state.cat) params.set('cat', state.cat);
            const data = await api('/api/images.php?' + params.toString());
            state.total = data.total;
            if (reset) state.images = data.images;
            else state.images = state.images.concat(data.images);
            renderImages(data.images, !reset);
            updateStats(data.stats);
        } catch (err) {
            if (grid) grid.innerHTML = '';
            toast(err.message, 'error');
        } finally {
            state.loading = false;
        }
    }

    async function removeImage(id) {
        if (!window.confirm('确定删除这张图片？原图和缩略图都会从服务器移除。')) return;
        const form = new FormData();
        form.append('id', id);
        form.append('_csrf', csrf);
        try {
            const data = await api('/api/delete.php', { method: 'POST', body: form });
            state.images = state.images.filter((img) => Number(img.id) !== Number(id));
            state.total = Math.max(0, state.total - 1);
            const card = document.querySelector('.library-card[data-id="' + Number(id) + '"]');
            if (card) card.remove();
            renderImages(state.images, false);
            if (state.images.length === 0) {
                const countEl = document.querySelector('[data-library-count]');
                const summaryEl = document.querySelector('[data-library-summary]');
                const empty = document.querySelector('[data-library-empty]');
                if (countEl) countEl.textContent = '共 0 张图片';
                if (summaryEl) summaryEl.textContent = '';
                if (empty) empty.hidden = false;
            }
            updateStats(data.stats);
            toast('已删除', 'success');
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    document.querySelector('[data-library]') && document.querySelector('[data-library]').addEventListener('click', (event) => {
        const del = event.target.closest('[data-delete]');
        if (del) removeImage(Number(del.getAttribute('data-delete')));
    });

    let searchTimer = null;
    const search = document.querySelector('[data-search]');
    if (search) {
        search.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                state.q = search.value.trim();
                loadImages(true);
            }, 320);
        });
    }

    const categoryFilter = document.querySelector('[data-category-filter]');
    if (categoryFilter) {
        categoryFilter.addEventListener('change', () => {
            state.cat = categoryFilter.value;
            loadImages(true);
        });
    }

    const loadMore = document.querySelector('[data-load-more]');
    if (loadMore) {
        loadMore.addEventListener('click', () => {
            if (state.images.length >= state.total) return;
            state.page += 1;
            loadImages(false);
        });
    }

    const uploadRoot = document.querySelector('[data-uploader]');
    if (uploadRoot && window.FoldxUploader) {
        window.FoldxUploader.init(uploadRoot, {
            onComplete: () => {
                loadImages(true);
                setTimeout(loadStats, 500);
            },
        });
    }

    const sidebar = document.querySelector('[data-sidebar]');
    const scrim = document.querySelector('[data-sidebar-scrim]');
    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('is-open');
        if (scrim) scrim.hidden = true;
        document.body.classList.remove('sidebar-open');
    }
    function openSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('is-open');
        if (scrim) scrim.hidden = false;
        document.body.classList.add('sidebar-open');
    }
    document.querySelector('[data-sidebar-open]') && document.querySelector('[data-sidebar-open]').addEventListener('click', openSidebar);
    document.querySelector('[data-sidebar-close]') && document.querySelector('[data-sidebar-close]').addEventListener('click', closeSidebar);
    if (scrim) scrim.addEventListener('click', closeSidebar);
    document.querySelectorAll('[data-sidebar] a[href^="#"]').forEach((link) => {
        link.addEventListener('click', () => {
            document.querySelectorAll('[data-sidebar] a[data-nav-link]').forEach((a) => a.classList.remove('active'));
            link.classList.add('active');
            if (window.innerWidth < 960) closeSidebar();
        });
    });

    loadImages(true);
})();
