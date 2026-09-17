# 文字 API（旧识桥 api）

原生 PHP + SQLite 的文字 API 管理系统。前后端分离，一键安装，支持随机文字与模板两种接口类型。

## 特性

- **原生 PHP + SQLite**：无需 MySQL，一个目录 + 一个 `.db` 文件即可运行
- **一键安装向导**：Web 表单或 CLI 都能装，自动生成 TOKEN / API 密钥、建库建表
- **两类接口**
  - `random_text`：从素材池随机返回一条
  - `template`：模板变量替换，如 `你好，{{name}}！`
- **管理后台**：接口 CRUD、素材管理、调用日志（含 IP、时间、参数）
- **安全**：管理员 TOKEN 鉴权、CORS 白名单、PDO 预处理、防目录列表 `.htaccess`
- **数据库锁重试**：SQLite 并发写入自动重试（5 次递增退避）

## 目录结构

```
.
├── .htaccess             # 根目录：禁止列目录、指定索引文件
├── index.php             # 前台门户
├── portal.html           # 使用文档 / 介绍页
├── api/                  # 后端 API（对外目录名，可改名后需同步 index.php）
│   ├── .htaccess
│   ├── index.php         # 统一入口（router）
│   ├── install.php       # 一键安装脚本（Web + CLI）
│   ├── config.php        # 由 install.php 生成
│   ├── db.php            # PDO + 建表 + 锁重试
│   └── handlers/
│       ├── admin.php     # 管理后台接口
│       ├── public.php    # 对外调用接口
│       └── runtime.php   # 运行时逻辑
└── admin/                # 管理后台前端
    ├── index.html        # 首页
    ├── edit-api.html     # 编辑接口
    ├── text-manage.html  # 素材管理
    ├── log.html          # 调用日志
    └── js/app.js
```

## 安装

### 环境要求

- PHP ≥ 7.4
- 扩展：PDO、pdo_sqlite
- 目录：`api/` 需可写（存放 `config.php`、`api.db`、`install.lock`）

### Web 安装

把整个目录上传到 PHP 主机后，访问：

```
https://你的域名/api/install.php
```

按表单填写（可点"自动生成"），提交后：

1. 生成 `api/config.php`
2. 创建 `api/api.db` 并建三张表
3. 写入 `api/install.lock` 标记已安装

**安装成功后请删除 `install.php`，或至少保留 `install.lock`。**

### CLI 安装

```bash
php api/install.php \
  --admin-token=YourAdminToken16chars \
  --api-key=YourApiKey16chars \
  --origins=https://example.com \
  --sample=1
```

TOKEN / 密钥长度 16-128，字符集 `[a-zA-Z0-9_-]`。

## 使用

### 管理接口

所有管理接口均需请求头 `X-Admin-Token: <ADMIN_TOKEN>`。

```
GET  /api/index.php?route=admin/api/list
POST /api/index.php?route=admin/api/save     # 新增或更新
POST /api/index.php?route=admin/api/delete
GET  /api/index.php?route=admin/text/list
POST /api/index.php?route=admin/text/save
POST /api/index.php?route=admin/text/delete
GET  /api/index.php?route=admin/log/list
```

后台页面：`/admin/index.html`（填写 TOKEN 即可进入）。

### 对外调用

```
GET /api/index.php?route=runtime&path=hello&key=<API_ACCESS_KEY>&name=张三
```

返回示例：

```json
{"ok":true,"data":"你好，张三！"}
```

CORS：`api/config.php` 中的 `$ALLOWED_ORIGINS` 控制，数组形式，`'*'` 表示全部放行。

## 配置

编辑 `api/config.php`：

```php
define('ADMIN_TOKEN', '...');         // 管理后台 TOKEN
define('API_ACCESS_KEY', '...');      // 对外调用密钥
$ALLOWED_ORIGINS = ['*'];             // CORS 白名单
define('DB_PATH', __DIR__ . '/api.db');
define('TRUST_X_FORWARDED_FOR', false); // 是否信任 X-Forwarded-For
```

保存即生效（短进程模式，无需重启）。

## 数据库

SQLite，位于 `api/api.db`。三张表：

- `api_config`：接口配置（path / name / type / content / enabled）
- `api_text`：素材表（外键 → api_config）
- `api_log`：调用日志（api_id / ip / call_time / params）

首次访问自动建表；`db.php` 内含 `PRAGMA foreign_keys=ON`、`busy_timeout=5000`，并封装了 `db_retry()` / `db_transaction()` 处理锁冲突。

## 安全提示

- 生产环境务必修改 `ADMIN_TOKEN` 与 `API_ACCESS_KEY`
- 安装完成后删除 `api/install.php`（或保留 `install.lock`）
- 关闭 `$ALLOWED_ORIGINS = ['*']`，改为具体域名
- 若前面有反向代理（Nginx / Caddy），设置 `TRUST_X_FORWARDED_FOR = true` 以获取真实 IP

## License

MIT
