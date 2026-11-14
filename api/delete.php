<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

require_api_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => '只接受 POST'], 405);
}
if (!csrf_check()) {
    json_response(['ok' => false, 'error' => '安全校验失败，请刷新页面后重试'], 419);
}

$id = (int) ($_POST['id'] ?? 0);
if ($id < 1) {
    json_response(['ok' => false, 'error' => '缺少图片 ID'], 400);
}
if (!delete_image_row($id)) {
    json_response(['ok' => false, 'error' => '图片不存在或已经删除'], 404);
}
json_response(['ok' => true, 'id' => $id, 'stats' => stats()]);
