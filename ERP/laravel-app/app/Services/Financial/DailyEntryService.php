<?php

namespace App\Services\Financial;

use App\Models\Financial\FinancialDailyEntry;
use App\Models\Financial\FinancialDailyEntryDetail;
use App\Models\Financial\FinancialExpenseCategory;
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
            ->with('details.category')
            ->orderBy('date')
            ->get();

        $categories = \App\Models\Financial\FinancialExpenseCategory::where('client_id', $clientId)
            ->orWhereNull('client_id')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'code']);

        return [
            'entries' => $entries,
            'categories' => $categories,
        ];
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
                        ]);
                    }
                }

                $entry->load('details.category');
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

    public function exportExcel(string $clientId, string $month): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        [$year, $monthNum] = explode('-', $month);
        $daysInMonth = (int) date('t', strtotime($month . '-01'));

        $entries = FinancialDailyEntry::where('client_id', $clientId)
            ->whereYear('date', $year)
            ->whereMonth('date', $monthNum)
            ->with('details.category')
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
            // Header row 1: category names (merged across amount + desc sub-cols)
            $headerRow = 1;
            $sheet->setCellValue($catCol, $headerRow, 'اليوم');
            $sheet->mergeCells($catCol . $headerRow . ':' . $catCol . ($headerRow + 1));
            $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol) . $headerRow)->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol) . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol) . $headerRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $catCol++;

            // Date column
            $sheet->setCellValue($catCol, $headerRow, 'التاريخ');
            $sheet->mergeCells($catCol . $headerRow . ':' . $catCol . ($headerRow + 1));
            $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol) . $headerRow)->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol) . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $catCol++;

            // Sales column
            $sheet->setCellValue($catCol, $headerRow, 'المبيعات');
            $sheet->mergeCells($catCol . $headerRow . ':' . $catCol . ($headerRow + 1));
            $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol) . $headerRow)->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol) . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
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

                // Sub-header: المبلغ and البيان
                $sheet->setCellValue($colLetter . ($headerRow + 1), 'المبلغ');
                $sheet->getStyle($colLetter . ($headerRow + 1))->getFont()->setSize(9)->getColor()->setARGB('FF666666');
                $sheet->getStyle($colLetter . ($headerRow + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($catCol + 1) . ($headerRow + 1), 'البيان');
                $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol + 1) . ($headerRow + 1))->getFont()->setSize(9)->getColor()->setARGB('FF666666');
                $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol + 1) . ($headerRow + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $catCol += 2;
            }

            // Total expenses column
            $sheet->setCellValue($catCol, $headerRow, 'إجمالي المصروفات');
            $sheet->mergeCells($catCol . $headerRow . ':' . $catCol . ($headerRow + 1));
            $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol) . $headerRow)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FFCC0000');
            $expCol = $catCol;
            $catCol++;

            // Net column
            $sheet->setCellValue($catCol, $headerRow, 'الصافي');
            $sheet->mergeCells($catCol . $headerRow . ':' . $catCol . ($headerRow + 1));
            $sheet->getStyle(Coordinate::stringFromColumnIndex($catCol) . $headerRow)->getFont()->setBold(true)->setSize(11);
            $netCol = $catCol;
            $lastCol = $catCol;

            // Data rows
            $startRow = 4;
            $row = $startRow;
            $details = $entry ? $entry->details : collect([]);
            $detailsByCat = $details->groupBy('expense_category_id');

            // Determine max rows needed
            $maxDetails = max($detailsByCat->map->count()->max() ?? 0, 1);

            for ($i = 0; $i < $maxDetails; $i++) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex(1) . $row, $i + 1);
                $sheet->getStyle(Coordinate::stringFromColumnIndex(1) . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                // Date
                $sheet->setCellValue(Coordinate::stringFromColumnIndex(2) . $row, $day . '/' . $monthNum . '/' . $year);
                $sheet->getStyle(Coordinate::stringFromColumnIndex(2) . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                // Sales only on first row
                if ($i === 0 && $entry) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($salesCol) . $row, $entry->total_sales);
                }
                $row++;
            }

            $dataEndRow = $row - 1;
            if ($dataEndRow < $startRow) $dataEndRow = $startRow;

            // Fill category columns
            $catIdx = 4; // starting column index for categories
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

                // Sum formula for this category
                $colLetter = Coordinate::stringFromColumnIndex($catIdx - 2);
                $sumRow = $dataEndRow + 1;
                $sheet->setCellValue($colLetter . $sumRow, "=SUM({$colLetter}{$startRow}:{$colLetter}{$dataEndRow})");
                $sheet->getStyle($colLetter . $sumRow)->getFont()->setBold(true);
                $sheet->getStyle($colLetter . $sumRow)->getNumberFormat()->setFormatCode('#,##0.00');
            }

            // Total expenses sum
            $sumRow = $dataEndRow + 1;
            $expFormula = '';
            $catIdx = 4;
            foreach ($categories as $i => $cat) {
                $colLetter = Coordinate::stringFromColumnIndex($catIdx);
                if ($i > 0) $expFormula .= '+';
                $expFormula .= "{$colLetter}{$sumRow}";
                $catIdx += 2;
            }
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($expCol) . $sumRow, "={$expFormula}");
            $sheet->getStyle(Coordinate::stringFromColumnIndex($expCol) . $sumRow)->getFont()->setBold(true)->getColor()->setARGB('FFCC0000');

            // Net = sales - total expenses
            if ($entry) {
                $salesVal = $entry->total_sales;
            } else {
                $salesVal = 0;
            }
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($netCol) . $sumRow, "=IF({$salesCol}{$dataEndRow}>0,{$salesCol}{$dataEndRow}-{$expCol}{$sumRow},0)");
            $sheet->getStyle(Coordinate::stringFromColumnIndex($netCol) . $sumRow)->getFont()->setBold(true);

            // Borders for data area
            $styleArray = [
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]],
            ];
            $sheet->getStyle(Coordinate::stringFromColumnIndex(1) . $startRow . ':' . Coordinate::stringFromColumnIndex($lastCol) . $sumRow)
                ->applyFromArray($styleArray);

            // Title row
            $sheet->mergeCells(Coordinate::stringFromColumnIndex(1) . '3:' . Coordinate::stringFromColumnIndex($lastCol) . '3');
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(1) . '3', "اليوم: {$day} / {$monthNum} / {$year}");
            $sheet->getStyle(Coordinate::stringFromColumnIndex(1) . '3')->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle(Coordinate::stringFromColumnIndex(1) . '3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            // Column widths
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex(1))->setWidth(6);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex(2))->setWidth(14);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($salesCol))->setWidth(14);

            // Accumulate grand totals
            if ($entry) {
                $grandSales += $entry->total_sales;
                $grandExpenses += $entry->total_expenses;
                foreach ($entry->details as $det) {
                    $grandCatTotals[$det->expense_category_id] = ($grandCatTotals[$det->expense_category_id] ?? 0) + $det->amount;
                }
            }
        }

        // Summary sheet
        $summary = $spreadsheet->createSheet();
        $summary->setTitle('مجمع');
        $summary->setRightToLeft(true);
        $summary->setCellValue('A1', 'ملخص الشهر');
        $summary->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $summary->mergeCells('A1:' . Coordinate::stringFromColumnIndex(count($categories) + 4) . '1');

        // Headers
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

        // Grand total row
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
}
