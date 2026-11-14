<?php
require_once __DIR__ . '/lib.php';

$logged = is_logged_in();
$stats = stats();
$category = trim((string) ($_GET['c'] ?? ''));
$category = mb_substr($category, 0, 20, 'UTF-8');
$recent = public_images(12, $category);
$categories = category_counts();
$pageTitle = app_name();
?>
<!doctype html>
<html lang="zh-CN" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#081a31">
    <script>try{if(localStorage.getItem('foldx-theme')==='light'){document.documentElement.dataset.theme='light'}}catch(e){}</script>
    <title><?= h($pageTitle) ?> · 稳定图片直链</title>
    <meta name="description" content="Foldx 图床：为博客与项目提供稳定图片直链，支持拖拽、粘贴和批量上传。">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="stylesheet" href="/assets/brand.css">
</head>
<body data-page="home" data-logged-in="<?= $logged ? '1' : '0' ?>">
<?= wallpaper_html() ?>
<div class="ambient" aria-hidden="true">
    <span class="grain"></span>
</div>

<header class="site-nav">
    <a class="brand" href="/" aria-label="Foldx 图床首页">
        <span class="brand-mark"><?= icon('image', 20) ?></span>
        <span class="brand-copy"><b>Foldx</b><small>IMAGE HOST</small></span>
    </a>
    <nav class="nav-links" aria-label="主导航">
        <a class="active" href="/">图床</a>
        <a href="#recent">图库</a>
        <a href="<?= $logged ? '/admin/' : '/login.php?next=/admin/' ?>">控制台</a>
        <a href="https://blog.foldx.cn/" target="_blank" rel="noopener">博客<?= icon('external', 13) ?></a>
    </nav>
    <div class="nav-actions">
        <button class="icon-btn theme-btn" type="button" data-theme-toggle aria-label="切换明暗主题">
            <span class="theme-sun"><?= icon('sun', 18) ?></span>
            <span class="theme-moon"><?= icon('moon', 18) ?></span>
        </button>
        <?php if ($logged): ?>
            <a class="btn btn-small" href="/admin/">进入控制台<?= icon('arrow', 15) ?></a>
        <?php else: ?>
            <a class="btn btn-small" href="/login.php?next=/admin/">管理登录<?= icon('arrow', 15) ?></a>
        <?php endif; ?>
    </div>
</header>

