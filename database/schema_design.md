# 网站重构数据库结构和API架构设计

## 1. 数据库结构设计

基于原有 `localhost.sql` 提供的数据库结构，我们将进行以下修改和新增，以支持软删除、详细日志和错误记录，并优化现有结构以适应前后端分离的架构。

**数据库版本：** MySQL 5.6.51

### 1.1. 现有表修改

为了实现软删除功能，将在所有需要软删除的表中添加 `deleted_at` 字段。同时，对现有字段进行规范化和优化。

#### `users` 表

-   **目的：** 存储用户信息，包括登录凭证、积分等。
-   **修改：**
    -   `username`: `CHAR(128)` -> `VARCHAR(255)`，允许更长的用户名。
    -   `password`: `CHAR(128)` -> `VARCHAR(255)`，存储哈希密码，长度可能增加。
    -   `lastlgn`: `TEXT` -> `DATETIME NULL`，存储上次登录时间，更精确的日期时间类型。
    -   `email`: `TEXT` -> `VARCHAR(255) UNIQUE NOT NULL`，邮箱作为唯一标识，并增加非空约束。
    -   `points`: `DOUBLE` -> `DECIMAL(10, 2) NOT NULL DEFAULT '0.00'`，使用 `DECIMAL` 存储积分，避免浮点数精度问题。
    -   `school`: `TEXT` -> `VARCHAR(255) NULL`，学校名称。
    -   `location`: `TEXT` -> `VARCHAR(255) NULL`，位置信息。
    -   **新增字段：**
        -   `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`，记录创建时间。
        -   `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`，记录更新时间。
        -   `deleted_at`: `DATETIME NULL`，软删除标记。当记录被软删除时，此字段存储删除时间。
        -   `status`: `ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active'`，用户状态。
        -   `is_admin`: `TINYINT(1) NOT NULL DEFAULT '0'`，管理员标记。
-   **索引：**
    -   `PRIMARY KEY (id)`
    -   `UNIQUE KEY (email)`
    -   `INDEX (username)`
    -   `INDEX (deleted_at)`

#### `products` 表

-   **目的：** 存储可兑换产品信息。
-   **修改：**
    -   `name`: `TEXT` -> `VARCHAR(255) NOT NULL`。
    -   `description`: `TEXT` -> `TEXT` (保持不变，可能包含长文本)。
    -   `image_path`: `TEXT` -> `VARCHAR(512) NOT NULL`，存储 Cloudflare R2 的 URL，增加长度。
    -   **新增字段：**
        -   `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`。
        -   `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`。
        -   `deleted_at`: `DATETIME NULL`，软删除标记。
-   **索引：**
    -   `PRIMARY KEY (product_id)`
    -   `INDEX (deleted_at)`

#### `points_transactions` 表

-   **目的：** 存储用户积分交易记录，包括碳减排核算结果。
-   **修改：**
    -   `username`: `TEXT` -> `VARCHAR(255) NULL`，可以考虑移除，直接通过 `uid` 关联 `users` 表。
    -   `email`: `TEXT` -> `VARCHAR(255) NOT NULL`，通过 `uid` 关联 `users` 表。
    -   `time`: `TEXT` -> `DATETIME NOT NULL`，记录交易时间。
    -   `img`: `TEXT` -> `VARCHAR(512) NULL`，存储 Cloudflare R2 的 URL。
    -   `points`: `DOUBLE` -> `DECIMAL(10, 2) NOT NULL`，使用 `DECIMAL`。
    -   `raw`: `DOUBLE` -> `DECIMAL(10, 2) NOT NULL`，原始数据，使用 `DECIMAL`。
    -   `act`: `TEXT` -> `VARCHAR(255) NOT NULL`，活动类型。
    -   `type`: `TEXT` -> `VARCHAR(50) NULL`，交易类型（例如 'ord' 普通交易, 'spec' 特殊交易）。
    -   `notes`: `TEXT` -> `TEXT NULL`。
    -   `activity_date`: `DATE` -> `DATE NULL`。
    -   `uid`: `INT(11)` -> `INT(11) NOT NULL`，关联 `users` 表的 `id`。
    -   `auth`: `TEXT` -> `VARCHAR(50) NULL`，认证状态或来源。
    -   **新增字段：**
        -   `status`: `ENUM('pending', 'approved', 'rejected', 'deleted') NOT NULL DEFAULT 'pending'`，交易状态，支持审核流程和软删除。
        -   `approved_by`: `INT(11) NULL`，审核人ID，关联 `users` 表。
        -   `approved_at`: `DATETIME NULL`，审核时间。
        -   `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`。
        -   `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`。
        -   `deleted_at`: `DATETIME NULL`，软删除标记。
