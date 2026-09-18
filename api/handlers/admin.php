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

// ============ 鉴权（账号 + 密码 → 会话令牌） ============

/**
 * 客户端 IP（默认 REMOTE_ADDR；TRUST_X_FORWARDED_FOR 开启时取 XFF 第一段）。
 */
function admin_client_ip(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    if (defined('TRUST_X_FORWARDED_FOR') && TRUST_X_FORWARDED_FOR && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $xf = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if ($xf !== '') {
            $ip = $xf;
        }
    }
    return $ip;
}

/** 会话有效期（秒），默认 7 天；config.php 可用 ADMIN_SESSION_TTL 覆盖。 */
function admin_session_ttl(): int
{
    $ttl = defined('ADMIN_SESSION_TTL') ? (int)ADMIN_SESSION_TTL : 604800;
    return $ttl > 0 ? $ttl : 604800;
}

/** 从请求头读取会话令牌：X-Admin-Token 优先，兼容 Authorization: Bearer xxx。 */
function admin_request_token(): string
{
    if (isset($_SERVER['HTTP_X_ADMIN_TOKEN']) && $_SERVER['HTTP_X_ADMIN_TOKEN'] !== '') {
        return trim((string)$_SERVER['HTTP_X_ADMIN_TOKEN']);
    }
    if (isset($_SERVER['HTTP_AUTHORIZATION']) && $_SERVER['HTTP_AUTHORIZATION'] !== '') {
        $auth = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
    }
    return '';
}

/** 管理员账号数量（为 0 表示还需前端初始化）。 */
function admin_user_count(): int
{
    $pdo = db_connect();
    return (int)$pdo->query('SELECT COUNT(*) FROM admin_user')->fetchColumn();
}

/**
 * 当前登录用户；无有效会话返回 null。
 * 会话令牌固定为 64 位十六进制，先校验格式再查库。
 */
function admin_current_user(): ?array
{
    $token = admin_request_token();
    if ($token === '' || !preg_match('#^[a-f0-9]{64}$#', $token)) {
        return null;
    }

    $pdo = db_connect();
    $st = $pdo->prepare(
        'SELECT s.id AS session_id, s.expire_time, u.id AS user_id, u.username
         FROM admin_session s JOIN admin_user u ON u.id = s.user_id
         WHERE s.token_hash = :h LIMIT 1'
    );
    $st->execute(array(':h' => hash('sha256', $token)));
    $row = $st->fetch();
    if ($row === false) {
        return null;
    }

    $now = time();
    $sessionId = (int)$row['session_id'];

    // 过期会话立即删除，避免残留可用记录
    if ((int)$row['expire_time'] <= $now) {
        $del = $pdo->prepare('DELETE FROM admin_session WHERE id = :id');
        $del->execute(array(':id' => $sessionId));
        return null;
    }

    // 滑动续期：剩余不足一半时才写库，避免每个请求都产生一次写操作
    $ttl = admin_session_ttl();
    if ((int)$row['expire_time'] - $now < (int)($ttl / 2)) {
        $up = $pdo->prepare('UPDATE admin_session SET expire_time = :e WHERE id = :id');
        $up->execute(array(':e' => $now + $ttl, ':id' => $sessionId));
    }

    return array(
        'user_id'    => (int)$row['user_id'],
        'username'   => (string)$row['username'],
        'session_id' => $sessionId,
    );
}

/** 需要登录的接口统一入口：未登录返回 401（延迟 300ms 防爆破）。 */
function admin_require_login(): array
{
    $user = admin_current_user();
    if ($user === null) {
        usleep(300 * 1000);
        json_error(401, '未登录或登录已过期');
    }
    return $user;
}

/** 登录失败是否已被限速：同一 IP 15 分钟内失败 5 次。 */
function admin_login_blocked(string $ip): bool
{
    $pdo = db_connect();
    $st = $pdo->prepare('SELECT fails, last_time FROM admin_login_attempt WHERE ip = :ip LIMIT 1');
    $st->execute(array(':ip' => $ip));
    $row = $st->fetch();
    if ($row === false) {
        return false;
    }
    if ((int)$row['fails'] < 5) {
        return false;
    }
    return (time() - (int)$row['last_time']) < 900;
}

