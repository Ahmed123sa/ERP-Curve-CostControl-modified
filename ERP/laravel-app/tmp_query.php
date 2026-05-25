<?php
try {
    $db = new PDO('sqlite:database/database.sqlite');
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    echo "Tables:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['name'] . PHP_EOL;
    }
    echo "\n--- Items ---\n";
    $stmt = $db->query("SELECT id, name, unit, category, default_cost FROM items ORDER BY name LIMIT 100");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo implode(' | ', $row) . PHP_EOL;
    }
} catch(Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
