<?php
declare(strict_types=1);

/**
 * Foldx 图床 · 公共函数
 * 保留 /i/ 原始直链，图片元数据存 SQLite。
 */

$configFile = __DIR__ . '/../imgfoldx-data/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('缺少图床配置：' . htmlspecialchars($configFile, ENT_QUOTES, 'UTF-8'));
}
$GLOBALS['FOLDX_CONFIG'] = require $configFile;
date_default_timezone_set('Asia/Shanghai');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('foldx_img_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function config(string $key, mixed $default = null): mixed
{
    $cfg = $GLOBALS['FOLDX_CONFIG'] ?? [];
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

function base_url(): string
{
    return rtrim((string) config('base_url', ''), '/');
}

function app_name(): string
{
    return (string) config('site_title', 'Foldx 图床');
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_bytes(int|float $bytes): string
{
    $b = max(0, (float) $bytes);
    if ($b < 1024) {
        return number_format($b, 0) . ' B';
    }
    if ($b < 1024 * 1024) {
        return number_format($b / 1024, 1) . ' KB';
    }
    if ($b < 1024 * 1024 * 1024) {
        return number_format($b / (1024 * 1024), 1) . ' MB';
    }
    return number_format($b / (1024 * 1024 * 1024), 2) . ' GB';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPath = (string) config('db_path');
    $dataDir = dirname($dbPath);
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0750, true);
    }
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    $pdo->exec("CREATE TABLE IF NOT EXISTS images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        path TEXT NOT NULL UNIQUE,
        thumb TEXT,
        name TEXT NOT NULL,
        mime TEXT,
        size INTEGER NOT NULL DEFAULT 0,
        width INTEGER NOT NULL DEFAULT 0,
        height INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        ip TEXT,
        category TEXT NOT NULL DEFAULT '未分类',
        tags TEXT NOT NULL DEFAULT '[]',
        ai_status TEXT NOT NULL DEFAULT 'approved',
        ai_reason TEXT
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_images_created ON images(created_at)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS meta (
        key TEXT PRIMARY KEY,
        value TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS upload_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        created_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'attempt'
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_upload_logs_ip ON upload_logs(ip, created_at)');

    // 兼容旧库：SQLite 不支持一次加多列，逐列检查。
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(images)')->fetchAll() as $col) {
        $columns[(string) $col['name']] = true;
    }
    $addColumns = [
        'category' => "ALTER TABLE images ADD COLUMN category TEXT NOT NULL DEFAULT '未分类'",
        'tags' => "ALTER TABLE images ADD COLUMN tags TEXT NOT NULL DEFAULT '[]'",
        'ai_status' => "ALTER TABLE images ADD COLUMN ai_status TEXT NOT NULL DEFAULT 'approved'",
        'ai_reason' => 'ALTER TABLE images ADD COLUMN ai_reason TEXT',
    ];
    foreach ($addColumns as $name => $sql) {
        if (!isset($columns[$name])) {
            $pdo->exec($sql);
        }
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_images_category ON images(category)');

    $seeded = $pdo->query("SELECT value FROM meta WHERE key = 'seeded'")->fetchColumn();
    if ($seeded !== '1') {
        seed_existing_images($pdo);
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO meta(key, value) VALUES('seeded', '1')");
        $stmt->execute();
    }

    return $pdo;
}

function seed_existing_images(PDO $pdo): void
{
    $storageDir = rtrim((string) config('storage_dir'), '/');
    if (!is_dir($storageDir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storageDir, FilesystemIterator::SKIP_DOTS)
    );
    $allowed = (array) config('allowed_mime', []);
    $allowedExt = [];
    foreach ($allowed as $ext) {
        $allowedExt[] = strtolower((string) $ext);
    }

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        $relative = ltrim(str_replace('\\', '/', substr($path, strlen($storageDir))), '/');
        if ($relative === '' || str_starts_with($relative, 'cache/')) {
            continue;
        }
        $ext = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            continue;
        }
        $mime = @mime_content_type($path) ?: '';
        $dim = @getimagesize($path);
        if (!$dim) {
            continue;
        }
        $createdAt = date('Y-m-d H:i:s', (int) @filemtime($path));
        $thumb = 'thumbs/' . date('Y/m/d', (int) @filemtime($path)) . '/' . pathinfo($relative, PATHINFO_FILENAME) . '.jpg';
        $thumbAbs = dirname($storageDir) . '/' . $thumb;
        make_thumb($path, $thumbAbs);

        $stmt = $pdo->prepare('INSERT OR IGNORE INTO images(path, thumb, name, mime, size, width, height, created_at, ip) VALUES(?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            'i/' . $relative,
            is_file($thumbAbs) ? $thumb : null,
            basename($relative),
            $mime,
            (int) @filesize($path),
            (int) $dim[0],
            (int) $dim[1],
            $createdAt,
            null,
        ]);
    }
}

