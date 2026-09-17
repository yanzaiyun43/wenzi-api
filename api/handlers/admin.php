<?php
/**
 * 旧识桥 api · api/handlers/admin.php（管理后台接口）
 * ----------------------------------------------------------------
 * 仅被统一入口 index.php（APP_ENTRY）include，禁止浏览器直接访问。
 * 提供三组管理接口，全部返回 JSON（code/msg/data）：
 *   - API 配置：admin/api/list、get、save、delete、toggle
 *   - 文字素材：admin/text/list、save、delete、batch-delete
 *   - 访问日志：admin/log/list、clear
 *
 * 鉴权：请求头 X-Admin-Token（兼容 Authorization: Bearer），hash_equals 比较。
 * 读操作 GET，写操作 POST。失败（鉴权/参数）延迟 300ms 防爆破。
 * 所有 SQL 走 PDO 预处理。
 * ----------------------------------------------------------------
 */

// —— 入口守卫 ——
if (!defined('APP_ENTRY')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}

// ============ 鉴权 ============

/**
 * 校验管理员 TOKEN。
 * 优先 X-Admin-Token，兼容 Authorization: Bearer xxx。
 * 使用 hash_equals 常数时间比较。
 */
function admin_authenticate(): bool
{
    $token = '';

    // 1) X-Admin-Token 优先
    if (isset($_SERVER['HTTP_X_ADMIN_TOKEN']) && $_SERVER['HTTP_X_ADMIN_TOKEN'] !== '') {
        $token = (string)$_SERVER['HTTP_X_ADMIN_TOKEN'];
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION']) && $_SERVER['HTTP_AUTHORIZATION'] !== '') {
        // 2) Authorization: Bearer xxx
        $auth = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
        if (stripos($auth, 'Bearer ') === 0) {
            $token = substr($auth, 7);
        }
    }

    if ($token === '') {
        return false;
    }

    $expected = defined('ADMIN_TOKEN') ? ADMIN_TOKEN : '';
    if ($expected === '') {
        return false;
    }

    return hash_equals($expected, $token);
}

/**
 * 未授权时输出并延迟 300ms 后退出。
 */
function admin_denied_401()
{
    usleep(300 * 1000);
    json_error(401, '未授权');
}

// ============ 公共工具 ============

/** 统一 JSON 错误（HTTP 状态码与 code 对应）；若 index.php 已定义则复用 */
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

/** 统一 JSON 成功；若 index.php 已定义则复用 */
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
 * 取分页参数：page（>=1）、page_size（1~100，默认 20）。
 */
function admin_page_params(): array
{
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) {
        $page = 1;
    }
    $size = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 20;
    if ($size < 1) {
        $size = 20;
    }
    if ($size > 100) {
        $size = 100;
    }
    return array($page, $size);
}

/**
 * 统一 400 参数错误，延迟 300ms 防爆破。
 */
function admin_denied_400(string $msg)
{
    usleep(300 * 1000);
    json_error(400, $msg);
}

/**
 * 统一 404 资源不存在，延迟 300ms 防爆破。
 */
function admin_denied_404(string $msg)
{
    usleep(300 * 1000);
    json_error(404, $msg);
}

// ============ 方法校验 ============

/**
 * 校验请求方法。$allowed 允许的方法列表。
 * 不匹配时返回 405（管理接口写操作仅 POST、读操作仅 GET）。
 */
function admin_check_method(array $allowed)
{
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    if (!in_array($method, $allowed, true)) {
        json_error(405, '请求方法不允许');
    }
}

/**
 * 读取 POST 参数（兼容 application/json 与 form-urlencoded）。
 */
function admin_post_params(): array
{
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? (string)$_SERVER['CONTENT_TYPE'] : '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return $data;
            }
        }
    }
    return $_POST;
}

// ============ 路由分发 ============

