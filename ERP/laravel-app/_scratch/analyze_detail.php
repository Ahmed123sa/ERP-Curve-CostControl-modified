<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$dir = 'G:/CostControl/MrMix/2026/5/New folder';
$files = glob($dir . '/*.xls');

foreach ($files as $file) {
    echo '========== ' . basename($file) . ' ==========' . PHP_EOL;
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);
    
    // Full row 8 (header) - show ALL columns that have data
    echo 'Row 8 (Header) - all non-empty columns:' . PHP_EOL;
    for ($c = 0; $c <= 50; $c++) {
        if (isset($rows[7][$c]) && trim((string)$rows[7][$c]) !== '') {
            echo '  col' . $c . ' (Excel col ' . ($c+1) . '): [' . $rows[7][$c] . ']' . PHP_EOL;
        }
    }
    
    // Show a few data rows with item name col38 and qty col10
    echo PHP_EOL . 'Sample data rows (showing col10=qty, col38=item):' . PHP_EOL;
    $dataCount = 0;
    foreach ($rows as $r => $row) {
        $item = $row[38] ?? '';
        $qty = $row[10] ?? '';
        if (trim((string)$item) !== '' && trim((string)$qty) !== '' && $r > 8) {
            echo '  Row ' . ($r+1) . ': qty=[' . $qty . '] item=[' . $item . ']' . PHP_EOL;
            $dataCount++;
            if ($dataCount >= 15) break;
        }
    }
    
    // Check if there are any rows with item names in other columns
    echo PHP_EOL . 'Rows where col38 (item) is empty but other non-header cols have text (looking for item name elsewhere):' . PHP_EOL;
    $found = 0;
    foreach ($rows as $r => $row) {
        $item = $row[38] ?? '';
        if (trim((string)$item) === '' && $r > 8) {
            // Check other columns for text content
            for ($c = 0; $c <= 50; $c++) {
                $v = $row[$c] ?? '';
                if (trim((string)$v) !== '' && $c != 1 && $c != 2 && $c != 6 && $c != 10 && $c != 12 && $c != 18 && $c != 20 && $c != 28) {
                    // This might have text data in an unexpected column
                    if (preg_match('/[?-?]/u', $v) || preg_match('/[a-zA-Z]/u', $v)) {
                        echo '  Row ' . ($r+1) . ': col' . $c . '=[' . $v . ']' . PHP_EOL;
                        $found++;
                        if ($found >= 10) break 2;
                    }
                }
            }
        }
    }
    if ($found == 0) echo '  (none - all item names are in col38)' . PHP_EOL;
    
    echo PHP_EOL;
}
