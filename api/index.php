<?php
/**
 * 旧识桥 api · api/index.php（统一入口路由）
 * ----------------------------------------------------------------
 * 前端只与这个文件交互：api/index.php?route=xxx
 *
 * 职责：
 *   1. 定义 APP_ENTRY 常量（api/admin.php、api/runtime.php 据此放行）
 *   2. 载入 config.php（ADMIN_TOKEN、API_ACCESS_KEY、ALLOWED_ORIGINS、DB_PATH 等）
 *   3. 载入 db.php（SQLite 连接与锁重试）
 *   4. CORS 处理 + OPTIONS 预检直接 204
 *   5. 解析 route 并分发：
 *        - route=runtime            -> api/runtime.php
 *        - route=admin/api/*        -> api/admin.php
 *        - route=admin/text/*       -> api/admin.php
 *        - route=admin/log/*        -> api/admin.php
 *   6. 未匹配 -> 404 JSON
 *
 * 路由以 query 为主，不依赖 Apache rewrite；serv00 不支持 rewrite 也能工作。
 * ----------------------------------------------------------------
 */

declare(strict_types=1);

// —— 1. 定义入口常量（api/*.php 顶部据此守卫）——
define('APP_ENTRY', true);

// —— 2/3. 载入配置与数据库层 ——
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// —— 4. CORS ——
cors_handle();
function cors_handle()
{
    // 解析来源
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

    $allowed = defined('ALLOWED_ORIGINS') ? ALLOWED_ORIGINS : array();
    // 兼容 config.php 暴露的 $ALLOWED_ORIGINS 变量（若 install 生成的用变量而非常量）
    global $ALLOWED_ORIGINS;
    if (empty($allowed) && isset($ALLOWED_ORIGINS) && is_array($ALLOWED_ORIGINS)) {
        $allowed = $ALLOWED_ORIGINS;
    }

    // 只要请求携带 Origin，响应就必须参与 Origin 缓存区分；
    // 即使来源未获准，也要发送 Vary，避免共享缓存复用错误响应。
    if ($origin !== '') {
        header('Vary: Origin');
    }

    $matchOrigin = '';
    if ($origin !== '') {
        if (in_array('*', $allowed, true)) {
            $matchOrigin = '*';
        } elseif (in_array($origin, $allowed, true)) {
            $matchOrigin = $origin;
        }
    }

    // 仅当来源命中允许列表才回 CORS 头；否则不写，让浏览器自己拦截跨域
    if ($matchOrigin !== '') {
        header('Access-Control-Allow-Origin: ' . $matchOrigin);
        header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token, X-API-Key, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Max-Age: 86400');
    }

    // OPTIONS 预检直接 204，不进入业务逻辑
    if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// —— 5. 路由分发 ——
function route_dispatch()
{
    $route = isset($_GET['route']) ? trim((string)$_GET['route']) : '';
    if ($route === '') {
        return json_error(400, '缺少 route 参数');
    }

    // 对外文字 API
    if ($route === 'runtime') {
        return include_runtime();
    }

    // 公开统计（无需鉴权）
    if ($route === 'stats') {
        return include_public();
    }

    // 管理后台接口：admin/api/*、admin/text/*、admin/log/*
    if (preg_match('#^admin/(api|text|log)/#', $route)) {
        return include_admin();
    }

    // 未匹配
    return json_error(404, '接口不存在');
}

function include_runtime()
{
    $file = __DIR__ . '/handlers/runtime.php';
    if (!is_file($file)) {
        return json_error(500, 'runtime 处理器缺失');
    }
    require $file;
    return;
}

function include_public()
{
    $file = __DIR__ . '/handlers/public.php';
    if (!is_file($file)) {
        return json_error(500, 'public 处理器缺失');
    }
    require $file;
    return;
}

function include_admin()
{
    $file = __DIR__ . '/handlers/admin.php';
    if (!is_file($file)) {
        return json_error(500, 'admin 处理器缺失');
    }
    require $file;
    return;
}

// —— 6. 工具：JSON 输出（管理接口统一格式，HTTP 状态码与 code 对应）——
function json_error(int $code, string $msg)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'code' => $code,
        'msg'  => $msg,
        'data' => null,
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok($data)
{
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'code' => 0,
        'msg'  => 'ok',
        'data' => $data,
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

// —— 执行分发 ——
route_dispatch();