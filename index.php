<?php
/**
 * 旧识桥 api · 网站根目录入口（index.php）
 * ----------------------------------------------------------------
 * 访问域名根目录时自动分流：
 *   - 系统未安装（api/install.lock 不存在）时，跳转 api/install.php 安装向导
 *   - 已完成安装时，输出 portal.html 统计门户，保持根路径不变
 *
 * 部署：与 api/、admin/、portal.html 一起上传到网站根目录即可。
 * 本文件只负责入口分流，不包含业务路由。
 * ----------------------------------------------------------------
 */

$lockFile = __DIR__ . '/api/install.lock';

// 已安装：按静态文件原样输出，避免把 HTML 当作 PHP 脚本解析。
if (is_file($lockFile)) {
    $portal = __DIR__ . '/portal.html';
    if (is_file($portal)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($portal);
        exit;
    }

    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "统计门户文件不存在，请重新上传 portal.html。\n";
    exit;
}

// 未安装：跳转安装向导，并禁止缓存旧的入口状态。
$installer = __DIR__ . '/api/install.php';
if (is_file($installer)) {
    header('Cache-Control: no-store');
    header('Location: api/install.php', true, 302);
    exit;
}

// 兜底：未安装且 install.php 已不存在。
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo "系统尚未安装，且未找到 api/install.php。\n";
echo "请重新上传 api/install.php 后访问 /api/install.php 完成安装。\n";