function make_thumb(string $src, string $dst, int $max = 420): bool
{
    if (!is_file($src)) {
        return false;
    }
    $dir = dirname($dst);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $raw = @file_get_contents($src);
    if ($raw === false) {
        return false;
    }
    $image = @imagecreatefromstring($raw);
    if (!$image) {
        return false;
    }
    $w = imagesx($image);
    $h = imagesy($image);
    if ($w < 1 || $h < 1) {
        imagedestroy($image);
        return false;
    }
    $scale = min(1, $max / max($w, $h));
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));
    $thumb = imagecreatetruecolor($tw, $th);
    $white = imagecolorallocate($thumb, 255, 255, 255);
    imagefilledrectangle($thumb, 0, 0, $tw, $th, $white);
    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagedestroy($image);
    $ok = @imagejpeg($thumb, $dst, 84);
    imagedestroy($thumb);
    if ($ok) {
        @chmod($dst, 0644);
    }
    return (bool) $ok;
}

function image_url(array $row): string
{
    return base_url() . '/' . ltrim((string) $row['path'], '/');
}

function thumb_url(array $row): string
{
    $thumb = (string) ($row['thumb'] ?? '');
    if ($thumb !== '' && is_file(dirname((string) config('storage_dir')) . '/' . $thumb)) {
        return base_url() . '/' . ltrim($thumb, '/');
    }
    return image_url($row);
}

function image_payload(array $row): array
{
    $tags = [];
    if (!empty($row['tags'])) {
        $decoded = json_decode((string) $row['tags'], true);
        if (is_array($decoded)) {
            $tags = array_values(array_filter(array_map('strval', $decoded), static fn ($tag) => $tag !== ''));
        }
    }
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'path' => (string) $row['path'],
        'url' => image_url($row),
        'thumb' => thumb_url($row),
        'size' => (int) $row['size'],
        'sizeText' => format_bytes((int) $row['size']),
        'width' => (int) $row['width'],
        'height' => (int) $row['height'],
        'createdAt' => (string) $row['created_at'],
        'dateText' => date('Y-m-d', strtotime((string) $row['created_at']) ?: time()),
        'category' => (string) ($row['category'] ?? '未分类') ?: '未分类',
        'tags' => $tags,
        'aiStatus' => (string) ($row['ai_status'] ?? 'approved'),
        'aiReason' => (string) ($row['ai_reason'] ?? ''),
    ];
}

function category_counts(): array
{
    $out = [];
    $stmt = db()->query("SELECT category, COUNT(*) AS n FROM images GROUP BY category ORDER BY n DESC, category ASC");
    foreach ($stmt->fetchAll() as $row) {
        $name = (string) ($row['category'] ?? '未分类');
        $out[$name] = (int) $row['n'];
    }
    return $out;
}

function public_images(int $limit = 8, string $category = ''): array
{
    $limit = max(1, min(48, $limit));
    $category = trim($category);
    $sql = 'SELECT * FROM images';
    $params = [];
    if ($category !== '') {
        $sql .= ' WHERE category = :category';
        $params[':category'] = $category;
    }
    $sql .= ' ORDER BY datetime(created_at) DESC, id DESC LIMIT :limit';
    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = image_payload($row);
    }
    return $out;
}

