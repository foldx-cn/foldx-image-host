<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

$public = isset($_GET['public']) && $_GET['public'] === '1';
if (!$public) {
    require_api_login();
}

$limit = (int) ($_GET['limit'] ?? 24);
$limit = max(1, min($public ? 12 : 60, $limit));
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$q = trim((string) ($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 80, 'UTF-8');
$category = trim((string) ($_GET['cat'] ?? ''));
$category = mb_substr($category, 0, 20, 'UTF-8');

$pdo = db();
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(name LIKE :q OR path LIKE :q OR category LIKE :q OR tags LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($category !== '') {
    $where[] = 'category = :category';
    $params[':category'] = $category;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$totalStmt = $pdo->prepare('SELECT COUNT(*) FROM images' . $whereSql);
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();

$sql = 'SELECT * FROM images' . $whereSql . ' ORDER BY datetime(created_at) DESC, id DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$images = [];
foreach ($stmt->fetchAll() as $row) {
    $images[] = image_payload($row);
}

json_response([
    'ok' => true,
    'images' => $images,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'hasMore' => ($offset + count($images)) < $total,
    'categories' => category_counts(),
    'stats' => stats(),
]);
