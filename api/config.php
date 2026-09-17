<?php
/**
 * 旧识桥 api · backend/config.php（由 install.php 生成）
 * 可手动编辑以下常量更换密钥/来源；保存即生效，无需重启（短进程模式）。
 */

if (!defined('APP_ENTRY') && !defined('APP_INSTALL')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}

define('ADMIN_TOKEN', 'TestToken123456789');

define('API_ACCESS_KEY', 'TestApiKey123456789');

$ALLOWED_ORIGINS = array (
  0 => '*',
);

define('DB_PATH', __DIR__ . '/api.db');

define('TRUST_X_FORWARDED_FOR', false);