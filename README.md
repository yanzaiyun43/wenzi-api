# 文字 API（旧识桥 api）

原生 PHP + SQLite 的文字 API 管理系统。前后端分离，一键安装，支持随机文字与模板两种接口类型。

## 特性

- **原生 PHP + SQLite**：无需 MySQL，一个目录 + 一个 `.db` 文件即可运行
- **一键安装向导**：Web 表单或 CLI 都能装，建库建表并写入管理员账号
- **两类接口**
  - `random_text`：从素材池随机返回一条
  - `template`：模板变量替换，如 `你好，{{name}}！`
- **管理后台**：接口 CRUD、素材管理、调用日志（含 IP、时间、参数）
- **账号密码登录**：后台用管理员账号 + 密码登录换取 64 位会话令牌（哈希存库），不再是固定 TOKEN；同一 IP 15 分钟内失败 5 次会限流 15 分钟
- **改密即踢下线**：修改密码后，同一账号在其他设备上的会话立即失效，仅当前设备保持登录
- **统计门户**：根路径展示调用统计，并列出全部可调用接口，带一键复制
- **对外调用无需密钥**：地址即接口，谁拿到都能调（按需在后台禁用即可停止对外服务）
- **数据库锁重试**：SQLite 并发写入自动重试（5 次递增退避）
- **调用日志自动清理**：按概率触发、单次最多删 1000 行，避免大库首次清理长时间占写锁

## 目录结构

```
.
├── .htaccess             # 根目录：禁止列目录、指定索引文件
├── index.php             # 前台入口（分流到安装向导或统计门户）
├── portal.html           # 统计门户（统计 + 接口清单 + 复制）
├── api/                  # 后端 API
│   ├── .htaccess
│   ├── index.php         # 统一入口（router）
│   ├── install.php       # 一键安装脚本（Web + CLI）
│   ├── config.php        # 由 install.php 生成
│   ├── db.php            # PDO + 建表 + 锁重试
│   └── handlers/
│       ├── admin.php     # 管理后台接口（登录 + 业务）
│       ├── public.php    # 公开统计与接口清单
│       └── runtime.php   # 对外调用逻辑
└── admin/                # 管理后台前端
    ├── login.html        # 登录 / 首次初始化账号
    ├── index.html        # API 列表
    ├── edit-api.html     # 新增 / 编辑接口
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

表单默认已带一个随机生成的 20 位密码（可点「随机生成」换一个，也可手输），填账号密码并提交后：

1. 生成 `api/config.php`
2. 创建 `api/api.db` 并建六张表
3. 写入管理员账号（密码只存哈希）
4. 写入 `api/install.lock` 标记已安装

**安装成功后请删除 `install.php`，或至少保留 `install.lock`。**

### CLI 安装

```bash
php api/install.php \
  --admin-user=admin \
  --admin-pass=你的密码至少8位 \
  --origins=https://example.com \
  --sample=1
```

账号 3-32 位 `[a-zA-Z0-9_.-]`，密码 8-128 位。`--sample=0` 表示不创建示例接口。

### 升级已有部署

已经有数据的老版本（TOKEN + API 密钥）升级后：

- 对外调用直接可用，不再校验 `key`（老地址里多余的 `&key=xxx` 会被忽略）
- 后台首次打开 `/admin/` 会提示「初始化管理员账号」，设置账号密码后即可登录
- 如果更想直接重置，删掉 `api/install.lock` 后访问 `api/install.php` 重装，业务数据保留，账号密码会重置

## 使用

### 管理后台

访问 `/admin/`：

- 已安装但还没有管理员账号，则显示「初始化管理员账号」，设置后直接进入
- 已有账号，则账号 + 密码登录，会话默认 7 天（`ADMIN_SESSION_TTL`），滑动续期（剩余不足一半时刷新）
- 登录后右上角可「修改密码」「退出登录」；修改密码会立即失效该账号在其他设备上的会话，当前设备保持登录
- 同一 IP 15 分钟内登录失败 5 次会被限流 15 分钟（返回 429），改密失败不计入该计数

会话令牌保存在浏览器 `localStorage`，请求时通过 `X-Admin-Token` 头传递（兼容 `Authorization: Bearer <token>`）。令牌 64 位十六进制，数据库只存它的 SHA-256 摘要。

### 管理接口

| 方法 | 路由 | 说明 |
|---|---|---|
| GET | `/api/index.php?route=admin/session` | 会话状态（无需登录，用于前端守卫） |
| POST | `/api/index.php?route=admin/init` | 首次初始化管理员账号（仅当系统还没有账号） |
| POST | `/api/index.php?route=admin/login` | 账号 + 密码登录，返回会话令牌 |
| POST | `/api/index.php?route=admin/logout` | 退出当前会话（需登录） |
| POST | `/api/index.php?route=admin/password` | 修改密码（需登录 + 原密码） |
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

除 `session` / `init` / `login` 外，其余管理接口都需要登录后的会话令牌。

登录与写操作的 JSON 请求体示例：

```json
{
  "path": "welcome",
  "name": "欢迎",
  "type": "template",
  "content": "你好，{{name}}！",
  "enabled": 1
}
```

登录：

```bash
curl -X POST 'https://你的域名/api/index.php?route=admin/login' \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"你的密码"}'
```

### 公开统计与接口清单

```text
GET /api/index.php?route=stats
```

返回调用汇总，以及当前启用中的全部接口（`path` / 名称 / 类型），供统计门户展示与复制：

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "overview": { "api_total": 2, "text_total": 2, "call_total": 15, "call_today": 3 },
    "apis": [ { "path": "hello", "name": "打招呼", "type": "template" } ],
    "apis_limit": 500
  }
}
```

