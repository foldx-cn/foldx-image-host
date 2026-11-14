<?php
require_once __DIR__ . '/../lib.php';
require_login();

$stats = stats();
$user = (string) ($_SESSION['foldx_user'] ?? 'admin');
?>
<!doctype html>
<html lang="zh-CN" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#0b0a10">
    <script>try{if(localStorage.getItem('foldx-theme')==='light'){document.documentElement.dataset.theme='light'}}catch(e){}</script>
    <title>控制台 · <?= h(app_name()) ?></title>
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="stylesheet" href="/assets/brand.css">
</head>
<body data-page="admin" data-logged-in="1">
<?= wallpaper_html() ?>
<div class="ambient" aria-hidden="true"><span class="grain"></span></div>

<aside class="admin-sidebar" data-sidebar>
    <div class="sidebar-top">
        <a class="brand" href="/">
            <span class="brand-mark"><?= icon('image', 20) ?></span>
            <span class="brand-copy"><b>Foldx</b><small>IMAGE HOST</small></span>
        </a>
        <button class="icon-btn sidebar-close" type="button" data-sidebar-close aria-label="关闭菜单">×</button>
    </div>

    <nav class="sidebar-nav" aria-label="控制台导航">
        <a class="active" href="#overview" data-nav-link><?= icon('grid', 18) ?><span>概览</span></a>
        <a href="#upload" data-nav-link><?= icon('upload', 18) ?><span>上传</span></a>
        <a href="#library" data-nav-link><?= icon('image', 18) ?><span>图片库</span></a>
        <a href="#settings" data-nav-link><?= icon('shield', 18) ?><span>站点信息</span></a>
    </nav>

    <div class="sidebar-storage">
        <div class="storage-head"><span>存储用量</span><b data-sidebar-bytes><?= h($stats['bytesText']) ?></b></div>
        <div class="storage-bar"><span style="width:<?= min(100, max(1, ((float) $stats['bytes'] / max(1, (float) ($stats['bytes'] + 60 * 1024 * 1024 * 1024))) * 100)) ?>%"></span></div>
        <p><?= h((string) $stats['count']) ?> 张图片 · SQLite + 本地文件</p>
    </div>

    <div class="sidebar-foot">
        <div class="user-chip">
            <span class="avatar"><?= h(mb_strtoupper(mb_substr($user, 0, 1))) ?></span>
            <span><b><?= h($user) ?></b><small>管理员</small></span>
        </div>
        <form method="post" action="/logout.php">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <button class="sidebar-logout" type="submit">退出登录</button>
        </form>
    </div>
</aside>

