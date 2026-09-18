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

所有管理接口均需请求头 `X-Admin-Token: <ADMIN_TOKEN>`，也兼容 `Authorization: Bearer <ADMIN_TOKEN>`。

| 方法 | 路由 | 说明 |
|---|---|---|
| GET | `/api/index.php?route=admin/api/list` | API 列表，支持分页、关键词、启用状态筛选 |
| GET | `/api/index.php?route=admin/api/get&id=1` | 获取单个 API 详情 |
| POST | `/api/index.php?route=admin/api/save` | 新增或更新 API |
| POST | `/api/index.php?route=admin/api/delete` | 删除 API，同时级联删除素材 |
| POST | `/api/index.php?route=admin/api/toggle` | 启用或禁用 API |
| GET | `/api/index.php?route=admin/text/list&api_id=1` | 素材列表，支持分页和关键词筛选 |
| POST | `/api/index.php?route=admin/text/save` | 新增或更新单条素材 |
| POST | `/api/index.php?route=admin/text/delete` | 删除单条素材 |
| POST | `/api/index.php?route=admin/text/batch-delete` | 批量删除素材 |
| POST | `/api/index.php?route=admin/text/batch-save` | 批量导入素材，可选择跳过重复项 |
| GET | `/api/index.php?route=admin/log/list` | 调用日志，支持 API、IP、时间范围筛选 |
| POST | `/api/index.php?route=admin/log/clear` | 清空全部或指定 API 的调用日志 |

写操作的 JSON 请求体示例：

```json
{
  "path": "welcome",
  "name": "欢迎",
  "type": "template",
  "content": "你好，{{name}}！",
  "enabled": 1
}
```

后台页面：`/admin/index.html`（填写 TOKEN 即可进入）。

### 公开统计

统计接口无需管理员 TOKEN：

```text
GET /api/index.php?route=stats
```

仅返回四项汇总数字：API 总数、素材总数、调用总数和今日调用数；不返回 API 路径、名称、类型、启用状态、素材数、调用数或其他接口明细。数据库文件不存在或统计读取失败时返回 `503`，不会因公开请求创建数据库或表。

### 对外调用

对外调用需要 API 密钥，可通过 `key` 查询参数或 `X-API-Key` 请求头传入：

```text
GET /api/index.php?route=runtime&path=hello&key=<API_ACCESS_KEY>&name=张三
```

也可以使用请求头：

```text
GET /api/index.php?route=runtime&path=hello&name=张三
X-API-Key: <API_ACCESS_KEY>
```

`random_text` 接口随机返回素材文本；`template` 接口将 `{{name}}` 等变量替换为查询参数值，未传入的变量替换为空。成功时返回 `text/plain` 纯文本，错误时返回 JSON：

```text
你好，张三！
```

```json
{"code":403,"msg":"key 错误","data":null}
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

业务入口会在数据库文件已存在时幂等检查并补齐表结构；未安装时公开 `stats` 会先返回 `503`，不会因公开请求创建数据库。安装脚本负责首次创建数据库和表；`db.php` 内含 `PRAGMA foreign_keys=ON`、`busy_timeout=5000`，并封装了 `db_retry()` / `db_transaction()` 处理锁冲突。

## 安全提示

- 生产环境务必修改 `ADMIN_TOKEN` 与 `API_ACCESS_KEY`
- 安装完成后删除 `api/install.php`（或保留 `install.lock`）
- 关闭 `$ALLOWED_ORIGINS = ['*']`，改为具体域名
- 若前面有反向代理（Nginx / Caddy），设置 `TRUST_X_FORWARDED_FOR = true` 以获取真实 IP

## License

MIT