-   **索引：**
    -   `PRIMARY KEY (id)`
    -   `INDEX (uid)`
    -   `INDEX (email)`
    -   `INDEX (activity_date)`
    -   `INDEX (status)`
    -   `INDEX (deleted_at)`

#### `spec_points_transactions` 表

-   **目的：** 存储特殊积分交易记录。**建议合并到 `points_transactions` 表中，通过 `type` 字段区分。** 如果保留，修改与 `points_transactions` 类似。
-   **修改（如果保留）：**
    -   `username`: `TEXT` -> `VARCHAR(255) NULL`。
    -   `email`: `TEXT` -> `VARCHAR(255) NOT NULL`。
    -   `time`: `TEXT` -> `DATETIME NOT NULL`。
    -   `img`: `TEXT` -> `VARCHAR(512) NULL`。
    -   `points`: `DOUBLE` -> `DECIMAL(10, 2) NULL`。
    -   `raw`: `DOUBLE` -> `DECIMAL(10, 2) NOT NULL`。
    -   `act`: `TEXT` -> `VARCHAR(255) NOT NULL`。
    -   `uid`: `INT(11)` -> `INT(11) NOT NULL`。
    -   **新增字段：**
        -   `status`: `ENUM('pending', 'approved', 'rejected', 'deleted') NOT NULL DEFAULT 'pending'`。
        -   `approved_by`: `INT(11) NULL`。
        -   `approved_at`: `DATETIME NULL`。
        -   `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`。
        -   `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`。
        -   `deleted_at`: `DATETIME NULL`。
-   **索引：**
    -   `PRIMARY KEY (id)`
    -   `INDEX (uid)`
    -   `INDEX (email)`
    -   `INDEX (status)`
    -   `INDEX (deleted_at)`

#### `transactions` 表

-   **目的：** 存储产品兑换交易记录。
-   **修改：**
    -   `points_spent`: `DOUBLE` -> `DECIMAL(10, 2) NOT NULL`。
    -   `transaction_time`: `TEXT` -> `DATETIME NOT NULL`。
    -   `product_id`: `INT(11)` -> `INT(11) NOT NULL`，关联 `products` 表。
    -   `user_email`: `TEXT` -> `VARCHAR(255) NOT NULL`，通过 `user_id` 关联 `users` 表。
    -   `school`: `TEXT` -> `VARCHAR(255) NULL`。
    -   `location`: `TEXT` -> `VARCHAR(255) NULL`。
    -   **新增字段：**
        -   `user_id`: `INT(11) NOT NULL`，关联 `users` 表的 `id`。
        -   `status`: `ENUM('pending', 'completed', 'cancelled', 'deleted') NOT NULL DEFAULT 'pending'`，交易状态，支持软删除。
        -   `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`。
        -   `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`。
        -   `deleted_at`: `DATETIME NULL`，软删除标记。
-   **索引：**
    -   `PRIMARY KEY (id)`
    -   `INDEX (user_id)`
    -   `INDEX (product_id)`
    -   `INDEX (status)`
    -   `INDEX (deleted_at)`

#### `messages` 表

