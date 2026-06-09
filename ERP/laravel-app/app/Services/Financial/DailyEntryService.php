<?php

namespace App\Services\Financial;

use App\Models\Client;
use App\Models\Financial\FinancialDailyEntry;
use App\Models\Financial\FinancialDailyEntryDetail;
use App\Models\Financial\FinancialExpenseCategory;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class DailyEntryService
{
    public function list(string $clientId, string $month): array
    {
        [$year, $monthNum] = explode('-', $month);

        $entries = FinancialDailyEntry::where('client_id', $clientId)
            ->whereYear('date', $year)
            ->whereMonth('date', $monthNum)
            ->with('details.category', 'details.item')
            ->orderBy('date')
            ->get();

        $categories = FinancialExpenseCategory::where('client_id', $clientId)
            ->orWhereNull('client_id')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'code', 'sort_order']);

        return [
            'entries' => $entries,
            'categories' => $categories,
        ];
    }

    public function itemsByCategory(string $clientId, ?string $categoryId = null): \Illuminate\Support\Collection
    {
        return Item::where('client_id', $clientId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'expense_category_id']);
    }

    public function store(string $clientId, array $data): FinancialDailyEntry
    {
        return DB::transaction(function () use ($clientId, $data) {
            $totalExpenses = collect($data['details'] ?? [])->sum('amount');
            $netDaily = ($data['total_sales'] ?? 0) - $totalExpenses;

            $entry = FinancialDailyEntry::updateOrCreate(
                ['client_id' => $clientId, 'date' => $data['date']],
                [
                    'total_sales' => $data['total_sales'] ?? 0,
                    'total_expenses' => $totalExpenses,
                    'net_daily' => $netDaily,
                    'notes' => $data['notes'] ?? null,
                ]
            );

            $entry->details()->delete();

            if (!empty($data['details'])) {
                foreach ($data['details'] as $detail) {
                    if (($detail['amount'] ?? 0) > 0) {
                        $entry->details()->create([
                            'client_id' => $clientId,
                            'expense_category_id' => $detail['expense_category_id'],
                            'amount' => $detail['amount'],
                            'description' => $detail['description'] ?? null,
                            'quantity' => $detail['quantity'] ?? null,
                            'item_id' => $detail['item_id'] ?? null,
                        ]);
                    }
                }

                $entry->load('details.category', 'details.item');
            }

            return $entry;
        });
    }

    public function destroy(string $clientId, string $id): bool
    {
        $entry = FinancialDailyEntry::where('client_id', $clientId)
            ->findOrFail($id);

        return $entry->delete();
    }

    public function exportSingleDay(string $clientId, string $month, int $day): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        [$year, $monthNum] = explode('-', $month);
        $dateStr = sprintf('%s-%02d-%02d', $year, $monthNum, $day);

        $client = Client::find($clientId);
        $clientName = $client ? $client->name : '';

        $entry = FinancialDailyEntry::where('client_id', $clientId)
            ->whereDate('date', $dateStr)
            ->with('details.category', 'details.item')
            ->first();

        $categories = FinancialExpenseCategory::where('client_id', $clientId)
            ->orWhereNull('client_id')
            ->orderBy('sort_order')
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle((string) $day);
        $sheet->setRightToLeft(true);

        $catCol = 1;
        $headerRow = 1;
        $sheet->setCellValue('A1', 'اليوم');
        $sheet->mergeCells('A1:A2');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $catCol++;

        $sheet->setCellValue('B1', 'التاريخ');
        $sheet->mergeCells('B1:B2');
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('B1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $catCol++;

        $salesColLetter = Coordinate::stringFromColumnIndex($catCol);
        $sheet->setCellValue($salesColLetter . '1', 'المبيعات');
        $sheet->mergeCells($salesColLetter . '1:' . $salesColLetter . '2');
        $sheet->getStyle($salesColLetter . '1')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle($salesColLetter . '1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $salesCol = $catCol;
        $catCol++;

        foreach ($categories as $cat) {
            $colLetter = Coordinate::stringFromColumnIndex($catCol);
            $sheet->setCellValue($colLetter . '1', $cat->name);
            $sheet->mergeCells($colLetter . '1:' . Coordinate::stringFromColumnIndex($catCol + 1) . '1');
            $sheet->getStyle($colLetter . '1')->getFont()->setBold(true)->setSize(10);
            $sheet->getStyle($colLetter . '1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getColumnDimension($colLetter)->setWidth(14);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($catCol + 1))->setWidth(18);
            $sheet->setCellValue($colLetter . '2', 'المبلغ');
            $sheet->getStyle($colLetter . '2')->getFont()->setSize(9)->getColor()->setARGB('FF666666');
            $sheet->getStyle($colLetter . '2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($catCol + 1) . '2', 'البيان');
            $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol + 1) . '2')->getFont()->setSize(9)->getColor()->setARGB('FF666666');
            $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol + 1) . '2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $catCol += 2;
        }

        $expColLetter = Coordinate::stringFromColumnIndex($catCol);
        $sheet->setCellValue($expColLetter . '1', 'إجمالي المصروفات');
        $sheet->mergeCells($expColLetter . '1:' . $expColLetter . '2');
        $sheet->getStyle($expColLetter . '1')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FFCC0000');
        $expCol = $catCol;
        $catCol++;

        $netColLetter = Coordinate::stringFromColumnIndex($catCol);
        $sheet->setCellValue($netColLetter . '1', 'الصافي');
        $sheet->mergeCells($netColLetter . '1:' . $netColLetter . '2');
        $sheet->getStyle($netColLetter . '1')->getFont()->setBold(true)->setSize(11);
        $netCol = $catCol;
        $lastCol = $catCol;

        $startRow = 4;
        $row = $startRow;
        $details = $entry ? $entry->details : collect([]);
        $detailsByCat = $details->groupBy('expense_category_id');
        $maxDetails = max($detailsByCat->map->count()->max() ?? 0, 1);

        for ($i = 0; $i < $maxDetails; $i++) {
            $sheet->setCellValue('A' . $row, $i + 1);
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->setCellValue('B' . $row, $day . '/' . $monthNum . '/' . $year);
            $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            if ($i === 0 && $entry) {
                $sheet->setCellValue($salesColLetter . $row, $entry->total_sales);
            }
            $row++;
        }

        $dataEndRow = $row - 1;
        if ($dataEndRow < $startRow) $dataEndRow = $startRow;

        $catIdx = 4;
        foreach ($categories as $cat) {
            $catDetails = $detailsByCat->get($cat->id, collect([]));
            $r = $startRow;
            foreach ($catDetails as $det) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($catIdx) . $r, $det->amount);
                $sheet->getStyle(Coordinate::stringFromColumnIndex($catIdx) . $r)->getNumberFormat()->setFormatCode('#,##0.00');
                if ($det->description) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($catIdx + 1) . $r, $det->description);
                }
                $r++;
            }
            $catIdx += 2;

            $colLetter = Coordinate::stringFromColumnIndex($catIdx - 2);
            $sumRow = $dataEndRow + 1;
            $sheet->setCellValue($colLetter . $sumRow, "=SUM({$colLetter}{$startRow}:{$colLetter}{$dataEndRow})");
            $sheet->getStyle($colLetter . $sumRow)->getFont()->setBold(true);
            $sheet->getStyle($colLetter . $sumRow)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $sumRow = $dataEndRow + 1;
        $expFormula = '';
        $catIdx = 4;
        foreach ($categories as $i => $cat) {
            $colLetter = Coordinate::stringFromColumnIndex($catIdx);
            if ($i > 0) $expFormula .= '+';
            $expFormula .= "{$colLetter}{$sumRow}";
            $catIdx += 2;
        }
        $sheet->setCellValue($expColLetter . $sumRow, "={$expFormula}");
        $sheet->getStyle($expColLetter . $sumRow)->getFont()->setBold(true)->getColor()->setARGB('FFCC0000');
        $sheet->setCellValue($netColLetter . $sumRow, "=IF({$salesColLetter}{$dataEndRow}>0,{$salesColLetter}{$dataEndRow}-{$expColLetter}{$sumRow},0)");
        $sheet->getStyle($netColLetter . $sumRow)->getFont()->setBold(true);

        $styleArray = [
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]],
        ];
        $sheet->getStyle(Coordinate::stringFromColumnIndex(1) . $startRow . ':' . Coordinate::stringFromColumnIndex($lastCol) . $sumRow)
            ->applyFromArray($styleArray);

        $sheet->mergeCells('A3:' . Coordinate::stringFromColumnIndex($lastCol) . '3');
        $sheet->setCellValue('A3', "اليوم: {$day} / {$monthNum} / {$year}");
        $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension($salesColLetter)->setWidth(14);

        $writer = new Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "مالي_{$clientName}_{$monthNum}_{$day}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function exportExcel(string $clientId, string $month): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        [$year, $monthNum] = explode('-', $month);
        $daysInMonth = (int) date('t', strtotime($month . '-01'));

        $entries = FinancialDailyEntry::where('client_id', $clientId)
            ->whereYear('date', $year)
            ->whereMonth('date', $monthNum)
            ->with('details.category', 'details.item')
            ->orderBy('date')
            ->get()
            ->keyBy(fn($e) => (int) $e->date->format('d'));

        $categories = FinancialExpenseCategory::where('client_id', $clientId)
            ->orWhereNull('client_id')
            ->orderBy('sort_order')
            ->get();

        // Compute global max details across all days so $sumRow is consistent
        $globalMaxDetails = 1;
        foreach ($entries as $e) {
            $detailsByCat = $e->details->groupBy('expense_category_id');
            $globalMaxDetails = max($globalMaxDetails, $detailsByCat->map->count()->max() ?? 1);
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $grandSales = 0;
        $grandExpenses = 0;
        $grandCatTotals = [];

        $client = Client::find($clientId);
        $clientName = $client ? $client->name : '';
        $sysName = config('app.name', 'ERP CostControl');

        $startRow = 8;
        $dataEndRow = $startRow + $globalMaxDetails - 1;
        $sumRow = $dataEndRow + 1;

        // ── Daily sheets ────────────────────────────────
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle((string) $day);
            $sheet->setRightToLeft(true);
            $entry = $entries->get($day);

            $titleFont = ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF1e3a5f']];
            $subFont   = ['size' => 11, 'color' => ['argb' => 'FF555555']];
            $colHeaderFont = ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']];
            $normalFont = ['size' => 10];

            $totalCols = 3 + count($categories) * 2 + 2;
            $lastColLetter = Coordinate::stringFromColumnIndex($totalCols);

            // Row 1: Logo + System Name
            $sheet->mergeCells('A1:E1');
            $sheet->setCellValue('A1', $sysName);
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setName('Arial')->getColor()->setARGB('FF2F5496');
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension(1)->setRowHeight(40);

            // Logo (after mergeCells)
            if ($client && $client->logo && Storage::disk('public')->exists($client->logo)) {
                $drawing = new Drawing();
                $drawing->setPath(Storage::disk('public')->path($client->logo));
                $drawing->setHeight(40);
                $drawing->setCoordinates('A1');
                $drawing->setOffsetX(5);
                $drawing->setOffsetY(2);
                $drawing->setWorksheet($sheet);
            }

            // Row 2: Client Name
            $sheet->mergeCells('A2:E2');
            $sheet->setCellValue('A2', $clientName);
            $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12)->setName('Arial')->getColor()->setARGB('FF1e3a5f');
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getRowDimension(2)->setRowHeight(26);

            // Row 3: Title
            $sheet->mergeCells('A3:E3');
            $sheet->setCellValue('A3', "اليومية المالية لشهر {$monthNum}");
            $sheet->getStyle('A3')->getFont()->applyFromArray($titleFont);
            $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getRowDimension(3)->setRowHeight(30);

            // Row 4: Month/Client
            $sheet->mergeCells('A4:E4');
            $sheet->setCellValue('A4', "{$monthNum}/{$year}");
            $sheet->getStyle('A4')->getFont()->applyFromArray($subFont);
            $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Row 5: Day date banner
            $sheet->mergeCells('A5:E5');
            $sheet->setCellValue('A5', "اليوم: {$day} / {$monthNum} / {$year}");
            $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF1e3a5f');
            $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            // Header styling
            $headerFill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1e3a5f']];
            $headerBorder = [
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF2d4a7a']]],
            ];

            // Columns layout shifted to rows 7-8 (with new header rows 1-5)
            $catStarts = [];
            $col = 1;
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '7', 'اليوم');
            $sheet->getStyle(Coordinate::stringFromColumnIndex($col) . '7')->getFont()->applyFromArray($colHeaderFont);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth(8);
            $col++;
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '7', 'التاريخ');
            $sheet->getStyle(Coordinate::stringFromColumnIndex($col) . '7')->getFont()->applyFromArray($colHeaderFont);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth(14);
            $col++;

            $salesCol = $col;
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '7', 'المبيعات');
            $sheet->getStyle(Coordinate::stringFromColumnIndex($col) . '7')->getFont()->applyFromArray($colHeaderFont);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth(16);
            $salesColLetter = Coordinate::stringFromColumnIndex($col);
            $col++;
            $catStarts[] = $col;

            foreach ($categories as $cat) {
                $catStarts[] = $col;
                $colLetter = Coordinate::stringFromColumnIndex($col);
                $sheet->setCellValue($colLetter . '7', $cat->name);
                $sheet->mergeCells($colLetter . '7:' . Coordinate::stringFromColumnIndex($col + 1) . '7');
                $sheet->getStyle($colLetter . '7')->getFont()->applyFromArray($colHeaderFont);
                $sheet->getStyle($colLetter . '7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getColumnDimension($colLetter)->setWidth(14);
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col + 1))->setWidth(18);
                $sheet->setCellValue($colLetter . '8', 'المبلغ');
                $sheet->getStyle($colLetter . '8')->getFont()->setSize(9)->getColor()->setARGB('FF666666');
                $sheet->getStyle($colLetter . '8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col + 1) . '8', 'البيان');
                $sheet->getStyle(Coordinate::stringFromColumnIndex($col + 1) . '8')->getFont()->setSize(9)->getColor()->setARGB('FF666666');
                $sheet->getStyle(Coordinate::stringFromColumnIndex($col + 1) . '8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $col += 2;
            }

            $expCol = $col;
            $expColLetter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($expColLetter . '7', 'إجمالي المصروفات');
            $sheet->getStyle($expColLetter . '7')->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFCC0000');
            $sheet->getStyle($expColLetter . '7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getColumnDimension($expColLetter)->setWidth(16);
            $col++;

            $netCol = $col;
            $netColLetter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($netColLetter . '7', 'الصافي');
            $sheet->getStyle($netColLetter . '7')->getFont()->setBold(true)->setSize(10);
            $sheet->getStyle($netColLetter . '7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getColumnDimension($netColLetter)->setWidth(16);
            $lastCol = $col;

            // Style headers row 7-8
            $sheet->getStyle('A7:' . Coordinate::stringFromColumnIndex($lastCol) . '8')
                ->getFill()->applyFromArray($headerFill);
            $sheet->getStyle('A7:' . Coordinate::stringFromColumnIndex($lastCol) . '8')
                ->applyFromArray($headerBorder);
            $sheet->getStyle('A7:' . Coordinate::stringFromColumnIndex($lastCol) . '7')
                ->applyFromArray(['font' => ['color' => ['argb' => 'FFFFFFFF']]]);

            // Write data rows
            $details = $entry ? $entry->details : collect([]);
            $detailsByCat = $details->groupBy('expense_category_id');

            for ($i = 0; $i < $globalMaxDetails; $i++) {
                $r = $startRow + $i;
                $sheet->setCellValue('A' . $r, $i + 1);
                $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->setCellValue('B' . $r, $day . '/' . $monthNum . '/' . $year);
                $sheet->getStyle('B' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                if ($i === 0 && $entry) {
                    $sheet->setCellValue($salesColLetter . $r, $entry->total_sales);
                    $sheet->getStyle($salesColLetter . $r)->getNumberFormat()->setFormatCode('#,##0.00');
                }
            }

            // Category detail data (المبلغ | البيان)
            $catIdx = 4; // column D (1-based) = first category column
            foreach ($categories as $cat) {
                $catDetails = $detailsByCat->get($cat->id, collect([]));
                for ($i = 0; $i < count($catDetails); $i++) {
                    $det = $catDetails[$i];
                    $r = $startRow + $i;
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($catIdx) . $r, $det->amount);
                    $sheet->getStyle(Coordinate::stringFromColumnIndex($catIdx) . $r)->getNumberFormat()->setFormatCode('#,##0.00');
                    if ($det->description) {
                        $sheet->setCellValue(Coordinate::stringFromColumnIndex($catIdx + 1) . $r, $det->description);
                    }
                }
                $colLetter = Coordinate::stringFromColumnIndex($catIdx);
                $sheet->setCellValue($colLetter . $sumRow, "=SUM({$colLetter}{$startRow}:{$colLetter}{$dataEndRow})");
                $sheet->getStyle($colLetter . $sumRow)->getFont()->setBold(true);
                $sheet->getStyle($colLetter . $sumRow)->getNumberFormat()->setFormatCode('#,##0.00');
                $catIdx += 2;
            }

            // Sales total in sum row
            $sheet->setCellValue($salesColLetter . $sumRow, "=SUM({$salesColLetter}{$startRow}:{$salesColLetter}{$dataEndRow})");
            $sheet->getStyle($salesColLetter . $sumRow)->getFont()->setBold(true);
            $sheet->getStyle($salesColLetter . $sumRow)->getNumberFormat()->setFormatCode('#,##0.00');

            // Total expenses formula
            $expFormula = '';
            $catIdx = 4;
            foreach ($categories as $i => $cat) {
                $colLetter = Coordinate::stringFromColumnIndex($catIdx);
                if ($i > 0) $expFormula .= '+';
                $expFormula .= "{$colLetter}{$sumRow}";
                $catIdx += 2;
            }
            $sheet->setCellValue($expColLetter . $sumRow, "={$expFormula}");
            $sheet->getStyle($expColLetter . $sumRow)->getFont()->setBold(true)->getColor()->setARGB('FFCC0000');
            $sheet->getStyle($expColLetter . $sumRow)->getNumberFormat()->setFormatCode('#,##0.00');

            // Net formula
            $sheet->setCellValue($netColLetter . $sumRow, "=IF({$salesColLetter}{$sumRow}>0,{$salesColLetter}{$sumRow}-{$expColLetter}{$sumRow},0)");
            $sheet->getStyle($netColLetter . $sumRow)->getFont()->setBold(true);
            $sheet->getStyle($netColLetter . $sumRow)->getNumberFormat()->setFormatCode('#,##0.00');

            // Compact summary after sum row
            $summH = $sumRow + 2;
            $sheet->mergeCells('A' . $summH . ':C' . $summH);
            $sheet->setCellValue('A' . $summH, 'ملخص اليوم');
            $sheet->getStyle('A' . $summH)->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle('A' . $summH)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF2F2F2');
            $sheet->getStyle('A' . $summH)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $r = $summH + 1;
            $sheet->setCellValue('A' . $r, 'المبيعات');
            $sheet->getStyle('A' . $r)->getFont()->setSize(10);
            $sheet->setCellValue('B' . $r, "={$salesColLetter}{$sumRow}");
            $sheet->getStyle('B' . $r)->getFont()->setBold(true)->setSize(10);
            $sheet->getStyle('B' . $r)->getNumberFormat()->setFormatCode('#,##0.00');

            $r++;
            $sheet->setCellValue('A' . $r, 'إجمالي المصروفات');
            $sheet->getStyle('A' . $r)->getFont()->setSize(10);
            $sheet->setCellValue('B' . $r, "={$expColLetter}{$sumRow}");
            $sheet->getStyle('B' . $r)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFCC0000');
            $sheet->getStyle('B' . $r)->getNumberFormat()->setFormatCode('#,##0.00');

            $r++;
            $sheet->setCellValue('A' . $r, 'صافي اليومية');
            $sheet->getStyle('A' . $r)->getFont()->setSize(10);
            $sheet->setCellValue('B' . $r, "={$netColLetter}{$sumRow}");
            $sheet->getStyle('B' . $r)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FF008000');
            $sheet->getStyle('B' . $r)->getNumberFormat()->setFormatCode('#,##0.00');

            // Borders for data area (including sum row)
            $styleArray = [
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]],
            ];
            $sheet->getStyle('A' . $startRow . ':' . Coordinate::stringFromColumnIndex($lastCol) . $sumRow)
                ->applyFromArray($styleArray);

            // Accumulate grand totals
            if ($entry) {
                $grandSales += $entry->total_sales;
                $grandExpenses += $entry->total_expenses;
                foreach ($entry->details as $det) {
                    $grandCatTotals[$det->expense_category_id] = ($grandCatTotals[$det->expense_category_id] ?? 0) + $det->amount;
                }
            }
        }

        // ── Summary sheet (مجمع) with cross-sheet formulas ──
        $summary = $spreadsheet->createSheet();
        $summary->setTitle('مجمع');
        $summary->setRightToLeft(true);

        $summaryCols = 3 + count($categories) + 2;
        $summaryLastColLetter = Coordinate::stringFromColumnIndex($summaryCols);

        // Row 1: Logo + System Name
        $summary->mergeCells('A1:E1');
        $summary->setCellValue('A1', $sysName);
        $summary->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setName('Arial')->getColor()->setARGB('FF2F5496');
        $summary->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $summary->getRowDimension(1)->setRowHeight(40);

        // Logo
        if ($client && $client->logo && Storage::disk('public')->exists($client->logo)) {
            $drawing = new Drawing();
            $drawing->setPath(Storage::disk('public')->path($client->logo));
            $drawing->setHeight(40);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(5);
            $drawing->setOffsetY(2);
            $drawing->setWorksheet($summary);
        }

        // Row 2: Client Name
        $summary->mergeCells('A2:E2');
        $summary->setCellValue('A2', $clientName);
        $summary->getStyle('A2')->getFont()->setBold(true)->setSize(12)->setName('Arial')->getColor()->setARGB('FF1e3a5f');
        $summary->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $summary->getRowDimension(2)->setRowHeight(26);

        // Row 3: Title
        $summary->mergeCells('A3:E3');
        $summary->setCellValue('A3', "ملخص اليوميات المالية لشهر {$monthNum}");
        $summary->getStyle('A3')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF1e3a5f');
        $summary->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $summary->getRowDimension(3)->setRowHeight(30);

        $hRow = 5;

        // Column A (1): اليوم
        $summary->setCellValue('A' . $hRow, 'اليوم');
        $summary->getColumnDimension('A')->setWidth(8);

        // Column B (2): المبيعات (SUM across daily sheets)
        $summary->setCellValue('B' . $hRow, 'المبيعات');
        $summary->getColumnDimension('B')->setWidth(16);

        // Columns C onward: one column per category
        $catSummaryCols = [];
        $col = 3;
        foreach ($categories as $cat) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $summary->setCellValue($colLetter . $hRow, $cat->name);
            $summary->getColumnDimension($colLetter)->setWidth(16);
            $catSummaryCols[$cat->id] = $col;
            $col++;
        }

        // Total expenses column
        $expSummaryCol = $col;
        $expSummaryLetter = Coordinate::stringFromColumnIndex($col);
        $summary->setCellValue($expSummaryLetter . $hRow, 'إجمالي المصروفات');
        $summary->getColumnDimension($expSummaryLetter)->setWidth(18);
        $col++;

        // Net column
        $netSummaryCol = $col;
        $netSummaryLetter = Coordinate::stringFromColumnIndex($col);
        $summary->setCellValue($netSummaryLetter . $hRow, 'الصافي');
        $summary->getColumnDimension($netSummaryLetter)->setWidth(16);
        $lastSummaryCol = $col;

        // Header styling
        $summary->getStyle('A' . $hRow . ':' . Coordinate::stringFromColumnIndex($lastSummaryCol) . $hRow)
            ->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $summary->getStyle('A' . $hRow . ':' . Coordinate::stringFromColumnIndex($lastSummaryCol) . $hRow)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1e3a5f');
        $summary->getStyle('A' . $hRow . ':' . Coordinate::stringFromColumnIndex($lastSummaryCol) . $hRow)
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_MEDIUM);

        // Data rows: cross-sheet formulas for each day
        $r = $hRow + 1;
        $firstDataRow = $r;

        $catIdxArr = [];
        foreach ($categories as $cat) {
            $catIdxArr[$cat->id] = 4 + array_search($cat->id, array_keys($catSummaryCols)) * 2;
        }

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $sheetName = (string) $day;
            $summary->setCellValue('A' . $r, $day);
            $summary->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Sales: ='1'!C{sumRow}
            $summary->setCellValue('B' . $r, "='{$sheetName}'!{$salesColLetter}{$sumRow}");
            $summary->getStyle('B' . $r)->getNumberFormat()->setFormatCode('#,##0.00');

            $col = 3;
            foreach ($categories as $cat) {
                $catLetter = Coordinate::stringFromColumnIndex($catIdxArr[$cat->id] ?? ($col * 2));
                $summary->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, "='{$sheetName}'!{$catLetter}{$sumRow}");
                $summary->getStyle(Coordinate::stringFromColumnIndex($col) . $r)->getNumberFormat()->setFormatCode('#,##0.00');
                $col++;
            }

            $summary->setCellValue($expSummaryLetter . $r, "='{$sheetName}'!{$expColLetter}{$sumRow}");
            $summary->getStyle($expSummaryLetter . $r)->getNumberFormat()->setFormatCode('#,##0.00');

            $summary->setCellValue($netSummaryLetter . $r, "='{$sheetName}'!{$netColLetter}{$sumRow}");
            $summary->getStyle($netSummaryLetter . $r)->getNumberFormat()->setFormatCode('#,##0.00');

            $r++;
        }

        $lastDataRow = $r - 1;
        $totalRow = $r;

        // Total row with =SUM formulas
        $summary->setCellValue('A' . $totalRow, 'الإجمالي');
        $summary->getStyle('A' . $totalRow)->getFont()->setBold(true);
        $summary->getStyle('A' . $totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $summary->setCellValue('B' . $totalRow, "=SUM(B{$firstDataRow}:B{$lastDataRow})");
        $summary->getStyle('B' . $totalRow)->getFont()->setBold(true);
        $summary->getStyle('B' . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00');

        $col = 3;
        foreach ($categories as $cat) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $summary->setCellValue($colLetter . $totalRow, "=SUM({$colLetter}{$firstDataRow}:{$colLetter}{$lastDataRow})");
            $summary->getStyle($colLetter . $totalRow)->getFont()->setBold(true);
            $summary->getStyle($colLetter . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00');
            $col++;
        }

        $summary->setCellValue($expSummaryLetter . $totalRow, "=SUM({$expSummaryLetter}{$firstDataRow}:{$expSummaryLetter}{$lastDataRow})");
        $summary->getStyle($expSummaryLetter . $totalRow)->getFont()->setBold(true);
        $summary->getStyle($expSummaryLetter . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00');

        $summary->setCellValue($netSummaryLetter . $totalRow, "=SUM({$netSummaryLetter}{$firstDataRow}:{$netSummaryLetter}{$lastDataRow})");
        $summary->getStyle($netSummaryLetter . $totalRow)->getFont()->setBold(true);
        $summary->getStyle($netSummaryLetter . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00');

        // Borders for summary
        $summary->getStyle('A' . $firstDataRow . ':' . Coordinate::stringFromColumnIndex($lastSummaryCol) . $totalRow)
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $writer = new Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "مالي_{$clientName}_{$monthNum}_{$year}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function exportWarehouseIncoming(string $clientId, string $month, ?int $day = null): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        [$year, $monthNum] = explode('-', $month);

        $query = FinancialDailyEntryDetail::withoutGlobalScope('client')
            ->join('financial_daily_entries', 'financial_daily_entry_details.daily_entry_id', '=', 'financial_daily_entries.id')
            ->where('financial_daily_entry_details.client_id', $clientId)
            ->where('financial_daily_entries.client_id', $clientId)
            ->whereHas('category', fn($q) => $q->where('is_purchase', true))
            ->whereYear('financial_daily_entries.date', $year)
            ->whereMonth('financial_daily_entries.date', $monthNum)
            ->select('financial_daily_entry_details.*')
            ->with(['dailyEntry', 'item', 'category'])
            ->orderBy('financial_daily_entries.date')
            ->orderBy('expense_category_id');

        if ($day !== null) {
            $dateStr = sprintf('%s-%02d-%02d', $year, $monthNum, $day);
            $query->whereDate('financial_daily_entries.date', $dateStr);
        }

        $purchaseDetails = $query->get();

        $dateLabel = $day ? "{$day}/{$monthNum}" : $month;
        $title = "وارد مخزن --- {$dateLabel}";

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('الجرد');
        $sheet->setRightToLeft(true);

        // Row 1: Headers
        $headers = ['م', 'الصنف', 'الوحدة', 'الكمية', 'cost'];
        $colLetters = ['A', 'B', 'C', 'D', 'E'];
        foreach ($headers as $i => $h) {
            $cell = $colLetters[$i] . '1';
            $sheet->setCellValue($cell, $h);
            $sheet->getStyle($cell)->getFont()->setBold(true);
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8E8E8');
        }

        // Row 2: Title with date
        $sheet->mergeCells('A2:E2');
        $sheet->setCellValue('A2', $title);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(25);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->getColumnDimension('E')->setWidth(12);

        $row = 3;
        $idx = 1;
        foreach ($purchaseDetails as $det) {
            $itemName = $det->item?->name ?? $det->description ?? '—';
            $unit = $det->item?->unit ?? '';
            $qty = $det->quantity ?? 0;
            $cost = $det->amount ?? 0;

            $sheet->setCellValue('A' . $row, $idx);
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->setCellValue('B' . $row, $itemName);
            $sheet->setCellValue('C' . $row, $unit);
            $sheet->setCellValue('D' . $row, $qty);
            $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0.000');
            $sheet->setCellValue('E' . $row, $cost);
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

            $row++;
            $idx++;
        }

        $dataEnd = $row - 1;
        if ($dataEnd >= 3) {
            $styleArray = [
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]],
            ];
            $sheet->getStyle('A1:E' . $dataEnd)->applyFromArray($styleArray);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = $day ? "وارد_مخزن_{$day}_{$monthNum}" : "وارد_مخزن_{$month}";
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "{$filename}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
