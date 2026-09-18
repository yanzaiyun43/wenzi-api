<?php
/**
 * 旧识桥 api · api/install.php（一键安装脚本）
 * ----------------------------------------------------------------
 * 访问方式：https://你的域名/api/install.php
 * 流程：
 *   1. install.lock 已存在 -> 拒绝安装
 *   2. 环境检查（PHP>=7.4、PDO、pdo_sqlite、目录可写等）
 *   3. 显示表单：管理员账号 / 密码（可自动生成）/ 允许来源 / 示例 API 开关
 *   4. 一键安装：生成 config.php（var_export，禁拼接）、创建 api.db、建表、
 *      写入管理员账号（password_hash）、install.lock；
 *      config.php 已存在且无 lock 时二次确认覆盖
 *   5. 安装成功后提示删除 install.php 或保留 install.lock
 *
 * 对外接口不再需要密钥（key）；后台改为「账号 + 密码」登录。
 * 重装会重置管理员账号与密码（原有数据保留，示例素材幂等写入）。
 *
 * 可选 CLI：php api/install.php --admin-user=admin --admin-pass=xxxx --origins=https://example.com
 * 禁止 eval/exec/system/shell_exec 等危险函数；禁止执行用户输入代码。
 * ----------------------------------------------------------------
 */

// 本脚本独立运行：定义 APP_INSTALL 常量供 config.php / db.php 放行
define('APP_INSTALL', true);

// —— 检测 PHP CLI 模式 ——
$IS_CLI = (PHP_SAPI === 'cli');

