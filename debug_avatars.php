<?php

// Debug avatar query
try {
    $db = new PDO('sqlite:/home/runner/work/backend/backend/test.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Testing avatar query...\n";
    
    $sql = "
        SELECT id, uuid, name, description, file_path, thumbnail_path, 
               category, sort_order, is_default
        FROM avatars 
        WHERE is_active = 1 AND deleted_at IS NULL
        ORDER BY sort_order ASC, id ASC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    
    $avatars = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($avatars) . " avatars:\n";
    foreach ($avatars as $avatar) {
        print_r($avatar);
        echo "\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}