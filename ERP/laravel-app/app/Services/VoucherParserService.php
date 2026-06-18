<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * VoucherParserService
 * المسؤول عن قراءة ملفات Excel وتحليل الأذون
 */
class VoucherParserService
{
    private const HEADER_PATTERN = '/^\s*-{0,3}\s*(\d{1,2}[\/\-]\d{1,2})\s*[\|-]?\s*(.*?)\s*-{0,3}\s*$/u';

    public function parse(string $filePath, int $year = null): array
    {
        $year ??= now()->year;
        $spreadsheet = IOFactory::load($filePath);
        $vouchers    = [];
        $errors      = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $result = $this->parseSheet($sheet, $year);
            $vouchers = array_merge($vouchers, $result['vouchers']);
            $errors   = array_merge($errors, $result['errors']);
        }

        return ['vouchers' => $vouchers, 'errors' => $errors];
    }

    private function parseSheet($sheet, int $year): array
    {
        $vouchers = [];
        $errors   = [];
        $rows     = $sheet->toArray(null, true, true, false);

        $currentHeader   = null;
        $qtyColIndex     = 3;
        $costColIndex    = 4;
        $dateColIndex    = null;

        foreach ($rows as $rowIndex => $row) {
            if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            // 1. هل السطر ده header إذن؟
            $headerFound = false;
            foreach ($row as $cell) {
                $cellStr = trim((string) ($cell ?? ''));
                $headerDate = null;
                $headerLoc = '';

                // Try exact pattern first (date-first with pipe/dash separator)
                if (preg_match(self::HEADER_PATTERN, $cellStr, $matches)) {
                    $headerDate = $matches[1] ?? null;
                    $headerLoc = trim($matches[2] ?? '');
                }

                // Fallback: find date (DD/MM or DD-MM) anywhere in cell
                if (!$headerDate && preg_match('/(\d{1,2}[\/\-]\d{1,2})/u', $cellStr, $dateMatches)) {
                    $headerDate = $dateMatches[1];
                    $headerLoc = trim(str_replace($headerDate, '', $cellStr));
                    $headerLoc = preg_replace('/^[\s\|\-\/\\\]+|[\s\|\-\/\\\]+$/u', '', $headerLoc);
                }

                if ($headerDate) {
                    $locName = $headerLoc;
                    
                    // لو الاسم فاضي أو عام جداً، جرب نستخدم اسم الشيت (مثلاً اسم التاب هو "المعمل")
                    if (empty($locName) || in_array(mb_strtolower($locName), ['اذن صرف', 'وارد', 'صرف', 'اذن', 'اذن صرف فرع'])) {
                        $locName = $sheet->getTitle();
                    }

                    $currentHeader = [
                        'date_str'    => $headerDate,
                        'location'    => $locName,
                        'date'        => $this->parseDate($headerDate, $year),
                        'items'       => [],
                    ];
                    $vouchers[] = &$currentHeader;
                    $headerFound = true;

                    // محاولة اكتشاف الأعمدة من الأسطر التالية
                    $dateColIndex = null;
                    for ($look = 1; $look <= 3; $look++) {
                        if (!isset($rows[$rowIndex + $look])) break;
                        foreach ($rows[$rowIndex + $look] as $ci => $c) {
                            $txt = mb_strtolower(trim((string) $c));
                            if (in_array($txt, ['الكمية', 'كمية', 'qty', 'quantity'])) $qtyColIndex = $ci;
                            if (in_array($txt, ['cost', 'تكلفة', 'التكلفة', 'إجمالي'])) $costColIndex = $ci;
                            if (in_array($txt, ['التاريخ', 'تاريخ', 'date', 'day'])) $dateColIndex = $ci;
                        }
                    }
                    break;
                }
            }

            if ($headerFound) continue;

            // 2. بيانات الأصناف
            if ($currentHeader !== null) {
                $seq  = $row[0] ?? null;
                $name = trim((string) ($row[1] ?? ''));
                
                if ($seq !== null && is_numeric(trim((string)$seq)) && $name !== '') {
                    $qtyVal   = $this->cleanNumber($row[$qtyColIndex] ?? 0);
                    $costVal  = $this->cleanNumber($row[$costColIndex] ?? 0);
                    $unitCost = ($qtyVal > 0 && $costVal > 0) ? round($costVal / $qtyVal, 4) : 0.0;

                    $lineDate = null;
                    if ($dateColIndex !== null && !empty($row[$dateColIndex])) {
                        $lineDate = $this->parseDate((string) $row[$dateColIndex], $year)->toDateString();
                    }

                    $currentHeader['items'][] = [
                        'seq'       => (int) $seq,
                        'name'      => $name,
                        'unit'      => trim((string) ($row[2] ?? '')),
                        'qty'       => $qtyVal,
                        'cost'      => $costVal,
                        'unit_cost' => $unitCost,
                        'date'      => $lineDate,
                    ];
                }
            }
        }

        $cleaned = array_filter($vouchers, fn($v) => !empty($v['items']));
        return ['vouchers' => array_values($cleaned), 'errors' => $errors];
    }

    private function cleanNumber($val): float
    {
        if ($val === null || $val === '') return 0.0;
        if (is_numeric($val)) return (float) $val;
        $clean = str_replace([',', ' '], ['', ''], (string)$val);
        return is_numeric($clean) ? (float) $clean : 0.0;
    }

    private function parseDate(string $dateStr, int $year): Carbon
    {
        try {
            if (is_numeric($dateStr)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $dateStr));
            }

            $clean = trim(str_replace(['\\', '-', '.'], '/', $dateStr));
            $parts = explode('/', $clean);
            if (count($parts) < 2) {
                return Carbon::now();
            }

            $first = (int) $parts[0];
            $second = (int) $parts[1];
            $third = isset($parts[2]) ? (int) $parts[2] : $year;
            if ($third < 100) {
                $third += 2000;
            }

            try {
                return Carbon::createFromDate($third, $second, $first);
            } catch (\Exception) {
                return Carbon::createFromDate($third, $first, $second);
            }
        } catch (\Exception) {
            return Carbon::now();
        }
    }

    public function detectVoucherType(string $locationName): string
    {
        $lower = mb_strtolower($locationName);

        // 1. Specific multi-word types checked first (avoid keyword overlap)
        if (Str::contains($lower, ['مبيعات خارجية', 'بيع خارجي'])) {
            return 'external_sale';
        }
        if (Str::contains($lower, ['أول المدة', 'اول المدة'])) {
            return 'opening';
        }

        // 2. Production — checked before purchase because production output
        //    is often in a sheet named e.g. "وارد إنتاج" or "مشتريات إنتاج"
        if (Str::contains($lower, ['انتاج', 'إنتاج'])) {
            return 'production';
        }

        // 3. Purchase keywords (most specific inbound type)
        if (Str::contains($lower, ['وارد', 'مشتريات', 'شراء'])) {
            return 'purchase';
        }

        // 4. Other specific types
        $typeMap = [
            'withdrawal'    => ['مسحوبات', 'سحب'],
            'return'        => ['مرتجع', 'إرجاع'],
            'adjustment'    => ['تسوية', 'تعديل', 'جرد'],
            'opening'       => ['افتتاحي', 'بداية', 'opening'],
        ];

        foreach ($typeMap as $type => $keywords) {
            if (Str::contains($lower, $keywords)) return $type;
        }

        // 6. Dispatch keywords — last (broadest, catch-all for branch names)
        if (Str::contains($lower, ['صرف', 'منصرف', 'فرع', 'تحويل', 'نقل', 'صادر'])) {
            return 'dispatch';
        }

        return 'dispatch';
    }
}