<div class="admin-main">
    <header class="admin-topbar">
        <button class="icon-btn menu-btn" type="button" data-sidebar-open aria-label="打开菜单">☰</button>
        <div class="crumb"><span>Foldx</span><span class="sep">/</span><b>控制台</b></div>
        <div class="topbar-actions">
            <a class="topbar-link" href="/" target="_blank" rel="noopener">查看站点<?= icon('external', 14) ?></a>
            <button class="icon-btn theme-btn" type="button" data-theme-toggle aria-label="切换明暗主题">
                <span class="theme-sun"><?= icon('sun', 18) ?></span>
                <span class="theme-moon"><?= icon('moon', 18) ?></span>
            </button>
        </div>
    </header>

    <main class="dashboard">
        <section class="dash-hero" id="overview">
            <div>
                <p class="eyebrow"><span class="eyebrow-dot"></span>OVERVIEW</p>
                <h1>图片控制台</h1>
                <p class="dash-lead">公开上传已经交给 AI 审核和自动分类。这里留着查看、搜索、复制直链和必要时的删除。</p>
            </div>
            <div class="dash-hero-actions">
                <button class="btn" type="button" data-scroll-upload><?= icon('upload', 16) ?>上传图片</button>
                <a class="btn btn-ghost" href="#library">查看图片库</a>
            </div>
        </section>

        <section class="stat-grid" aria-label="统计">
            <article class="stat-card glass">
                <span class="stat-icon blue"><?= icon('image', 21) ?></span>
                <div><b data-stat="count"><?= h((string) $stats['count']) ?></b><span>图片总数</span></div>
            </article>
            <article class="stat-card glass">
                <span class="stat-icon cyan"><?= icon('cloud', 21) ?></span>
                <div><b data-stat="bytes"><?= h($stats['bytesText']) ?></b><span>占用空间</span></div>
            </article>
            <article class="stat-card glass">
                <span class="stat-icon pink"><?= icon('upload', 21) ?></span>
                <div><b data-stat="today"><?= h((string) $stats['today']) ?></b><span>今日上传</span></div>
            </article>
            <article class="stat-card glass">
                <span class="stat-icon violet"><?= icon('shield', 21) ?></span>
                <div><b><?= h($stats['maxFileText']) ?></b><span>单张上限</span></div>
            </article>
        </section>

        <section class="admin-card glass" id="upload">
            <div class="admin-card-head">
                <div>
                    <p class="eyebrow"><span class="eyebrow-dot"></span>UPLOAD</p>
                    <h2>上传新图片</h2>
                    <p>管理员上传同样会经过 AI 审核；审核通过后按内容自动归类。</p>
                </div>
                <span class="panel-badge"><?= h($stats['maxFileText']) ?> / 张</span>
            </div>

            <div class="uploader" data-uploader data-mode="admin">
                <div class="dropzone" data-dropzone tabindex="0" role="button" aria-label="选择或拖入图片">
                    <div class="drop-icon"><?= icon('cloud', 30) ?></div>
                    <p class="drop-title">拖到这里，或点击选择</p>
                    <p class="drop-sub">也可以直接粘贴剪贴板里的图片</p>
                    <span class="drop-hint">JPG / PNG / WebP / GIF · 单张不超过 <?= h($stats['maxFileText']) ?></span>
                    <input type="file" data-file-input accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden>
                </div>
                <div class="upload-queue" data-queue hidden></div>
                <div class="upload-foot">
                    <span class="upload-status" data-status>等待选择图片</span>
                    <button class="btn btn-small" type="button" data-upload-btn disabled>上传<?= icon('upload', 15) ?></button>
                </div>
                <div class="upload-progress" data-progress hidden><span></span></div>
            </div>
        </section>

        <section class="admin-card glass" id="library">
            <div class="admin-card-head library-head">
                <div>
                    <p class="eyebrow"><span class="eyebrow-dot"></span>LIBRARY</p>
                    <h2>图片库</h2>
                    <p data-library-count>正在读取图片…</p>
                </div>
                <div class="library-tools">
                    <select data-category-filter aria-label="按分类筛选">
                        <option value="">全部分类</option>
                        <?php foreach (category_counts() as $cat => $count): ?>
                            <option value="<?= h($cat) ?>"><?= h($cat) ?>（<?= h((string) $count) ?>）</option>
                        <?php endforeach; ?>
                    </select>
                    <label class="search-box">
                        <?= icon('search', 17) ?>
                        <input type="search" data-search placeholder="搜索文件名、分类或标签" autocomplete="off">
                    </label>
                </div>
            </div>

            <div class="library-grid" data-library></div>
            <div class="library-empty" data-library-empty hidden>
                <span class="empty-icon"><?= icon('image', 32) ?></span>
                <h3>没有找到图片</h3>
                <p>换个关键词，或者上传一张新的。</p>
            </div>
            <div class="library-foot">
                <span data-library-summary></span>
                <button class="btn btn-ghost btn-small" type="button" data-load-more hidden>加载更多</button>
            </div>
        </section>

        <section class="admin-card glass" id="settings">
            <div class="admin-card-head">
                <div>
                    <p class="eyebrow"><span class="eyebrow-dot"></span>SETTINGS</p>
                    <h2>站点信息</h2>
                    <p>只读展示服务端配置。账号和密码在图床配置文件中维护。</p>
                </div>
            </div>
            <div class="settings-list">
                <div class="setting-row">
                    <div><b>直链前缀</b><span>图片公开访问地址</span></div>
                    <div class="setting-value"><code data-copy-value><?= h(base_url()) ?></code><button class="mini-btn" type="button" data-copy="<?= h(base_url()) ?>"><?= icon('copy', 14) ?>复制</button></div>
                </div>
                <div class="setting-row">
                    <div><b>图片目录</b><span>原始文件保存位置，保留 /i/ 路径</span></div>
                    <div class="setting-value"><code><?= h(realpath((string) config('storage_dir')) ?: (string) config('storage_dir')) ?></code></div>
                </div>
                <div class="setting-row">
                    <div><b>上传限制</b><span>单张大小 / 单次数量</span></div>
                    <div class="setting-value"><code><?= h($stats['maxFileText']) ?> / <?= h((string) config('max_upload_count', 20)) ?> 张</code></div>
                </div>
                <div class="setting-row">
                    <div><b>AI 审核</b><span>本地视觉模型：CLIP + NudeNet</span></div>
                    <div class="setting-value"><span class="status-pill"><i></i><?= config('ai_moderation_enabled', true) ? '已开启 · 自动分类' : '已关闭' ?></span></div>
                </div>
                <div class="setting-row">
                    <div><b>访问控制</b><span>公开上传 + AI 审核；删除需管理员登录</span></div>
                    <div class="setting-value"><span class="status-pill"><i></i>管理员验证已开启</span></div>
                </div>
                <div class="setting-row">
                    <div><b>元数据</b><span>SQLite 单文件数据库</span></div>
                    <div class="setting-value"><code><?= h(basename((string) config('db_path'))) ?></code></div>
                </div>
            </div>
        </section>

        <footer class="dash-footer">
            <span>Foldx 图床 · 后台</span>
            <a href="https://blog.foldx.cn/" target="_blank" rel="noopener">博客</a>
            <a href="https://www.foldx.cn/" target="_blank" rel="noopener">主站</a>
        </footer>
    </main>
</div>

<div class="sidebar-scrim" data-sidebar-scrim hidden></div>
<div class="toast" data-toast role="status" aria-live="polite"></div>
<script src="/assets/uploader.js"></script>
<script src="/assets/app.js"></script>
<script src="/assets/admin.js"></script>
</body>
</html>