-   **目的：** 存储站内信消息。
-   **修改：**
    -   `sender_id`: `TEXT` -> `INT(11) NOT NULL`，关联 `users` 表的 `id`。
    -   `receiver_id`: `TEXT` -> `INT(11) NOT NULL`，关联 `users` 表的 `id`。
    -   `send_time`: `DATETIME` -> `DATETIME NOT NULL`。
    -   `is_read`: `TINYINT(1)` -> `TINYINT(1) NOT NULL DEFAULT '0'`。
    -   **新增字段：**
        -   `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`。
        -   `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`。
        -   `deleted_at`: `DATETIME NULL`，软删除标记。
-   **索引：**
    -   `PRIMARY KEY (message_id)`
    -   `INDEX (sender_id)`
    -   `INDEX (receiver_id)`
    -   `INDEX (is_read)`
    -   `INDEX (deleted_at)`

#### `schools` 表

-   **目的：** 存储学校信息。
-   **修改：**
    -   `name`: `TEXT` -> `VARCHAR(255) NOT NULL UNIQUE`。
    -   **新增字段：**
        -   `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`。
        -   `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`。
        -   `deleted_at`: `DATETIME NULL`，软删除标记。
-   **索引：**
    -   `PRIMARY KEY (id)`
    -   `UNIQUE KEY (name)`
    -   `INDEX (deleted_at)`

### 1.2. 新增表

#### `audit_logs` 表

-   **目的：** 记录所有关键操作的审计日志，包括用户行为、系统事件等。
-   **字段：**
    -   `id`: `INT(11) PRIMARY KEY AUTO_INCREMENT`。
    -   `user_id`: `INT(11) NULL`，操作用户ID，关联 `users` 表，如果是非用户操作则为NULL。
    -   `action`: `VARCHAR(255) NOT NULL`，操作类型（例如 'user_login', 'record_carbon_saving', 'product_exchange', 'user_update'）。
    -   `entity_type`: `VARCHAR(100) NULL`，被操作实体类型（例如 'user', 'points_transaction', 'product'）。
    -   `entity_id`: `INT(11) NULL`，被操作实体ID。
    -   `old_value`: `JSON NULL`，操作前的数据快照（MySQL 5.6.51 不支持 JSON 类型，可以使用 `TEXT` 或 `LONGTEXT` 存储 JSON 字符串）。
    -   `new_value`: `JSON NULL`，操作后的数据快照（同上，使用 `TEXT` 或 `LONGTEXT`）。
    -   `ip_address`: `VARCHAR(45) NULL`，操作IP地址。
    -   `user_agent`: `VARCHAR(512) NULL`，用户代理字符串。
    -   `timestamp`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`，操作时间。
    -   `notes`: `TEXT NULL`，附加说明。
-   **索引：**
    -   `PRIMARY KEY (id)`
    -   `INDEX (user_id)`
    -   `INDEX (action)`
    -   `INDEX (entity_type, entity_id)`
    -   `INDEX (timestamp)`

#### `error_records` 表 (替代 `error_logs`)

-   **目的：** 记录系统运行时产生的错误和异常信息。
-   **修改：** 原有的 `error_logs` 表结构基本合理，但字段类型可以优化，并增加 `request_method` 和 `request_url`。
-   **字段：**
    -   `id`: `INT(11) PRIMARY KEY AUTO_INCREMENT`。
    -   `error_type`: `VARCHAR(100) NULL`，错误类型（例如 'PHP Error', 'Exception', 'API Error', 'Database Error'）。
    -   `error_message`: `TEXT`，错误消息。
    -   `error_file`: `VARCHAR(512) NULL`，发生错误的文件。
    -   `error_line`: `INT(11) NULL`，发生错误的行号。
    -   `stack_trace`: `LONGTEXT NULL`，异常堆栈跟踪。
    -   `request_method`: `VARCHAR(10) NULL`，请求方法（GET, POST等）。
    -   `request_url`: `VARCHAR(1024) NULL`，请求URL。
    -   `client_ip`: `VARCHAR(45) NULL`，客户端IP地址。
    -   `user_id`: `INT(11) NULL`，如果用户已登录，记录用户ID。
    -   `request_data`: `LONGTEXT NULL`，请求数据（GET, POST, FILES, COOKIE, SESSION, SERVER等，存储为 JSON 字符串）。
    -   `response_status`: `INT(11) NULL`，响应HTTP状态码。
    -   `timestamp`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`，错误发生时间。
