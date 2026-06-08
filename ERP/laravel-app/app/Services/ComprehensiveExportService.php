<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Item;
use App\Models\MonthlyClosing;
use App\Models\Production\DailyProduction;
use App\Models\Production\ProcessingBatchOutput;
use App\Models\Production\Recipe;
use App\Models\StockLedger;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ComprehensiveExportService
{
    public function export(string $clientId, string $month)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(600);
        $dt = Carbon::parse($month.'-01');
        $daysInMonth = $dt->daysInMonth;
        $dayCols = 31; // Fixed 31 day columns to match reference file & keep cross-sheet refs stable
        $start = $dt->toDateString();
        $end = $dt->copy()->endOfMonth()->toDateString();

        $client = Client::find($clientId);
        if (! $client) {
            abort(404, 'Client not found');
        }

        $warehouses = Warehouse::where('client_id', $clientId)->where('is_active', true)->get();
        $mainSub = $warehouses->whereIn('type', ['main', 'sub'])->values();
        $branches = $warehouses->where('type', 'branch')->values();
        $items = Item::where('client_id', $clientId)->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('DejaVu Sans')->setSize(9);

        $whSheetNames = [];

        // Sheet 1: Daily Production
        $productionSheetName = 'الانتاج اليومي';
        $prodSheet = $spreadsheet->getActiveSheet()->setRightToLeft(true)->setTitle($productionSheetName);
        $this->buildProductionSheet($prodSheet, $clientId, $month, $dt, $daysInMonth, $dayCols, $client);

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
            $this->buildWarehouseSheet($sheet, $clientId, $wh, $month, $dt, $daysInMonth, $dayCols, $items, $client);
        }

        // Branch Consumption Sheets
        foreach ($branches as $br) {
            $name = mb_substr("منصرف {$br->name}", 0, 31);
            $brSheetNames[$br->id] = $name;
            $sheet = $spreadsheet->createSheet()->setRightToLeft(true)->setTitle($name);
            $this->buildBranchSheet($sheet, $clientId, $br, $month, $dt, $daysInMonth, $dayCols, $items, $client);
        }

        // Closing Raw Materials Sheet
        $closingSheetName = 'تقفيل خامات';
        $sheet = $spreadsheet->createSheet()->setRightToLeft(true)->setTitle($closingSheetName);
        $this->buildClosingSheet($sheet, $spreadsheet, $clientId, $month, $items, $mainSub, $branches,
            $whSheetNames, $brSheetNames, $dt, $daysInMonth, $dayCols, $client);

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
    private function buildProductionSheet($sheet, string $clientId, string $month, Carbon $dt, int $daysInMonth, int $dayCols, $client)
    {
        $recipes = Recipe::withoutGlobalScope('client')
            ->where('client_id', $clientId)
            ->with('outputItem:id,name,unit')
            ->get();
        $prodEntries = DailyProduction::withoutGlobalScope('client')
            ->where('client_id', $clientId)
            ->whereYear('date', $dt->year)->whereMonth('date', $dt->month)
            ->get()
            ->groupBy('recipe_id');

        // Manual items: entries whose recipe_id is actually an Item ID (not a Recipe)
        $recipeIds = $recipes->pluck('id')->toArray();
        $manualItemIds = collect($prodEntries->keys())
            ->reject(fn ($rid) => in_array($rid, $recipeIds))
            ->values();
        $manualItems = $manualItemIds->isNotEmpty()
            ? Item::whereIn('id', $manualItemIds)->where('client_id', $clientId)->get()->keyBy('id')
            : collect();

        // Items already covered by recipes that have production entries
        $recipeItemIdsWithProd = $recipes->filter(fn ($r) => $prodEntries->has($r->id))
            ->pluck('item_id')->filter()->unique()->toArray();

        // Stock-produced items: items produced via StockLedger but not in DailyProduction
        $stockProdIds = StockLedger::withoutGlobalScope('client')
            ->where('client_id', $clientId)
            ->where('voucher_type', 'production')
            ->where('movement_type', 'in')
            ->whereBetween('date', [$dt->toDateString(), $dt->copy()->endOfMonth()->toDateString()])
            ->whereNotIn('item_id', $recipeItemIdsWithProd)
            ->whereNotIn('item_id', $manualItemIds->toArray())
            ->distinct()->pluck('item_id');
        $stockProdItems = $stockProdIds->isNotEmpty()
            ? Item::whereIn('id', $stockProdIds)->where('client_id', $clientId)->get()->keyBy('id')
            : collect();
        $stockProdLedger = StockLedger::withoutGlobalScope('client')
            ->where('client_id', $clientId)
            ->where('voucher_type', 'production')
            ->where('movement_type', 'in')
            ->whereBetween('date', [$dt->toDateString(), $dt->copy()->endOfMonth()->toDateString()])
            ->whereIn('item_id', $stockProdIds)
            ->get()
            ->groupBy('item_id');

        // Branding header
        $hdrRows = 2;
        $lastColInx = 2 + $dayCols + 2;
        $this->writeHeaderWithLogo($sheet, $client, $lastColInx, 'الانتاج اليومي', $month);

        // Columns: A=الصنف, B=الوحدة, C onwards = days (31 cols), then الإجمالي, سعر البيع, القيمة
        $h1Row = 1 + $hdrRows;
        $h2Row = 2 + $hdrRows;
        $dataStart = 3 + $hdrRows;

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

        $this->writeRow($sheet, $h1Row, $headers1, true);
        $this->writeRow($sheet, $h2Row, $headers2, true);

        $totalCol = 2 + $dayCols;
        $priceCol = $totalCol + 1;
        $valueCol = $totalCol + 2;

        $rowIdx = $dataStart;

        // Recipes section
        $recipesStart = $rowIdx;
        foreach ($recipes as $recipe) {
            $entries = $prodEntries->get($recipe->id, collect());
            $dailyQty = [];
            $totalQty = 0;
            for ($d = 1; $d <= $dayCols; $d++) {
                if ($d > $daysInMonth) {
                    $dailyQty[$d] = 0;

                    continue;
                }
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
            $totalRef = Coordinate::stringFromColumnIndex(1 + $totalCol).$rowIdx;
            $sheet->setCellValue($totalRef, "=SUM({$firstDayCol}{$rowIdx}:{$lastDayCol}{$rowIdx})");

            $priceRef = Coordinate::stringFromColumnIndex(1 + $priceCol).$rowIdx;
            $valueRef = Coordinate::stringFromColumnIndex(1 + $valueCol).$rowIdx;
            $sheet->setCellValue($valueRef, "={$totalRef}*{$priceRef}");

            $rowIdx++;
        }

        // Manual items (recipe_id is an Item ID, not a Recipe ID)
        foreach ($manualItems as $item) {
            $entries = $prodEntries->get($item->id, collect());
            $dailyQty = [];
            $totalQty = 0;
            for ($d = 1; $d <= $dayCols; $d++) {
                if ($d > $daysInMonth) {
                    $dailyQty[$d] = 0;

                    continue;
                }
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
            $totalRef = Coordinate::stringFromColumnIndex(1 + $totalCol).$rowIdx;
            $sheet->setCellValue($totalRef, "=SUM({$firstDayCol}{$rowIdx}:{$lastDayCol}{$rowIdx})");

            $rowIdx++;
        }

        // Stock-produced items (produced via StockLedger but not in DailyProduction)
        $stockStart = $rowIdx;
        foreach ($stockProdItems as $item) {
            $entries = $stockProdLedger->get($item->id, collect());
            $dailyQty = [];
            $totalQty = 0;
            for ($d = 1; $d <= $dayCols; $d++) {
                if ($d > $daysInMonth) {
                    $dailyQty[$d] = 0;

                    continue;
                }
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
            $totalRef = Coordinate::stringFromColumnIndex(1 + $totalCol).$rowIdx;
            $sheet->setCellValue($totalRef, "=SUM({$firstDayCol}{$rowIdx}:{$lastDayCol}{$rowIdx})");

            $rowIdx++;
        }

        // Processing batch outputs (items produced via processing batches)
        $processingOutputs = ProcessingBatchOutput::with('day')
            ->whereHas('day', function ($q) use ($clientId, $dt, $daysInMonth) {
                $q->withoutGlobalScope('client')
                    ->where('client_id', $clientId)
                    ->whereBetween('date', [$dt->toDateString(), $dt->copy()->day($daysInMonth)->toDateString()]);
            })->get();
        $processingByItem = $processingOutputs->groupBy('item_id');
        $processingItemIds = $processingByItem->keys();
        $processingItems = $processingItemIds->isNotEmpty()
            ? Item::whereIn('id', $processingItemIds)->where('client_id', $clientId)->get()->keyBy('id')
            : collect();
        foreach ($processingItems as $item) {
            $entries = $processingByItem->get($item->id, collect());
            $dailyQty = [];
            $totalQty = 0;
            for ($d = 1; $d <= $dayCols; $d++) {
                if ($d > $daysInMonth) {
                    $dailyQty[$d] = 0;

                    continue;
                }
                $dateStr = $dt->copy()->day($d)->toDateString();
                $qty = (float) $entries->filter(fn ($o) => $o->day && $o->day->date->format('Y-m-d') === $dateStr)->sum('qty');
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
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(1 + $totalCol).$rowIdx,
                '=SUM('.Coordinate::stringFromColumnIndex(3)."{$rowIdx}:".Coordinate::stringFromColumnIndex(2 + $dayCols)."{$rowIdx})");

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
        $totalColRef = Coordinate::stringFromColumnIndex(1 + $totalCol).$totalRow;
        $fdc = Coordinate::stringFromColumnIndex(3);
        $ldc = Coordinate::stringFromColumnIndex(2 + $dayCols);
        $sheet->setCellValue($totalColRef, "=SUM({$fdc}{$totalRow}:{$ldc}{$totalRow})");
        $valueTotalRef = Coordinate::stringFromColumnIndex(1 + $valueCol).$totalRow;
        $evc = Coordinate::stringFromColumnIndex(1 + $valueCol);
        $sheet->setCellValue($valueTotalRef, "=SUM({$evc}{$dataStart}:{$evc}{$prevRowIdx})");

        $sheet->freezePane('C'.$dataStart);
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(8);
        for ($c = 3; $c <= $lastColInx; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(9);
        }
        $this->addFooter($sheet, $totalRow + 1, $lastColInx);
    }

    // ─── Warehouse Receipt Sheet ──────────────────────────
    private function buildWarehouseSheet($sheet, string $clientId, $wh, string $month, Carbon $dt, int $daysInMonth, int $dayCols, $items, $client)
    {
        $closings = MonthlyClosing::withoutGlobalScope('client')
            ->where('client_id', $clientId)
            ->where('warehouse_id', $wh->id)->where('month', $month)
            ->get()->keyBy('item_id');

        // Fallback opening: fetch previous month's closings for items without current month data
        $prevMonth = $dt->copy()->subMonth()->format('Y-m');
        $prevClosings = MonthlyClosing::withoutGlobalScope('client')
            ->where('client_id', $clientId)
            ->where('warehouse_id', $wh->id)->where('month', $prevMonth)
            ->get()->keyBy('item_id');

        $dailyLedger = StockLedger::withoutGlobalScope('client')
            ->where('stock_ledger.client_id', $clientId)
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

        $hdrRows = 2;
        $this->writeHeaderWithLogo($sheet, $client, $totalCols, "وارد {$wh->name}", $month);

        // Row 1: title (now at row 3)
        $r1 = 1 + $hdrRows;
        $r2 = 2 + $hdrRows;
        $r3 = 3 + $hdrRows;
        $dataStart = 4 + $hdrRows;

        $this->writeRow($sheet, $r1,
            array_merge([$wh->name, 'النسخه الاصليه'], array_fill(2, $totalCols - 2, '')),
            true, false);

        // Row 2: day-of-week labels
        $row2 = ['برنامج المخزن عن شهر'.$monthNum, '', '', 'رصيد اول الشهر', ''];
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
        $this->writeRow($sheet, $r2, $row2, true, false);

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
        $this->writeRow($sheet, $r3, $row3, true, false);

        $sheet->setCellValue('A'.$dataStart, 'الصنف');

        $rowIdx = $dataStart;
        foreach ($items as $item) {
            $c = $closings->get($item->id);
            if ($c) {
                $opening = (float) $c->opening_qty;
                $avgCost = (float) $c->avg_cost;
                $rawActual = $c->getRawOriginal('closing_qty_actual');
                $endingBalance = $rawActual !== null ? (float) $c->closing_qty_actual : (float) $c->closing_qty_theoretical;
                $openingVal = (float) $c->opening_value;
                $inVal = (float) $c->in_value;
                $closingVal = (float) $c->closing_value;
                $inQty = (float) $c->in_qty;
                $actualReceived = $rawActual !== null ? (float) $c->closing_qty_actual : null;
            } else {
                // Fallback: use previous month's closing as opening
                $pc = $prevClosings->get($item->id);
                if ($pc) {
                    $opening = (float) $pc->closing_qty_theoretical;
                    $avgCost = (float) $pc->avg_cost;
                    $openingVal = (float) $pc->closing_value;
                } else {
                    $opening = 0;
                    $avgCost = 0;
                    $openingVal = 0;
                }
                $endingBalance = 0;
                $inVal = 0;
                $closingVal = 0;
                $inQty = 0;
                $actualReceived = null;
            }
            $itemDays = isset($perItem[$item->id]) ? $perItem[$item->id]['qty'] : [];
            $itemCosts = isset($perItem[$item->id]) ? $perItem[$item->id]['cost'] : [];
            $totalDaily = array_sum($itemDays);
            $totalCost = array_sum($itemCosts);

            if ($totalCost == 0 && $inVal > 0) {
                $totalCost = $inVal;
            }

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
            // AN = ending balance (actual preferred)
            $rowData[] = $endingBalance;
            // AO = actual received (closing_qty_actual)
            $rowData[] = $actualReceived !== null ? $actualReceived : '';
            // AP = opening value
            $rowData[] = $opening > 0 ? round($opening * ($avgCost > 0 ? $avgCost : 0), 2) : 0;
            // AQ = ending balance value
            $rowData[] = $endingBalance > 0 ? round($endingBalance * ($avgCost > 0 ? $avgCost : 0), 2) : 0;
            // AR = actual received value
            $rowData[] = '';
            // AS = average (formula =IF(AT=0,0,AT/AK))
            $rowData[] = '';
            // AT = total cost
            $rowData[] = $totalCost > 0 ? round($totalCost, 2) : 0;

            $this->writeDataRow($sheet, $rowIdx, $rowData);

            // Formulas
            $cRef = Coordinate::stringFromColumnIndex(3).$rowIdx;
            $dRef = Coordinate::stringFromColumnIndex(4).$rowIdx;
            $akRef = Coordinate::stringFromColumnIndex($akCol).$rowIdx;
            $alRef = Coordinate::stringFromColumnIndex($alCol).$rowIdx;
            $aoRef = Coordinate::stringFromColumnIndex($aoCol).$rowIdx;
            $arRef = Coordinate::stringFromColumnIndex($arCol).$rowIdx;
            $asRef = Coordinate::stringFromColumnIndex($asCol).$rowIdx;
            $atRef = Coordinate::stringFromColumnIndex($atCol).$rowIdx;
            $amRef = Coordinate::stringFromColumnIndex($amCol).$rowIdx;
            $anRef = Coordinate::stringFromColumnIndex($anCol).$rowIdx;
            $apRef = Coordinate::stringFromColumnIndex($apCol).$rowIdx;
            $aqRef = Coordinate::stringFromColumnIndex($aqCol).$rowIdx;

            // C = D + AK
            $sheet->setCellValue($cRef, "={$dRef}+{$akRef}");
            // AK = SUM(F:AJ) — always 31 day columns
            $firstDayRef = Coordinate::stringFromColumnIndex($dayStart).$rowIdx;
            $lastDayRef = Coordinate::stringFromColumnIndex($dayStart + $dayCols - 1).$rowIdx;
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

        $sheet->freezePane('C'.$dataStart);
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(8);
        $sheet->getColumnDimension('C')->setWidth(10);
        for ($c = 4; $c <= $totalCols; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(8);
        }
        $this->addFooter($sheet, $sumRow + 1, $totalCols);
    }

    // ─── Branch Consumption Sheet ─────────────────────────
    private function buildBranchSheet($sheet, string $clientId, $br, string $month, Carbon $dt, int $daysInMonth, int $dayCols, $items, $client)
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

        $closings = MonthlyClosing::withoutGlobalScope('client')
            ->where('client_id', $clientId)
            ->where('warehouse_id', $br->id)->where('month', $month)
            ->get()->keyBy('item_id');

        // Fallback opening
        $prevMonth = $dt->copy()->subMonth()->format('Y-m');
        $prevClosings = MonthlyClosing::withoutGlobalScope('client')
            ->where('client_id', $clientId)
            ->where('warehouse_id', $br->id)->where('month', $prevMonth)
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

        $hdrRows = 2;
        $this->writeHeaderWithLogo($sheet, $client, $totalCols, "منصرف {$br->name}", $month);

        $r1 = 1 + $hdrRows;
        $r2 = 2 + $hdrRows;
        $r3 = 3 + $hdrRows;
        $dataStart = 4 + $hdrRows;

        // Row 1: title
        $this->writeRow($sheet, $r1,
            array_merge([$br->name, 'النسخه الاصليه'], array_fill(2, $totalCols - 2, '')),
            true, false);

        // Row 2: labels
        $row2 = ['برنامج المخزن عن شهر'.$monthNum, '', '', 'رصيد اول الشهر', ''];
        for ($d = 1; $d <= $dayCols; $d++) {
            $row2[] = $dayNamesArr[$d];
        }
        $row2 = array_merge($row2, array_fill(0, 10, ''));
        $labels = ['اجمالي المستلم', 'سعر', 'قيمة المستلم', 'اخر المده', 'المستلم الفعلي',
            'قيمة اول المدة', 'قيمة اخر المدة', 'قيمة المستلم الفعلي', 'average', 'cost'];
        for ($i = 0; $i < 10; $i++) {
            array_splice($row2, $akCol - 1 + $i, 1, $labels[$i]);
        }
        $this->writeRow($sheet, $r2, $row2, true, false);

        // Row 3: sub-headers
        $row3 = ['اسم الصنف', 'الوحده', 'اجمالي المستلم', '', ''];
        for ($d = 1; $d <= $dayCols; $d++) {
            $row3[] = $serialDates[$d];
        }
        $row3 = array_merge($row3, array_fill(0, 10, ''));
        for ($i = 0; $i < 10; $i++) {
            array_splice($row3, $akCol - 1 + $i, 1, $labels[$i]);
        }
        $this->writeRow($sheet, $r3, $row3, true, false);

        $sheet->setCellValue('A'.$dataStart, 'الصنف');

        $rowIdx = $dataStart;
        foreach ($items as $item) {
            $c = $closings->get($item->id);
            if ($c) {
                $opening = (float) $c->opening_qty;
                $avgCost = (float) $c->avg_cost;
                $closingTheoretical = (float) $c->closing_qty_theoretical;
                $inVal = (float) $c->in_value;
                // For branches, AN shows actual physical count if available
                $rawActual = $c->getRawOriginal('closing_qty_actual');
                $endingBalance = $rawActual !== null ? (float) $c->closing_qty_actual : (float) $c->closing_qty_theoretical;
            } else {
                $pc = $prevClosings->get($item->id);
                if ($pc) {
                    $opening = (float) $pc->closing_qty_theoretical;
                    $avgCost = (float) $pc->avg_cost;
                } else {
                    $opening = 0;
                    $avgCost = 0;
                }
                $closingTheoretical = 0;
                $inVal = 0;
                $endingBalance = 0;
            }
            $itemDays = isset($perItem[$item->id]) ? $perItem[$item->id]['qty'] : [];
            $itemCosts = isset($perItem[$item->id]) ? $perItem[$item->id]['cost'] : [];
            $totalDaily = array_sum($itemDays);
            $totalCost = array_sum($itemCosts);
            if ($totalCost == 0 && $inVal > 0) {
                $totalCost = $inVal;
            }

            $rowData = [$item->name, $item->unit, ''];
            $rowData[] = $opening;
            $rowData[] = '';
            for ($d = 1; $d <= $dayCols; $d++) {
                $rowData[] = $itemDays[$d] ?? 0;
            }
            $rowData[] = round($totalDaily, 3);
            $rowData[] = $avgCost > 0 ? round($avgCost, 3) : 0;
            $rowData[] = round($totalDaily * ($avgCost > 0 ? $avgCost : 0), 2);
            $rowData[] = $endingBalance;
            $rowData[] = '';
            $rowData[] = $opening > 0 ? round($opening * ($avgCost > 0 ? $avgCost : 0), 2) : 0;
            $rowData[] = $closingTheoretical > 0 ? round($closingTheoretical * ($avgCost > 0 ? $avgCost : 0), 2) : 0;
            $rowData[] = '';
            $rowData[] = '';
            $rowData[] = $totalCost > 0 ? round($totalCost, 2) : 0;

            $this->writeDataRow($sheet, $rowIdx, $rowData);

            $cRef = Coordinate::stringFromColumnIndex(3).$rowIdx;
            $dRef = Coordinate::stringFromColumnIndex(4).$rowIdx;
            $akRef = Coordinate::stringFromColumnIndex($akCol).$rowIdx;
            $alRef = Coordinate::stringFromColumnIndex($alCol).$rowIdx;
            $aoRef = Coordinate::stringFromColumnIndex($aoCol).$rowIdx;
            $arRef = Coordinate::stringFromColumnIndex($arCol).$rowIdx;
            $asRef = Coordinate::stringFromColumnIndex($asCol).$rowIdx;
            $atRef = Coordinate::stringFromColumnIndex($atCol).$rowIdx;
            $amRef = Coordinate::stringFromColumnIndex($amCol).$rowIdx;
            $anRef = Coordinate::stringFromColumnIndex($anCol).$rowIdx;
            $apRef = Coordinate::stringFromColumnIndex($apCol).$rowIdx;
            $aqRef = Coordinate::stringFromColumnIndex($aqCol).$rowIdx;

            $sheet->setCellValue($cRef, "={$dRef}+{$akRef}");
            $firstDayRef = Coordinate::stringFromColumnIndex($dayStart).$rowIdx;
            $lastDayRef = Coordinate::stringFromColumnIndex($dayStart + $dayCols - 1).$rowIdx;
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

        $sheet->freezePane('C'.$dataStart);
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
        $items, $mainSub, $branches, $whSheetNames, $brSheetNames, Carbon $dt, int $daysInMonth, int $dayCols, $client)
    {
        $closings = MonthlyClosing::withoutGlobalScope('client')
            ->where('client_id', $clientId)
            ->where('month', $month)->get()->groupBy('item_id');

        $headersRow2 = ['', ''];
        $headersRow3 = ['الصنف', ''];

        // Helper to sanitize sheet names for Excel formula references
        $esc = function ($name) {
            return str_replace("'", "''", $name);
        };

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
        $theoreticalIdx = count($headersRow3);
        $headersRow3[] = 'اخر المده';
        $headersRow2[] = '';
        // Actual per warehouse
        $actualCols = [];
        foreach ($mainSub as $wh) {
            $idx = count($headersRow3);
            $actualCols[] = $idx;
            $headersRow3[] = "رصيد فعلي {$wh->name}";
            $headersRow2[] = 'رصيد الفعلى';
        }
        $diffIdx = count($headersRow3);
        $headersRow3[] = 'فرق';
        $headersRow2[] = 'فرق';
        $priceIdx = count($headersRow3);
        $headersRow3[] = 'سعر';
        $headersRow2[] = '';
        $valueIdx = count($headersRow3);
        $headersRow3[] = 'قيمة';
        $headersRow2[] = '';
        $colCount = count($headersRow3);

        $hdrRows = 2;
        $this->writeHeaderWithLogo($sheet, $client, $colCount, 'تقفيل الخامات', $month);

        $r1 = 1 + $hdrRows;
        $r2 = 2 + $hdrRows;
        $r3 = 3 + $hdrRows;
        $dataStart = 4 + $hdrRows;

        $row2 = array_pad($headersRow2, $colCount, '');
        $this->writeRow($sheet, $r1, ['تقفيل الخامات', '', "شهر {$month}"], true);
        $this->writeRow($sheet, $r2, $row2, true);
        $this->writeRow($sheet, $r3, $headersRow3, true);
        $sheet->setCellValue('A'.$dataStart, 'الصنف');

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
                $sn = $esc($whSheetNames[$wh->id] ?? ('وارد '.$wh->name));
                $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                $op = $c ? (float) $c->opening_qty : 0;
                $rowData[] = "='{$sn}'!D{$rowIdx}";
                $totalOpening += $op;
            }

            // Receipts per warehouse (cross-sheet ='وارد {name}'!AK{row})
            foreach ($mainSub as $wh) {
                $sn = $esc($whSheetNames[$wh->id] ?? ('وارد '.$wh->name));
                $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                $in = $c ? (float) $c->in_qty : 0;
                $rowData[] = "='{$sn}'!AK{$rowIdx}";
                $totalReceipts += $in;
            }

            // Consumption per branch (cross-sheet ='منصرف {name}'!AK{row})
            foreach ($branches as $br) {
                $sn = $esc($brSheetNames[$br->id] ?? ('منصرف '.$br->name));
                $c = $itemClosings->where('warehouse_id', $br->id)->first();
                $brIn = $c ? (float) $c->in_qty : 0;
                $rowData[] = "='{$sn}'!AK{$rowIdx}";
                $totalConsumption += $brIn;
            }

            // Get avg cost
            $avgCost = 0;
            $mainSheetName = $mainWhId ? $esc($whSheetNames[$mainWhId] ?? '') : null;
            foreach ($mainSub as $wh) {
                $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                if ($c) {
                    if ($avgCost === 0) {
                        $avgCost = (float) $c->avg_cost;
                    }
                }
            }
            $price = $avgCost > 0 ? $avgCost : (float) ($item->default_cost ?? 0);

            // N = theoretical (placeholder for formula)
            $rowData[] = '';
            // Actual per warehouse (cross-sheet ='وارد {name}'!AN{row})
            foreach ($mainSub as $wh) {
                $sn = $esc($whSheetNames[$wh->id] ?? ('وارد '.$wh->name));
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
                $sn = $esc($whSheetNames[$wh->id] ?? ('وارد '.$wh->name));
                $openingRefs[] = "='{$sn}'!D{$rowIdx}";
            }
            $receiptRefs = [];
            foreach ($mainSub as $wh) {
                $sn = $esc($whSheetNames[$wh->id] ?? ('وارد '.$wh->name));
                $receiptRefs[] = "='{$sn}'!AK{$rowIdx}";
            }
            $consumptionRefs = [];
            foreach ($branches as $br) {
                $sn = $esc($brSheetNames[$br->id] ?? ('منصرف '.$br->name));
                $consumptionRefs[] = "='{$sn}'!AK{$rowIdx}";
            }

            // N = (Σ openings + Σ receipts - Σ consumption)
            $nRef = Coordinate::stringFromColumnIndex(1 + $theoreticalIdx).$rowIdx;
            $allSumParts = array_merge($openingRefs, $receiptRefs);
            $allFormula = implode('+', $allSumParts);
            if (! empty($consumptionRefs)) {
                $allFormula .= '-('.implode('+', $consumptionRefs).')';
            }
            $sheet->setCellValue($nRef, "={$allFormula}");

            // Diff = IF(SUM(actuals)>0, SUM(actuals)-N, "")
            $firstActualRef = Coordinate::stringFromColumnIndex(1 + $actualCols[0]).$rowIdx;
            $lastActualRef = Coordinate::stringFromColumnIndex(1 + end($actualCols)).$rowIdx;
            $dRef = Coordinate::stringFromColumnIndex(1 + $diffIdx).$rowIdx;
            $sheet->setCellValue($dRef, "=IF(SUM({$firstActualRef}:{$lastActualRef})>0,SUM({$firstActualRef}:{$lastActualRef})-{$nRef},\"\")");

            // Value = price * theoretical
            $yRef = Coordinate::stringFromColumnIndex(1 + $priceIdx).$rowIdx;
            $zRef = Coordinate::stringFromColumnIndex(1 + $valueIdx).$rowIdx;
            $sheet->setCellValue($zRef, "={$yRef}*{$nRef}");

            $rowIdx++;
        }

        $sheet->freezePane('C'.$dataStart);
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
        $dt = Carbon::parse($month.'-01');

        $client = Client::find($clientId);
        if (! $client) {
            abort(404, 'Client not found');
        }

        $warehouses = Warehouse::where('client_id', $clientId)->where('is_active', true)->get();
        $mainSub = $warehouses->whereIn('type', ['main', 'sub'])->values();
        $branches = $warehouses->where('type', 'branch')->values();
        $items = Item::where('client_id', $clientId)->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $closings = MonthlyClosing::withoutGlobalScope('client')->where('client_id', $clientId)->where('month', $month)->get()->groupBy('item_id');

        $spreadsheet = new Spreadsheet;
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

        $headers[] = 'اخر المده';
        $headerGroup2[] = '';
        foreach ($mainSub as $wh) {
            $headers[] = "رصيد فعلي {$wh->name}";
            $headerGroup2[] = 'رصيد الفعلى';
        }
        $headers[] = 'فرق';
        $headerGroup2[] = 'فرق';
        $headers[] = 'سعر';
        $headerGroup2[] = '';
        $headers[] = 'قيمة';
        $headerGroup2[] = '';
        $colCount = count($headers);

        $hdrRows = 2;
        $this->writeHeaderWithLogo($sheet, $client, $colCount, 'تقفيل خامات', $month);

        $r1 = 1 + $hdrRows;
        $r2 = 2 + $hdrRows;
        $r3 = 3 + $hdrRows;
        $dataStart = 4 + $hdrRows;

        $this->writeRow($sheet, $r1, ['تقفيل الخامات', '', "شهر {$month}"], true);
        $row2 = array_pad($headerGroup2, $colCount, '');
        $this->writeRow($sheet, $r2, $row2, true);
        $this->writeRow($sheet, $r3, $headers, true);
        $sheet->setCellValue('A'.$dataStart, 'الصنف');

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

            // Consumption per branch (net internal transfers to match grandSummary)
            foreach ($branches as $br) {
                $c = $itemClosings->where('warehouse_id', $br->id)->first();
                $con = $c ? ((float) $c->internal_in_qty - (float) $c->internal_out_qty) : 0;
                if ($con < 0) {
                    $con = 0;
                }
                $rowData[] = $con;
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
                $rawActual = $c ? $c->getRawOriginal('closing_qty_actual') : null;
                $actual = ($c && $rawActual !== null) ? (float) $c->closing_qty_actual : '';
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

            $nRef = Coordinate::stringFromColumnIndex(1 + $theoreticalIdx).$rowIdx;

            if ($consumptionCount > 0) {
                $formula = "=SUM({$firstAllCol}{$rowIdx}:{$lastAllCol}{$rowIdx})-SUM({$firstConsumeCol}{$rowIdx}:{$lastConsumeCol}{$rowIdx})";
            } else {
                $formula = "=SUM({$firstAllCol}{$rowIdx}:{$lastAllCol}{$rowIdx})";
            }
            $sheet->setCellValue($nRef, $formula);

            // Diff = IF(SUM(actuals)>0, SUM(actuals)-N, "")
            $firstActualRef = Coordinate::stringFromColumnIndex(1 + $actualCols[0]).$rowIdx;
            $lastActualRef = Coordinate::stringFromColumnIndex(1 + end($actualCols)).$rowIdx;
            $dRef = Coordinate::stringFromColumnIndex(1 + $diffIdx).$rowIdx;
            $sheet->setCellValue($dRef, "=IF(SUM({$firstActualRef}:{$lastActualRef})>0,SUM({$firstActualRef}:{$lastActualRef})-{$nRef},\"\")");

            // Value = price * N
            $yRef = Coordinate::stringFromColumnIndex(1 + $priceIdx).$rowIdx;
            $zRef = Coordinate::stringFromColumnIndex(1 + $valueIdx).$rowIdx;
            $sheet->setCellValue($zRef, "={$yRef}*{$nRef}");

            $rowIdx++;
        }

        $sheet->freezePane('C'.$dataStart);
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

    // ─── Branding Header ──────────────────────────────────
    private function writeHeaderWithLogo($sheet, $client, int $colCount, string $sheetLabel, string $month)
    {
        // Row 1: Logo + Client info
        $sheet->mergeCells(Coordinate::stringFromColumnIndex(1).'1:'.
            Coordinate::stringFromColumnIndex($colCount).'1');
        $sheet->setCellValue('A1', "{$client->name} | {$sheetLabel} | {$month}");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF1e3a5f');
        $sheet->getStyle('A1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Insert client logo if available
        if ($client->logo && Storage::disk('public')->exists($client->logo)) {
            $drawing = new Drawing;
            $drawing->setPath(Storage::disk('public')->path($client->logo));
            $drawing->setHeight(40);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(5);
            $drawing->setOffsetY(2);
            $drawing->setWorksheet($sheet);
        }

        // Row 2: Provider subtitle
        $sheet->mergeCells(Coordinate::stringFromColumnIndex(1).'2:'.
            Coordinate::stringFromColumnIndex($colCount).'2');
        $sheet->setCellValue('A2', 'Curve Cost Control System - Ahmed Ali');
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setARGB('FF666666');
        $sheet->getStyle('A2')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(20);
    }

    // ─── Helpers ──────────────────────────────────────────
    private function writeRow($sheet, int $row, array $data, bool $isHeader = false, bool $isBold = false)
    {
        $lastCol = count($data);
        $range = Coordinate::stringFromColumnIndex(1).$row.':'.
                 Coordinate::stringFromColumnIndex($lastCol).$row;

        foreach ($data as $ci => $val) {
            $cellRef = Coordinate::stringFromColumnIndex($ci + 1).$row;
            $sheet->setCellValue($cellRef, $val);
        }

        $style = $sheet->getStyle($range);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $style->getBorders()->getAllBorders()->getColor()->setARGB('FFCCCCCC');
        if ($isHeader) {
            $style->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
            $style->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF1e3a5f');
        } elseif ($isBold) {
            $style->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FF1e3a5f');
            $style->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFEFF6FF');
        }
        if (! $isHeader) {
            $cellRef = Coordinate::stringFromColumnIndex(1).$row;
            $sheet->getStyle($cellRef)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle($cellRef)->getFont()->setBold(true);
        }
    }

    private function writeDataRow($sheet, int $row, array $data)
    {
        $lastCol = count($data);
        $range = Coordinate::stringFromColumnIndex(1).$row.':'.
                 Coordinate::stringFromColumnIndex($lastCol).$row;

        foreach ($data as $ci => $val) {
            $cellRef = Coordinate::stringFromColumnIndex($ci + 1).$row;
            $sheet->setCellValue($cellRef, $val);
        }

        $style = $sheet->getStyle($range);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $style->getBorders()->getAllBorders()->getColor()->setARGB('FFCCCCCC');

        // First cell (item name) right-aligned bold
        $sheet->getStyle(Coordinate::stringFromColumnIndex(1).$row)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle(Coordinate::stringFromColumnIndex(1).$row)
            ->getFont()->setBold(true)->setSize(10);

        // Second cell (unit) subdued color
        $sheet->getStyle(Coordinate::stringFromColumnIndex(2).$row)
            ->getFont()->getColor()->setARGB('FF888888');

        // Negative values in red
        foreach ($data as $ci => $val) {
            if (is_numeric($val) && (float) $val < 0) {
                $cellRef = Coordinate::stringFromColumnIndex($ci + 1).$row;
                $sheet->getStyle($cellRef)->getFont()->getColor()->setARGB('FFDC2626');
            }
        }
    }

    private function addFooter($sheet, int $footerRow, int $colCount)
    {
        $sheet->mergeCells(Coordinate::stringFromColumnIndex(1).$footerRow.':'.
            Coordinate::stringFromColumnIndex($colCount).$footerRow);
        $sheet->setCellValue('A'.$footerRow, 'تم التصدير بواسطة Curve Cost Control System - Ahmed Ali');
        $sheet->getStyle('A'.$footerRow)->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF999999');
        $sheet->getStyle('A'.$footerRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }
}
