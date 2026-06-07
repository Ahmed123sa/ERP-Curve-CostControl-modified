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
    
    // Check columns 0-50 for data density
    echo 'Column density (non-empty count across all rows):' . PHP_EOL;
    for ($c = 0; $c <= 50; $c++) {
        $count = 0;
        $sample = '';
        foreach ($rows as $r => $row) {
            if (isset($row[$c]) && trim((string)$row[$c]) !== '') {
                $count++;
                if ($sample === '' && $r > 7) { // skip headers
                    $sample = $row[$c];
                }
            }
        }
        if ($count > 0) {
            echo '  col' . $c . ': ' . $count . ' non-empty, sample="' . $sample . '"' . PHP_EOL;
        }
    }
    
    // Check merged cells / structure
    echo PHP_EOL . 'Sheet title row: ' . $sheet->getTitle() . PHP_EOL;
    
    echo PHP_EOL;
}