-   **索引：**
    -   `PRIMARY KEY (id)`
    -   `INDEX (error_type)`
    -   `INDEX (timestamp)`
    -   `INDEX (user_id)`

### 1.3. 数据库更新文件 (`backend/database/migrations/`) 示例

我们将为每个表结构变更和新增表创建独立的 SQL 迁移文件，以方便版本控制和部署。

例如：

-   `2025_08_16_000001_add_soft_deletes_to_users_table.sql`
-   `2025_08_16_000002_create_audit_logs_table.sql`
-   `2025_08_16_000003_create_error_records_table.sql`
-   ... (其他表的修改和索引添加)

**示例 SQL (users 表软删除字段添加):**

```sql
-- 2025_08_16_000001_add_soft_deletes_to_users_table.sql

ALTER TABLE `users`
ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
ADD COLUMN `deleted_at` DATETIME NULL,
ADD COLUMN `status` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT '0';

ALTER TABLE `users`
MODIFY COLUMN `username` VARCHAR(255) DEFAULT NULL,
MODIFY COLUMN `password` VARCHAR(255) NOT NULL,
MODIFY COLUMN `lastlgn` DATETIME NULL,
MODIFY COLUMN `email` VARCHAR(255) UNIQUE NOT NULL,
MODIFY COLUMN `points` DECIMAL(10, 2) NOT NULL DEFAULT '0.00',
MODIFY COLUMN `school` VARCHAR(255) NULL,
MODIFY COLUMN `location` VARCHAR(255) NULL;

CREATE UNIQUE INDEX `idx_users_email` ON `users` (`email`);
CREATE INDEX `idx_users_deleted_at` ON `users` (`deleted_at`);
CREATE INDEX `idx_users_username` ON `users` (`username`);

-- 其他表的类似修改...
```

## 2. API 架构设计

我们将采用 RESTful API 设计原则，实现前后端分离的架构。所有 API 都将通过 `/api/v1` 前缀访问，并使用 JSON 作为数据交换格式。

### 2.1. 认证与授权

-   **认证：** 使用基于 Token 的认证机制（例如 JWT）。用户登录成功后，后端返回一个 JWT，前端将其存储在本地（例如 `localStorage` 或 `sessionStorage`），并在后续请求中通过 `Authorization` 头发送。
-   **授权：** 后端 API 将根据用户角色（例如管理员、普通用户）和权限进行访问控制。

### 2.2. 通用 API 响应结构

所有 API 响应都将遵循统一的 JSON 结构：

**成功响应：**

```json
{
    "success": true,
    "message": "操作成功",
    "data": { /* 响应数据 */ }
}
```

**错误响应：**

```json
{
    "success": false,
    "message": "错误信息",
    "code": "错误码 (可选)",
    "errors": { /* 详细错误信息 (可选) */ }
}
```

### 2.3. API 端点列表 (示例)

以下是主要业务功能的 API 端点示例。具体参数和响应将根据实际需求细化。

#### 2.3.1. 用户管理

-   `POST /api/v1/auth/register`: 用户注册
    -   请求：`username`, `email`, `password`, `verification_code`
    -   响应：`user_id`, `username`, `email`, `token`
-   `POST /api/v1/auth/login`: 用户登录
    -   请求：`email`, `password`
    -   响应：`user_id`, `username`, `email`, `token`
-   `POST /api/v1/auth/logout`: 用户登出
-   `GET /api/v1/users/me`: 获取当前用户信息 (需要认证)
-   `PUT /api/v1/users/me`: 更新当前用户信息 (需要认证)
-   `GET /api/v1/users/{id}`: 获取指定用户信息 (管理员权限)
-   `PUT /api/v1/users/{id}`: 更新指定用户信息 (管理员权限)
-   `DELETE /api/v1/users/{id}`: 软删除用户 (管理员权限)
-   `POST /api/v1/auth/send-verification-code`: 发送邮箱验证码
-   `POST /api/v1/auth/reset-password`: 重置密码

