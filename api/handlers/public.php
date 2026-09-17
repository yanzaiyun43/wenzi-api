<?php
/**
 * 旧识桥 api · api/handlers/public.php（公开统计接口）
 * ----------------------------------------------------------------
 * 仅被统一入口 index.php（APP_ENTRY）include，禁止浏览器直接访问。
 * 提供公开统计接口，无需鉴权：
 *   - stats：返回总览统计（API 数、素材总数、调用总数、今日调用）+ 每个 API 卡片数据
 * 返回数据不含 key、素材内容、调用参数等敏感信息。
 * ----------------------------------------------------------------
 */

// —— 入口守卫 ——
if (!defined('APP_ENTRY')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}

// 失败返回 JSON
if (!function_exists('json_error')) {
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
}

// 成功返回 JSON
if (!function_exists('json_ok')) {
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
}

/**
 * GET stats —— 公开统计数据（无需鉴权）
 * 返回：
 *   overview: { api_total, text_total, call_total, call_today }
 *   apis: [{ id, path, name, type, enabled, text_count, call_total }]
 */
function public_stats()
{
    $pdo = db_connect();

    // 总览：API 总数
    $st = $pdo->query('SELECT COUNT(*) FROM api_config');
    $apiTotal = (int)$st->fetchColumn();

    // 总览：素材总数
    $st = $pdo->query('SELECT COUNT(*) FROM api_text');
    $textTotal = (int)$st->fetchColumn();

    // 总览：调用总数
    $st = $pdo->query('SELECT COUNT(*) FROM api_log');
    $callTotal = (int)$st->fetchColumn();

    // 总览：今日调用（按服务器时区）
    $todayStart = strtotime(date('Y-m-d 00:00:00'));
    $st = $pdo->prepare('SELECT COUNT(*) FROM api_log WHERE call_time >= :today');
    $st->execute(array(':today' => $todayStart));
    $callToday = (int)$st->fetchColumn();

    // 每个 API 的统计
    $st = $pdo->query('SELECT id, path, name, type, enabled FROM api_config ORDER BY id');
    $apisRaw = $st->fetchAll();

    $apis = array();
    foreach ($apisRaw as $api) {
        $apiId = (int)$api['id'];

        // 该 API 素材数
        $st = $pdo->prepare('SELECT COUNT(*) FROM api_text WHERE api_id = :api_id');
        $st->execute(array(':api_id' => $apiId));
        $textCount = (int)$st->fetchColumn();

        // 该 API 调用总数
        $st = $pdo->prepare('SELECT COUNT(*) FROM api_log WHERE api_id = :api_id');
        $st->execute(array(':api_id' => $apiId));
        $callTotalApi = (int)$st->fetchColumn();

        $apis[] = array(
            'id'          => $apiId,
            'path'        => $api['path'],
            'name'        => $api['name'],
            'type'        => $api['type'],
            'enabled'     => (int)$api['enabled'],
            'text_count'  => $textCount,
            'call_total'  => $callTotalApi,
        );
    }

    json_ok(array(
        'overview' => array(
            'api_total'   => $apiTotal,
            'text_total'  => $textTotal,
            'call_total'  => $callTotal,
            'call_today'  => $callToday,
        ),
        'apis' => $apis,
    ));
}

// —— 分发 ——
$route = isset($_GET['route']) ? (string)$_GET['route'] : '';
if ($route === 'stats') {
    public_stats();
} else {
    json_error(404, '接口不存在');
}