不返回素材内容、调用次数等细节。数据库文件不存在或读取失败时返回 `503`，不会因公开请求创建数据库或表。

> 接口清单是公开的：门户页 `/` 任何人都能看到全部接口地址并直接调用。不想公开某个接口，在后台把它禁用即可（禁用后不出现在清单里，调用返回 403）。

### 对外调用

对外调用**不需要密钥**：

```text
GET /api/index.php?route=runtime&path=hello&name=张三
```

`random_text` 接口随机返回素材文本；`template` 接口将 `{{name}}` 等变量替换为查询参数值，未传入的变量替换为空。成功时返回 `text/plain` 纯文本，错误时返回 JSON：

```text
你好，张三！
```

```json
{"code":404,"msg":"API 不存在","data":null}
```

CORS：`api/config.php` 中的 `$ALLOWED_ORIGINS` 控制，数组形式，`'*'` 表示全部放行。

### 两种接口类型对比

| 类型 | `content` 含义 | 调用方式 | 说明 |
|---|---|---|---|
| `random_text` | 忽略（素材存在素材池） | `?route=runtime&path=daily` | 从该接口的素材池随机取一条返回 |
| `template` | 含 `{{变量}}` 的模板文本 | `?route=runtime&path=hello&name=张三` | 把 `{{name}}` 替换为同名查询参数值 |

**随机文字（`random_text`）**——不需要传参数：

```text
GET /api/index.php?route=runtime&path=daily
```

返回示例：

```text
今天也要加油哦！
```

**模板（`template`）**——变量名就是查询参数名：

1. 后台建接口时，`content` 写含 `{{name}}`、`{{date}}` 等占位符的模板文本
2. 调用时带上同名查询参数，参数值会替换对应占位符；没传的参数替换为空字符串
3. 变量名限 `[a-zA-Z0-9_]{1,32}`，占位符允许前后空白（`{{ name }}` 也识别）

```text
GET /api/index.php?route=runtime&path=hello&name=张三
```

```
你好，张三！
```

鉴权、路由控制类参数名（`key`、`token`、`route`、`path` 等及其大小写、连字符变体）默认被拦截表挡住，不能用作模板变量回显，也不会写进调用日志；拦截表可配置（见下方「配置」）。

## 配置

编辑 `api/config.php`：

```php
$ALLOWED_ORIGINS = ['*'];                // CORS 白名单
define('DB_PATH', __DIR__ . '/api.db');
define('TRUST_X_FORWARDED_FOR', false);  // 是否信任 X-Forwarded-For
define('LOG_RETENTION_DAYS', 90);        // 调用日志保留天数，0 表示不清理
define('ADMIN_SESSION_TTL', 604800);     // 后台登录会话有效期（秒），默认 7 天

// 模板参数拦截表：默认拦截 key/token/route/path 等鉴权、路由控制参数名，
// 可按需追加：
define('RUNTIME_EXTRA_BLOCKED_PARAMS', array('secret', 'callback'));

// 模板回显白名单：某个参数名即使命中拦截表也允许回显（默认关闭，慎用）：
define('RUNTIME_ALLOW_TEMPLATE_PARAMS', array('callback'));
```

管理员账号与密码不在配置文件里（只存数据库哈希），在后台登录页或「修改密码」处维护。

## 数据库

SQLite，位于 `api/api.db`。六张表：

- `api_config`：接口配置（path / name / type / content / enabled）
- `api_text`：素材表（外键指向 api_config）
- `api_log`：调用日志（api_id / ip / call_time / params）
- `admin_user`：管理员账号（username / password_hash）
- `admin_session`：登录会话（token 的 sha256 摘要 / 过期时间）
- `admin_login_attempt`：登录失败计数（按 IP 限速）

业务入口会在数据库文件已存在时幂等检查并补齐表结构；未安装时公开 `stats` 会先返回 `503`，不会因公开请求创建数据库。`db.php` 内含 `PRAGMA foreign_keys=ON`、`busy_timeout=5000`，并封装了 `db_retry()` / `db_transaction()` 处理锁冲突。

## 安全提示

- 后台账号密码请设置得足够强；忘记密码可删 `api/install.lock` 后重装重置（业务数据保留，账号密码重置）
- 安装完成后删除 `api/install.php`（或保留 `install.lock`）；删除 `install.lock` 可重装
- 对外接口无需密钥，属于公开服务：不要在素材里放隐私内容，必要时在后台禁用接口（禁用后不出现在接口清单里，调用返回 403）
- 关闭 `$ALLOWED_ORIGINS = ['*']`，改为具体域名（该限制只作用于浏览器跨域，不阻止直接调用）
- 若前面有反向代理（Nginx / Caddy），设置 `TRUST_X_FORWARDED_FOR = true` 以获取真实 IP（登录限速也依赖该 IP，代理环境下务必开启，否则限速按代理 IP 计算）
- 模板回显白名单 `RUNTIME_ALLOW_TEMPLATE_PARAMS` 默认关闭；确需放开某个被拦截的参数名时才配置，放开即使命中拦截表也回显
- 调用日志默认保留 90 天（`LOG_RETENTION_DAYS`），按概率触发清理、单次最多删 1000 行

## License

MIT