function admin_dispatch()
{
    global $route_handled;
    $route = isset($_GET['route']) ? (string)$_GET['route'] : '';

    // 鉴权：除 OPTIONS 外（OPTIONS 已在 index.php 拦截），全部管理接口需鉴权
    if (!admin_authenticate()) {
        admin_denied_401();
    }

    switch ($route) {
        case 'admin/api/list':
            admin_api_list();
            break;
        case 'admin/api/get':
            admin_api_get();
            break;
        case 'admin/api/save':
            admin_api_save();
            break;
        case 'admin/api/delete':
            admin_api_delete();
            break;
        case 'admin/api/toggle':
            admin_api_toggle();
            break;
        case 'admin/text/list':
            admin_text_list();
            break;
        case 'admin/text/save':
            admin_text_save();
            break;
        case 'admin/text/delete':
            admin_text_delete();
            break;
        case 'admin/text/batch-delete':
            admin_text_batch_delete();
            break;
        case 'admin/text/batch-save':
            admin_text_batch_save();
            break;
        case 'admin/log/list':
            admin_log_list();
            break;
        case 'admin/log/clear':
            admin_log_clear();
            break;
        default:
            json_error(404, '接口不存在');
    }

    $route_handled = true;
}

// ============ API 配置管理 ============

/** GET admin/api/list —— 分页/搜索/启用筛选 */
function admin_api_list()
{
    admin_check_method(array('GET'));
    list($page, $size) = admin_page_params();

    $where = array();
    $params = array();

    // keyword：匹配 path 或 name（模糊）
    if (isset($_GET['keyword']) && $_GET['keyword'] !== '') {
        $where[] = '(path LIKE :kw OR name LIKE :kw)';
        $params[':kw'] = '%' . (string)$_GET['keyword'] . '%';
    }
    // enabled：1 启用 / 0 关闭 / 不传=全部
    if (isset($_GET['enabled']) && $_GET['enabled'] !== '') {
        $en = (int)$_GET['enabled'];
        if ($en === 0 || $en === 1) {
            $where[] = 'enabled = :en';
            $params[':en'] = $en;
        }
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $pdo = db_connect();
    $offset = ($page - 1) * $size;

    $st = $pdo->prepare("SELECT COUNT(*) FROM api_config $whereSql");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $sql = "SELECT id, path, name, type, content, enabled, create_time
            FROM api_config $whereSql
            ORDER BY id DESC
            LIMIT :lim OFFSET :off";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':lim', $size, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    $list = $st->fetchAll();

    json_ok(array(
        'list'      => $list,
        'total'     => $total,
        'page'      => $page,
        'page_size' => $size,
    ));
}

/** GET admin/api/get —— 按 id 取单条 */
function admin_api_get()
{
    admin_check_method(array('GET'));
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        admin_denied_400('id 无效');
    }

    $pdo = db_connect();
    $st = $pdo->prepare('SELECT id, path, name, type, content, enabled, create_time FROM api_config WHERE id = :id');
    $st->execute(array(':id' => $id));
    $row = $st->fetch();

    if ($row === false) {
        admin_denied_404('API 不存在');
    }

    json_ok($row);
}

/** POST admin/api/save —— 新增或编辑 */
function admin_api_save()
{
    admin_check_method(array('POST'));
    $p = admin_post_params();

    $id   = isset($p['id']) ? (int)$p['id'] : 0;
    $path = isset($p['path']) ? trim((string)$p['path']) : '';
    $name = isset($p['name']) ? trim((string)$p['name']) : '';
    $type = isset($p['type']) ? (string)$p['type'] : '';
    $content = isset($p['content']) ? (string)$p['content'] : '';
    $enabled = isset($p['enabled']) ? (int)$p['enabled'] : 1;

    // 校验 path：1~64，仅 [a-zA-Z0-9_-]
    if ($path === '' || strlen($path) > 64 || !preg_match('#^[a-zA-Z0-9_-]{1,64}$#', $path)) {
        admin_denied_400('path 无效（1-64 位字母数字下划线中划线）');
    }
    // 校验 name：1~100
    if ($name === '' || slen($name) > 100) {
        admin_denied_400('name 无效（1-100 字）');
    }
    // 校验 type
    if (!in_array($type, array('random_text', 'template'), true)) {
        admin_denied_400('type 无效');
    }
    // 校验 content：<=10000（仅 template 使用，random_text 可为空）
    if (slen($content) > 10000) {
        admin_denied_400('content 超长（最大 10000 字）');
    }
    // 校验 enabled
    $enabled = ($enabled === 1) ? 1 : 0;

    $pdo = db_connect();
    $now = time();

    if ($id > 0) {
        // 编辑：path 被其他记录占用时友好报错
        $dup = $pdo->prepare('SELECT id FROM api_config WHERE path = :path AND id <> :id LIMIT 1');
        $dup->execute(array(':path' => $path, ':id' => $id));
        if ($dup->fetch() !== false) {
            admin_denied_400('path 已存在');
        }
        $st = $pdo->prepare('UPDATE api_config SET path=:path, name=:name, type=:type, content=:content, enabled=:enabled WHERE id=:id');
        try {
            $st->execute(array(
                ':path' => $path, ':name' => $name, ':type' => $type,
                ':content' => $content, ':enabled' => $enabled, ':id' => $id,
            ));
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'UNIQUE') !== false) {
                admin_denied_400('path 已存在');
            }
            json_error(500, '服务器错误');
        }
        if ($st->rowCount() === 0) {
            // 可能 id 不存在（update 0 行）——校验存在性
            $chk = $pdo->prepare('SELECT COUNT(*) FROM api_config WHERE id=:id');
            $chk->execute(array(':id' => $id));
            if ((int)$chk->fetchColumn() === 0) {
                admin_denied_404('API 不存在');
            }
        }
        json_ok(array('id' => $id));
    } else {
        // 新增：path 已存在时友好报错（并兜底捕获唯一约束异常）
        $dup = $pdo->prepare('SELECT id FROM api_config WHERE path = :path LIMIT 1');
        $dup->execute(array(':path' => $path));
        if ($dup->fetch() !== false) {
            admin_denied_400('path 已存在');
        }
        $st = $pdo->prepare('INSERT INTO api_config (path, name, type, content, enabled, create_time) VALUES (:path,:name,:type,:content,:enabled,:now)');
        try {
            $st->execute(array(
                ':path' => $path, ':name' => $name, ':type' => $type,
                ':content' => $content, ':enabled' => $enabled, ':now' => $now,
            ));
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'UNIQUE') !== false) {
                admin_denied_400('path 已存在');
            }
            json_error(500, '服务器错误');
        }
        json_ok(array('id' => (int)$pdo->lastInsertId()));
    }
}

