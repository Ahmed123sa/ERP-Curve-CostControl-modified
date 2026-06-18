<?php
require "C:/Users/DELL/Downloads/ERP_CostControl/ERP/laravel-app/vendor/autoload.php";
use PhpOffice\PhpSpreadsheet\IOFactory;

$files = [
    "MrMix" => "G:/CostControl/MrMix/2026/5/New folder/مستر ميكس من وردية 925 الي وردية 986.xls",
    "MyBroast" => "G:/CostControl/MrMix/2026/5/New folder/ماي بروست من وردية 929 الي وردية 990.xls",
    "SHRIMP" => "G:/CostControl/MrMix/2026/5/New folder/SHRIMP-5-2026.xls",
];

foreach ($files as $label => $file) {
    if (!file_exists($file)) {
        echo "FILE [$label] NOT FOUND: $file\n\n";
        continue;
    }
    echo "============================================================\n";
    echo "=== FILE: $label ===\n";
    echo "=== Path: $file ===\n";
    echo "============================================================\n";
    
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);
    $total = count($rows);
    echo "Total rows: $total\n\n";

    echo "--- 1. Category columns: Rows 6-9, Cols 28-42 ---\n";
    for ($r = 6; $r <= min(9, $total-1); $r++) {
        if (!isset($rows[$r])) continue;
        echo "Row " . ($r+1) . ": ";
        for ($c = 28; $c <= 42; $c++) {
            if (isset($rows[$r][$c]) && ((is_string($rows[$r][$c]) && trim($rows[$r][$c]) !== "") || is_numeric($rows[$r][$c]))) {
                echo "col$c=[" . $rows[$r][$c] . "] ";
            }
        }
        echo "\n";
    }
    echo "\n";

    echo "--- 2. Unique sizes (col28) across all data rows ---\n";
    $sizes = [];
    for ($r = 9; $r < $total; $r++) {
        $sz = isset($rows[$r][28]) ? trim((string)$rows[$r][28]) : "";
        $nm = isset($rows[$r][38]) ? trim((string)$rows[$r][38]) : "";
        if (!empty($sz)) {
            if (!isset($sizes[$sz])) $sizes[$sz] = ["count" => 0, "examples" => []];
            $sizes[$sz]["count"]++;
            if (count($sizes[$sz]["examples"]) < 3 && !empty($nm)) $sizes[$sz]["examples"][] = $nm;
        }
    }
    foreach ($sizes as $k => $v) {
        echo "[$k] => count=" . $v["count"];
        if (!empty($v["examples"])) echo " ex: " . implode(", ", $v["examples"]);
        echo "\n";
    }
    echo "\n";

    echo "--- 3. Sample rows 9-38 (0-indexed) ---\n";
    for ($r = 9; $r < min(39, $total); $r++) {
        if (!isset($rows[$r])) continue;
        $qty = isset($rows[$r][10]) ? $rows[$r][10] : "";
        $name = isset($rows[$r][38]) ? trim((string)$rows[$r][38]) : "";
        $size = isset($rows[$r][28]) ? trim((string)$rows[$r][28]) : "";
        $cat32 = isset($rows[$r][32]) ? trim((string)$rows[$r][32]) : "";
        $cat33 = isset($rows[$r][33]) ? trim((string)$rows[$r][33]) : "";
        $cat = $cat32;
        if (!empty($cat33) && empty($cat)) $cat = $cat33;
        elseif (!empty($cat33) && !empty($cat)) $cat = $cat . "|" . $cat33;
        echo "Row " . ($r+1) . ": qty=[$qty] name=[$name] size=[$size] cat=[$cat]\n";
    }
    echo "\n";

    echo "--- 4. Raw character data for first 5 items with names ---\n";
    $found = 0;
    for ($r = 9; $r < $total && $found < 5; $r++) {
        $nm = isset($rows[$r][38]) ? $rows[$r][38] : "";
        if (!empty(trim((string)$nm))) {
            echo "Row " . ($r+1) . ": name = ";
            $len = strlen((string)$nm);
            for ($i = 0; $i < $len; $i++) {
                echo "[\x" . dechex(ord((string)$nm[$i])) . "]";
            }
            echo " | display: [$nm]\n";
            $found++;
        }
    }
    echo "\n";

    echo "--- 5. Items sharing common base name with sizes ---\n";
    $items = [];
    for ($r = 9; $r < $total; $r++) {
        $nm = isset($rows[$r][38]) ? trim((string)$rows[$r][38]) : "";
        $sz = isset($rows[$r][28]) ? trim((string)$rows[$r][28]) : "";
        if (!empty($nm)) {
            $items[$nm][] = $sz;
        }
    }
    $bases = [];
    foreach ($items as $name => $sizes) {
        $base = preg_replace("/\s.*/", "", $name);
        $bases[$base][$name] = $sizes;
    }
    foreach ($bases as $base => $nameMap) {
        if (count($nameMap) > 1) {
            echo "Base: [$base]\n";
            foreach ($nameMap as $name => $sizes) {
                $uniqueSizes = array_values(array_unique(array_filter($sizes)));
                echo "  Name: [$name] Sizes: " . (empty($uniqueSizes) ? "(empty)" : implode(", ", $uniqueSizes)) . "\n";
            }
            echo "\n";
        }
    }
    echo "\n";

    echo "--- 6. Non-empty data in columns 39-44 ---\n";
    $foundCols = [];
    for ($r = 9; $r < $total; $r++) {
        for ($c = 39; $c <= 44; $c++) {
            if (isset($rows[$r][$c]) && !empty(trim((string)$rows[$r][$c]))) {
                if (!isset($foundCols[$c])) $foundCols[$c] = [];
                $val = $rows[$r][$c];
                $foundCols[$c][] = $val;
            }
        }
    }
    foreach ($foundCols as $c => $vals) {
        $unique = array_values(array_unique($vals));
        echo "col$c: " . count($vals) . " non-empty values, unique (" . count($unique) . "): " . implode(", ", array_slice($unique, 0, 15)) . "\n";
    }
    if (empty($foundCols)) echo "No data found in columns 39-44\n";
    echo "\n";
}
