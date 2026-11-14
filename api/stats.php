<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

$public = isset($_GET['public']) && $_GET['public'] === '1';
if (!$public) {
    require_api_login();
}

json_response(['ok' => true, 'stats' => stats()]);
