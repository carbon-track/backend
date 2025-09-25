<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use PDO;

/**
 * Lightweight SQLite schema + minimal seed for integration tests.
 * Avoids full production migration complexity while satisfying controller queries.
 */
class TestSchemaBuilder
{
    public static function init(PDO $pdo): void
    {
        // Enable foreign keys (safe even if not used extensively)
        try { $pdo->exec('PRAGMA foreign_keys = ON'); } catch (\Throwable $e) {}
        // Provide MySQL NOW() compatibility for SQLite
        try {
            if (method_exists($pdo, 'sqliteCreateFunction')) {
                $pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
            }
        } catch (\Throwable $e) { /* ignore */ }

        $tables = [
            // Users
            "CREATE TABLE IF NOT EXISTS users (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                username TEXT UNIQUE,\n                email TEXT UNIQUE,\n                password TEXT,\n                uuid TEXT,\n                school_id INTEGER,\n                status TEXT,\n                points INTEGER DEFAULT 0,\n                is_admin INTEGER DEFAULT 0,\n                avatar_id INTEGER,\n                image_path TEXT,\n                last_login_at TEXT,\n                deleted_at TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Products
            "CREATE TABLE IF NOT EXISTS products (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                name TEXT,\n                description TEXT,\n                category TEXT,\n                category_slug TEXT,\n                images TEXT,\n                image_path TEXT,\n                stock INTEGER DEFAULT 0,\n                points_required INTEGER DEFAULT 0,\n                status TEXT DEFAULT 'active',\n                sort_order INTEGER DEFAULT 0,\n                deleted_at TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            "CREATE TABLE IF NOT EXISTS product_categories (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                name TEXT NOT NULL,\n                slug TEXT NOT NULL,\n                description TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                UNIQUE(slug)\n            )",
            "CREATE TABLE IF NOT EXISTS product_tags (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                name TEXT NOT NULL,\n                slug TEXT NOT NULL,\n                description TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                UNIQUE(slug)\n            )",
            "CREATE TABLE IF NOT EXISTS product_tag_map (\n                product_id INTEGER NOT NULL,\n                tag_id INTEGER NOT NULL,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                UNIQUE(product_id, tag_id)\n            )",
            // Carbon activities (align with production columns subset used by model & controllers)
            "CREATE TABLE IF NOT EXISTS carbon_activities (\n                id TEXT PRIMARY KEY,\n                name_zh TEXT,\n                name_en TEXT,\n                category TEXT,\n                carbon_factor REAL,\n                unit TEXT,\n                description_zh TEXT,\n                description_en TEXT,\n                icon TEXT,\n                is_active INTEGER DEFAULT 1,\n                sort_order INTEGER DEFAULT 0,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                deleted_at TEXT\n            )",
            // Carbon records
            "CREATE TABLE IF NOT EXISTS carbon_records (\n                id TEXT PRIMARY KEY,\n                user_id INTEGER,\n                activity_id TEXT,\n                amount REAL,\n                unit TEXT,\n                carbon_saved REAL,\n                points_earned INTEGER,\n                date TEXT,\n                description TEXT,\n                images TEXT,\n                proof_images TEXT,\n                status TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                approved_at TEXT,\n                deleted_at TEXT\n            )",
            // Schools (needed for registration validation when school_id provided)
            "CREATE TABLE IF NOT EXISTS schools (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                name TEXT,\n                status TEXT DEFAULT 'active',\n                deleted_at TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Point exchanges
            "CREATE TABLE IF NOT EXISTS point_exchanges (\n                id TEXT PRIMARY KEY,\n                user_id INTEGER,\n                product_id INTEGER,\n                quantity INTEGER,\n                points_used INTEGER,\n                product_name TEXT,\n                product_price INTEGER,\n                delivery_address TEXT,\n                contact_phone TEXT,\n                notes TEXT,\n                status TEXT,\n                tracking_number TEXT,\n                deleted_at TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT\n            )",
            "CREATE TABLE IF NOT EXISTS user_badges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                badge_id INTEGER,
                status TEXT DEFAULT 'active',
                awarded_at TEXT DEFAULT CURRENT_TIMESTAMP
            )",
            // Points transactions (expanded to satisfy AdminController & joins)
            // Production table has many columns; we include the ones accessed in tests/controllers.
            // Needed columns (referenced): id, uid, user_id (some code may use either), points, status, img, notes,
            // activity_id, type, raw, auth, created_at, updated_at, deleted_at, approved_by, approved_at, activity_date.
            // Use TEXT/INTEGER with NULL defaults to stay permissive.
            "CREATE TABLE IF NOT EXISTS points_transactions (\n                id TEXT PRIMARY KEY,\n                uid INTEGER,\n                user_id INTEGER,\n                username TEXT,\n                email TEXT,\n                points REAL,\n                raw REAL,\n                act TEXT,\n                type TEXT,\n                description TEXT,\n                status TEXT,\n                img TEXT,\n                notes TEXT,\n                activity_id TEXT,\n                activity_date TEXT,\n                auth TEXT,\n                approved_by INTEGER,\n                approved_at TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT,\n                deleted_at TEXT,\n                related_table TEXT,\n                related_id TEXT\n            )",
            // Messages (minimal columns used in service)
            "CREATE TABLE IF NOT EXISTS messages (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                sender_id INTEGER,\n                receiver_id INTEGER,\n                title TEXT,\n                content TEXT,\n                is_read INTEGER DEFAULT 0,\n                deleted_at TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Audit logs (expanded to satisfy AuditLogService::logAudit expected columns)
            // Only a subset of data is critical for tests; optional columns kept nullable.
            "CREATE TABLE IF NOT EXISTS audit_logs (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                user_id INTEGER,\n                actor_type TEXT,\n                action TEXT,\n                data TEXT,\n                ip_address TEXT,\n                user_agent TEXT,\n                request_method TEXT,\n                endpoint TEXT,\n                old_data TEXT,\n                new_data TEXT,\n                affected_table TEXT,\n                affected_id INTEGER,\n                status TEXT,\n                response_code INTEGER,\n                session_id TEXT,\n                request_id TEXT,\n                referrer TEXT,\n                operation_category TEXT,\n                operation_subtype TEXT,\n                change_type TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Login attempts
            "CREATE TABLE IF NOT EXISTS login_attempts (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                username TEXT,\n                ip_address TEXT,\n                success INTEGER,\n                attempted_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Error logs (simplified)
            "CREATE TABLE IF NOT EXISTS error_logs (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                error_type TEXT,\n                error_message TEXT,\n                error_file TEXT,\n                error_line INTEGER,\n                error_time TEXT,\n                script_name TEXT,\n                client_get TEXT,\n                client_post TEXT,\n                client_files TEXT,\n                client_cookie TEXT,\n                client_session TEXT,\n                client_server TEXT,
                request_id TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Idempotency records
            "CREATE TABLE IF NOT EXISTS idempotency_records (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                request_id TEXT UNIQUE,\n                response_body TEXT,
                server_meta TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Avatars (expanded to satisfy controller selected columns)
            "CREATE TABLE IF NOT EXISTS avatars (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                uuid TEXT,\n                name TEXT,\n                description TEXT,\n                file_path TEXT,\n                thumbnail_path TEXT,\n                category TEXT,\n                sort_order INTEGER DEFAULT 0,\n                is_default INTEGER DEFAULT 0,\n                is_active INTEGER DEFAULT 1,\n                deleted_at TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )"
            ,
            // System logs table for request logging middleware
            "CREATE TABLE IF NOT EXISTS system_logs (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                request_id TEXT,\n                method TEXT,\n                path TEXT,\n                status_code INTEGER,\n                user_id INTEGER,\n                ip_address TEXT,\n                user_agent TEXT,\n                duration_ms REAL,\n                request_body TEXT,\n                response_body TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )"
        ];

