<?php

// Simple SQLite database setup for testing
try {
    $db = new PDO('sqlite:/home/runner/work/backend/backend/test.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create users table
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(255),
            password VARCHAR(255) NOT NULL,
            lastlgn DATETIME,
            email VARCHAR(255) NOT NULL UNIQUE,
            points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            school VARCHAR(255),
            location VARCHAR(255),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            is_admin BOOLEAN NOT NULL DEFAULT 0,
            class_name VARCHAR(100),
            school_id INTEGER,
            avatar_id INTEGER
        )
    ");
    
    // Create schools table
    $db->exec("
        CREATE TABLE IF NOT EXISTS schools (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            deleted_at DATETIME,
            location VARCHAR(255),
            is_active BOOLEAN NOT NULL DEFAULT 1
        )
    ");
    
    // Create avatars table
    $db->exec("
        CREATE TABLE IF NOT EXISTS avatars (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid VARCHAR(36) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(255),
            file_path VARCHAR(500) NOT NULL,
            thumbnail_path VARCHAR(500),
            category VARCHAR(50) DEFAULT 'default',
            sort_order INTEGER DEFAULT 0,
            is_active BOOLEAN DEFAULT 1,
            is_default BOOLEAN DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME
        )
    ");
    
    // Create carbon_activities table
    $db->exec("
        CREATE TABLE IF NOT EXISTS carbon_activities (
            id VARCHAR(36) PRIMARY KEY,
            name_zh VARCHAR(255) NOT NULL,
            name_en VARCHAR(255) NOT NULL,
            category VARCHAR(100) NOT NULL,
            carbon_factor DECIMAL(10,4) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            description_zh TEXT,
            description_en TEXT,
            icon VARCHAR(100),
            is_active BOOLEAN NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME
        )
    ");
    
    // Insert some sample data
    
    // Sample school
    $db->exec("
        INSERT OR IGNORE INTO schools (id, name, location, is_active) 
        VALUES (1, 'Test School', 'Test Location', 1)
    ");
    
    // Sample avatar
    $db->exec("
        INSERT OR IGNORE INTO avatars (id, uuid, name, file_path, category, is_active, is_default) 
        VALUES (1, '550e8400-e29b-41d4-a716-446655440001', 'Default Avatar', '/avatars/default/avatar_01.png', 'default', 1, 1)
    ");
    
    // Sample carbon activity
    $db->exec("
        INSERT OR IGNORE INTO carbon_activities (id, name_zh, name_en, category, carbon_factor, unit, is_active, sort_order)
        VALUES 
        ('550e8400-e29b-41d4-a716-446655440001', '购物时自带袋子', 'Bring your own bag when shopping', 'daily', 0.0190, 'times', 1, 1),
        ('550e8400-e29b-41d4-a716-446655440002', '公交地铁通勤', 'Use public transport', 'transport', 0.1005, 'km', 1, 2)
    ");
    
    echo "Database setup completed successfully.\n";
    
} catch (PDOException $e) {
    echo "Database setup failed: " . $e->getMessage() . "\n";
    exit(1);
}