/** 记录一次登录失败（按 IP 累加）。 */
function admin_login_record_fail(string $ip): void
{
    $pdo = db_connect();
    $now = time();
    db_retry(function () use ($pdo, $ip, $now) {
        $st = $pdo->prepare('SELECT fails FROM admin_login_attempt WHERE ip = :ip LIMIT 1');
        $st->execute(array(':ip' => $ip));
        $fails = $st->fetchColumn();
        if ($fails === false) {
            $ins = $pdo->prepare('INSERT INTO admin_login_attempt (ip, fails, last_time) VALUES (:ip, 1, :t)');
            $ins->execute(array(':ip' => $ip, ':t' => $now));
        } else {
            $up = $pdo->prepare('UPDATE admin_login_attempt SET fails = fails + 1, last_time = :t WHERE ip = :ip');
            $up->execute(array(':t' => $now, ':ip' => $ip));
        }
    });
}

/** 登录成功后清空该 IP 的失败计数。 */
function admin_login_clear_fail(string $ip): void
{
    $pdo = db_connect();
    $st = $pdo->prepare('DELETE FROM admin_login_attempt WHERE ip = :ip');
    $st->execute(array(':ip' => $ip));
}

/** 账号格式：3-32 位字母数字下划线点中划线。 */
function admin_username_valid(string $u): bool
{
    return (bool)preg_match('#^[a-zA-Z0-9_.-]{3,32}$#', $u);
}

/** 密码格式：8-128 字节。 */
function admin_password_valid(string $p): bool
{
    $len = strlen($p);
    return $len >= 8 && $len <= 128;
}