/** POST admin/api/delete —— 删除（级联删素材） */
function admin_api_delete()
{
    admin_check_method(array('POST'));
    $p = admin_post_params();
    $id = isset($p['id']) ? (int)$p['id'] : 0;
    if ($id <= 0) {
        admin_denied_400('id 无效');
    }

    $pdo = db_connect();
    $st = $pdo->prepare('DELETE FROM api_config WHERE id=:id');
    $st->execute(array(':id' => $id));
    if ($st->rowCount() === 0) {
        admin_denied_404('API 不存在');
    }

    json_ok(null);
}

/** POST admin/api/toggle —— 启用/禁用 */
function admin_api_toggle()
{
    admin_check_method(array('POST'));
    $p = admin_post_params();
    $id = isset($p['id']) ? (int)$p['id'] : 0;
    $enabled = isset($p['enabled']) ? (int)$p['enabled'] : -1;

    if ($id <= 0 || ($enabled !== 0 && $enabled !== 1)) {
        admin_denied_400('参数无效');
    }

    $pdo = db_connect();
    $st = $pdo->prepare('UPDATE api_config SET enabled=:enabled WHERE id=:id');
    $st->execute(array(':enabled' => $enabled, ':id' => $id));
    if ($st->rowCount() === 0) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM api_config WHERE id=:id');
        $chk->execute(array(':id' => $id));
        if ((int)$chk->fetchColumn() === 0) {
            admin_denied_404('API 不存在');
        }
    }

    json_ok(array('id' => $id, 'enabled' => $enabled));
}

// ============ 文字素材管理 ============

