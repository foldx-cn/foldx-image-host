<?php
/**
 * Copy this file to /www/wwwroot/imgfoldx-data/config.php.
 * Never commit the real config.php — it contains the administrator password hash.
 */
return [
    'base_url' => 'https://img.example.com',
    'site_title' => 'Foldx 图床',

    // 用 `php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"` 生成
    'admin_user' => 'admin',
    'admin_password_hash' => 'PASTE_YOUR_PASSWORD_HASH_HERE',

    'max_file_size' => 20 * 1024 * 1024,
    'max_upload_count' => 20,

    // 生产环境放在 Web 根目录之外
    'storage_dir' => __DIR__ . '/../img.example.com/i',
    'db_path' => __DIR__ . '/images.sqlite',

    // 公开上传边界（登录后的管理员不受频率限制）
    'public_max_files' => 10,
    'public_max_file_size' => 20 * 1024 * 1024,
    'public_hour_limit' => 15,
    'public_day_limit' => 50,

    // 本地审核服务，见 ai/moderator.py；只监听 127.0.0.1
    'ai_moderation_enabled' => true,
    'ai_moderation_url' => 'http://127.0.0.1:8791/moderate',
    'ai_moderation_timeout' => 15,
];