        foreach ($tables as $sql) {
            try { $pdo->exec($sql); } catch (\Throwable $e) { /* ignore */ }
        }

        // Perform lightweight schema upgrades for existing SQLite file (idempotent)
        self::ensureColumns($pdo, 'users', ['avatar_id INTEGER']);
        self::ensureColumns($pdo, 'products', ['category_slug TEXT']);
        self::ensureColumns($pdo, 'audit_logs', [
            'actor_type TEXT', 'data TEXT', 'request_method TEXT', 'endpoint TEXT', 'old_data TEXT', 'new_data TEXT',
            'affected_table TEXT', 'affected_id INTEGER', 'status TEXT', 'response_code INTEGER', 'session_id TEXT', 'request_id TEXT',
            'referrer TEXT', 'operation_category TEXT', 'operation_subtype TEXT', 'change_type TEXT'
        ]);
        self::ensureColumns($pdo, 'system_logs', ['server_meta TEXT']);
        self::ensureColumns($pdo, 'error_logs', ['request_id TEXT']);
        self::ensureColumns($pdo, 'points_transactions', [
            'uid INTEGER', 'raw REAL', 'act TEXT', 'description TEXT', 'status TEXT', 'img TEXT', 'notes TEXT', 'activity_id TEXT',
            'approved_by INTEGER', 'approved_at TEXT', 'updated_at TEXT', 'deleted_at TEXT', 'activity_date TEXT',
            'auth TEXT'
        ]);