/** GET admin/text/list —— 按 api_id 分页/搜索素材 */
function admin_text_list()
{
    admin_check_method(array('GET'));
    $apiId = isset($_GET['api_id']) ? (int)$_GET['api_id'] : 0;
    if ($apiId <= 0) {
        admin_denied_400('api_id 无效');
    }

    list($page, $size) = admin_page_params();

    $where = array('api_id = :api_id');
    $params = array(':api_id' => $apiId);

    if (isset($_GET['keyword']) && $_GET['keyword'] !== '') {
        $where[] = 'content LIKE :kw';
        $params[':kw'] = '%' . (string)$_GET['keyword'] . '%';
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $pdo = db_connect();
    $offset = ($page - 1) * $size;

    $st = $pdo->prepare("SELECT COUNT(*) FROM api_text $whereSql");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $sql = "SELECT id, api_id, content FROM api_text $whereSql ORDER BY id DESC LIMIT :lim OFFSET :off";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':lim', $size, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    $list = $st->fetchAll();

    json_ok(array(
        'list'      => $list,
        'total'     => $total,
        'page'      => $page,
        'page_size' => $size,
    ));
}

/** POST admin/text/save —— 新增/编辑素材 */
function admin_text_save()
{
    admin_check_method(array('POST'));
    $p = admin_post_params();

    $id    = isset($p['id']) ? (int)$p['id'] : 0;
    $apiId = isset($p['api_id']) ? (int)$p['api_id'] : 0;
    $content = isset($p['content']) ? (string)$p['content'] : '';

    if ($apiId <= 0) {
        admin_denied_400('api_id 无效');
    }
    // 素材内容 <=5000
    if (slen($content) > 5000) {
        admin_denied_400('素材内容超长（最大 5000 字）');
    }

    $pdo = db_connect();

    // 校验 api 存在
    $chk = $pdo->prepare('SELECT COUNT(*) FROM api_config WHERE id=:id');
    $chk->execute(array(':id' => $apiId));
    if ((int)$chk->fetchColumn() === 0) {
        admin_denied_404('API 不存在');
    }

    if ($id > 0) {
        $st = $pdo->prepare('UPDATE api_text SET content=:content WHERE id=:id AND api_id=:api_id');
        $st->execute(array(':content' => $content, ':id' => $id, ':api_id' => $apiId));
        if ($st->rowCount() === 0) {
            $chk2 = $pdo->prepare('SELECT COUNT(*) FROM api_text WHERE id=:id');
            $chk2->execute(array(':id' => $id));
            if ((int)$chk2->fetchColumn() === 0) {
                admin_denied_404('素材不存在');
            }
        }
        json_ok(array('id' => $id));
    } else {
        $st = $pdo->prepare('INSERT INTO api_text (api_id, content) VALUES (:api_id, :content)');
        $st->execute(array(':api_id' => $apiId, ':content' => $content));
        json_ok(array('id' => (int)$pdo->lastInsertId()));
    }
}

/** POST admin/text/delete —— 删除单条素材 */
function admin_text_delete()
{
    admin_check_method(array('POST'));
    $p = admin_post_params();
    $id = isset($p['id']) ? (int)$p['id'] : 0;
    if ($id <= 0) {
        admin_denied_400('id 无效');
    }

    $pdo = db_connect();
    $st = $pdo->prepare('DELETE FROM api_text WHERE id=:id');
    $st->execute(array(':id' => $id));
    if ($st->rowCount() === 0) {
        admin_denied_404('素材不存在');
    }

    json_ok(null);
}

/** POST admin/text/batch-delete —— 批量删除素材 */
function admin_text_batch_delete()
{
    admin_check_method(array('POST'));
    $p = admin_post_params();
    $ids = isset($p['ids']) ? (array)$p['ids'] : array();

    // 过滤：只保留正整数
    $clean = array();
    foreach ($ids as $v) {
        $v = (int)$v;
        if ($v > 0) {
            $clean[] = $v;
        }
    }
    if (empty($clean)) {
        admin_denied_400('ids 无效');
    }
    // 数量上限
    if (count($clean) > 500) {
        admin_denied_400('一次最多删除 500 条');
    }

    $pdo = db_connect();
    $in = implode(',', array_fill(0, count($clean), '?'));
    $st = $pdo->prepare("DELETE FROM api_text WHERE id IN ($in)");
    $st->execute($clean);

    json_ok(array('deleted' => $st->rowCount()));
}

/** POST admin/text/batch-save —— 批量导入素材（单事务，去重） */
function admin_text_batch_save()
{
    admin_check_method(array('POST'));
    $p = admin_post_params();

    $apiId = isset($p['api_id']) ? (int)$p['api_id'] : 0;
    $contents = isset($p['contents']) ? (array)$p['contents'] : array();
    $skipDuplicate = isset($p['skip_duplicate']) ? (bool)$p['skip_duplicate'] : true;

    if ($apiId <= 0) {
        admin_denied_400('api_id 无效');
    }
    if (empty($contents)) {
        admin_denied_400('contents 为空');
    }
    if (count($contents) > 5000) {
        admin_denied_400('一次最多导入 5000 条');
    }

    // 校验 api 存在
    $pdo = db_connect();
    $chk = $pdo->prepare('SELECT COUNT(*) FROM api_config WHERE id=:id');
    $chk->execute(array(':id' => $apiId));
    if ((int)$chk->fetchColumn() === 0) {
        admin_denied_404('API 不存在');
    }

    // 读取该 API 下已有内容（用于去重）
    $existing = array();
    if ($skipDuplicate) {
        $st = $pdo->prepare('SELECT content FROM api_text WHERE api_id = :api_id');
        $st->execute(array(':api_id' => $apiId));
        while ($row = $st->fetch()) {
            $existing[$row['content']] = true;
        }
    }

    // 去重处理
    $toInsert = array();
    $seenInBatch = array();
    $skipped = 0;
    foreach ($contents as $c) {
        $c = trim((string)$c);
        if ($c === '') continue;
        if (slen($c) > 5000) continue;
        if (isset($seenInBatch[$c])) { $skipped++; continue; }
        if ($skipDuplicate && isset($existing[$c])) { $skipped++; continue; }
        $toInsert[] = $c;
        $seenInBatch[$c] = true;
    }

    if (empty($toInsert)) {
        json_ok(array('inserted' => 0, 'skipped' => $skipped));
    }

    // 单事务批量写入
    $inserted = 0;
    try {
        $result = db_transaction($pdo, function ($pdo) use ($apiId, $toInsert, &$inserted) {
            $st = $pdo->prepare('INSERT INTO api_text (api_id, content) VALUES (:api_id, :content)');
            foreach ($toInsert as $c) {
                $st->execute(array(':api_id' => $apiId, ':content' => $c));
                $inserted++;
            }
            return true;
        });
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'UNIQUE') !== false) {
            // 极少数并发冲突，兜底：逐条尝试
            $inserted = 0;
            $st = $pdo->prepare('INSERT IGNORE INTO api_text (api_id, content) VALUES (:api_id, :content)');
            foreach ($toInsert as $c) {
                $st->execute(array(':api_id' => $apiId, ':content' => $c));
                if ($st->rowCount() > 0) $inserted++;
            }
        } else {
            json_error(500, '批量导入失败：' . $e->getMessage());
        }
    }

    json_ok(array('inserted' => $inserted, 'skipped' => $skipped));
}