#### 2.3.2. 碳减排核算与积分

-   `POST /api/v1/carbon-track/record`: 记录碳减排活动 (需要认证)
    -   请求：`activity_type`, `data_input`, `notes`, `activity_date`, `image` (文件上传)
    -   响应：`points_transaction_id`, `carbon_savings`, `message`
    -   **幂等性考虑：** 客户端应生成唯一的 `request_id` 并随请求发送，后端记录并检查，避免重复处理。
-   `GET /api/v1/carbon-track/transactions`: 获取用户积分交易记录 (需要认证)
    -   参数：`page`, `limit`, `status`, `start_date`, `end_date`
-   `GET /api/v1/carbon-track/transactions/{id}`: 获取单条积分交易详情 (需要认证)
-   `PUT /api/v1/carbon-track/transactions/{id}/approve`: 审核通过积分交易 (管理员权限)
-   `PUT /api/v1/carbon-track/transactions/{id}/reject`: 审核拒绝积分交易 (管理员权限)
-   `DELETE /api/v1/carbon-track/transactions/{id}`: 软删除积分交易记录 (管理员权限)
-   `GET /api/v1/carbon-track/factors`: 获取碳减排因子列表 (公开)

#### 2.3.3. 产品与兑换

-   `GET /api/v1/products`: 获取产品列表 (公开)
    -   参数：`page`, `limit`, `search`
-   `GET /api/v1/products/{id}`: 获取产品详情 (公开)
-   `POST /api/v1/products`: 创建产品 (管理员权限)
-   `PUT /api/v1/products/{id}`: 更新产品 (管理员权限)
-   `DELETE /api/v1/products/{id}`: 软删除产品 (管理员权限)
-   `POST /api/v1/exchange`: 兑换产品 (需要认证)
    -   请求：`product_id`, `quantity`
    -   响应：`transaction_id`, `points_spent`, `message`
    -   **幂等性考虑：** 客户端应生成唯一的 `request_id` 并随请求发送，后端记录并检查，避免重复处理。
-   `GET /api/v1/exchange/transactions`: 获取用户兑换记录 (需要认证)

#### 2.3.4. 消息系统

-   `GET /api/v1/messages`: 获取用户消息列表 (需要认证)
    -   参数：`page`, `limit`, `is_read`, `sender_id`, `receiver_id`
-   `GET /api/v1/messages/{id}`: 获取单条消息详情 (需要认证)
-   `POST /api/v1/messages`: 发送消息 (需要认证)
-   `PUT /api/v1/messages/{id}/read`: 标记消息已读 (需要认证)
-   `DELETE /api/v1/messages/{id}`: 软删除消息 (需要认证)

#### 2.3.5. 学校管理

-   `GET /api/v1/schools`: 获取学校列表 (公开)
-   `POST /api/v1/schools`: 创建学校 (管理员权限)
-   `PUT /api/v1/schools/{id}`: 更新学校 (管理员权限)
-   `DELETE /api/v1/schools/{id}`: 软删除学校 (管理员权限)

### 2.4. 错误处理与日志记录

-   **统一错误处理：** 后端将实现全局错误处理中间件，捕获所有未处理的异常和错误，并返回统一的错误响应格式。
-   **详细日志记录：**
    -   所有 API 请求和响应都将记录到 `audit_logs` 表中。
    -   所有系统错误和异常都将记录到 `error_records` 表中。
    -   日志将包含请求方法、URL、IP 地址、用户 ID (如果已认证)、请求数据、响应状态码、错误信息、堆栈跟踪等详细信息。
    -   敏感信息（如密码）在日志中进行脱敏处理。

### 2.5. 幂等性

对于涉及资源创建或状态变更的 POST/PUT 请求，将通过以下方式确保幂等性：