/** 为指定账号签发会话令牌，返回明文 token（只在本次响应中出现）。 */
function admin_issue_session(int $userId): array
{
    $now = time();
    $ttl = admin_session_ttl();
    $token = bin2hex(random_bytes(32));

    $pdo = db_connect();
    // 登录时顺手清理过期会话，避免会话表无限增长
    $clean = $pdo->prepare('DELETE FROM admin_session WHERE expire_time <= :now');
    $clean->execute(array(':now' => $now));

    $st = $pdo->prepare('INSERT INTO admin_session (token_hash, user_id, create_time, expire_time)
                         VALUES (:h, :uid, :c, :e)');
    $st->execute(array(
        ':h'   => hash('sha256', $token),
        ':uid' => $userId,
        ':c'   => $now,
        ':e'   => $now + $ttl,
    ));

    return array('token' => $token, 'expire_time' => $now + $ttl);
}

// ============ 账号与登录接口 ============

/** GET admin/session —— 会话状态（前端登录守卫用，无需登录）。 */
function admin_auth_session()
{
    admin_check_method(array('GET'));
    $needInit = (admin_user_count() === 0);
    $user = $needInit ? null : admin_current_user();

    json_ok(array(
        'need_init' => $needInit,
        'logged_in' => $user !== null,
        'username'  => $user !== null ? $user['username'] : null,
    ));
}

/** POST admin/init —— 首次初始化管理员账号（仅当系统中还没有任何账号）。 */
function admin_auth_init()
{
    admin_check_method(array('POST'));
    $p = admin_post_params();

    $username = isset($p['username']) ? trim((string)$p['username']) : '';
    $password = isset($p['password']) ? (string)$p['password'] : '';

    if (admin_user_count() > 0) {
        usleep(300 * 1000);
        json_error(403, '管理员账号已存在');
    }
    if (!admin_username_valid($username)) {
        admin_denied_400('账号无效（3-32 位字母数字下划线点中划线）');
    }
    if (!admin_password_valid($password)) {
        admin_denied_400('密码长度需 8-128 位');
    }

    $pdo = db_connect();
    $now = time();
    $st = $pdo->prepare('INSERT INTO admin_user (username, password_hash, create_time, update_time)
                         VALUES (:u, :h, :c, :c2)');
    try {
        $st->execute(array(
            ':u'  => $username,
            ':h'  => password_hash($password, PASSWORD_DEFAULT),
            ':c'  => $now,
            ':c2' => $now,
        ));
    } catch (PDOException $e) {
        error_log('[wenzi-api] admin init failed: ' . $e->getMessage());
        json_error(500, '初始化失败，请重试');
    }

    $userId = (int)$pdo->lastInsertId();
    $session = admin_issue_session($userId);

    json_ok(array(
        'token'       => $session['token'],
        'username'    => $username,
        'expire_time' => $session['expire_time'],
    ));
}

/** POST admin/login —— 账号 + 密码登录，返回会话令牌。 */
function admin_auth_login()
{
    admin_check_method(array('POST'));
    $p = admin_post_params();

    $username = isset($p['username']) ? trim((string)$p['username']) : '';
    $password = isset($p['password']) ? (string)$p['password'] : '';

    $ip = admin_client_ip();
    if (admin_login_blocked($ip)) {
        usleep(300 * 1000);
        json_error(429, '登录失败次数过多，请 15 分钟后再试');
    }
    if ($username === '' || $password === '') {
        admin_denied_400('请输入账号和密码');
    }
    if (admin_user_count() === 0) {
        json_error(409, '尚未初始化管理员账号');
    }

    $pdo = db_connect();
    $st = $pdo->prepare('SELECT id, username, password_hash FROM admin_user WHERE username = :u LIMIT 1');
    $st->execute(array(':u' => $username));
    $user = $st->fetch();

    if ($user === false || !password_verify($password, (string)$user['password_hash'])) {
        admin_login_record_fail($ip);
        usleep(300 * 1000);
        json_error(401, '账号或密码错误');
    }

    admin_login_clear_fail($ip);

    // 密码哈希算法升级后自动重算
    if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
        $up = $pdo->prepare('UPDATE admin_user SET password_hash = :h, update_time = :t WHERE id = :id');
        $up->execute(array(
            ':h'  => password_hash($password, PASSWORD_DEFAULT),
            ':t'  => time(),
            ':id' => (int)$user['id'],
        ));
    }

    $session = admin_issue_session((int)$user['id']);

    json_ok(array(
        'token'       => $session['token'],
        'username'    => (string)$user['username'],
        'expire_time' => $session['expire_time'],
    ));
}

/** POST admin/logout —— 退出当前会话（需登录）。 */
function admin_auth_logout(array $user)
{
    admin_check_method(array('POST'));
    $pdo = db_connect();
    $st = $pdo->prepare('DELETE FROM admin_session WHERE id = :id');
    $st->execute(array(':id' => (int)$user['session_id']));
    json_ok(null);
}

/** POST admin/password —— 修改密码（需登录 + 校验原密码）。 */
function admin_auth_password(array $user)
{
    admin_check_method(array('POST'));
    $p = admin_post_params();

    $old = isset($p['old_password']) ? (string)$p['old_password'] : '';
    $new = isset($p['new_password']) ? (string)$p['new_password'] : '';

    if (!admin_password_valid($new)) {
        admin_denied_400('新密码长度需 8-128 位');
    }

    $pdo = db_connect();
    $st = $pdo->prepare('SELECT password_hash FROM admin_user WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => (int)$user['user_id']));
    $hash = $st->fetchColumn();

    if ($hash === false || !password_verify($old, (string)$hash)) {
        admin_login_record_fail(admin_client_ip());
        usleep(300 * 1000);
        json_error(401, '原密码错误');
    }

    $up = $pdo->prepare('UPDATE admin_user SET password_hash = :h, update_time = :t WHERE id = :id');
    $up->execute(array(
        ':h'  => password_hash($new, PASSWORD_DEFAULT),
        ':t'  => time(),
        ':id' => (int)$user['user_id'],
    ));

    // 改密后其它设备上的会话立即失效，只保留当前会话
    $del = $pdo->prepare('DELETE FROM admin_session WHERE user_id = :uid AND id <> :sid');
    $del->execute(array(':uid' => (int)$user['user_id'], ':sid' => (int)$user['session_id']));

    json_ok(null);
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

    // 无需登录即可访问：会话状态、首次初始化、登录
    if ($route === 'admin/session') {
        admin_auth_session();
        $route_handled = true;
        return;
    }
    if ($route === 'admin/init') {
        admin_auth_init();
        $route_handled = true;
        return;
    }
    if ($route === 'admin/login') {
        admin_auth_login();
        $route_handled = true;
        return;
    }

    // 其余管理接口一律要求已登录（OPTIONS 已在 index.php 拦截）
    $user = admin_require_login();

    switch ($route) {
        case 'admin/logout':
            admin_auth_logout($user);
            break;
        case 'admin/password':
            admin_auth_password($user);
            break;
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
            error_log('[wenzi-api] admin_api_save update failed: ' . $e->getMessage());
            json_error(500, '服务器错误');
        }
        if ($st->rowCount() === 0) {
            // 可能 id 不存在，或提交内容与原值相同；校验记录是否仍存在。
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
            error_log('[wenzi-api] admin_api_save insert failed: ' . $e->getMessage());
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
        try {
            $st->execute(array(':content' => $content, ':id' => $id, ':api_id' => $apiId));
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'UNIQUE') !== false) {
                admin_denied_400('素材内容已存在');
            }
            error_log('[wenzi-api] admin_text_save update failed: ' . $e->getMessage());
            json_error(500, '服务器错误');
        }
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
        try {
            $st->execute(array(':api_id' => $apiId, ':content' => $content));
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'UNIQUE') !== false) {
                admin_denied_400('素材内容已存在');
            }
            error_log('[wenzi-api] admin_text_save insert failed: ' . $e->getMessage());
            json_error(500, '服务器错误');
        }
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

    // 清洗输入并统计无效行；空行和超长行不再静默丢失。
    $toInsert = array();
    $seenInBatch = array();
    $skipped = 0;
    $invalid = 0;
    foreach ($contents as $c) {
        $c = trim((string)$c);
        if ($c === '' || slen($c) > 5000) {
            $invalid++;
            continue;
        }
        if (isset($seenInBatch[$c])) {
            $skipped++;
            continue;
        }
        $toInsert[] = $c;
        $seenInBatch[$c] = true;
    }

    // 读取该 API 下已有内容。即使前端选择“不去重”，数据库唯一约束也不允许重复；
    // 后续 INSERT OR IGNORE 会把并发冲突准确计入 skipped。
    $existing = array();
    $st = $pdo->prepare('SELECT content FROM api_text WHERE api_id = :api_id');
    $st->execute(array(':api_id' => $apiId));
    while ($row = $st->fetch()) {
        $existing[$row['content']] = true;
    }
    if ($skipDuplicate) {
        $filtered = array();
        foreach ($toInsert as $c) {
            if (isset($existing[$c])) {
                $skipped++;
                continue;
            }
            $filtered[] = $c;
        }
        $toInsert = $filtered;
    }

    if (empty($toInsert)) {
        json_ok(array('inserted' => 0, 'skipped' => $skipped, 'invalid' => $invalid));
    }

    $inserted = 0;
    try {
        db_transaction($pdo, function ($pdo) use ($apiId, $toInsert, &$inserted, &$skipped) {
            $st = $pdo->prepare('INSERT OR IGNORE INTO api_text (api_id, content) VALUES (:api_id, :content)');
            foreach ($toInsert as $c) {
                $st->execute(array(':api_id' => $apiId, ':content' => $c));
                if ($st->rowCount() > 0) {
                    $inserted++;
                } else {
                    // 既有数据或并发请求刚插入：唯一约束导致忽略，计入跳过。
                    $skipped++;
                }
            }
        });
    } catch (PDOException $e) {
        error_log('[wenzi-api] admin_text_batch_save failed: ' . $e->getMessage());
        json_error(500, '批量导入失败，请稍后重试');
    }

    json_ok(array('inserted' => $inserted, 'skipped' => $skipped, 'invalid' => $invalid));
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