<main>
    <section class="hero">
        <div class="hero-copy">
            <p class="eyebrow"><span class="eyebrow-dot"></span>OPEN IMAGE HOST</p>
            <h1>图，放在<br><span class="grad">自己的地方。</span></h1>
            <p class="lead">公开上传，不用登录。AI 先检查一遍，再按内容自动归类，最后给你一条干净的直链。</p>
            <div class="hero-notes">
                <span><?= icon('upload', 16) ?> 谁都能上传</span>
                <span><?= icon('shield', 16) ?> AI 审核</span>
                <span><?= icon('grid', 16) ?> 自动分类</span>
            </div>
            <div class="hero-actions">
                <a class="btn" href="#upload">上传图片<?= icon('arrow', 16) ?></a>
                <a class="btn btn-ghost" href="#recent">浏览图库</a>
            </div>
        </div>

        <div class="hero-panel glass" id="upload">
            <div class="panel-head">
                <div>
                    <span class="panel-kicker">UPLOAD</span>
                    <h2>放进一张图</h2>
                </div>
                <span class="panel-badge">公开上传 · AI 审核</span>
            </div>

            <div class="uploader" data-uploader data-mode="public">
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
            <p class="upload-policy">上传即表示同意公开托管。不合规图片会被 AI 拦下，分类由模型自动完成。</p>

            <div class="hero-stats">
                <div><b><?= h((string) $stats['count']) ?></b><span>张图片</span></div>
                <div><b><?= h($stats['bytesText']) ?></b><span>已托管</span></div>
                <div><b><?= h((string) $stats['today']) ?></b><span>今日新增</span></div>
            </div>
        </div>
    </section>

    <section class="section" id="recent">
        <div class="section-head">
            <div>
                <p class="eyebrow"><span class="eyebrow-dot"></span>GALLERY</p>
                <h2><?= $category !== '' ? h($category) : '图库' ?></h2>
            </div>
            <?php if ($logged): ?><a class="text-link" href="/admin/#library">管理全部图片<?= icon('arrow', 15) ?></a><?php endif; ?>
        </div>

        <?php if ($categories): ?>
            <nav class="category-tabs" aria-label="图片分类">
                <a class="category-tab<?= $category === '' ? ' active' : '' ?>" href="/#recent">全部 <span><?= h((string) array_sum($categories)) ?></span></a>
                <?php foreach ($categories as $name => $count): ?>
                    <a class="category-tab<?= $category === $name ? ' active' : '' ?>" href="/?c=<?= rawurlencode($name) ?>#recent"><?= h($name) ?> <span><?= h((string) $count) ?></span></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <?php if ($recent): ?>
            <div class="recent-grid">
                <?php foreach ($recent as $img): ?>
                    <article class="image-card glass">
                        <a class="image-thumb" href="<?= h($img['url']) ?>" target="_blank" rel="noopener" aria-label="打开原图">
                            <img src="<?= h($img['thumb']) ?>" alt="<?= h($img['name']) ?>" loading="lazy">
                            <span class="image-category"><?= h($img['category']) ?></span>
                            <span class="image-shine" aria-hidden="true"></span>
                        </a>
                        <div class="image-meta">
                            <div class="image-name" title="<?= h($img['name']) ?>"><?= h($img['name']) ?></div>
                            <div class="image-sub"><?= h($img['dateText']) ?> · <?= h($img['sizeText']) ?></div>
                        </div>
                        <div class="image-actions">
                            <button class="mini-btn" type="button" data-copy="<?= h($img['url']) ?>" aria-label="复制直链"><?= icon('copy', 15) ?>复制</button>
                            <a class="mini-btn" href="<?= h($img['url']) ?>" target="_blank" rel="noopener" aria-label="在新标签打开"><?= icon('external', 15) ?>打开</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty glass">
                <span class="empty-icon"><?= icon('image', 34) ?></span>
                <h3><?= $category !== '' ? '这个分类还没有图' : '还没有图片' ?></h3>
                <p>上传第一张，它会被 AI 审核并自动放在合适的分类里。</p>
            </div>
        <?php endif; ?>
    </section>

    <section class="section feature-section">
        <div class="section-head">
            <div>
                <p class="eyebrow"><span class="eyebrow-dot"></span>HOW IT WORKS</p>
                <h2>上传、看一眼、归好类</h2>
            </div>
        </div>
        <div class="feature-grid">
            <article class="feature-card glass">
                <span class="feature-icon"><?= icon('upload', 22) ?></span>
                <h3>谁都能上传</h3>
                <p>不用账号，不用等管理员。拖进来或粘贴一张图，几秒后就能拿到直链。</p>
            </article>
            <article class="feature-card glass">
                <span class="feature-icon"><?= icon('shield', 22) ?></span>
                <h3>AI 先审一遍</h3>
                <p>本地视觉模型会检查裸露与不当内容；不合规的图片不落盘，也不占用图库空间。</p>
            </article>
            <article class="feature-card glass">
                <span class="feature-icon"><?= icon('grid', 22) ?></span>
                <h3>分类自己长出来</h3>
                <p>二次元、截图、人物、风景、表情包……上传后自动打上分类和标签，图库按类浏览。</p>
            </article>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-brand">
            <span class="brand-mark small"><?= icon('image', 17) ?></span>
            <span>Foldx 图床</span>
        </div>
        <p>为博客与项目提供稳定图片直链 · <a href="https://blog.foldx.cn/" target="_blank" rel="noopener">Sienna Fuca</a></p>
        <p class="footer-meta">© <?= date('Y') ?> Foldx · <?= h(base_url()) ?></p>
    </div>
</footer>

<div class="toast" data-toast role="status" aria-live="polite"></div>
<script src="/assets/uploader.js"></script>
<script src="/assets/app.js"></script>
</body>
</html>