-   **客户端生成唯一请求 ID：** 客户端在发送请求时，为每个请求生成一个唯一的 `X-Request-ID` (UUID) 请求头。
-   **后端检查并记录：** 后端接收到请求后，首先检查 `X-Request-ID` 是否已存在于 `audit_logs` 或专门的幂等性记录表中。
    -   如果存在且请求已成功处理，则直接返回上次成功的响应，不再重复执行业务逻辑。
    -   如果不存在，则处理请求，并将 `X-Request-ID` 和处理结果记录下来。
-   **事务：** 确保所有数据库操作都在事务中进行，保证原子性。

## 3. 碳减排核算公式和算法

碳减排核算的核心算法将从原有的 `calculate.php` 中提取，并封装为独立的 PHP 类或服务，确保其逻辑的完整性和可测试性。该算法将作为后端 API 的一部分，在处理碳减排记录请求时被调用。

**核心逻辑 (伪代码):**

```php
class CarbonCalculator
{
    private $factors = [
        '购物时自带袋子 / Bring your own bag when shopping' => 0.0190,
        '早睡觉一小时 / Sleep an hour earlier' => 0.0950,
        '刷牙时关掉水龙头 / Turn off the tap while brushing teeth' => 0.0090,
        '出门自带水杯 / Bring your own water bottle' => 0.0400,
        '垃圾分类 / Sort waste properly' => 0.0004,
        '减少打印纸 / Reduce unnecessary printing paper' => 0.0040,
        '减少使用一次性餐盒 / Reduce disposable meal boxes' => 0.1900,
        '简易包装礼物 / Use minimal gift wrapping' => 0.1400,
        '夜跑 / Night running' => 0.0950,
        '自然风干湿发 / Air-dry wet hair' => 0.1520,
        '点外卖选择“无需餐具” / Choose No-Cutlery when ordering delivery' => 0.0540,
        '下班时关电脑和灯 / Turn off computer and lights when off-duty' => 0.1660,
        '晚上睡觉全程关灯 / Keep lights off at night' => 0.1100,
        '快速洗澡 / Take a quick shower' => 0.1200,
        '阳光晾晒衣服 / Sun-dry clothes' => 0.3230,
        '夏天空调调至26°C以上 / Set AC to above 78°F during Summer' => 0.2190,
        '攒够一桶衣服再洗 / Save and wash a full load of clothes' => 0.4730,
        '化妆品用完购买替代装 / Buy refillable cosmetics or toiletries' => 0.0850,
        '购买本地应季水果 / Buy local seasonal fruits' => 2.9800,
        '自己做饭 / Cook at home' => 0.1900,
        '吃一顿轻食 / Have a light meal' => 0.3600,
        '吃完水果蔬菜 / Finish all fruits and vegetables' => 0.0163,
        '光盘行动 / Finish all food on the plate' => 0.0163,
        '喝燕麦奶或植物基食品 / Drink oat milk or plant-based food' => 0.6430,
        '公交地铁通勤 / Use public transport' => 0.1005,
        '骑行探索城市 / Explore the city by bike' => 0.1490,
        '种一棵树 / Plant a tree' => 10.0000,
        '购买二手书 / Buy a second-hand book' => 2.8800,
        '乘坐快轨去机场 / Take high-speed rail to the airport' => 3.8700,
        '拼车 / Carpool' => 0.0450,
        '自行车出行 / Travel by bike' => 0.1490,
        '旅行时自备洗漱用品 / Bring your own toiletries when traveling' => 0.0470,
        '旧物改造 / Repurpose old items' => 0.7700,
        '购买一级能效家电 / Buy an energy-efficient appliance' => 2.1500,
        '购买白色或浅色衣物 / Buy white or light-colored clothes' => 3.4300,
        '花一天享受户外 / Spend a full day outdoors' => 0.7570,
        '自己种菜并吃 / Grow and eat your own vegetables' => 0.0250,
        '减少使用手机时间 / Reduce screen time' => 0.0003,
        // 特殊活动，例如 '节约用电1度', '节约用水1L', '垃圾分类1次'
        '节约用电1度' => 1.0, // 假设每度电碳排放量为1kg CO2e
        '节约用水1L' => 1.0, // 假设每升水碳排放量为1kg CO2e
        '垃圾分类1次' => 145.0 // 假设每次垃圾分类碳减排量为145g CO2e
    ];

    public function calculateCarbonSavings(string $activityType, float $dataInput): float
    {
        if (!isset($this->factors[$activityType])) {
            throw new \InvalidArgumentException('未知的活动类型: ' . $activityType);
        }
        return $dataInput * $this->factors[$activityType];
    }

    public function getCarbonFactors(): array
    {
        return $this->factors;
    }
}
```

