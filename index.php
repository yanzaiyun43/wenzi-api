<?php
/**
 * 旧识桥 api · 网站根目录入口（index.php）
 * ----------------------------------------------------------------
 * 访问域名根目录时自动分流：
 *   - 系统未安装（api/install.lock 不存在）→ 跳转 api/install.php 安装向导
 *   - 已完成安装 → 直接显示 portal.html 统计门户（不再跳转）
 *
 * 部署：与 api/、admin/、portal.html 一起上传到网站根目录即可。
 *       本文件只做分流，不渲染页面、不含任何业务逻辑。
 * 备注：如不想使用本入口，删除本文件不影响系统本体；
 *       但访问根目录会显示目录列表（或 403），需手动访问具体页面路径。
 * ----------------------------------------------------------------
 */

$lockFile = __DIR__ . '/api/install.lock';

// 已安装 → 统计门户（直接包含 portal.html，不跳转，保持域名根路径）
if (is_file($lockFile)) {
    require_once __DIR__ . '/portal.html';
    exit;
}

// 未安装 → 安装向导
$installer = __DIR__ . '/api/install.php';
if (is_file($installer)) {
    header('Cache-Control: no-store');
    header('Location: api/install.php', true, 302);
    exit;
}

// 兜底：未安装且 install.php 已不存在
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo "系统尚未安装，且未找到 api/install.php。\n";
echo "请重新上传 api/install.php 后访问 /api/install.php 完成安装。\n";
