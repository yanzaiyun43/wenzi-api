<?php
/**
 * 旧识桥 api · api/handlers/runtime.php（对外文字 API 运行接口）
 * ----------------------------------------------------------------
 * 仅被统一入口 index.php（APP_ENTRY）include，禁止浏览器直接访问。
 * 路由：route=runtime&path=xxx&key=xxx（key 也可用请求头 X-API-Key 传递）
 *
 * 流程：
 *   1. 校验 key（hash_equals）；错误返回 403 JSON + 延迟 200ms
 *   2. 按 path 查询 api_config；不存在 -> 404 JSON
 *   3. enabled=0 -> 403 JSON
 *   4. 按 type 处理：
 *        - random_text：api_text 随机取一条（ORDER BY RANDOM() LIMIT 1），无素材 -> 404 JSON
 *        - template：替换 {{参数名}}（变量名 [a-zA-Z0-9_]{1,32}，未传参替换为空）
 *   5. 成功返回纯文本（text/plain），失败返回 JSON
 *   6. 写调用日志（key 脱敏，截断 2000），失败忽略
 *
 * 禁止 eval / 动态 include / 执行任何用户输入代码。
 * 输出最大长度 1MB。
 * ----------------------------------------------------------------
 */

// —— 入口守卫 ——
if (!defined('APP_ENTRY')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}

// 失败返回 JSON（HTTP 状态码与 code 对应）；避免与 index.php 冲突
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

/**
 * runtime 主流程入口。
 */
function runtime_run()
{
    // ---------- 1. 校验 key ----------
    $key = '';
    // X-API-Key 头优先
    if (isset($_SERVER['HTTP_X_API_KEY']) && $_SERVER['HTTP_X_API_KEY'] !== '') {
        $key = (string)$_SERVER['HTTP_X_API_KEY'];
    } elseif (isset($_GET['key']) && $_GET['key'] !== '') {
        $key = (string)$_GET['key'];
    }

    $expected = defined('API_ACCESS_KEY') ? API_ACCESS_KEY : '';
    $keyOk = ($expected !== '' && $key !== '' && hash_equals($expected, $key));

    if (!$keyOk) {
        // key 错误/缺失：延迟 200ms 防爆破，返回 403 JSON
        usleep(200 * 1000);
        json_error(403, 'key 错误');
    }

    // ---------- 2. 读取 path ----------
    $path = isset($_GET['path']) ? trim((string)$_GET['path']) : '';
    if ($path === '') {
        json_error(400, '缺少 path 参数');
    }
    // path 长度/格式校验（与保存时一致）
    if (strlen($path) > 64 || !preg_match('#^[a-zA-Z0-9_-]{1,64}$#', $path)) {
        json_error(400, 'path 无效');
    }

    // ---------- 3/4. 查询 API ----------
    $pdo = db_connect();

    $st = $pdo->prepare('SELECT id, path, name, type, content, enabled FROM api_config WHERE path = :path');
    $st->execute(array(':path' => $path));
    $api = $st->fetch();

    if ($api === false) {
        json_error(404, 'API 不存在');
    }

    if ((int)$api['enabled'] !== 1) {
        json_error(403, 'API 已禁用');
    }

    $apiId = (int)$api['id'];
    $type = (string)$api['type'];

    // ---------- 5. 按类型处理，得到输出文本 ----------
    $output = '';
    if ($type === 'template') {
        $content = (string)$api['content'];
        $output = runtime_replace_template($content);
    } elseif ($type === 'random_text') {
        $output = runtime_random_text($pdo, $apiId);
    } else {
        // 理论不可达（数据库 CHECK 约束），兜底
        json_error(500, '未知 API 类型');
    }

    // ---------- 6. 输出长度限制 1MB（按 UTF-8 边界截断） ----------
    $output = utf8_truncate_bytes($output, 1048576);

    // ---------- 7. 成功返回纯文本 ----------
    header('Content-Type: text/plain; charset=utf-8');
    echo $output;

    // PHP-FPM 下先结束客户端响应，再继续记录日志，避免日志锁等待拉长用户感知延迟。
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // ---------- 8. 写调用日志（失败忽略，key 已排除）----------
    runtime_write_log($pdo, $apiId);

    exit;
}