## 4. 项目目录结构

重构后的项目将采用以下目录结构：

```
./
├── frontend/             # 前端 React 应用
│   ├── public/
│   ├── src/
│   │   ├── components/
│   │   ├── pages/
│   │   ├── services/     # API 调用服务
│   │   ├── utils/
│   │   └── App.js
│   ├── package.json
│   └── ...
├── backend/              # 后端 PHP Slim 应用
│   ├── public/           # Web 服务器根目录 (index.php)
│   ├── src/
│   │   ├── Controllers/
│   │   ├── Models/       # 数据库模型 (例如使用 Eloquent ORM)
│   │   ├── Services/     # 业务逻辑服务 (例如 CarbonCalculator.php)
│   │   ├── Middleware/
│   │   ├── Handlers/     # 错误和日志处理
│   │   └── routes.php
│   ├── vendor/
│   ├── composer.json
│   ├── .env.example      # 环境变量配置示例
│   ├── database/         # 数据库相关文件
│   │   ├── migrations/   # 数据库迁移文件 (SQL)
│   │   └── seeders/      # 数据库填充文件 (可选)
│   └── ...
└── README.md
```

## 5. 前后端 API 交互逻辑

-   **统一 API 前缀：** 所有后端 API 都将通过 `/api/v1` 访问，方便前端配置代理或直接请求。
-   **跨域资源共享 (CORS)：** 后端将配置 CORS，允许前端域进行跨域请求。
-   **数据格式：** 前后端之间的数据交换统一使用 JSON 格式。
-   **错误处理：** 前端将根据后端返回的统一错误响应结构进行错误提示和处理。
-   **认证：** 前端在登录后获取 JWT，并将其存储在 `localStorage` 中。所有需要认证的请求都将在 `Authorization` 头中携带此 JWT。
-   **文件上传：** 对于图片上传，前端将使用 `FormData` 对象将文件发送到后端 API。后端接收文件后，将其上传到 Cloudflare R2，并将 R2 的 URL 存储到数据库中。
-   **幂等性：** 对于关键的写操作（如记录碳减排、兑换产品），前端将生成一个唯一的 `X-Request-ID` 并发送给后端，后端利用此 ID 确保操作的幂等性。

## 6. 数据库兼容性 (MySQL 5.6.51)

-   **JSON 类型：** MySQL 5.6.51 不支持原生的 `JSON` 数据类型。在 `audit_logs` 和 `error_records` 表中，需要将 `JSON` 字段改为 `TEXT` 或 `LONGTEXT`，并在 PHP 端进行 JSON 字符串的编码和解码。
-   **`ON UPDATE CURRENT_TIMESTAMP`：** MySQL 5.6.51 支持 `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`，可以正常使用。
-   **`ENUM` 类型：** `ENUM` 类型在 MySQL 5.6.51 中也支持。
-   **索引：** 确保创建的索引类型和语法与 MySQL 5.6.51 兼容。

**总结：**

本次重构将显著提升网站的架构健壮性、可维护性和扩展性。通过前后端分离、引入软删除、详细日志和错误处理，以及规范化的 API 设计，将为未来的功能迭代打下坚实基础。碳减排核算的核心算法将得到妥善保留和封装，确保业务核心逻辑的准确性。

