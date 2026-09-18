<?php
/**
 * 旧识桥 api · api/handlers/public.php（公开统计接口）
 * ----------------------------------------------------------------
 * 仅被统一入口 index.php（APP_ENTRY）include，禁止浏览器直接访问。
 * 提供公开统计接口，无需鉴权：
 *   - stats：仅返回总览统计（API 数、素材总数、调用总数、今日调用）
 * 统计不公开 API 路径、名称、状态、素材数或调用数等业务明细；
 * 数据库不存在时直接返回受控错误，绝不由公开请求创建数据库或表。
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
 * GET stats —— 公开总览统计数据（无需鉴权）。
 * 仅返回 overview: { api_total, text_total, call_total, call_today }。
 * 未完成安装或数据库文件不存在时返回 503，不调用 db_connect()，避免公开请求创建数据库或表。
 */
function public_stats()
{
    if (!is_file(DB_PATH)) {
        json_error(503, '统计暂不可用');
    }

    try {
        $pdo = db_connect();

        // 一次查询得到四项汇总，避免每次 stats 请求创建多个 statement。
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $st = $pdo->prepare(
            'SELECT '
            . '(SELECT COUNT(*) FROM api_config) AS api_total, '
            . '(SELECT COUNT(*) FROM api_text) AS text_total, '
            . '(SELECT COUNT(*) FROM api_log) AS call_total, '
            . '(SELECT COUNT(*) FROM api_log WHERE call_time >= :today) AS call_today'
        );
        $st->execute(array(':today' => $todayStart));
        $overview = $st->fetch();
        if ($overview === false) {
            throw new PDOException('统计查询无返回结果');
        }
        $apiTotal = (int)$overview['api_total'];
        $textTotal = (int)$overview['text_total'];
        $callTotal = (int)$overview['call_total'];
        $callToday = (int)$overview['call_today'];
    } catch (PDOException $e) {
        error_log('[wenzi-api] public_stats failed: ' . $e->getMessage());
        json_error(503, '统计暂不可用');
    }

    json_ok(array(
        'overview' => array(
            'api_total'   => $apiTotal,
            'text_total'  => $textTotal,
            'call_total'  => $callTotal,
            'call_today'  => $callToday,
        ),
    ));
}

// —— 分发 ——
$route = isset($_GET['route']) ? (string)$_GET['route'] : '';
if ($route === 'stats') {
    public_stats();
} else {
    json_error(404, '接口不存在');
}