/**
 * template：替换 {{参数名}}。
 * 变量名 [a-zA-Z0-9_]{1,32}，使用 preg_replace_callback；
 * 未传参数替换为空字符串；不执行任何用户输入代码。
 */
function runtime_replace_template($content)
{
    return preg_replace_callback(
        '#\{\{\s*([a-zA-Z0-9_]{1,32})\s*\}\}#',
        function ($m) {
            $name = $m[1];
            // 这些参数用于鉴权或路由控制，禁止被模板回显。
            $reserved = array(
                'key', 'api-key', 'x-api-key', 'api_key',
                'token', 'access-token', 'access_token',
                'admin-token', 'admin_token', 'authorization',
                'route', 'path'
            );
            if (in_array(strtolower($name), $reserved, true)) {
                return '';
            }
            return isset($_GET[$name]) ? (string)$_GET[$name] : '';
        },
        $content
    );
}

/**
 * random_text：从 api_text 随机取一条。无素材返回 404 JSON。
 */
function runtime_random_text($pdo, $apiId)
{
    $st = $pdo->prepare('SELECT content FROM api_text WHERE api_id = :api_id ORDER BY RANDOM() LIMIT 1');
    $st->execute(array(':api_id' => $apiId));
    $row = $st->fetch();

    if ($row === false) {
        json_error(404, '该 API 暂无素材');
    }

    return (string)$row['content'];
}

/**
 * 写调用日志（失败忽略）。
 * params 记录 GET 参数，但排除 key / route / admin-token / X-API-Key；
 * 最大长度 2000，超出截断。IP 默认 REMOTE_ADDR，除非 TRUST_X_FORWARDED_FOR 开启。
 */
function runtime_write_log($pdo, $apiId)
{
    // 脱敏：只记录业务查询参数，不记录任何鉴权、路由控制参数。
    // 键名统一转小写后比较，避免大小写变体绕过脱敏。
    $exclude = array(
        'key', 'api-key', 'api_key', 'x-api-key',
        'token', 'access-token', 'access_token',
        'admin-token', 'admin_token', 'authorization',
        'route', 'path'
    );
    $params = array();
    foreach ($_GET as $k => $v) {
        $kk = strtolower(trim((string)$k));
        if (in_array($kk, $exclude, true)) {
            continue;
        }
        // 请求头中的 X-API-Key / Authorization 从不写入 params；
        // 这里仅记录 GET 业务参数，避免鉴权信息落入日志。
        $params[$k] = $v;
    }

    $paramsStr = json_encode($params, JSON_UNESCAPED_UNICODE);
    if (!is_string($paramsStr)) {
        $paramsStr = '{}';
    }
    $paramsStr = utf8_truncate_bytes($paramsStr, 2000);

    // IP
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    if (defined('TRUST_X_FORWARDED_FOR') && TRUST_X_FORWARDED_FOR && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $xf = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if ($xf !== '') {
            $ip = $xf;
        }
    }

    // 写日志失败忽略，不影响主接口
    try {
        $st = $pdo->prepare('INSERT INTO api_log (api_id, ip, call_time, params) VALUES (:api_id, :ip, :call_time, :params)');
        $st->execute(array(
            ':api_id'    => $apiId,
            ':ip'        => $ip,
            ':call_time' => time(),
            ':params'    => $paramsStr,
        ));
        runtime_prune_logs($pdo);
    } catch (PDOException $e) {
        // 忽略：日志失败不影响主接口返回
    }
}

/**
 * 按配置概率清理过期日志，避免每次调用都做 DELETE。
 * LOG_RETENTION_DAYS <= 0 时关闭自动清理；默认保留 90 天。
 */
function runtime_prune_logs($pdo)
{
    $days = defined('LOG_RETENTION_DAYS') ? (int)LOG_RETENTION_DAYS : 90;
    if ($days <= 0 || mt_rand(1, 100) !== 1) {
        return;
    }

    $cutoff = time() - ($days * 86400);
    // 单次最多删除 1000 行，避免首次清理历史大库时长时间占写锁。
    $st = $pdo->prepare('DELETE FROM api_log WHERE id IN (SELECT id FROM api_log WHERE call_time < :cutoff ORDER BY id LIMIT 1000)');
    $st->execute(array(':cutoff' => $cutoff));
}

// —— 执行 ——
runtime_run();