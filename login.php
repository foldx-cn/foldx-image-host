<?php
require_once __DIR__ . '/lib.php';

if (is_logged_in()) {
    header('Location: /admin/');
    exit;
}

$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '/admin/');
if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
    $next = '/admin/';
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = '页面已过期，请刷新后重试。';
    } else {
        $user = trim((string) ($_POST['user'] ?? ''));
        $pass = (string) ($_POST['pass'] ?? '');
        $expectedUser = (string) config('admin_user', 'admin');
        $hash = (string) config('admin_password_hash', '');
        if ($user === $expectedUser && $hash !== '' && password_verify($pass, $hash)) {
            login_user($user);
            header('Location: ' . $next);
            exit;
        }
        $error = '账号或密码不正确。';
    }
}
?>
<!doctype html>
<html lang="zh-CN" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#0b0a10">
    <script>try{if(localStorage.getItem('foldx-theme')==='light'){document.documentElement.dataset.theme='light'}}catch(e){}</script>
    <title>登录 · <?= h(app_name()) ?></title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="stylesheet" href="/assets/brand.css">
</head>
<body data-page="login">
<?= wallpaper_html() ?>
<div class="ambient" aria-hidden="true"><span class="grain"></span></div>

<main class="auth-wrap">
    <div class="auth-card glass">
        <div class="auth-brand">
            <span class="brand-mark"><?= icon('image', 21) ?></span>
            <span class="brand-copy"><b>Foldx</b><small>IMAGE HOST</small></span>
        </div>
        <p class="eyebrow"><span class="eyebrow-dot"></span>ADMIN</p>
        <h1>控制台登录</h1>
        <p class="auth-lead">上传不需要登录；这个控制台只负责整理、删除和查看统计。</p>

        <?php if ($error !== ''): ?>
            <div class="form-error" role="alert"><?= h($error) ?></div>
        <?php endif; ?>

        <form class="auth-form" method="post" action="/login.php">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="next" value="<?= h($next) ?>">
            <label>
                <span>管理员账号</span>
                <input type="text" name="user" value="<?= h((string) config('admin_user', 'admin')) ?>" autocomplete="username" required>
            </label>
            <label>
                <span>密码</span>
                <input type="password" name="pass" placeholder="输入控制台密码" autocomplete="current-password" required autofocus>
            </label>
            <button class="btn auth-submit" type="submit">进入控制台<?= icon('arrow', 16) ?></button>
        </form>

        <div class="auth-foot">
            <a href="/"><?= icon('arrow', 14) ?> 返回图床首页</a>
            <button class="icon-btn theme-btn" type="button" data-theme-toggle aria-label="切换明暗主题">
                <span class="theme-sun"><?= icon('sun', 18) ?></span>
                <span class="theme-moon"><?= icon('moon', 18) ?></span>
            </button>
        </div>
        <p class="auth-note">仅限站点所有者使用 · 请勿公开分享密码</p>
    </div>
</main>

<div class="toast" data-toast role="status" aria-live="polite"></div>
<script src="/assets/app.js"></script>
</body>
</html>