        // Seed minimal reference data if absent
        self::seed($pdo);
    }

    private static function ensureColumns(PDO $pdo, string $table, array $definitions): void
    {
        try {
            $existing = [];
            $res = $pdo->query("PRAGMA table_info($table)");
            if ($res) {
                foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    $existing[strtolower($col['name'])] = true;
                }
            }
            foreach ($definitions as $def) {
                [$name] = explode(' ', $def, 2);
                if (!isset($existing[strtolower($name)])) {
                    try { $pdo->exec("ALTER TABLE $table ADD COLUMN $def"); } catch (\Throwable $e) { /* ignore */ }
                }
            }
        } catch (\Throwable $e) { /* ignore */ }
    }

    private static function seed(PDO $pdo): void
    {
        // Carbon activities (ensure at least one)
        $count = (int)$pdo->query("SELECT COUNT(*) FROM carbon_activities")->fetchColumn();
        if ($count === 0) {
            $pdo->exec("INSERT INTO carbon_activities (id,name_zh,name_en,category,carbon_factor,unit,is_active) VALUES \n                ('550e8400-e29b-41d4-a716-446655440001','购物时自带袋子','Bring your own bag when shopping','daily',0.019,'times',1),\n                ('550e8400-e29b-41d4-a716-446655440002','步行 / 骑行代替开车','Walk or cycle instead of driving','transport',0.27,'km',1)");
        }
        // Avatars
        $ac = (int)$pdo->query("SELECT COUNT(*) FROM avatars")->fetchColumn();
        if ($ac === 0) {
            $pdo->exec("INSERT INTO avatars (uuid,name,file_path,category,is_active) VALUES \n                ('550e8400-e29b-41d4-a716-446655440001','默认头像1','/avatars/default/avatar_01.png','default',1)");
        }
        // Schools
        $sc = (int)$pdo->query("SELECT COUNT(*) FROM schools")->fetchColumn();
        if ($sc === 0) {
            $pdo->exec("INSERT INTO schools (id,name,status) VALUES (1,'示例学校', 'active')");
        }
        // Product categories
        $cat = (int)$pdo->query("SELECT COUNT(*) FROM product_categories")->fetchColumn();
        if ($cat === 0) {
            $pdo->exec("INSERT INTO product_categories (name, slug) VALUES \n                ('每日用品','daily'),\n                ('绿色出行','transport')");
        }

        // Products
        $pc = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        if ($pc === 0) {
            $pdo->exec("INSERT INTO products (name,description,category,category_slug,images,image_path,stock,points_required,status,sort_order) VALUES \n                ('可重复使用水杯','环保材质500ml水杯','daily','daily','[\"/images/products/eco_bottle_1.jpg\"]','/images/products/eco_bottle_1.jpg',100,100,'active',1),\n                ('竹制餐具套装','可降解竹制餐具三件套','daily','daily','[\"/images/products/bamboo_utensils.jpg\"]','/images/products/bamboo_utensils.jpg',50,150,'active',2)");
        }
        // Admin user (optional convenience)
        $uc = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($uc === 0) {
            $password = password_hash('password123', PASSWORD_BCRYPT);
            $pdo->exec("INSERT INTO users (username,email,password,school_id,status,points,is_admin,uuid) VALUES \n                ('admin_user','admin@testdomain.com','{$password}',1,'active',1000,1,'550e8400-e29b-41d4-a716-4466554400aa')");
        }
    }
}



