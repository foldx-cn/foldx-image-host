<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => '只接受 POST'], 405);
}
if (!csrf_check()) {
    json_response(['ok' => false, 'error' => '安全校验失败，请刷新页面后重试'], 419);
}

$isAdmin = is_logged_in();
$rate = public_upload_rate_limit();
if (!$rate['allowed']) {
    json_response(['ok' => false, 'error' => $rate['message']], 429);
}

$mimeMap = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
$maxSize = (int) config('max_file_size', 20 * 1024 * 1024);
$maxFiles = min(20, max(1, (int) config('max_upload_count', 20)));
if (!$isAdmin) {
    $maxFiles = min($maxFiles, max(1, (int) config('public_max_files', 10)));
    $maxSize = min($maxSize, max(1, (int) config('public_max_file_size', $maxSize)));
}
$storageDir = rtrim((string) config('storage_dir'), '/');
$appRoot = dirname($storageDir);

$files = $_FILES['files'] ?? null;
if (!is_array($files) || !isset($files['name'])) {
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = (int) ini_get('post_max_size') * 1024 * 1024;
    $hint = $contentLength > $postMax ? '上传内容超过服务器限制，请减少文件数量或单张大小' : '没有收到文件';
    json_response(['ok' => false, 'error' => $hint], 400);
}

$names = (array) $files['name'];
$tmpNames = (array) $files['tmp_name'];
$errors = (array) ($files['error'] ?? []);
$sizes = (array) ($files['size'] ?? []);
$count = count($names);
if ($count > $maxFiles) {
    json_response(['ok' => false, 'error' => '一次最多上传 ' . $maxFiles . ' 张图片'], 400);
}

$uploaded = [];
$failures = [];
$finfo = new finfo(FILEINFO_MIME_TYPE);

for ($i = 0; $i < $count; $i++) {
    $original = (string) ($names[$i] ?? '');
    $tmp = (string) ($tmpNames[$i] ?? '');
    $error = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);
    $size = (int) ($sizes[$i] ?? 0);

    if ($error === UPLOAD_ERR_NO_FILE) {
        continue;
    }
    if ($error !== UPLOAD_ERR_OK || !$tmp || !is_uploaded_file($tmp)) {
        $failures[] = ['name' => $original, 'error' => '上传没有完成'];
        continue;
    }
    if ($size <= 0 || $size > $maxSize) {
        $failures[] = ['name' => $original, 'error' => '单张图片不能超过 ' . format_bytes($maxSize)];
        continue;
    }
    $mime = (string) $finfo->file($tmp);
    if (!isset($mimeMap[$mime])) {
        $failures[] = ['name' => $original, 'error' => '只支持 JPG、PNG、WebP、GIF'];
        continue;
    }
    $dim = @getimagesize($tmp);
    if (!$dim || (int) $dim[0] < 1 || (int) $dim[1] < 1) {
        $failures[] = ['name' => $original, 'error' => '图片内容无法识别'];
        continue;
    }

    // AI 审核与自动分类。审核不通过或服务异常时不落盘。
    $moderation = ai_moderate_image($tmp);
    if (empty($moderation['safe'])) {
        $reason = (string) ($moderation['reason'] ?? '未通过 AI 审核');
        $failures[] = [
            'name' => $original,
            'error' => '未通过 AI 审核：' . $reason,
            'aiStatus' => 'rejected',
        ];
        continue;
    }
    $category = (string) ($moderation['category'] ?? '未分类');
    $tags = (array) ($moderation['tags'] ?? []);
    $aiReason = (string) ($moderation['reason'] ?? '');

    $ext = $mimeMap[$mime];
    $dateDir = date('Y/m/d');
    $targetDir = $storageDir . '/' . $dateDir;
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
        $failures[] = ['name' => $original, 'error' => '存储目录不可写'];
        continue;
    }

    $base = bin2hex(random_bytes(5));
    while (is_file($targetDir . '/' . $base . '.' . $ext)) {
        $base = bin2hex(random_bytes(5));
    }
    $filename = $base . '.' . $ext;
    $relative = 'i/' . $dateDir . '/' . $filename;
    $absolute = $targetDir . '/' . $filename;
    if (!@move_uploaded_file($tmp, $absolute)) {
        $failures[] = ['name' => $original, 'error' => '保存文件失败'];
        continue;
    }
    @chmod($absolute, 0644);

    $thumbRelative = 'thumbs/' . $dateDir . '/' . $base . '.jpg';
    $thumbAbsolute = $appRoot . '/' . $thumbRelative;
    make_thumb($absolute, $thumbAbsolute);

    $createdAt = date('Y-m-d H:i:s');
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $stmt = db()->prepare('INSERT INTO images(path, thumb, name, mime, size, width, height, created_at, ip, category, tags, ai_status, ai_reason) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $relative,
        is_file($thumbAbsolute) ? $thumbRelative : null,
        $original !== '' ? mb_substr($original, 0, 180, 'UTF-8') : $filename,
        $mime,
        $size,
        (int) $dim[0],
        (int) $dim[1],
        $createdAt,
        $ip,
        $category,
        json_encode(array_values($tags), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'approved',
        mb_substr($aiReason, 0, 180, 'UTF-8'),
    ]);
    $id = (int) db()->lastInsertId();
    $row = [
        'id' => $id,
        'path' => $relative,
        'thumb' => is_file($thumbAbsolute) ? $thumbRelative : null,
        'name' => $original !== '' ? $original : $filename,
        'size' => $size,
        'width' => (int) $dim[0],
        'height' => (int) $dim[1],
        'created_at' => $createdAt,
        'category' => $category,
        'tags' => json_encode(array_values($tags), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'ai_status' => 'approved',
        'ai_reason' => $aiReason,
    ];
    $uploaded[] = image_payload($row);
}

if (!$uploaded) {
    json_response(['ok' => false, 'error' => '没有图片上传成功', 'failures' => $failures], 400);
}
json_response(['ok' => true, 'files' => $uploaded, 'failures' => $failures]);
