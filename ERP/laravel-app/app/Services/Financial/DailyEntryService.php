<?php

namespace App\Services\Financial;

use App\Models\Financial\FinancialDailyEntry;
use App\Models\Financial\FinancialDailyEntryDetail;
use App\Models\Financial\FinancialExpenseCategory;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

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
        }, "اليومية_{$month}_{$day}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
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

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $grandSales = 0;
        $grandExpenses = 0;
        $grandCatTotals = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle((string) $day);
            $sheet->setRightToLeft(true);
            $entry = $entries->get($day);

            $catCol = 1;
            $headerRow = 1;
            $colA = Coordinate::stringFromColumnIndex($catCol);
            $sheet->setCellValue($colA . $headerRow, 'اليوم');
            $sheet->mergeCells($colA . $headerRow . ':' . $colA . ($headerRow + 1));
            $sheet->getStyle($colA . $headerRow)->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle($colA . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($colA . $headerRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $catCol++;

            $colB = Coordinate::stringFromColumnIndex($catCol);
            $sheet->setCellValue($colB . $headerRow, 'التاريخ');
            $sheet->mergeCells($colB . $headerRow . ':' . $colB . ($headerRow + 1));
            $sheet->getStyle($colB . $headerRow)->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle($colB . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $catCol++;

            $salesColLetter = Coordinate::stringFromColumnIndex($catCol);
            $sheet->setCellValue($salesColLetter . $headerRow, 'المبيعات');
            $sheet->mergeCells($salesColLetter . $headerRow . ':' . $salesColLetter . ($headerRow + 1));
            $sheet->getStyle($salesColLetter . $headerRow)->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle($salesColLetter . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $salesCol = $catCol;
            $catCol++;

            foreach ($categories as $cat) {
                $colLetter = Coordinate::stringFromColumnIndex($catCol);
                $sheet->setCellValue($colLetter . $headerRow, $cat->name);
                $sheet->mergeCells($colLetter . $headerRow . ':' . Coordinate::stringFromColumnIndex($catCol + 1) . $headerRow);
                $sheet->getStyle($colLetter . $headerRow)->getFont()->setBold(true)->setSize(10);
                $sheet->getStyle($colLetter . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle($colLetter . $headerRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getColumnDimension($colLetter)->setWidth(14);
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($catCol + 1))->setWidth(18);

                $sheet->setCellValue($colLetter . ($headerRow + 1), 'المبلغ');
                $sheet->getStyle($colLetter . ($headerRow + 1))->getFont()->setSize(9)->getColor()->setARGB('FF666666');
                $sheet->getStyle($colLetter . ($headerRow + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($catCol + 1) . ($headerRow + 1), 'البيان');
                $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol + 1) . ($headerRow + 1))->getFont()->setSize(9)->getColor()->setARGB('FF666666');
                $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol + 1) . ($headerRow + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $catCol += 2;
            }

            $expColLetter = Coordinate::stringFromColumnIndex($catCol);
            $sheet->setCellValue($expColLetter . $headerRow, 'إجمالي المصروفات');
            $sheet->mergeCells($expColLetter . $headerRow . ':' . $expColLetter . ($headerRow + 1));
            $sheet->getStyle($expColLetter . $headerRow)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FFCC0000');
            $expCol = $catCol;
            $catCol++;

            $netColLetter = Coordinate::stringFromColumnIndex($catCol);
            $sheet->setCellValue($netColLetter . $headerRow, 'الصافي');
            $sheet->mergeCells($netColLetter . $headerRow . ':' . $netColLetter . ($headerRow + 1));
            $sheet->getStyle($netColLetter . $headerRow)->getFont()->setBold(true)->setSize(11);
            $netCol = $catCol;
            $lastCol = $catCol;

            $startRow = 4;
            $row = $startRow;
            $details = $entry ? $entry->details : collect([]);
            $detailsByCat = $details->groupBy('expense_category_id');

            $maxDetails = max($detailsByCat->map->count()->max() ?? 0, 1);

            for ($i = 0; $i < $maxDetails; $i++) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex(1) . $row, $i + 1);
                $sheet->getStyle(Coordinate::stringFromColumnIndex(1) . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex(2) . $row, $day . '/' . $monthNum . '/' . $year);
                $sheet->getStyle(Coordinate::stringFromColumnIndex(2) . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
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

            $sheet->mergeCells(Coordinate::stringFromColumnIndex(1) . '3:' . Coordinate::stringFromColumnIndex($lastCol) . '3');
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(1) . '3', "اليوم: {$day} / {$monthNum} / {$year}");
            $sheet->getStyle(Coordinate::stringFromColumnIndex(1) . '3')->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle(Coordinate::stringFromColumnIndex(1) . '3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex(1))->setWidth(6);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex(2))->setWidth(14);
            $sheet->getColumnDimension($salesColLetter)->setWidth(14);

            if ($entry) {
                $grandSales += $entry->total_sales;
                $grandExpenses += $entry->total_expenses;
                foreach ($entry->details as $det) {
                    $grandCatTotals[$det->expense_category_id] = ($grandCatTotals[$det->expense_category_id] ?? 0) + $det->amount;
                }
            }
        }

        $summary = $spreadsheet->createSheet();
        $summary->setTitle('مجمع');
        $summary->setRightToLeft(true);
        $summary->setCellValue('A1', 'ملخص الشهر');
        $summary->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $summary->mergeCells('A1:' . Coordinate::stringFromColumnIndex(count($categories) + 4) . '1');

        $hRow = 3;
        $summary->setCellValue('A' . $hRow, 'اليوم');
        $summary->setCellValue('B' . $hRow, 'المبيعات');
        $col = 3;
        foreach ($categories as $cat) {
            $summary->setCellValue(Coordinate::stringFromColumnIndex($col) . $hRow, $cat->name);
            $col++;
        }
        $summary->setCellValue(Coordinate::stringFromColumnIndex($col) . $hRow, 'إجمالي المصروفات');
        $col++;
        $summary->setCellValue(Coordinate::stringFromColumnIndex($col) . $hRow, 'الصافي');

        $summary->getStyle('A' . $hRow . ':' . Coordinate::stringFromColumnIndex($col) . $hRow)
            ->getFont()->setBold(true);
        $summary->getStyle('A' . $hRow . ':' . Coordinate::stringFromColumnIndex($col) . $hRow)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8E8E8');

        $r = $hRow + 1;
        $sumSales = 0;
        $sumExpenses = 0;
        $sumNet = 0;
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $entry = $entries->get($day);
            $summary->setCellValue('A' . $r, $day);
            $summary->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            if ($entry) {
                $summary->setCellValue('B' . $r, $entry->total_sales);
                $col = 3;
                $rowExpenses = 0;
                foreach ($categories as $cat) {
                    $amt = $entry->details->where('expense_category_id', $cat->id)->sum('amount');
                    if ($amt > 0) {
                        $summary->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $amt);
                    }
                    $rowExpenses += $amt;
                    $col++;
                }
                $summary->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $rowExpenses);
                $col++;
                $net = $entry->total_sales - $rowExpenses;
                $summary->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $net);
                $sumSales += $entry->total_sales;
                $sumExpenses += $rowExpenses;
                $sumNet += $net;
            }
            $r++;
        }

        $summary->setCellValue('A' . $r, 'الإجمالي');
        $summary->getStyle('A' . $r)->getFont()->setBold(true);
        $summary->setCellValue('B' . $r, $sumSales);
        $col = 3;
        $grandExpTotal = 0;
        foreach ($categories as $cat) {
            $total = $grandCatTotals[$cat->id] ?? 0;
            $summary->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $total > 0 ? $total : '');
            $grandExpTotal += $total;
            $col++;
        }
        $summary->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $sumExpenses);
        $col++;
        $summary->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $sumNet);
        $summary->getStyle('A' . $hRow . ':' . Coordinate::stringFromColumnIndex($col) . $r)
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $writer = new Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "اليوميات_{$month}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
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