// ============ 日志管理 ============

/** GET admin/log/list —— 分页/筛选（api_id、ip、时间范围） */
function admin_log_list()
{
    admin_check_method(array('GET'));
    list($page, $size) = admin_page_params();

    $where = array();
    $params = array();

    if (isset($_GET['api_id']) && $_GET['api_id'] !== '') {
        $where[] = 'api_id = :api_id';
        $params[':api_id'] = (int)$_GET['api_id'];
    }
    if (isset($_GET['ip']) && $_GET['ip'] !== '') {
        $where[] = 'ip = :ip';
        $params[':ip'] = (string)$_GET['ip'];
    }
    if (isset($_GET['start_time']) && $_GET['start_time'] !== '') {
        $where[] = 'call_time >= :start_time';
        $params[':start_time'] = (int)$_GET['start_time'];
    }
    if (isset($_GET['end_time']) && $_GET['end_time'] !== '') {
        $where[] = 'call_time <= :end_time';
        $params[':end_time'] = (int)$_GET['end_time'];
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $pdo = db_connect();
    $offset = ($page - 1) * $size;

    $st = $pdo->prepare("SELECT COUNT(*) FROM api_log $whereSql");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $sql = "SELECT id, api_id, ip, call_time, params FROM api_log $whereSql ORDER BY id DESC LIMIT :lim OFFSET :off";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':lim', $size, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    $list = $st->fetchAll();

    json_ok(array(
        'list'      => $list,
        'total'     => $total,
        'page'      => $page,
        'page_size' => $size,
    ));
}

/** POST admin/log/clear —— 清空日志（可按 before_time、api_id 过滤） */
function admin_log_clear()
{
    admin_check_method(array('POST'));
    $p = admin_post_params();

    $where = array();
    $params = array();

    if (isset($p['before_time']) && $p['before_time'] !== '') {
        $where[] = 'call_time <= :before_time';
        $params[':before_time'] = (int)$p['before_time'];
    }
    if (isset($p['api_id']) && $p['api_id'] !== '') {
        $where[] = 'api_id = :api_id';
        $params[':api_id'] = (int)$p['api_id'];
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $pdo = db_connect();
    $st = $pdo->prepare("DELETE FROM api_log $whereSql");
    $st->execute($params);

    json_ok(array('deleted' => $st->rowCount()));
}

// —— 执行管理分发 ——
admin_dispatch();