/* Foldx 图床 · 通用上传器 */
(function () {
    'use strict';

    function formatBytes(bytes) {
        const n = Number(bytes) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        if (n < 1024 * 1024 * 1024) return (n / (1024 * 1024)).toFixed(1) + ' MB';
        return (n / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    }

    function init(root, options) {
        const opts = options || {};
        const dropzone = root.querySelector('[data-dropzone]');
        const input = root.querySelector('[data-file-input]');
        const queueEl = root.querySelector('[data-queue]');
        const statusEl = root.querySelector('[data-status]');
        const button = root.querySelector('[data-upload-btn]');
        const progressEl = root.querySelector('[data-progress]');
        if (!dropzone || !input || !queueEl || !statusEl || !button) return;

        let files = [];
        let uploading = false;

        function setStatus(text, tone) {
            statusEl.textContent = text;
            statusEl.dataset.tone = tone || '';
        }

        function setProgress(value) {
            if (!progressEl) return;
            progressEl.hidden = false;
            const bar = progressEl.querySelector('span');
            if (bar) bar.style.width = Math.max(0, Math.min(100, value)) + '%';
            if (value >= 100) {
                setTimeout(() => { progressEl.hidden = true; }, 700);
            }
        }

        function refresh() {
            button.disabled = uploading || files.length === 0;
            queueEl.hidden = files.length === 0;
            queueEl.replaceChildren();
            files.forEach((file, index) => {
                const item = document.createElement('div');
                item.className = 'queue-item';
                const thumb = document.createElement('img');
                thumb.alt = '';
                thumb.src = URL.createObjectURL(file);
                thumb.onload = () => URL.revokeObjectURL(thumb.src);
                const meta = document.createElement('div');
                meta.className = 'queue-meta';
                const name = document.createElement('b');
                name.textContent = file.name;
                const size = document.createElement('span');
                size.textContent = formatBytes(file.size);
                meta.append(name, size);
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'queue-remove';
                remove.setAttribute('aria-label', '移除 ' + file.name);
                remove.textContent = '×';
                remove.addEventListener('click', () => {
                    files.splice(index, 1);
                    refresh();
                    if (!files.length) setStatus('等待选择图片');
                });
                item.append(thumb, meta, remove);
                queueEl.append(item);
            });
            if (!files.length) setStatus(uploading ? '正在上传…' : '等待选择图片');
            else if (!uploading) setStatus(files.length + ' 张图片待上传');
        }

        function addFiles(list) {
            const accepted = [];
            Array.from(list || []).forEach((file) => {
                if (!file || !file.type || !file.type.startsWith('image/')) return;
                if (file.size > 20 * 1024 * 1024) {
                    if (window.Foldx && window.Foldx.toast) window.Foldx.toast('单张图片过大：' + file.name, 'error');
                    return;
                }
                const duplicate = files.some((f) => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified);
                if (!duplicate) accepted.push(file);
            });
            files = files.concat(accepted).slice(0, 20);
            refresh();
        }

        function uploadOne(file) {
            return new Promise((resolve) => {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', '/api/upload.php');
                xhr.setRequestHeader('X-CSRF-Token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
                xhr.responseType = 'json';
                xhr.upload.onprogress = (event) => {
                    if (event.lengthComputable && opts.onFileProgress) {
                        opts.onFileProgress(file, event.loaded, event.total);
                    }
                };
                xhr.onload = () => {
                    let data = xhr.response;
                    if (!data || typeof data !== 'object') {
                        try { data = JSON.parse(xhr.responseText || '{}'); } catch (e) { data = {}; }
                    }
                    if (xhr.status >= 200 && xhr.status < 300 && data.ok) {
                        resolve({ ok: true, file: data.files && data.files[0], data: data });
                    } else {
                        resolve({ ok: false, file: file, error: (data && data.error) || '上传失败，请重试' });
                    }
                };
                xhr.onerror = () => resolve({ ok: false, file: file, error: '网络连接失败' });
                const form = new FormData();
                form.append('files[]', file, file.name);
                xhr.send(form);
            });
        }

        async function startUpload() {
            if (uploading || !files.length) return;
            uploading = true;
            button.disabled = true;
            dropzone.classList.add('is-disabled');
            const total = files.length;
            let done = 0;
            let success = [];
            let failures = [];
            setStatus('正在上传 0 / ' + total);
            setProgress(1);
            for (const file of files) {
                const result = await uploadOne(file);
                done += 1;
                if (result.ok) success.push(result.file);
                else failures.push({ name: file.name, error: result.error });
                setStatus('正在上传 ' + done + ' / ' + total);
                setProgress(Math.round((done / total) * 100));
            }
            uploading = false;
            dropzone.classList.remove('is-disabled');
            if (success.length) {
                setStatus('已上传 ' + success.length + ' 张，AI 已分类' + (failures.length ? '；' + failures.length + ' 张未通过' : ''), 'success');
                if (window.Foldx && window.Foldx.toast) {
                    const last = success[success.length - 1] || {};
                    window.Foldx.toast('上传完成：' + success.length + ' 张 · ' + (last.category || '已分类'), 'success');
                }
                if (opts.onComplete) opts.onComplete(success, failures);
            } else {
                setStatus('上传失败，请重试', 'error');
                if (window.Foldx && window.Foldx.toast) {
                    window.Foldx.toast(failures[0] ? failures[0].error : '上传失败', 'error');
                }
            }
            files = [];
            input.value = '';
            setTimeout(() => {
                refresh();
                setProgress(100);
            }, 300);
        }

        dropzone.addEventListener('click', () => {
            if (!uploading) input.click();
        });
        dropzone.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                if (!uploading) input.click();
            }
        });
        input.addEventListener('change', () => {
            addFiles(input.files);
            input.value = '';
        });

        ['dragenter', 'dragover'].forEach((type) => {
            dropzone.addEventListener(type, (event) => {
                event.preventDefault();
                if (!uploading) dropzone.classList.add('is-over');
            });
        });
        ['dragleave', 'drop'].forEach((type) => {
            dropzone.addEventListener(type, (event) => {
                event.preventDefault();
                dropzone.classList.remove('is-over');
            });
        });
        dropzone.addEventListener('drop', (event) => {
            if (!uploading) addFiles(event.dataTransfer && event.dataTransfer.files);
        });

        document.addEventListener('paste', (event) => {
            if (uploading || !dropzone.offsetParent) return;
            const items = event.clipboardData && event.clipboardData.items;
            if (!items) return;
            const imageFiles = [];
            for (const item of items) {
                if (item.kind === 'file' && item.type.startsWith('image/')) {
                    const file = item.getAsFile();
                    if (file) imageFiles.push(file);
                }
            }
            if (imageFiles.length) {
                event.preventDefault();
                addFiles(imageFiles);
            }
        });

        button.addEventListener('click', startUpload);
        refresh();
    }

    window.FoldxUploader = { init: init };
})();
