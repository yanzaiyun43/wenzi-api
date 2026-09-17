<?php
/**
 * 旧识桥 api · backend/db.php
 * ----------------------------------------------------------------
 * SQLite（PDO）连接、初始化建表、锁重试封装。
 * 被统一入口 index.php（APP_ENTRY）与安装脚本 install.php（APP_INSTALL）
 * 共同载入（调用方请使用 require_once）。
 *
 * - 连接必设 PRAGMA foreign_keys = ON、busy_timeout = 5000。
 * - WAL 为可选优化：共享文件系统（如 serv00）不支持时静默回退，不报错。
 * - db_retry()：捕获 PDOException，错误含 database is locked /
 *   database table is locked 时自动重试，最多 5 次，间隔 100/200/300/400/500ms 递增。
 * - 业务 SQL 由调用方使用 PDO 预处理；本文件自身仅含固定建表/PRAGMA 语句。
 * ----------------------------------------------------------------
 */

// 入口守卫：仅允许统一入口或安装脚本载入，浏览器直连返回 403
if (!defined('APP_ENTRY') && !defined('APP_INSTALL')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}

if (!defined('DB_PATH')) {
    define('DB_PATH', __DIR__ . '/api.db');
}
if (!defined('DB_LOCK_MAX_RETRY')) {
    define('DB_LOCK_MAX_RETRY', 5);       // 最多重试 5 次
}
if (!defined('DB_LOCK_BASE_DELAY_MS')) {
    define('DB_LOCK_BASE_DELAY_MS', 100); // 间隔起点 100ms，逐次递增
}

/**
 * 字符串长度（UTF-8 安全）。mbstring 可用时用 mb_strlen，否则回退 strlen。
 * serv00 等环境可能未启用 mbstring，回退保证不报错。
 */
function slen($s)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen((string)$s);
    }
    return strlen((string)$s);
}

/**
 * 建立/复用 PDO SQLite 连接；必要时自动建表（首次访问自动建表）。
 * 连接失败抛 PDOException（如目录不可写），由调用方处理。
 */
function db_connect(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));

    // 必设 PRAGMA：外键约束 + 忙等待超时 5000ms
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    // WAL 可选优化：不支持时静默回退到默认 journal 模式，不能报错
    try {
        $mode = $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();
        if (is_string($mode) && strtolower($mode) === 'wal') {
            // WAL 启用成功
        }
        // 未启用则保持默认模式，静默继续
    } catch (PDOException $e) {
        // 静默回退，不报错
    }

    // 首次访问自动建表（幂等；建表也可能遇到锁，走重试封装）
    db_retry(function () use ($pdo) {
        db_init_tables($pdo);
    });

    return $pdo;
}

/**
 * 建表与索引（幂等，IF NOT EXISTS）。三张表结构以需求文档为准。
 */
function db_init_tables(PDO $pdo)
{
    // 1. api_config 接口配置表
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_config (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        path TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        type TEXT NOT NULL CHECK(type IN ('random_text', 'template')),
        content TEXT DEFAULT '',
        enabled INTEGER NOT NULL DEFAULT 1,
        create_time INTEGER NOT NULL
    )");

    // 2. api_text 文字素材表
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_text (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        api_id INTEGER NOT NULL,
        content TEXT NOT NULL,
        FOREIGN KEY(api_id) REFERENCES api_config(id) ON DELETE CASCADE
    )");

    // 3. api_log 调用日志表
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        api_id INTEGER,
        ip TEXT,
        call_time INTEGER,
        params TEXT,
        FOREIGN KEY(api_id) REFERENCES api_config(id) ON DELETE SET NULL
    )");

    // 索引：api_text.api_id；api_log.api_id、call_time
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_text_api_id   ON api_text(api_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_log_api_id    ON api_log(api_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_log_call_time ON api_log(call_time)');
}

/**
 * SQLite 锁重试封装。
 * 捕获 PDOException：错误信息含 "database is locked" / "database table is locked"
 * 时自动重试，最多 DB_LOCK_MAX_RETRY 次，间隔从 DB_LOCK_BASE_DELAY_MS 起递增
 * （100ms、200ms、300ms、400ms、500ms）。其他异常原样抛出，交由调用方处理。
 *
 * @param callable $fn 要执行的函数，返回值原样返回
 * @return mixed
 * @throws PDOException 非锁异常，或重试耗尽后仍锁定
 */
function db_retry(callable $fn)
{
    $attempts = 0;
    while (true) {
        try {
            return $fn();
        } catch (PDOException $e) {
            $msg = strtolower($e->getMessage());
            $isLock = (strpos($msg, 'database is locked') !== false)
                   || (strpos($msg, 'database table is locked') !== false);
            if (!$isLock || $attempts >= DB_LOCK_MAX_RETRY) {
                throw $e;
            }
            $attempts++;
            usleep(DB_LOCK_BASE_DELAY_MS * 1000 * $attempts);
        }
    }
}

/**
 * 在事务中执行 $fn($pdo)（含锁重试）。
 * $fn 抛出任何异常都会回滚；PDOException 交给 db_retry 按锁规则处理。
 *
 * @return mixed $fn 的返回值
 */
function db_transaction(PDO $pdo, callable $fn)
{
    return db_retry(function () use ($pdo, $fn) {
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    });
}
