<?php
namespace App\Services;

use App\Models\Item;
use App\Models\Warehouse;
use App\Models\MonthlyClosing;
use App\Models\StockLedger;
use App\Models\DispatchOrder;
use App\Models\Production\DailyProduction;
use App\Models\Production\Recipe;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ComprehensiveExportService
{
    public function export(string $clientId, string $month)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(600);
        $dt = Carbon::parse($month . '-01');
        $daysInMonth = $dt->daysInMonth;
        $dayCols = 31; // Fixed 31 day columns to match reference file & keep cross-sheet refs stable
        $start = $dt->toDateString();
        $end = $dt->copy()->endOfMonth()->toDateString();
        $warehouses = Warehouse::where('client_id', $clientId)->where('is_active', true)->get();
        $mainSub = $warehouses->whereIn('type', ['main', 'sub'])->values();
        $branches = $warehouses->where('type', 'branch')->values();
        $items = Item::where('client_id', $clientId)->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('DejaVu Sans')->setSize(9);

        $whSheetNames = [];

        // Sheet 1: Daily Production
        $productionSheetName = 'الانتاج اليومي';
        $prodSheet = $spreadsheet->getActiveSheet()->setRightToLeft(true)->setTitle($productionSheetName);
        $this->buildProductionSheet($prodSheet, $clientId, $month, $dt, $daysInMonth, $dayCols);

        // Warehouse Receipt Sheets
        $whSheetIdx = 1;
        foreach ($mainSub as $wh) {
            $name = mb_substr("وارد {$wh->name}", 0, 31);
            $whSheetNames[$wh->id] = $name;
            if (++$whSheetIdx > $spreadsheet->getSheetCount()) {
                $sheet = $spreadsheet->createSheet()->setRightToLeft(true)->setTitle($name);
            } else {
                $sheet = $spreadsheet->getSheet($whSheetIdx - 1)->setRightToLeft(true)->setTitle($name);
            }
            $this->buildWarehouseSheet($sheet, $clientId, $wh, $month, $dt, $daysInMonth, $dayCols, $items);
        }

        // Branch Consumption Sheets
        foreach ($branches as $br) {
            $name = mb_substr("منصرف {$br->name}", 0, 31);
            $brSheetNames[$br->id] = $name;
            $sheet = $spreadsheet->createSheet()->setRightToLeft(true)->setTitle($name);
            $this->buildBranchSheet($sheet, $clientId, $br, $month, $dt, $daysInMonth, $dayCols, $items);
        }

        // Closing Raw Materials Sheet
        $closingSheetName = 'تقفيل خامات';
        $sheet = $spreadsheet->createSheet()->setRightToLeft(true)->setTitle($closingSheetName);
        $this->buildClosingSheet($sheet, $spreadsheet, $clientId, $month, $items, $mainSub, $branches,
            $whSheetNames, $brSheetNames, $dt, $daysInMonth, $dayCols);

        if ($spreadsheet->getSheetCount() > 1) {
            $defaultSheet = $spreadsheet->getSheetByName('Worksheet');
            if ($defaultSheet) {
                $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($defaultSheet));
            }
        }

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "الدورة_الكاملة_{$month}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ─── Production Sheet ─────────────────────────────────
    private function buildProductionSheet($sheet, string $clientId, string $month, Carbon $dt, int $daysInMonth, int $dayCols)
    {
        $recipes = Recipe::where('client_id', $clientId)
            ->with('outputItem:id,name,unit')
            ->get();
        $prodEntries = DailyProduction::where('client_id', $clientId)
            ->whereYear('date', $dt->year)->whereMonth('date', $dt->month)
            ->get()
            ->groupBy('recipe_id');

        // Manual items: entries whose recipe_id is actually an Item ID (not a Recipe)
        $recipeIds = $recipes->pluck('id')->toArray();
        $manualItemIds = collect($prodEntries->keys())
            ->reject(fn($rid) => in_array($rid, $recipeIds))
            ->values();
        $manualItems = $manualItemIds->isNotEmpty()
            ? Item::whereIn('id', $manualItemIds)->where('client_id', $clientId)->get()->keyBy('id')
            : collect();

        // Columns: A=الصنف, B=الوحدة, C onwards = days (31 cols), then الإجمالي, سعر البيع, القيمة
        $headers1 = ['الصنف', 'الوحدة'];
        $headers2 = ['', ''];
        for ($d = 1; $d <= $dayCols; $d++) {
            $headers1[] = $dt->copy()->day(min($d, $daysInMonth))->format('Y-m-d');
            $headers2[] = (string) $d;
        }
        $headers1[] = 'الإجمالي';
        $headers2[] = '';
        $headers1[] = 'سعر البيع';
        $headers2[] = '';
        $headers1[] = 'القيمة';
        $headers2[] = '';

        $this->writeRow($sheet, 1, $headers1, true);
        $this->writeRow($sheet, 2, $headers2, true);
        $dataStart = 3;

        $totalCol = 2 + $dayCols;
        $priceCol = $totalCol + 1;
        $valueCol = $totalCol + 2;
        $lastColInx = 2 + $dayCols + 2;

        $rowIdx = $dataStart;
        foreach ($recipes as $recipe) {
            $entries = $prodEntries->get($recipe->id, collect());
            $dailyQty = [];
            $totalQty = 0;
            for ($d = 1; $d <= $dayCols; $d++) {
                if ($d > $daysInMonth) { $dailyQty[$d] = 0; continue; }
                $dateStr = $dt->copy()->day($d)->toDateString();
                $qty = (float) $entries->where('date', $dateStr)->sum('qty');
                $dailyQty[$d] = $qty;
                $totalQty += $qty;
            }
            $sellingPrice = (float) ($recipe->selling_price ?: 0);
            $rowData = [$recipe->name, $recipe->unit ?? $recipe->outputItem?->unit ?? ''];
            for ($d = 1; $d <= $dayCols; $d++) {
                $rowData[] = $dailyQty[$d] ?: 0;
            }
            $rowData[] = $totalQty;
            $rowData[] = $sellingPrice;
            $rowData[] = $sellingPrice > 0 ? round($totalQty * $sellingPrice, 2) : 0;

            $this->writeDataRow($sheet, $rowIdx, $rowData);

            $firstDayCol = Coordinate::stringFromColumnIndex(3);
            $lastDayCol = Coordinate::stringFromColumnIndex(2 + $dayCols);
            $totalRef = Coordinate::stringFromColumnIndex(1 + $totalCol) . $rowIdx;
            $sheet->setCellValue($totalRef, "=SUM({$firstDayCol}{$rowIdx}:{$lastDayCol}{$rowIdx})");

            $priceRef = Coordinate::stringFromColumnIndex(1 + $priceCol) . $rowIdx;
            $valueRef = Coordinate::stringFromColumnIndex(1 + $valueCol) . $rowIdx;
            $sheet->setCellValue($valueRef, "={$totalRef}*{$priceRef}");

            $rowIdx++;
        }

        // Manual items (recipe_id is an Item ID, not a Recipe ID)
        foreach ($manualItems as $item) {
            $entries = $prodEntries->get($item->id, collect());
            $dailyQty = [];
            $totalQty = 0;
            for ($d = 1; $d <= $dayCols; $d++) {
                if ($d > $daysInMonth) { $dailyQty[$d] = 0; continue; }
                $dateStr = $dt->copy()->day($d)->toDateString();
                $qty = (float) $entries->where('date', $dateStr)->sum('qty');
                $dailyQty[$d] = $qty;
                $totalQty += $qty;
            }
            $rowData = [$item->name, $item->unit ?? ''];
            for ($d = 1; $d <= $dayCols; $d++) {
                $rowData[] = $dailyQty[$d] ?: 0;
            }
            $rowData[] = $totalQty;
            $rowData[] = 0;
            $rowData[] = 0;

            $this->writeDataRow($sheet, $rowIdx, $rowData);

            $firstDayCol = Coordinate::stringFromColumnIndex(3);
            $lastDayCol = Coordinate::stringFromColumnIndex(2 + $dayCols);
            $totalRef = Coordinate::stringFromColumnIndex(1 + $totalCol) . $rowIdx;
            $sheet->setCellValue($totalRef, "=SUM({$firstDayCol}{$rowIdx}:{$lastDayCol}{$rowIdx})");

            $rowIdx++;
        }

        // Totals row
        $prevRowIdx = $rowIdx - 1;
        $totalRow = $rowIdx;
        $this->writeRow($sheet, $totalRow, ['الإجمالي', ''], false, true);
        for ($d = 1; $d <= $dayCols; $d++) {
            $col = Coordinate::stringFromColumnIndex(2 + $d);
            $sheet->setCellValue("{$col}{$totalRow}", "=SUM({$col}{$dataStart}:{$col}{$prevRowIdx})");
        }
        $totalColRef = Coordinate::stringFromColumnIndex(1 + $totalCol) . $totalRow;
        $fdc = Coordinate::stringFromColumnIndex(3);
        $ldc = Coordinate::stringFromColumnIndex(2 + $dayCols);
        $sheet->setCellValue($totalColRef, "=SUM({$fdc}{$totalRow}:{$ldc}{$totalRow})");
        $valueTotalRef = Coordinate::stringFromColumnIndex(1 + $valueCol) . $totalRow;
        $evc = Coordinate::stringFromColumnIndex(1 + $valueCol);
        $sheet->setCellValue($valueTotalRef, "=SUM({$evc}{$dataStart}:{$evc}{$prevRowIdx})");

        $sheet->freezePane('C3');
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(8);
        for ($c = 3; $c <= $lastColInx; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(9);
        }
        $this->addFooter($sheet, $totalRow + 1, $lastColInx);
    }

    // ─── Warehouse Receipt Sheet ──────────────────────────
    private function buildWarehouseSheet($sheet, string $clientId, $wh, string $month, Carbon $dt, int $daysInMonth, int $dayCols, $items)
    {
        $closings = MonthlyClosing::where('client_id', $clientId)
            ->where('warehouse_id', $wh->id)->where('month', $month)
            ->get()->keyBy('item_id');

        $dailyLedger = StockLedger::where('stock_ledger.client_id', $clientId)
            ->where('stock_ledger.warehouse_id', $wh->id)
            ->whereBetween('stock_ledger.date', [$dt->toDateString(), $dt->copy()->endOfMonth()->toDateString()])
            ->where('stock_ledger.movement_type', 'in')
            ->whereIn('stock_ledger.voucher_type', ['purchase', 'production'])
            ->get(['item_id', 'date', 'qty', 'unit_cost', 'total_cost']);

        $perItem = [];
        foreach ($dailyLedger as $entry) {
            $day = (int) Carbon::parse($entry->date)->format('j');
            $iid = $entry->item_id;
            $perItem[$iid]['qty'][$day] = ($perItem[$iid]['qty'][$day] ?? 0) + (float) $entry->qty;
            $perItem[$iid]['cost'][$day] = ($perItem[$iid]['cost'][$day] ?? 0) + (float) $entry->total_cost;
        }

        $monthNum = (int) Carbon::parse($month)->format('n');
        $serialDates = [];
        $dayNamesArr = [];
        $arabicDays = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        for ($d = 1; $d <= $dayCols; $d++) {
            $refDay = min($d, $daysInMonth);
            $dayDt = $dt->copy()->day($refDay);
            $serialDates[$d] = Date::PHPToExcel($dayDt);
            $dayNamesArr[$d] = $arabicDays[(int) $dayDt->format('w')];
        }

        // Column layout (1-based):
        // A(1)=الصنف, B(2)=الوحده, C(3)=اجمالي المستلم(=D+AK), D(4)=اول المده, E(5)=empty
        // F(6) onwards = days (31 cols), AK(37)=الاجمالي مشتريات, AL(38)=سعر
        // AM(39)=قيمة المشتريات, AN(40)=اخر المده, AO(41)=المستلم الفعلي
        // AP(42)=قيمة اول المدة, AQ(43)=قيمة اخر المدة, AR(44)=قيمة المستلم الفعلي
        // AS(45)=average, AT(46)=cost
        $dayStart = 6;
        $akCol = $dayStart + $dayCols;        // always col 37 (AK)
        $alCol = $akCol + 1;
        $amCol = $akCol + 2;
        $anCol = $akCol + 3;
        $aoCol = $akCol + 4;
        $apCol = $akCol + 5;
        $aqCol = $akCol + 6;
        $arCol = $akCol + 7;
        $asCol = $akCol + 8;
        $atCol = $akCol + 9;
        $totalCols = $atCol;

        // Row 1: title
        $this->writeRow($sheet, 1,
            array_merge([$wh->name, 'النسخه الاصليه'], array_fill(2, $totalCols - 2, '')),
            true, false);

        // Row 2: day-of-week labels
        $row2 = ['برنامج المخزن عن شهر' . $monthNum, '', '', 'رصيد اول الشهر', ''];
        for ($d = 1; $d <= $dayCols; $d++) {
            $row2[] = $dayNamesArr[$d];
        }
        $row2 = array_merge($row2, array_fill(0, 10, ''));
        array_splice($row2, $akCol - 1, 1, 'الاجمالي مشتريات');
        array_splice($row2, $alCol - 1, 1, 'سعر');
        array_splice($row2, $amCol - 1, 1, 'قيمة المشتريات');
        array_splice($row2, $anCol - 1, 1, 'اخر المده');
        array_splice($row2, $aoCol - 1, 1, 'المستلم الفعلي');
        array_splice($row2, $apCol - 1, 1, 'قيمة اول المدة');
        array_splice($row2, $aqCol - 1, 1, 'قيمة اخر المدة');
        array_splice($row2, $arCol - 1, 1, 'قيمة المستلم الفعلي');
        array_splice($row2, $asCol - 1, 1, 'average');
        array_splice($row2, $atCol - 1, 1, 'cost');
        $this->writeRow($sheet, 2, $row2, true, false);

        // Row 3: column sub-headers + serial dates
        $row3 = ['اسم الصنف', 'الوحده', 'اجمالي المستلم', '', ''];
        for ($d = 1; $d <= $dayCols; $d++) {
            $row3[] = $serialDates[$d];
        }
        $row3 = array_merge($row3, array_fill(0, 10, ''));
        array_splice($row3, $akCol - 1, 1, 'الاجمالي مشتريات');
        array_splice($row3, $alCol - 1, 1, 'سعر');
        array_splice($row3, $amCol - 1, 1, 'قيمة المشتريات');
        array_splice($row3, $anCol - 1, 1, 'اخر المده');
        array_splice($row3, $aoCol - 1, 1, 'المستلم الفعلي');
        array_splice($row3, $apCol - 1, 1, 'قيمة اول المدة');
        array_splice($row3, $aqCol - 1, 1, 'قيمة اخر المدة');
        array_splice($row3, $arCol - 1, 1, 'قيمة المستلم الفعلي');
        array_splice($row3, $asCol - 1, 1, 'average');
        array_splice($row3, $atCol - 1, 1, 'cost');
        $this->writeRow($sheet, 3, $row3, true, false);

        $dataStart = 4;
        $sheet->setCellValue('A4', 'الصنف');

        $rowIdx = $dataStart;
        foreach ($items as $item) {
            $c = $closings->get($item->id);
            $opening = $c ? (float) $c->opening_qty : 0;
            $avgCost = $c ? (float) $c->avg_cost : 0;
            $closingTheoretical = $c ? (float) $c->closing_qty_theoretical : 0;
            $openingVal = $c ? (float) $c->opening_value : 0;
            $inVal = $c ? (float) $c->in_value : 0;
            $closingVal = $c ? (float) $c->closing_value : 0;
            $inQty = $c ? (float) $c->in_qty : 0;
            $itemDays = isset($perItem[$item->id]) ? $perItem[$item->id]['qty'] : [];
            $itemCosts = isset($perItem[$item->id]) ? $perItem[$item->id]['cost'] : [];
            $totalDaily = array_sum($itemDays);
            $totalCost = array_sum($itemCosts);

            if ($totalCost == 0 && $inVal > 0) $totalCost = $inVal;

            // A=item name, B=unit, C=formula(=D+AK), D=opening, E=empty
            $rowData = [$item->name, $item->unit, ''];
            $rowData[] = $opening;
            $rowData[] = '';

            // F onwards = daily quantities (31 cols)
            for ($d = 1; $d <= $dayCols; $d++) {
                $rowData[] = $itemDays[$d] ?? 0;
            }

            // AK = total receipts
            $rowData[] = round($totalDaily, 3);
            // AL = price
            $rowData[] = $avgCost > 0 ? round($avgCost, 3) : 0;
            // AM = purchases value
            $rowData[] = round($totalDaily * ($avgCost > 0 ? $avgCost : 0), 2);
            // AN = closing
            $rowData[] = $closingTheoretical;
            // AO = actual received
            $rowData[] = '';
            // AP = opening value
            $rowData[] = $opening > 0 ? round($opening * ($avgCost > 0 ? $avgCost : 0), 2) : 0;
            // AQ = closing value
            $rowData[] = $closingTheoretical > 0 ? round($closingTheoretical * ($avgCost > 0 ? $avgCost : 0), 2) : 0;
            // AR = actual received value
            $rowData[] = '';
            // AS = average (formula =IF(AT=0,0,AT/AK))
            $rowData[] = '';
            // AT = total cost
            $rowData[] = $totalCost > 0 ? round($totalCost, 2) : 0;

            $this->writeDataRow($sheet, $rowIdx, $rowData);

            // Formulas
            $cRef = Coordinate::stringFromColumnIndex(3) . $rowIdx;
            $dRef = Coordinate::stringFromColumnIndex(4) . $rowIdx;
            $akRef = Coordinate::stringFromColumnIndex($akCol) . $rowIdx;
            $alRef = Coordinate::stringFromColumnIndex($alCol) . $rowIdx;
            $aoRef = Coordinate::stringFromColumnIndex($aoCol) . $rowIdx;
            $arRef = Coordinate::stringFromColumnIndex($arCol) . $rowIdx;
            $asRef = Coordinate::stringFromColumnIndex($asCol) . $rowIdx;
            $atRef = Coordinate::stringFromColumnIndex($atCol) . $rowIdx;
            $amRef = Coordinate::stringFromColumnIndex($amCol) . $rowIdx;
            $anRef = Coordinate::stringFromColumnIndex($anCol) . $rowIdx;
            $apRef = Coordinate::stringFromColumnIndex($apCol) . $rowIdx;
            $aqRef = Coordinate::stringFromColumnIndex($aqCol) . $rowIdx;

            // C = D + AK
            $sheet->setCellValue($cRef, "={$dRef}+{$akRef}");
            // AK = SUM(F:AJ) — always 31 day columns
            $firstDayRef = Coordinate::stringFromColumnIndex($dayStart) . $rowIdx;
            $lastDayRef = Coordinate::stringFromColumnIndex($dayStart + $dayCols - 1) . $rowIdx;
            $sheet->setCellValue($akRef, "=SUM({$firstDayRef}:{$lastDayRef})");
            // AM = AK * AL
            $sheet->setCellValue($amRef, "={$akRef}*{$alRef}");
            // AO = D + AK - AN
            $sheet->setCellValue($aoRef, "={$dRef}+{$akRef}-{$anRef}");
            // AP = D * AL
            $sheet->setCellValue($apRef, "={$dRef}*{$alRef}");
            // AQ = AN * AL
            $sheet->setCellValue($aqRef, "={$anRef}*{$alRef}");
            // AR = AO * AL
            $sheet->setCellValue($arRef, "={$aoRef}*{$alRef}");
            // AS = IF(AT=0,0,AT/AK)
            $sheet->setCellValue($asRef, "=IF({$atRef}=0,0,{$atRef}/{$akRef})");

            $rowIdx++;
        }

        // Summary row
        $prevRowIdx = $rowIdx - 1;
        $sumRow = $rowIdx;
        $sumHeader = array_fill(0, $totalCols, '');
        $sumHeader[0] = 'الإجمالي';
        $this->writeRow($sheet, $sumRow, $sumHeader, false, true);
        // D column sum
        $dColL = Coordinate::stringFromColumnIndex(4);
        $sheet->setCellValue("{$dColL}{$sumRow}", "=SUM({$dColL}{$dataStart}:{$dColL}{$prevRowIdx})");
        // Each day column sum
        for ($d = 0; $d < $dayCols; $d++) {
            $colL = Coordinate::stringFromColumnIndex($dayStart + $d);
            $sheet->setCellValue("{$colL}{$sumRow}", "=SUM({$colL}{$dataStart}:{$colL}{$prevRowIdx})");
        }
        // C, AK, AM, AO, AP, AQ, AR, AT columns sum
        $sumCols = [3, $akCol, $amCol, $anCol, $aoCol, $apCol, $aqCol, $arCol, $atCol];
        foreach ($sumCols as $sc) {
            $colL = Coordinate::stringFromColumnIndex($sc);
            $sheet->setCellValue("{$colL}{$sumRow}", "=SUM({$colL}{$dataStart}:{$colL}{$prevRowIdx})");
        }

        $sheet->freezePane('C4');
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(8);
        $sheet->getColumnDimension('C')->setWidth(10);
        for ($c = 4; $c <= $totalCols; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(8);
        }
        $this->addFooter($sheet, $sumRow + 1, $totalCols);
    }

    // ─── Branch Consumption Sheet ─────────────────────────
    private function buildBranchSheet($sheet, string $clientId, $br, string $month, Carbon $dt, int $daysInMonth, int $dayCols, $items)
    {
        $dailyLedger = StockLedger::withoutGlobalScope('client')
            ->where('stock_ledger.client_id', $clientId)
            ->where('stock_ledger.warehouse_id', $br->id)
            ->whereBetween('stock_ledger.date', [$dt->toDateString(), $dt->copy()->endOfMonth()->toDateString()])
            ->where('stock_ledger.movement_type', 'in')
            ->join('dispatch_orders', function ($j) {
                $j->on('stock_ledger.ref_id', '=', 'dispatch_orders.id')
                  ->where('stock_ledger.ref_type', '=', 'dispatch_order');
            })
            ->whereIn('dispatch_orders.type', ['dispatch', 'purchase'])
            ->get(['stock_ledger.item_id', 'stock_ledger.date', 'stock_ledger.qty', 'stock_ledger.unit_cost', 'stock_ledger.total_cost']);

        $perItem = [];
        foreach ($dailyLedger as $entry) {
            $day = (int) Carbon::parse($entry->date)->format('j');
            $iid = $entry->item_id;
            $perItem[$iid]['qty'][$day] = ($perItem[$iid]['qty'][$day] ?? 0) + (float) $entry->qty;
            $perItem[$iid]['cost'][$day] = ($perItem[$iid]['cost'][$day] ?? 0) + (float) $entry->total_cost;
        }

        $closings = MonthlyClosing::where('client_id', $clientId)
            ->where('warehouse_id', $br->id)->where('month', $month)
            ->get()->keyBy('item_id');

        $monthNum = (int) Carbon::parse($month)->format('n');
        $serialDates = [];
        $dayNamesArr = [];
        $arabicDays = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        for ($d = 1; $d <= $dayCols; $d++) {
            $refDay = min($d, $daysInMonth);
            $dayDt = $dt->copy()->day($refDay);
            $serialDates[$d] = Date::PHPToExcel($dayDt);
            $dayNamesArr[$d] = $arabicDays[(int) $dayDt->format('w')];
        }

        // Same column layout as warehouse (fixed 31 day cols)
        $dayStart = 6;
        $akCol = $dayStart + $dayCols;
        $alCol = $akCol + 1;
        $amCol = $akCol + 2;
        $anCol = $akCol + 3;
        $aoCol = $akCol + 4;
        $apCol = $akCol + 5;
        $aqCol = $akCol + 6;
        $arCol = $akCol + 7;
        $asCol = $akCol + 8;
        $atCol = $akCol + 9;
        $totalCols = $atCol;

        // Row 1: title
        $this->writeRow($sheet, 1,
            array_merge([$br->name, 'النسخه الاصليه'], array_fill(2, $totalCols - 2, '')),
            true, false);

        // Row 2: labels
        $row2 = ['برنامج المخزن عن شهر' . $monthNum, '', '', 'رصيد اول الشهر', ''];
        for ($d = 1; $d <= $dayCols; $d++) {
            $row2[] = $dayNamesArr[$d];
        }
        $row2 = array_merge($row2, array_fill(0, 10, ''));
        $labels = ['اجمالي المستلم', 'سعر', 'قيمة المستلم', 'اخر المده', 'المستلم الفعلي',
                    'قيمة اول المدة', 'قيمة اخر المدة', 'قيمة المستلم الفعلي', 'average', 'cost'];
        for ($i = 0; $i < 10; $i++) {
            array_splice($row2, $akCol - 1 + $i, 1, $labels[$i]);
        }
        $this->writeRow($sheet, 2, $row2, true, false);

        // Row 3: sub-headers
        $row3 = ['اسم الصنف', 'الوحده', 'اجمالي المستلم', '', ''];
        for ($d = 1; $d <= $dayCols; $d++) {
            $row3[] = $serialDates[$d];
        }
        $row3 = array_merge($row3, array_fill(0, 10, ''));
        for ($i = 0; $i < 10; $i++) {
            array_splice($row3, $akCol - 1 + $i, 1, $labels[$i]);
        }
        $this->writeRow($sheet, 3, $row3, true, false);

        $dataStart = 4;
        $sheet->setCellValue('A4', 'الصنف');

        $rowIdx = $dataStart;
        foreach ($items as $item) {
            $c = $closings->get($item->id);
            $opening = $c ? (float) $c->opening_qty : 0;
            $avgCost = $c ? (float) $c->avg_cost : 0;
            $closingTheoretical = $c ? (float) $c->closing_qty_theoretical : 0;
            $itemDays = isset($perItem[$item->id]) ? $perItem[$item->id]['qty'] : [];
            $itemCosts = isset($perItem[$item->id]) ? $perItem[$item->id]['cost'] : [];
            $totalDaily = array_sum($itemDays);
            $totalCost = array_sum($itemCosts);
            $inVal = $c ? (float) $c->in_value : 0;
            if ($totalCost == 0 && $inVal > 0) $totalCost = $inVal;

            $rowData = [$item->name, $item->unit, ''];
            $rowData[] = $opening;
            $rowData[] = '';
            for ($d = 1; $d <= $dayCols; $d++) {
                $rowData[] = $itemDays[$d] ?? 0;
            }
            $rowData[] = round($totalDaily, 3);
            $rowData[] = $avgCost > 0 ? round($avgCost, 3) : 0;
            $rowData[] = round($totalDaily * ($avgCost > 0 ? $avgCost : 0), 2);
            $rowData[] = $closingTheoretical;
            $rowData[] = '';
            $rowData[] = $opening > 0 ? round($opening * ($avgCost > 0 ? $avgCost : 0), 2) : 0;
            $rowData[] = $closingTheoretical > 0 ? round($closingTheoretical * ($avgCost > 0 ? $avgCost : 0), 2) : 0;
            $rowData[] = '';
            $rowData[] = '';
            $rowData[] = $totalCost > 0 ? round($totalCost, 2) : 0;

            $this->writeDataRow($sheet, $rowIdx, $rowData);

            $cRef = Coordinate::stringFromColumnIndex(3) . $rowIdx;
            $dRef = Coordinate::stringFromColumnIndex(4) . $rowIdx;
            $akRef = Coordinate::stringFromColumnIndex($akCol) . $rowIdx;
            $alRef = Coordinate::stringFromColumnIndex($alCol) . $rowIdx;
            $aoRef = Coordinate::stringFromColumnIndex($aoCol) . $rowIdx;
            $arRef = Coordinate::stringFromColumnIndex($arCol) . $rowIdx;
            $asRef = Coordinate::stringFromColumnIndex($asCol) . $rowIdx;
            $atRef = Coordinate::stringFromColumnIndex($atCol) . $rowIdx;
            $amRef = Coordinate::stringFromColumnIndex($amCol) . $rowIdx;
            $anRef = Coordinate::stringFromColumnIndex($anCol) . $rowIdx;
            $apRef = Coordinate::stringFromColumnIndex($apCol) . $rowIdx;
            $aqRef = Coordinate::stringFromColumnIndex($aqCol) . $rowIdx;

            $sheet->setCellValue($cRef, "={$dRef}+{$akRef}");
            $firstDayRef = Coordinate::stringFromColumnIndex($dayStart) . $rowIdx;
            $lastDayRef = Coordinate::stringFromColumnIndex($dayStart + $dayCols - 1) . $rowIdx;
            $sheet->setCellValue($akRef, "=SUM({$firstDayRef}:{$lastDayRef})");
            $sheet->setCellValue($amRef, "={$akRef}*{$alRef}");
            $sheet->setCellValue($aoRef, "={$dRef}+{$akRef}-{$anRef}");
            $sheet->setCellValue($apRef, "={$dRef}*{$alRef}");
            $sheet->setCellValue($aqRef, "={$anRef}*{$alRef}");
            $sheet->setCellValue($arRef, "={$aoRef}*{$alRef}");
            $sheet->setCellValue($asRef, "=IF({$atRef}=0,0,{$atRef}/{$akRef})");

            $rowIdx++;
        }

        // Summary
        $prevRowIdx = $rowIdx - 1;
        $sumRow = $rowIdx;
        $sumHeader = array_fill(0, $totalCols, '');
        $sumHeader[0] = 'الإجمالي';
        $this->writeRow($sheet, $sumRow, $sumHeader, false, true);
        $dColL = Coordinate::stringFromColumnIndex(4);
        $sheet->setCellValue("{$dColL}{$sumRow}", "=SUM({$dColL}{$dataStart}:{$dColL}{$prevRowIdx})");
        for ($d = 0; $d < $dayCols; $d++) {
            $colL = Coordinate::stringFromColumnIndex($dayStart + $d);
            $sheet->setCellValue("{$colL}{$sumRow}", "=SUM({$colL}{$dataStart}:{$colL}{$prevRowIdx})");
        }
        $sumCols = [3, $akCol, $amCol, $anCol, $aoCol, $apCol, $aqCol, $arCol, $atCol];
        foreach ($sumCols as $sc) {
            $colL = Coordinate::stringFromColumnIndex($sc);
            $sheet->setCellValue("{$colL}{$sumRow}", "=SUM({$colL}{$dataStart}:{$colL}{$prevRowIdx})");
        }

        $sheet->freezePane('C4');
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(8);
        $sheet->getColumnDimension('C')->setWidth(10);
        for ($c = 4; $c <= $totalCols; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(8);
        }
        $this->addFooter($sheet, $sumRow + 1, $totalCols);
    }

    // ─── Closing Raw Materials Sheet ─────────────────────
    private function buildClosingSheet($sheet, $spreadsheet, string $clientId, string $month,
        $items, $mainSub, $branches, $whSheetNames, $brSheetNames, Carbon $dt, int $daysInMonth, int $dayCols)
    {
        $closings = MonthlyClosing::where('client_id', $clientId)
            ->where('month', $month)->get()->groupBy('item_id');

        $headersRow2 = ['', ''];
        $headersRow3 = ['الصنف', ''];

        // Opening: warehouses only (main/sub, NOT branches)
        $openingCols = [];
        $openingSheetCol = [];
        foreach ($mainSub as $wh) {
            $idx = count($headersRow3);
            $openingCols[] = $idx;
            $openingSheetCol[$wh->id] = $idx;
            $headersRow3[] = "أول مدة {$wh->name}";
            $headersRow2[] = 'اول المده   +  وارد المخزن';
        }

        // Receipts for main/sub
        $receiptCols = [];
        $receiptSheetCol = [];
        foreach ($mainSub as $wh) {
            $idx = count($headersRow3);
            $receiptCols[] = $idx;
            $receiptSheetCol[$wh->id] = $idx;
            $headersRow3[] = "وارد {$wh->name}";
            $headersRow2[] = 'اول المده   +  وارد المخزن';
        }

        // Then the group header switches to "المنصرف" for branch consumption
        $consumptionCols = [];
        $consumptionSheetCol = [];
        foreach ($branches as $br) {
            $idx = count($headersRow3);
            $consumptionCols[] = $idx;
            $consumptionSheetCol[$br->id] = $idx;
            $headersRow3[] = "منصرف {$br->name}";
            $headersRow2[] = 'المنصرف';
        }

        // Fixed columns
        $theoreticalIdx = count($headersRow3); $headersRow3[] = 'اخر المده'; $headersRow2[] = '';
        // Actual per warehouse
        $actualCols = [];
        foreach ($mainSub as $wh) {
            $idx = count($headersRow3);
            $actualCols[] = $idx;
            $headersRow3[] = "رصيد فعلي {$wh->name}";
            $headersRow2[] = 'رصيد الفعلى';
        }
        $diffIdx = count($headersRow3);        $headersRow3[] = 'فرق'; $headersRow2[] = 'فرق';
        $priceIdx = count($headersRow3);       $headersRow3[] = 'سعر'; $headersRow2[] = '';
        $valueIdx = count($headersRow3);       $headersRow3[] = 'قيمة'; $headersRow2[] = '';
        $colCount = count($headersRow3);

        $row2 = array_pad($headersRow2, $colCount, '');
        $this->writeRow($sheet, 1, ['تقفيل الخامات', '', "شهر {$month}"], true);
        $this->writeRow($sheet, 2, $row2, true);
        $this->writeRow($sheet, 3, $headersRow3, true);
        $dataStart = 4;
        $sheet->setCellValue('A4', 'الصنف');

        // Track the main warehouse ID for cross-reference
        $mainWh = $mainSub->firstWhere('type', 'main');
        $mainWhId = $mainWh?->id;

        $rowIdx = $dataStart;
        foreach ($items as $item) {
            $itemClosings = $closings->get($item->id, collect());
            $rowData = [$item->name, ''];

            $totalOpening = 0;
            $totalReceipts = 0;
            $totalConsumption = 0;

            // Opening per warehouse (cross-sheet ='وارد {name}'!D{row})
            foreach ($mainSub as $wh) {
                $sn = $whSheetNames[$wh->id] ?? ('وارد ' . $wh->name);
                $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                $op = $c ? (float) $c->opening_qty : 0;
                $rowData[] = "='{$sn}'!D{$rowIdx}";
                $totalOpening += $op;
            }

            // Receipts per warehouse (cross-sheet ='وارد {name}'!AK{row})
            foreach ($mainSub as $wh) {
                $sn = $whSheetNames[$wh->id] ?? ('وارد ' . $wh->name);
                $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                $in = $c ? (float) $c->in_qty : 0;
                $rowData[] = "='{$sn}'!AK{$rowIdx}";
                $totalReceipts += $in;
            }

            // Consumption per branch (cross-sheet ='منصرف {name}'!AK{row})
            foreach ($branches as $br) {
                $sn = $brSheetNames[$br->id] ?? ('منصرف ' . $br->name);
                $c = $itemClosings->where('warehouse_id', $br->id)->first();
                $brIn = $c ? (float) $c->in_qty : 0;
                $rowData[] = "='{$sn}'!AK{$rowIdx}";
                $totalConsumption += $brIn;
            }

            // Get avg cost
            $avgCost = 0;
            $mainSheetName = $mainWhId ? ($whSheetNames[$mainWhId] ?? null) : null;
            foreach ($mainSub as $wh) {
                $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                if ($c) {
                    if ($avgCost === 0) $avgCost = (float) $c->avg_cost;
                }
            }
            $price = $avgCost > 0 ? $avgCost : (float) ($item->default_cost ?? 0);

            // N = theoretical (placeholder for formula)
            $rowData[] = '';
            // Actual per warehouse (cross-sheet ='وارد {name}'!AN{row})
            foreach ($mainSub as $wh) {
                $sn = $whSheetNames[$wh->id] ?? ('وارد ' . $wh->name);
                $rowData[] = "='{$sn}'!AN{$rowIdx}";
            }
            // Diff (placeholder for formula)
            $rowData[] = '';
            // Price
            if ($mainSheetName) {
                $rowData[] = "='{$mainSheetName}'!AL{$rowIdx}";
            } else {
                $rowData[] = $price > 0 ? round($price, 3) : 0;
            }
            // Value (placeholder for formula)
            $rowData[] = '';

            $this->writeDataRow($sheet, $rowIdx, $rowData);

            // Build formulas
            $openingRefs = [];
            foreach ($mainSub as $wh) {
                $sn = $whSheetNames[$wh->id] ?? ('وارد ' . $wh->name);
                $openingRefs[] = "='{$sn}'!D{$rowIdx}";
            }
            $receiptRefs = [];
            foreach ($mainSub as $wh) {
                $sn = $whSheetNames[$wh->id] ?? ('وارد ' . $wh->name);
                $receiptRefs[] = "='{$sn}'!AK{$rowIdx}";
            }
            $consumptionRefs = [];
            foreach ($branches as $br) {
                $sn = $brSheetNames[$br->id] ?? ('منصرف ' . $br->name);
                $consumptionRefs[] = "='{$sn}'!AK{$rowIdx}";
            }

            // N = (Σ openings + Σ receipts - Σ consumption)
            $nRef = Coordinate::stringFromColumnIndex(1 + $theoreticalIdx) . $rowIdx;
            $allSumParts = array_merge($openingRefs, $receiptRefs);
            $allFormula = implode('+', $allSumParts);
            if (!empty($consumptionRefs)) {
                $allFormula .= '-(' . implode('+', $consumptionRefs) . ')';
            }
            $sheet->setCellValue($nRef, "={$allFormula}");

            // Diff = IF(SUM(actuals)>0, SUM(actuals)-N, "")
            $firstActualRef = Coordinate::stringFromColumnIndex(1 + $actualCols[0]) . $rowIdx;
            $lastActualRef = Coordinate::stringFromColumnIndex(1 + end($actualCols)) . $rowIdx;
            $dRef = Coordinate::stringFromColumnIndex(1 + $diffIdx) . $rowIdx;
            $sheet->setCellValue($dRef, "=IF(SUM({$firstActualRef}:{$lastActualRef})>0,SUM({$firstActualRef}:{$lastActualRef})-{$nRef},\"\")");

            // Value = price * theoretical
            $yRef = Coordinate::stringFromColumnIndex(1 + $priceIdx) . $rowIdx;
            $zRef = Coordinate::stringFromColumnIndex(1 + $valueIdx) . $rowIdx;
            $sheet->setCellValue($zRef, "={$yRef}*{$nRef}");

            $rowIdx++;
        }

        $sheet->freezePane('C3');
        $sheet->getColumnDimension('A')->setWidth(26);
        $sheet->getColumnDimension('B')->setWidth(8);
        for ($c = 3; $c <= $colCount; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(10);
        }
        $this->addFooter($sheet, $rowIdx + 1, $colCount);
    }

    // ─── Standalone Closing Matrix Export ─────────────────
    public function exportClosingMatrix(string $clientId, string $month)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);
        $dt = Carbon::parse($month . '-01');
        $warehouses = Warehouse::where('client_id', $clientId)->where('is_active', true)->get();
        $mainSub = $warehouses->whereIn('type', ['main', 'sub'])->values();
        $branches = $warehouses->where('type', 'branch')->values();
        $items = Item::where('client_id', $clientId)->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $closings = MonthlyClosing::where('client_id', $clientId)->where('month', $month)->get()->groupBy('item_id');

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('DejaVu Sans')->setSize(9);
        $sheet = $spreadsheet->getActiveSheet()->setRightToLeft(true);

        $headers = ['الصنف'];
        $headerGroup2 = [''];

        // Opening per warehouse only (main/sub, NOT branches)
        foreach ($mainSub as $wh) {
            $headers[] = "أول مدة {$wh->name}";
            $headerGroup2[] = 'اول المده   +  وارد المخزن';
        }

        // Receipts per main/sub
        foreach ($mainSub as $wh) {
            $headers[] = "وارد {$wh->name}";
            $headerGroup2[] = 'اول المده   +  وارد المخزن';
        }

        // Consumption per branch
        foreach ($branches as $br) {
            $headers[] = "منصرف {$br->name}";
            $headerGroup2[] = 'المنصرف';
        }

        $headers[] = 'اخر المده'; $headerGroup2[] = '';
        foreach ($mainSub as $wh) {
            $headers[] = "رصيد فعلي {$wh->name}";
            $headerGroup2[] = 'رصيد الفعلى';
        }
        $headers[] = 'فرق'; $headerGroup2[] = 'فرق';
        $headers[] = 'سعر'; $headerGroup2[] = '';
        $headers[] = 'قيمة'; $headerGroup2[] = '';
        $colCount = count($headers);

        $this->writeRow($sheet, 1, ['تقفيل الخامات', '', "شهر {$month}"], true);
        $row2 = array_pad($headerGroup2, $colCount, '');
        $this->writeRow($sheet, 2, $row2, true);
        $this->writeRow($sheet, 3, $headers, true);
        $dataStart = 4;
        $sheet->setCellValue('A4', 'الصنف');

        // Column indices (0-based)
        $openingCount = count($mainSub);
        $receiptCount = count($mainSub);
        $consumptionCount = count($branches);
        $openingStart = 1; // column B
        $receiptStart = $openingStart + $openingCount;
        $consumptionStart = $receiptStart + $receiptCount;
        $theoreticalIdx = $consumptionStart + $consumptionCount; // N
        $actualCols = [];
        for ($i = 0; $i < $openingCount; $i++) {
            $actualCols[] = $theoreticalIdx + 1 + $i;
        }
        $diffIdx = end($actualCols) + 1;
        $priceIdx = $diffIdx + 1;
        $valueIdx = $priceIdx + 1;

        $rowIdx = $dataStart;
        foreach ($items as $item) {
            $itemClosings = $closings->get($item->id, collect());
            $rowData = [$item->name];

            // Openings (warehouses only, not branches)
            foreach ($mainSub as $wh) {
                $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                $rowData[] = $c ? (float) $c->opening_qty : 0;
            }

            // Receipts per main/sub
            foreach ($mainSub as $wh) {
                $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                $rowData[] = $c ? (float) $c->in_qty : 0;
            }

            // Consumption per branch
            foreach ($branches as $br) {
                $c = $itemClosings->where('warehouse_id', $br->id)->first();
                $rowData[] = $c ? (float) $c->in_qty : 0;
            }

            // Avg cost
            $avgCost = 0;
            foreach ($mainSub as $wh) {
                $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                if ($c && (float) $c->avg_cost > 0) {
                    $avgCost = (float) $c->avg_cost;
                    break;
                }
            }
            $price = $avgCost > 0 ? $avgCost : (float) ($item->default_cost ?? 0);

            // N = empty (formula fills it)
            $rowData[] = '';
            // Actual per warehouse
            foreach ($mainSub as $wh) {
                $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                $actual = ($c && $c->closing_qty_actual !== null) ? (float) $c->closing_qty_actual : '';
                $rowData[] = $actual !== '' ? $actual : '';
            }
            // Diff (empty, formula fills it)
            $rowData[] = '';
            // Price
            $rowData[] = $price > 0 ? round($price, 3) : 0;
            // Value (empty, formula fills it)
            $rowData[] = '';

            $this->writeDataRow($sheet, $rowIdx, $rowData);

            // Formulas
            // N = theoretical = SUM(B:receipt_end) - SUM(consumption_start:consumption_end)
            $firstAllCol = Coordinate::stringFromColumnIndex(1 + $openingStart); // B
            $lastAllCol = Coordinate::stringFromColumnIndex(1 + $receiptStart + $receiptCount - 1);
            $firstConsumeCol = Coordinate::stringFromColumnIndex(1 + $consumptionStart);
            $lastConsumeCol = Coordinate::stringFromColumnIndex(1 + $consumptionStart + $consumptionCount - 1);

            $nRef = Coordinate::stringFromColumnIndex(1 + $theoreticalIdx) . $rowIdx;

            if ($consumptionCount > 0) {
                $formula = "=SUM({$firstAllCol}{$rowIdx}:{$lastAllCol}{$rowIdx})-SUM({$firstConsumeCol}{$rowIdx}:{$lastConsumeCol}{$rowIdx})";
            } else {
                $formula = "=SUM({$firstAllCol}{$rowIdx}:{$lastAllCol}{$rowIdx})";
            }
            $sheet->setCellValue($nRef, $formula);

            // Diff = IF(SUM(actuals)>0, SUM(actuals)-N, "")
            $firstActualRef = Coordinate::stringFromColumnIndex(1 + $actualCols[0]) . $rowIdx;
            $lastActualRef = Coordinate::stringFromColumnIndex(1 + end($actualCols)) . $rowIdx;
            $dRef = Coordinate::stringFromColumnIndex(1 + $diffIdx) . $rowIdx;
            $sheet->setCellValue($dRef, "=IF(SUM({$firstActualRef}:{$lastActualRef})>0,SUM({$firstActualRef}:{$lastActualRef})-{$nRef},\"\")");

            // Value = price * N
            $yRef = Coordinate::stringFromColumnIndex(1 + $priceIdx) . $rowIdx;
            $zRef = Coordinate::stringFromColumnIndex(1 + $valueIdx) . $rowIdx;
            $sheet->setCellValue($zRef, "={$yRef}*{$nRef}");

            $rowIdx++;
        }

        $sheet->freezePane('C4');
        $sheet->getColumnDimension('A')->setWidth(28);
        for ($c = 2; $c <= $colCount; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(11);
        }
        $this->addFooter($sheet, $rowIdx + 1, $colCount);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "تقفيل_خامات_{$month}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────
    private function writeRow($sheet, int $row, array $data, bool $isHeader = false, bool $isBold = false)
    {
        foreach ($data as $ci => $val) {
            $cellRef = Coordinate::stringFromColumnIndex($ci + 1) . $row;
            $sheet->setCellValue($cellRef, $val);
            $style = $sheet->getStyle($cellRef);
            $style->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $style->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $style->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $style->getBorders()->getAllBorders()->getColor()->setARGB('FFCCCCCC');
            if ($isHeader) {
                $style->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
                $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FF1e3a5f');
            } elseif ($isBold) {
                $style->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FF1e3a5f');
                $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFEFF6FF');
            }
            if ($ci === 0 && !$isHeader) {
                $style->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                $style->getFont()->setBold(true);
            }
        }
    }

    private function writeDataRow($sheet, int $row, array $data)
    {
        foreach ($data as $ci => $val) {
            $cellRef = Coordinate::stringFromColumnIndex($ci + 1) . $row;
            $sheet->setCellValue($cellRef, $val);
            $style = $sheet->getStyle($cellRef);
            $style->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $style->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $style->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $style->getBorders()->getAllBorders()->getColor()->setARGB('FFCCCCCC');
            if ($ci === 0) {
                $style->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                $style->getFont()->setBold(true)->setSize(10);
            } elseif ($ci === 1) {
                $style->getFont()->getColor()->setARGB('FF888888');
            }
            if (is_string($val) && str_starts_with($val, '=')) {
            } elseif (is_numeric($val) && $val < 0) {
                $style->getFont()->getColor()->setARGB('FFDC2626');
            }
        }
    }

    private function addFooter($sheet, int $footerRow, int $colCount)
    {
        $sheet->mergeCells(Coordinate::stringFromColumnIndex(1) . $footerRow . ':' .
            Coordinate::stringFromColumnIndex($colCount) . $footerRow);
        $sheet->setCellValue('A' . $footerRow, 'تم التصدير بواسطة Curve Cost Control System - Ahmed Ali');
        $sheet->getStyle('A' . $footerRow)->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF999999');
        $sheet->getStyle('A' . $footerRow)->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    }
}
