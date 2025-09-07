<?php
// Temporary diagnostic script to inspect carbon_activities in SQLite test.db
try {
    $path = 'test.db';
    if (!file_exists($path)) {
        echo "DB file not found: $path" . PHP_EOL;
        exit(0);
    }
    $db = new PDO('sqlite:' . $path);
    $count = (int)$db->query('SELECT COUNT(*) FROM carbon_activities')->fetchColumn();
    echo "carbon_activities count=$count" . PHP_EOL;
    if ($count > 0) {
        $stmt = $db->query('SELECT id,name_zh,name_en,category,carbon_factor,unit FROM carbon_activities LIMIT 3');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}