function stats(): array
{
    $pdo = db();
    $total = (int) $pdo->query('SELECT COUNT(*) FROM images')->fetchColumn();
    $bytes = (int) $pdo->query('SELECT COALESCE(SUM(size), 0) FROM images')->fetchColumn();
    $today = date('Y-m-d');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM images WHERE substr(created_at, 1, 10) = :day');
    $stmt->execute([':day' => $today]);
    $todayCount = (int) $stmt->fetchColumn();
    return [
        'count' => $total,
        'bytes' => $bytes,
        'bytesText' => format_bytes($bytes),
        'today' => $todayCount,
        'maxFileText' => format_bytes((int) config('max_file_size', 0)),
    ];
}

function is_logged_in(): bool
{
    return !empty($_SESSION['foldx_user']);
}

/**
 * 随机二次元壁纸。素材来自主站同一批壁纸，每次打开页面换一张。
 */
function random_wallpaper(): string
{
    $files = glob(__DIR__ . '/assets/bg/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];
    if (!$files) {
        return '';
    }
    $file = $files[array_rand($files)];
    return '/assets/bg/' . basename($file);
}

function wallpaper_html(): string
{
    $url = random_wallpaper();
    if ($url === '') {
        return '';
    }
    return '<div class="wallpaper" style="background-image:url(\'' . h($url) . '\')" aria-hidden="true"></div>'
        . '<div class="wallpaper-shade" aria-hidden="true"></div>';
}

/**
 * 调用本机视觉审核服务。服务不可用时按不通过处理，避免公开上传绕过审核。
 */
function ai_moderate_image(string $path): array
{
    if (!filter_var(config('ai_moderation_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
        return ['safe' => true, 'category' => '未分类', 'tags' => [], 'reason' => 'AI 审核已关闭', 'engine' => 'disabled'];
    }
    $url = (string) config('ai_moderation_url', 'http://127.0.0.1:8791/moderate');
    $timeout = max(3, min(30, (int) config('ai_moderation_timeout', 15)));
    $payload = json_encode(['path' => $path], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $status !== 200) {
        return [
            'safe' => false,
            'category' => '审核失败',
            'tags' => [],
            'reason' => $error !== '' ? '审核服务不可用' : '审核服务返回异常',
            'engine' => 'local-error',
        ];
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data) || !isset($data['result']) || !is_array($data['result'])) {
        return ['safe' => false, 'category' => '审核失败', 'tags' => [], 'reason' => '审核结果格式异常', 'engine' => 'local-error'];
    }
    $result = $data['result'];
    $category = trim((string) ($result['category'] ?? '未分类'));
    if ($category === '') {
        $category = '未分类';
    }
    $category = mb_substr($category, 0, 20, 'UTF-8');
    $tags = [];
    if (isset($result['tags']) && is_array($result['tags'])) {
        foreach (array_slice($result['tags'], 0, 8) as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $tags[] = mb_substr($tag, 0, 20, 'UTF-8');
            }
        }
    }
    return [
        'safe' => !empty($result['safe']),
        'category' => $category,
        'tags' => array_values(array_unique($tags)),
        'reason' => mb_substr(trim((string) ($result['reason'] ?? '')), 0, 180, 'UTF-8'),
        'engine' => (string) ($result['engine'] ?? 'local'),
    ];
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

/**
 * 匿名上传的简单频率限制。管理员登录后不受影响。
 */
function public_upload_rate_limit(): array
{
    if (is_logged_in()) {
        return ['allowed' => true, 'remaining' => null, 'message' => ''];
    }
    $ip = client_ip();
    $hourLimit = max(1, (int) config('public_hour_limit', 15));
    $dayLimit = max($hourLimit, (int) config('public_day_limit', 50));
    $pdo = db();
    $now = time();
    $pdo->prepare('DELETE FROM upload_logs WHERE datetime(created_at) < datetime(?, ?)')
        ->execute([date('Y-m-d H:i:s', $now), '-3 days']);
    $sinceHour = date('Y-m-d H:i:s', $now - 3600);
    $sinceDay = date('Y-m-d H:i:s', $now - 86400);
    $hourStmt = $pdo->prepare('SELECT COUNT(*) FROM upload_logs WHERE ip = :ip AND datetime(created_at) >= datetime(:since)');
    $hourStmt->execute([':ip' => $ip, ':since' => $sinceHour]);
    $hourCount = (int) $hourStmt->fetchColumn();
    $dayStmt = $pdo->prepare('SELECT COUNT(*) FROM upload_logs WHERE ip = :ip AND datetime(created_at) >= datetime(:since)');
    $dayStmt->execute([':ip' => $ip, ':since' => $sinceDay]);
    $dayCount = (int) $dayStmt->fetchColumn();
    if ($hourCount >= $hourLimit || $dayCount >= $dayLimit) {
        return [
            'allowed' => false,
            'remaining' => 0,
            'message' => '公开上传太频繁了，请稍后再试。登录管理员不受此限制。',
        ];
    }
    $pdo->prepare('INSERT INTO upload_logs(ip, created_at, status) VALUES(?, ?, ?)')
        ->execute([$ip, date('Y-m-d H:i:s'), 'attempt']);
    return [
        'allowed' => true,
        'remaining' => max(0, $hourLimit - $hourCount - 1),
        'message' => '',
    ];
}

function login_user(string $user): void
{
    session_regenerate_id(true);
    $_SESSION['foldx_user'] = $user;
    $_SESSION['foldx_login_at'] = time();
}

function require_login(): void
{
    if (is_logged_in()) {
        return;
    }
    $next = $_SERVER['REQUEST_URI'] ?? '/admin/';
    header('Location: /login.php?next=' . rawurlencode($next));
    exit;
}

function require_api_login(): void
{
    if (is_logged_in()) {
        return;
    }
    json_response(['ok' => false, 'error' => '请先登录'], 401);
}

function csrf_token(): string
{
    if (empty($_SESSION['foldx_csrf'])) {
        $_SESSION['foldx_csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['foldx_csrf'];
}

function csrf_check(?string $token = null): bool
{
    $expected = (string) ($_SESSION['foldx_csrf'] ?? '');
    $given = $token ?? ($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return $expected !== '' && $given !== '' && hash_equals($expected, (string) $given);
}

function icon(string $name, int $size = 20): string
{
    $paths = [
        'cloud' => '<path d="M17.5 19H7a5 5 0 0 1-.7-9.95A7 7 0 0 1 20 11.5 3.75 3.75 0 0 1 19 19h-1.5Z"/>',
        'upload' => '<path d="M12 16V4m0 0 5 5m-5-5-5 5"/><path d="M5 20h14"/>',
        'shield' => '<path d="M12 3 5 6v5c0 4.5 3 7.5 7 10 4-2.5 7-5.5 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
        'grid' => '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>',
        'search' => '<circle cx="11" cy="11" r="6"/><path d="m16 16 5 5"/>',
        'trash' => '<path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="m6 7 1 13h10l1-13"/><path d="M10 11v5M14 11v5"/>',
        'link' => '<path d="M10 13a4 4 0 0 0 5.7 0l2.6-2.6a4 4 0 0 0-5.7-5.7L11 6.3"/><path d="M14 11a4 4 0 0 0-5.7 0l-2.6 2.6a4 4 0 0 0 5.7 5.7L13 17.7"/>',
        'sun' => '<circle cx="12" cy="12" r="3.5"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"/>',
        'arrow' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m4 17 5-5 4 4 3-3 4 4"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M20 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4"/>',
    ];
    $path = $paths[$name] ?? $paths['image'];
    return '<svg class="ui-icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function delete_image_row(int $id): bool
{
    $stmt = db()->prepare('SELECT * FROM images WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    $storageDir = rtrim((string) config('storage_dir'), '/');
    $appRoot = dirname($storageDir);
    $path = (string) $row['path'];
    if (str_starts_with($path, 'i/')) {
        $absolute = $appRoot . '/' . $path;
        $real = realpath($absolute);
        if ($real !== false && str_starts_with($real, $storageDir)) {
            @unlink($real);
        }
    }
    $thumb = (string) ($row['thumb'] ?? '');
    if ($thumb !== '') {
        $thumbAbs = $appRoot . '/' . $thumb;
        $thumbReal = realpath($thumbAbs);
        if ($thumbReal !== false && str_starts_with($thumbReal, $appRoot . '/thumbs')) {
            @unlink($thumbReal);
        }
    }
    $stmt = db()->prepare('DELETE FROM images WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return true;
}
