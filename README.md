# backend

## 本地开发连接远程 API 的 CORS 配置

为了方便本地前端（如 `http://localhost:5173`）调试已部署的远程后端 API（如 `https://dev-api.carbontrackapp.com`），后端 CORS 中间件已做特殊处理：

- **非生产环境 (`APP_ENV != 'production'`)**：
  - 会自动将常见的本地开发源（`http://localhost:5173`, `http://localhost:3000`, `http://127.0.0.1:5173`, `http://127.0.0.1:3000`）加入到 `CORS_ALLOWED_ORIGINS` 允许列表中。
  - 这意味着你无需在远程服务器的 `.env` 文件中手动添加 `localhost`，即可实现本地调试。

- **生产环境 (`APP_ENV = 'production'`)**：
  - 严格依赖 `.env` 文件中的 `CORS_ALLOWED_ORIGINS` 配置，不会自动添加任何本地源，确保生产安全。

**调试步骤**

1.  确保远程服务器的 `APP_ENV` 设置为 `development` 或其他非 `production` 的值。
2.  本地前端直接请求远程 API 地址即可。
3.  如果依然遇到 CORS 问题，请检查：
    - 浏览器开发者工具网络面板的 `OPTIONS` 和 `POST` 请求，确认 `Access-Control-Allow-Origin` 响应头是否正确返回了你的本地源地址。
    - 确认请求是否携带了非标准的 HTTP 头，如果是，需在后端的 `CORS_ALLOWED_HEADERS` 中添加。
    - 检查是否有 PHP 警告或错误输导致 HTTP 响应体被污染，破坏了 CORS 头的正常返回（当前已在 `index.php` 入口抑制了错误显示）。

## 向后兼容的 /api 路由别名

为减少前端环境切换或历史代码中的路径差异导致的 404，本服务在保留标准前缀 `/api/v1/*` 的同时，提供了关键接口的 `/api/*` 兼容别名：

- 认证：`/api/auth/*`（与 `/api/v1/auth/*` 等价）
- 学校与班级：
  - `GET /api/schools`
  - `POST /api/schools`
  - `GET /api/schools/{id}/classes`
  - `POST /api/schools/{id}/classes`

注意：推荐始终使用带版本号的 `/api/v1/*` 接口；`/api/*` 仅用于兼容存量客户端或未配置版本前缀的环境。

## system_logs 表迁移说明

如果你拉取代码后访问 `/api/v1/admin/system-logs` 返回 500，且数据库还没有 `system_logs` 表，请先创建该表：

MySQL / MariaDB:
  执行 `database/migrations/create_system_logs_table.sql`

SQLite (本地 `test.db` 或测试环境):
  执行 `database/migrations/create_system_logs_table_sqlite.sql`

创建完成后，新的请求会被 `RequestLoggingMiddleware` 写入 `system_logs`。首次创建后表是空的，需要再访问几个接口再查看列表。

若依然 500：
1. 确认为最新代码（`SystemLogService` 不再使用 `strftime()` 写入 created_at）。
2. 确认表结构列名与插入语句匹配：`request_id, method, path, status_code, user_id, ip_address, user_agent, duration_ms, request_body, response_body`。
3. 查看 PHP error log（或 `error_logs` 表）是否有 SQL 语法错误。

### 新增字段：server_meta
系统现在会把请求对应的 `$_SERVER`（经脱敏）整体序列化为 JSON 存入 `system_logs.server_meta`：
- 脱敏键：`HTTP_AUTHORIZATION`, `PHP_AUTH_PW`, `HTTP_COOKIE`
- 附加 `_summary` 节点包含 method / uri / ip
- 过长（> 120KB）时会截断并追加 `...[TRUNCATED]`

### 超级搜索参数：q
列表接口 `/api/v1/admin/system-logs` 新增查询参数 `q`，会在以下字段做模糊匹配（LIKE）：
- request_id
- path
- method
- user_agent
- ip_address
- status_code（转换为字符串比较）
- request_body
- response_body
- server_meta

示例：
`GET /api/v1/admin/system-logs?q=Mozilla`

性能提示：在大数据量场景建议后续为常用字段建立专门索引或全文索引，并考虑将 `server_meta` 拆分或引入外部日志仓库（如 ELK / OpenSearch）。
