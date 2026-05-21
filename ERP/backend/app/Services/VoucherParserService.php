<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Illuminate\Support\Carbon;

/**
 * VoucherParserService
 * نفس منطق inventory_parser.py بس في PHP
 *
 * يقرأ ملفات Excel بالشكل:
 *   م | الصنف | الوحدة | الكمية | cost
 *   --- 9/4 | وارد مخزن ---
 *   1  | فرايز | كيلو | 200 | 10300
 *
 * ويرجع array من الأذون
 */
class VoucherParserService
{
    private const HEADER_PATTERN = '/^\s*-{0,3}\s*([^|]+?)\s*\|\s*(.*?)\s*-{0,3}\s*$/u';

    /**
     * اقرأ ملف Excel وارجع الأذون
     *
     * @return array{vouchers: array, errors: array}
     */
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
        $qtyColIndex     = 3; // عمود الكمية (0-based)
        $costColIndex    = 4; // عمود الـ cost (0-based)

        foreach ($rows as $rowIndex => $row) {
            if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            // هل السطر ده header إذن؟
            foreach ($row as $cell) {
                $cellStr = trim((string) ($cell ?? ''));
                if (preg_match(self::HEADER_PATTERN, $cellStr, $matches)) {
                    $parsedDate = $this->parseDate(trim($matches[1]), $year);
                    if (!$parsedDate) {
                        $errors[] = [
                            'sheet' => $sheet->getTitle(),
                            'row'   => $rowIndex + 1,
                            'cell'  => $cellStr,
                            'error' => 'تعذر قراءة تاريخ الإذن',
                        ];
                        continue;
                    }

                    $currentHeader = [
                        'date_str'    => $matches[1],
                        'location'    => trim($matches[2]),
                        'date'        => $parsedDate,
                        'items'       => [],
                    ];
                    $vouchers[] = &$currentHeader;
                    break;
                }
            }

            // هل السطر ده بيانات صنف؟
            if ($currentHeader !== null) {
                $seq      = $row[0] ?? null;
                $name     = trim((string) ($row[1] ?? ''));
                $unit     = trim((string) ($row[2] ?? ''));
                $qty      = $row[$qtyColIndex] ?? null;
                $cost     = $row[$costColIndex] ?? null;

                // التأكد إن رقم التسلسل رقم فعلي (مش header)
                if ($seq !== null && is_numeric($seq) && $name !== '' && $qty !== null && is_numeric($qty)) {
                    $qtyVal  = (float) $qty;
                    $costVal = ($cost !== null && $cost !== '') ? (float) $cost : 0.0;
                    $unitCost = ($qtyVal > 0 && $costVal > 0) ? round($costVal / $qtyVal, 4) : 0.0;

                    $currentHeader['items'][] = [
                        'seq'       => (int) $seq,
                        'name'      => $name,
                        'unit'      => $unit,
                        'qty'       => $qtyVal,
                        'cost'      => $costVal,       // إجمالي القيمة
                        'unit_cost' => $unitCost,      // cost ÷ qty
                    ];
                }
            }
        }

        // إزالة الـ references وتنظيف المصفوفة
        $cleaned = [];
        foreach ($vouchers as $v) {
            if (!empty($v['items'])) {
                $cleaned[] = $v;
            }
        }

        return ['vouchers' => $cleaned, 'errors' => $errors];
    }

    /**
     * تحويل "9/4" إلى Carbon date
     */
    private function parseDate(string $dateStr, int $year): ?Carbon
    {
        try {
            // excel serial date
            if (is_numeric($dateStr)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $dateStr));
            }

            $clean = trim(str_replace(['\\', '-', '.'], '/', $dateStr));
            $parts = explode('/', $clean);
            if (count($parts) < 2) {
                return null;
            }

            $first = (int) $parts[0];
            $second = (int) $parts[1];
            $third = isset($parts[2]) ? (int) $parts[2] : $year;

            if ($third < 100) {
                $third += 2000;
            }

            // default expected format d/m[/y]
            try {
                return Carbon::createFromDate($third, $second, $first);
            } catch (\Exception) {
                // fallback m/d[/y]
                return Carbon::createFromDate($third, $first, $second);
            }
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * تحديد نوع الإذن من اسم الموقع
     * "وارد مخزن" → purchase
     * "ماي بروست"  → dispatch
     */
    public function detectVoucherType(string $locationName): string
    {
        $lower = mb_strtolower($locationName);
        if (str_contains($lower, 'وارد') || str_contains($lower, 'مخزن')) {
            return 'purchase';
        }
        if (str_contains($lower, 'مبيعات')) {
            return 'external_sale';
        }
        if (str_contains($lower, 'مسحوبات')) {
            return 'withdrawal';
        }
        if (str_contains($lower, 'معمل')) {
            return 'production';
        }
        // أي اسم تاني → إذن صرف لفرع
        return 'dispatch';
    }
}
