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
            "CREATE TABLE IF NOT EXISTS users (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                username TEXT UNIQUE,\n                email TEXT UNIQUE,\n                password TEXT,\n                uuid TEXT,\n                school_id INTEGER,\n                status TEXT,\n                points INTEGER DEFAULT 0,\n                is_admin INTEGER DEFAULT 0,\n                image_path TEXT,\n                deleted_at TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Products
            "CREATE TABLE IF NOT EXISTS products (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                name TEXT,\n                description TEXT,\n                category TEXT,\n                images TEXT,\n                image_path TEXT,\n                stock INTEGER DEFAULT 0,\n                points_required INTEGER DEFAULT 0,\n                status TEXT DEFAULT 'active',\n                sort_order INTEGER DEFAULT 0,\n                deleted_at TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Carbon activities (align with production columns subset used by model & controllers)
            "CREATE TABLE IF NOT EXISTS carbon_activities (\n                id TEXT PRIMARY KEY,\n                name_zh TEXT,\n                name_en TEXT,\n                category TEXT,\n                carbon_factor REAL,\n                unit TEXT,\n                description_zh TEXT,\n                description_en TEXT,\n                icon TEXT,\n                is_active INTEGER DEFAULT 1,\n                sort_order INTEGER DEFAULT 0,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                deleted_at TEXT\n            )",
            // Carbon records
            "CREATE TABLE IF NOT EXISTS carbon_records (\n                id TEXT PRIMARY KEY,\n                user_id INTEGER,\n                activity_id TEXT,\n                raw REAL,\n                points INTEGER,\n                status TEXT,\n                description TEXT,\n                proof_images TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                approved_at TEXT,\n                deleted_at TEXT\n            )",
            // Schools (needed for registration validation when school_id provided)
            "CREATE TABLE IF NOT EXISTS schools (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                name TEXT,\n                status TEXT DEFAULT 'active',\n                deleted_at TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Point exchanges
            "CREATE TABLE IF NOT EXISTS point_exchanges (\n                id TEXT PRIMARY KEY,\n                user_id INTEGER,\n                product_id INTEGER,\n                quantity INTEGER,\n                points_used INTEGER,\n                product_name TEXT,\n                product_price INTEGER,\n                delivery_address TEXT,\n                contact_phone TEXT,\n                notes TEXT,\n                status TEXT,\n                tracking_number TEXT,\n                deleted_at TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT\n            )",
            // Points transactions
            "CREATE TABLE IF NOT EXISTS points_transactions (\n                id TEXT PRIMARY KEY,\n                user_id INTEGER,\n                points REAL,\n                type TEXT,\n                description TEXT,\n                related_table TEXT,\n                related_id TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Messages (minimal columns used in service)
            "CREATE TABLE IF NOT EXISTS messages (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                sender_id INTEGER,\n                receiver_id INTEGER,\n                title TEXT,\n                content TEXT,\n                is_read INTEGER DEFAULT 0,\n                deleted_at TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Audit logs (subset)
            "CREATE TABLE IF NOT EXISTS audit_logs (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                user_id INTEGER,\n                action TEXT,\n                entity_type TEXT,\n                entity_id TEXT,\n                old_values TEXT,\n                new_values TEXT,\n                ip_address TEXT,\n                user_agent TEXT,\n                data TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Login attempts
            "CREATE TABLE IF NOT EXISTS login_attempts (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                username TEXT,\n                ip_address TEXT,\n                success INTEGER,\n                attempted_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Error logs (simplified)
            "CREATE TABLE IF NOT EXISTS error_logs (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                error_type TEXT,\n                error_message TEXT,\n                error_file TEXT,\n                error_line INTEGER,\n                error_time TEXT,\n                script_name TEXT,\n                client_get TEXT,\n                client_post TEXT,\n                client_files TEXT,\n                client_cookie TEXT,\n                client_session TEXT,\n                client_server TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Idempotency records
            "CREATE TABLE IF NOT EXISTS idempotency_records (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                request_id TEXT UNIQUE,\n                response_body TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )",
            // Avatars (expanded to satisfy controller selected columns)
            "CREATE TABLE IF NOT EXISTS avatars (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                uuid TEXT,\n                name TEXT,\n                description TEXT,\n                file_path TEXT,\n                thumbnail_path TEXT,\n                category TEXT,\n                sort_order INTEGER DEFAULT 0,\n                is_default INTEGER DEFAULT 0,\n                is_active INTEGER DEFAULT 1,\n                deleted_at TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP,\n                updated_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )"
            ,
            // System logs table for request logging middleware
            "CREATE TABLE IF NOT EXISTS system_logs (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                request_id TEXT,\n                method TEXT,\n                path TEXT,\n                status_code INTEGER,\n                user_id INTEGER,\n                ip_address TEXT,\n                user_agent TEXT,\n                duration_ms REAL,\n                request_body TEXT,\n                response_body TEXT,\n                created_at TEXT DEFAULT CURRENT_TIMESTAMP\n            )"
        ];

        foreach ($tables as $sql) {
            try { $pdo->exec($sql); } catch (\Throwable $e) { /* ignore */ }
        }

        // Seed minimal reference data if absent
        self::seed($pdo);
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
        // Products
        $pc = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        if ($pc === 0) {
            $pdo->exec("INSERT INTO products (name,description,category,images,image_path,stock,points_required,status,sort_order) VALUES \n                ('可重复使用水杯','环保材质500ml水杯','daily','[\"/images/products/eco_bottle_1.jpg\"]','/images/products/eco_bottle_1.jpg',100,100,'active',1),\n                ('竹制餐具套装','可降解竹制餐具三件套','daily','[\"/images/products/bamboo_utensils.jpg\"]','/images/products/bamboo_utensils.jpg',50,150,'active',2)");
        }
        // Admin user (optional convenience)
        $uc = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($uc === 0) {
            $password = password_hash('password123', PASSWORD_BCRYPT);
            $pdo->exec("INSERT INTO users (username,email,password,school_id,status,points,is_admin,uuid) VALUES \n                ('admin_user','admin@testdomain.com','{$password}',1,'active',1000,1,'550e8400-e29b-41d4-a716-4466554400aa')");
        }
    }
}