// —— 安装锁检查（Web 与 CLI 都先查）——
$lockFile = __DIR__ . '/install.lock';
if (file_exists($lockFile)) {
    if ($IS_CLI) {
        fwrite(STDERR, "系统已安装，请删除 install.lock 后重装\n");
        exit(1);
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh"><meta charset="utf-8"><title>已安装</title>'
       . '<body style="font-family:sans-serif;padding:40px"><h2>系统已安装</h2>'
       . '<p>请删除 install.php，或手动删除 install.lock 后重装。</p></body></html>';
    exit;
}

// —— 自动生成密码工具 ——
function install_rand_password($len = 20)
{
    // random_int 保证每个字符在字母表内均匀分布，无模运算偏差（PHP>=7.0 均可用）
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

// —— 校验输入 ——
function install_validate_username($v)
{
    return is_string($v) && preg_match('#^[a-zA-Z0-9_.\-]{3,32}$#', $v);
}
function install_validate_password($v)
{
    if (!is_string($v)) {
        return false;
    }
    $len = strlen($v);
    return $len >= 8 && $len <= 128;
}
function install_validate_origin($v)
{
    $v = trim($v);
    if ($v === '*') {
        return true;
    }
    // URL 校验：协议://域名[:端口]（不带末尾斜杠）
    if (!filter_var($v, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parts = parse_url($v);
    if (!isset($parts['scheme'], $parts['host'])) {
        return false;
    }
    // 禁止换行/控制字符
    if (preg_match('/[\r\n\t]/', $v)) {
        return false;
    }
    return true;
}
function install_parse_origins($raw)
{
    // 逗号分隔，去空白，过滤空项
    $parts = explode(',', (string)$raw);
    $out = array();
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

// —— 环境检查 ——
function install_env_checks()
{
    $problems = array();

    if (version_compare(PHP_VERSION, '7.4.0', '<')) {
        $problems[] = 'PHP 版本需 >= 7.4（当前 ' . PHP_VERSION . '）';
    }
    if (!extension_loaded('PDO')) {
        $problems[] = '缺少 PDO 扩展';
    }
    if (!extension_loaded('pdo_sqlite')) {
        $problems[] = '缺少 pdo_sqlite 扩展';
    }
    if (!function_exists('password_hash')) {
        $problems[] = '缺少 password_hash（PHP 需带标准密码哈希支持）';
    }
    if (!is_dir(__DIR__) || !is_writable(__DIR__)) {
        $problems[] = 'api/ 目录不可写';
    }
    $dbFile = __DIR__ . '/api.db';
    if (file_exists($dbFile) && !is_writable($dbFile)) {
        $problems[] = 'api.db 不可写';
    }
    $cfgFile = __DIR__ . '/config.php';
    if (file_exists($cfgFile) && !is_writable($cfgFile)) {
        $problems[] = 'config.php 不可写';
    }

    return $problems;
}

// —— 生成 config.php 内容（var_export，禁止拼接用户输入）——
// 管理员账号存在数据库里（密码只存哈希），配置文件不再包含任何密钥。
function install_build_config(array $origins)
{
    $originExpr = var_export($origins, true);

    return <<<PHP
<?php
/**
 * 旧识桥 api · api/config.php（由 install.php 生成）
 * 可手动编辑以下配置；保存即生效，无需重启（短进程模式）。
 * 管理员账号与密码不在本文件：请在后台登录页使用账号 + 密码登录。
 */

if (!defined('APP_ENTRY') && !defined('APP_INSTALL')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}

\$ALLOWED_ORIGINS = {$originExpr};

define('DB_PATH', __DIR__ . '/api.db');

define('TRUST_X_FORWARDED_FOR', false);

// 调用日志保留天数；设为 0 可关闭自动清理。
define('LOG_RETENTION_DAYS', 90);

// 后台登录会话有效期（秒），默认 7 天。
define('ADMIN_SESSION_TTL', 604800);
PHP;
}

// —— 执行安装（生成 config / 建库建表 / 写管理员账号 / 写锁）——
function install_run($username, $password, array $origins, $withSample)
{
    $cfgFile  = __DIR__ . '/config.php';
    $dbFile   = __DIR__ . '/api.db';
    $lockFile = __DIR__ . '/install.lock';
    $cfgTmp   = tempnam(__DIR__, '.config.php.');
    $lockTmp  = tempnam(__DIR__, '.install.lock.');
    $cfgBackup = null;
    $configInstalled = false;
    $lockInstalled = false;
    $dbExisted = is_file($dbFile);
    $inTransaction = false;
    $pdo = null;

    if ($cfgTmp === false || $lockTmp === false) {
        if ($cfgTmp !== false) @unlink($cfgTmp);
        if ($lockTmp !== false) @unlink($lockTmp);
        return array(false, '无法创建安装临时文件');
    }

    try {
        // 先写临时配置和临时锁，安装成功前不触碰正式配置/锁文件。
        $code = install_build_config($origins);
        if (file_put_contents($cfgTmp, $code, LOCK_EX) === false) {
            throw new RuntimeException('config.php 写入失败');
        }
        @chmod($cfgTmp, 0600);
        if (file_put_contents($lockTmp, date('c'), LOCK_EX) === false) {
            throw new RuntimeException('install.lock 写入失败');
        }
        @chmod($lockTmp, 0600);

        // 数据库初始化 + 管理员账号 + 示例数据，全部放在同一事务里；
        // 任一步失败（含后面的文件安装失败）都回滚，不留半套状态。
        require_once __DIR__ . '/db.php';
        $pdo = db_connect();
        $pdo->beginTransaction();
        $inTransaction = true;

        install_write_admin($pdo, $username, $password);
        if ($withSample) {
            install_sample_data($pdo);
        }

        // 覆盖旧配置前先留备份，后续任一步失败都恢复旧文件。
        if (is_file($cfgFile)) {
            $cfgBackup = tempnam(__DIR__, '.config.php.backup.');
            if ($cfgBackup === false || !@unlink($cfgBackup) || !@rename($cfgFile, $cfgBackup)) {
                throw new RuntimeException('原 config.php 备份失败');
            }
        }
        if (!@rename($cfgTmp, $cfgFile)) {
            throw new RuntimeException('config.php 安装失败');
        }
        $configInstalled = true;

        if (!@rename($lockTmp, $lockFile)) {
            throw new RuntimeException('install.lock 安装失败');
        }
        $lockInstalled = true;

        $pdo->commit();
        $inTransaction = false;

        if ($cfgBackup !== null) {
            @unlink($cfgBackup);
            $cfgBackup = null;
        }
        return array(true, 'ok');
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[wenzi-api] install failed: ' . $e->getMessage());
        if ($lockInstalled) {
            @unlink($lockFile);
        }
        if ($configInstalled) {
            @unlink($cfgFile);
        }
        if ($cfgBackup !== null && is_file($cfgBackup)) {
            @rename($cfgBackup, $cfgFile);
        }
        // 新安装失败时删除本次创建的 SQLite 文件及 WAL 临时文件；已有数据库不触碰。
        if (!$dbExisted) {
            @unlink($dbFile);
            @unlink($dbFile . '-wal');
            @unlink($dbFile . '-shm');
        }
        return array(false, '安装失败，请检查目录权限和数据库状态');
    } finally {
        if (is_file($cfgTmp)) @unlink($cfgTmp);
        if (is_file($lockTmp)) @unlink($lockTmp);
        if ($cfgBackup !== null && is_file($cfgBackup)) @unlink($cfgBackup);
    }
}

/**
 * 写入/重置管理员账号（密码只存哈希）。重装时清空旧账号与会话。
 */
function install_write_admin(PDO $pdo, $username, $password)
{
    $now = time();
    // 外键 ON DELETE CASCADE 会一并清掉旧会话
    $pdo->exec('DELETE FROM admin_user');
    $st = $pdo->prepare('INSERT INTO admin_user (username, password_hash, create_time, update_time)
                         VALUES (:u, :h, :c, :c2)');
    $st->execute(array(
        ':u'  => $username,
        ':h'  => password_hash($password, PASSWORD_DEFAULT),
        ':c'  => $now,
        ':c2' => $now,
    ));
}

function install_sample_data($pdo)
{
    $now = time();
    // 示例 template API
    $st = $pdo->prepare("INSERT OR IGNORE INTO api_config (path, name, type, content, enabled, create_time)
                        VALUES ('hello', '打招呼', 'template', '你好，{{name}}！', 1, :t)");
    $st->execute(array(':t' => $now));

    // 示例 random_text API
    $st = $pdo->prepare("INSERT OR IGNORE INTO api_config (path, name, type, content, enabled, create_time)
                        VALUES ('daily', '每日一句', 'random_text', '', 1, :t)");
    $st->execute(array(':t' => $now));

    // 给 daily 加两条素材
    $pid = $pdo->prepare("SELECT id FROM api_config WHERE path='daily'");
    $pid->execute();
    $dailyId = (int)$pid->fetchColumn();
    if ($dailyId > 0) {
        $ins = $pdo->prepare('INSERT OR IGNORE INTO api_text (api_id, content) VALUES (:aid, :content)');
        $ins->execute(array(':aid' => $dailyId, ':content' => '今天也要加油哦！'));
        $ins->execute(array(':aid' => $dailyId, ':content' => '愿你被世界温柔以待。'));
    }
}

// ============================================================
//  CLI 模式
// ============================================================
if ($IS_CLI) {
    $args = array();
    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            $eq = strpos($arg, '=');
            if ($eq !== false) {
                $args[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
            } else {
                $args[substr($arg, 2)] = 'true';
            }
        }
    }

    $err = null;
    $adminUser  = isset($args['admin-user']) ? trim((string)$args['admin-user']) : '';
    $adminPass  = isset($args['admin-pass']) ? (string)$args['admin-pass'] : '';
    $originsRaw = isset($args['origins']) ? (string)$args['origins'] : '';
    $sampleRaw  = isset($args['sample']) ? (string)$args['sample'] : '1';

    if (!install_validate_username($adminUser)) {
        $err = 'admin-user 无效（需 [a-zA-Z0-9_.-]{3,32}）';
    } elseif (!install_validate_password($adminPass)) {
        $err = 'admin-pass 无效（需 8-128 位）';
    } else {
        $origins = install_parse_origins($originsRaw);
        if (empty($origins)) {
            $err = 'origins 无效（需至少一个来源）';
        } else {
            foreach ($origins as $o) {
                if (!install_validate_origin($o)) {
                    $err = 'origins 含无效项：' . $o;
                    break;
                }
            }
        }
    }
    if ($err) {
        fwrite(STDERR, $err . "\n");
        exit(2);
    }

    // withSample：默认 true，仅当 sample=0 或 false 时关闭
    $withSample = !in_array($sampleRaw, array('0', 'false'), true);

    list($ok, $msg) = install_run($adminUser, $adminPass, $origins, $withSample);
    if (!$ok) {
        fwrite(STDERR, "安装失败：$msg\n");
        exit(1);
    }
    fwrite(STDOUT, "安装成功\n");
    fwrite(STDOUT, "管理员账号：$adminUser\n");
    fwrite(STDOUT, "管理员密码：$adminPass\n");
    fwrite(STDOUT, "管理后台：https://你的域名/admin/\n");
    fwrite(STDOUT, "统计门户：https://你的域名/\n");
    fwrite(STDOUT, "调用示例：https://你的域名/api/index.php?route=runtime&path=hello&name=张三\n");
    fwrite(STDOUT, "请删除 install.php 或保留 install.lock\n");
    exit(0);
}

// ============================================================
//  Web 模式
// ============================================================

// 处理表单提交
$submitted = ($_SERVER['REQUEST_METHOD'] === 'POST');
$resultOk = false;
$resultMsg = '';
$showConfirm = false;   // config.php 已存在且无 lock 时二次确认
$formData = array(
    'admin_user' => '',
    'admin_pass' => '',
    'origins'    => 'http://localhost',
    'sample'     => '1',
);

if ($submitted) {
    // 读取输入（密码不 trim，避免用户特意使用的空白被吃掉）
    $formData['admin_user'] = isset($_POST['admin_user']) ? trim((string)$_POST['admin_user']) : '';
    $formData['admin_pass'] = isset($_POST['admin_pass']) ? (string)$_POST['admin_pass'] : '';
    $formData['origins']    = isset($_POST['origins']) ? trim((string)$_POST['origins']) : '';
    $formData['sample']     = isset($_POST['sample']) ? (string)$_POST['sample'] : '0';
    $passConfirm            = isset($_POST['admin_pass2']) ? (string)$_POST['admin_pass2'] : '';

    // 二次确认覆盖：config.php 已存在且无 lock 时，需确认参数
    // 取消按钮也会提交 confirm=1，因此必须优先判断 go=cancel。
    $cancelled = (isset($_POST['go']) && $_POST['go'] === 'cancel');
    $confirmed = (isset($_POST['confirm']) && $_POST['confirm'] === '1');

    $configExists = file_exists(__DIR__ . '/config.php');
    if ($cancelled) {
        // 用户取消覆盖，不执行安装
        $resultMsg = '已取消覆盖安装';
    } elseif ($configExists && !$confirmed) {
        // 需二次确认，不执行
        $showConfirm = true;
        $resultMsg = 'config.php 已存在，是否覆盖安装？';
    } else {
        // 校验
        $err = null;
        if (!install_validate_username($formData['admin_user'])) {
            $err = '管理员账号无效（需 3-32 位字母数字下划线点中划线）';
        } elseif (!install_validate_password($formData['admin_pass'])) {
            $err = '管理员密码无效（需 8-128 位）';
        } elseif ($passConfirm !== $formData['admin_pass']) {
            $err = '两次输入的密码不一致';
        } else {
            $origins = install_parse_origins($formData['origins']);
            if (empty($origins)) {
                $err = '允许来源无效（需至少一个来源）';
            } else {
                foreach ($origins as $o) {
                    if (!install_validate_origin($o)) {
                        $err = '允许来源含无效项：' . htmlspecialchars($o);
                        break;
                    }
                }
            }
        }

        if ($err) {
            $resultMsg = $err;
        } else {
            $withSample = ($formData['sample'] === '1');
            list($ok, $msg) = install_run($formData['admin_user'], $formData['admin_pass'], $origins, $withSample);
            $resultOk = $ok;
            $resultMsg = $msg;
        }
    }
}

// 环境检查（无论是否提交都显示，失败则只提示）
$envProblems = install_env_checks();
$envOk = empty($envProblems);

// 未提交时给默认自动生成值（仅当表单空）
if (!$submitted) {
    $formData['admin_user'] = 'admin';
    $formData['admin_pass'] = install_rand_password(20);
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>旧识桥 api 安装</title>
<style>
  /* 与 admin/css/theme.css 同色板：米白底 #F7F7F5、墨蓝灰主色 #334155、灰边框 #E5E7EB */
  :root{
    --c-primary:#334155; --c-primary-dark:#1F2937;
    --c-border:#E5E7EB; --c-border-light:#EEF0F2;
    --c-text:#1F2328; --c-text-2:#4B5563; --c-text-3:#6B7280;
    --c-page:#F7F7F5; --c-card:#FFFFFF; --c-hover:#F3F4F6;
    --c-danger:#B91C1C; --c-ok-bg:#F0F4F0; --c-ok-tx:#3F5A3F; --c-ok-bd:#D5E0D5;
    --c-err-bg:#F9EEEE; --c-err-tx:#9E2F2F; --c-err-bd:#E8CFCF;
    --c-warn-bg:#F7F4EC; --c-warn-tx:#7A6A3C; --c-warn-bd:#E8E0CC;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--c-page);color:var(--c-text);
    font-family:"PingFang SC","Microsoft YaHei","Noto Sans SC",-apple-system,sans-serif;font-size:14px;line-height:1.65;-webkit-font-smoothing:antialiased}
  code{font-family:"JetBrains Mono",Consolas,"Courier New",monospace}
  .box{max-width:600px;margin:0 auto;background:var(--c-card);border:1px solid var(--c-border);border-radius:8px;padding:28px 32px;margin-top:40px}
  h1{font-size:19px;font-weight:600;margin:0 0 4px;letter-spacing:.5px}
  .sub{color:var(--c-text-3);font-size:13px;margin-bottom:22px}
  label{display:block;margin:16px 0 6px;font-weight:500;font-size:14px;color:var(--c-text-2)}
  .hint{color:var(--c-text-3);font-size:12px;font-weight:400}
  input[type=text],input[type=password],textarea{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--c-border);border-radius:6px;font-size:14px;font-family:inherit;background:var(--c-card);color:var(--c-text);transition:border-color .15s}
  input:focus,textarea:focus{outline:none;border-color:var(--c-primary);box-shadow:0 0 0 1px var(--c-primary) inset}
  textarea{min-height:64px;resize:vertical}
  .row{display:flex;gap:8px}
  .row input{flex:1}
  .pw-wrap{position:relative;flex:1}
  .pw-wrap input{padding-right:40px}
  .eye{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:none;background:transparent;color:var(--c-text-3);cursor:pointer;font-size:13px;padding:4px}
  .eye:hover{color:var(--c-primary)}
  button{background:var(--c-primary);color:#fff;border:none;padding:11px 18px;border-radius:6px;font-size:15px;cursor:pointer;margin-top:20px;transition:background .15s}
  button:hover{background:var(--c-primary-dark)}
  .btn-mini{background:var(--c-card);color:var(--c-primary);border:1px solid var(--c-primary);border-radius:6px;padding:9px 14px;cursor:pointer;font-size:13px;white-space:nowrap;margin-top:0;transition:background .15s}
  .btn-mini:hover{background:var(--c-hover)}
  .btn-ghost{background:var(--c-card);color:var(--c-text-2);border:1px solid var(--c-border);border-radius:6px;padding:11px 18px;cursor:pointer;font-size:15px;margin-top:20px}
  .btn-ghost:hover{background:var(--c-hover)}
  .ok{background:var(--c-ok-bg);color:var(--c-ok-tx);border:1px solid var(--c-ok-bd);padding:12px 14px;border-radius:6px;font-size:14px;margin-top:16px;white-space:pre-line}
  .err{background:var(--c-err-bg);color:var(--c-err-tx);border:1px solid var(--c-err-bd);padding:12px 14px;border-radius:6px;font-size:14px;margin-top:16px}
  .warn{background:var(--c-warn-bg);color:var(--c-warn-tx);border:1px solid var(--c-warn-bd);padding:12px 14px;border-radius:6px;font-size:14px;margin-top:16px}
  ul.check{list-style:none;padding:0;margin:12px 0}
  ul.check li{padding:4px 0;font-size:14px}
  .pass{color:var(--c-ok-tx)}.fail{color:var(--c-danger)}
  code{background:var(--c-hover);padding:1px 6px;border-radius:4px;font-size:13px;word-break:break-all}
  .radio-row{margin-top:6px}
  .radio-row label{display:inline;font-weight:400;margin:0 18px 0 0;color:var(--c-text-2)}
  @media (max-width:480px){.box{padding:20px 16px;margin-top:16px}}
</style>
</head>
<body>
<div class="box">
  <h1>旧识桥 api 一键安装</h1>
  <div class="sub">原生 PHP + SQLite，前后端分离文字 API 管理系统</div>

  <?php if (!$envOk): ?>
    <div class="err"><b>环境检查未通过</b>
      <ul class="check">
        <?php foreach ($envProblems as $p): ?>
          <li class="fail">✗ <?php echo htmlspecialchars($p); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php else: ?>

    <?php if ($resultOk): ?>
      <div class="ok">
        <b>✔ 安装成功！</b>
        管理员账号：<code><?php echo htmlspecialchars($formData['admin_user']); ?></code>
        管理员密码：<code><?php echo htmlspecialchars($formData['admin_pass']); ?></code>

        管理后台：<code>admin/</code>（用上面的账号 + 密码登录）
        统计门户：<code>/</code>（展示调用统计和全部接口清单）
        调用示例：<code>api/index.php?route=runtime&amp;path=hello&amp;name=张三</code>

        对外接口无需密钥，任何知道地址的人都可以调用。
        <b>请立即删除 install.php，或至少保留 install.lock。</b>
        重装会重置管理员账号密码，请在后台「修改密码」处改成自己记得住的密码。
      </div>
    <?php else: ?>
      <?php if ($resultMsg !== ''): ?>
        <div class="<?php echo $showConfirm ? 'warn' : 'err'; ?>"><?php echo htmlspecialchars($resultMsg); ?></div>
      <?php endif; ?>

      <form method="post" action="install.php" onsubmit="return validateForm()">
        <?php if ($showConfirm): ?>
          <input type="hidden" name="confirm" value="1">
        <?php endif; ?>

        <label>管理员账号 <span class="hint">3-32 位，字母数字下划线点中划线</span></label>
        <input type="text" name="admin_user" id="admin_user" value="<?php echo htmlspecialchars($formData['admin_user']); ?>" autocomplete="username" placeholder="例如 boss">

        <label>管理员密码 <span class="hint">8-128 位；登录后台用，也可点右侧随机生成</span></label>
        <div class="row">
          <div class="pw-wrap">
            <input type="password" name="admin_pass" id="admin_pass" value="<?php echo htmlspecialchars($formData['admin_pass']); ?>" autocomplete="new-password">
            <button type="button" class="eye" onclick="togglePw(this,'admin_pass')" title="显示/隐藏">👁</button>
          </div>
          <button type="button" class="btn-mini" onclick="randPass()">随机生成</button>
        </div>

        <label>确认密码</label>
        <div class="pw-wrap">
          <input type="password" name="admin_pass2" id="admin_pass2" value="<?php echo htmlspecialchars($formData['admin_pass']); ?>" autocomplete="new-password">
          <button type="button" class="eye" onclick="togglePw(this,'admin_pass2')" title="显示/隐藏">👁</button>
        </div>

        <label>允许的前端来源 <span class="hint">多个用逗号分隔；* 表示全部</span></label>
        <textarea name="origins" id="origins"><?php echo htmlspecialchars($formData['origins']); ?></textarea>

        <label>是否创建示例 API</label>
        <div class="radio-row">
          <label><input type="radio" name="sample" value="1" <?php echo $formData['sample'] === '1' ? 'checked' : ''; ?>> 是</label>
          <label><input type="radio" name="sample" value="0" <?php echo $formData['sample'] === '0' ? 'checked' : ''; ?>> 否</label>
        </div>

        <?php if ($showConfirm): ?>
          <button type="submit" name="go" value="confirm">确认覆盖安装</button>
          <button type="submit" name="go" value="cancel" class="btn-ghost" style="margin-left:8px">取消</button>
        <?php else: ?>
          <button type="submit">安装</button>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
function togglePw(btn,id){
  var el=document.getElementById(id);
  el.type = el.type==='password' ? 'text' : 'password';
}
function randPass(){
  // 字母表 60 个字符（已去除易混淆的 0/O/1/l/i），用 random_int 等价方式生成
  // crypto.getRandomValues 返回 0-255，对 60 取模有偏差；改用 16-bit 拒绝采样消除：
  // 每次取两个字节拼成 16-bit (0-65535)，若 >= 65536 - (65536%60) 则丢弃重取。
  var a='abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  var limit = 65536 - (65536 % a.length); // 65536 % 60 = 16, limit = 65520
  var s='';
  var need = 20;
  while(s.length < need){
    var bytes = crypto.getRandomValues(new Uint8Array(2));
    var v = (bytes[0] << 8) | bytes[1];
    if(v < limit){
      s += a[v % a.length];
    }
  }
  document.getElementById('admin_pass').value=s;
  document.getElementById('admin_pass2').value=s;
}
function validateForm(){
  var u=document.getElementById('admin_user').value.trim();
  var p=document.getElementById('admin_pass').value;
  var p2=document.getElementById('admin_pass2').value;
  if(!/^[a-zA-Z0-9_.-]{3,32}$/.test(u)){alert('管理员账号需 3-32 位字母数字下划线点中划线');return false;}
  if(p.length<8||p.length>128){alert('管理员密码需 8-128 位');return false;}
  if(p!==p2){alert('两次输入的密码不一致');return false;}
  return true;
}
</script>
</body>
</html>
