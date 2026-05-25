<?php
namespace App\Services\MenuEngineering;

use App\Models\Client;
use App\Models\MenuEngineering\MenuCategory;
use App\Models\MenuEngineering\MenuEngineeringMenu;
use App\Models\MenuEngineering\MenuRecipe;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Storage;

class MenuExportService
{
    private const HEADERS = ['الصنف', 'الكمية', 'وحدة الشراء', 'سعر الوحدة', 'وحدة الريسيبي', 'CF', 'Yield %', 'EP Cost', 'الإجمالي'];

    // ── Full Menu Excel ──────────────────────────────────

    public function streamMenuExcel(MenuEngineeringMenu $menu, Client $client): StreamedResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $recipes = MenuRecipe::where('menu_id', $menu->id)
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->where('exclude_from_menu', false)
            ->with('items.ingredient:id,name')
            ->get();

        $categories = MenuCategory::where('menu_id', $menu->id)
            ->orderBy('sort_order')->pluck('name')->toArray();

        $grouped = [];
        foreach ($recipes as $r) {
            $cat = $r->category ?: 'أخرى';
            $grouped[$cat][] = $r;
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $sheetIndex = 0;
        foreach ($categories as $catName) {
            if (empty($grouped[$catName])) continue;
            if ($sheetIndex === 0) {
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle($this->sanitizeTitle($catName));
            } else {
                $sheet = $spreadsheet->createSheet();
                $sheet->setTitle($this->sanitizeTitle($catName));
            }
            $sheet->setRightToLeft(true);
            $row = 1;
            $this->writeBrandingRow($sheet, $row, $client, $menu, 12);
            $row += 2;
            $this->writeCategorySheet($sheet, $row, $catName, $grouped[$catName], $client);
            $sheetIndex++;
        }

        // Add any uncategorized recipes that weren't in the categories list
        $knownCats = array_flip($categories);
        foreach ($grouped as $catName => $catRecipes) {
            if (isset($knownCats[$catName])) continue;
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->sanitizeTitle($catName));
            $sheet->setRightToLeft(true);
            $row = 1;
            $this->writeBrandingRow($sheet, $row, $client, $menu, 12);
            $row += 2;
            $this->writeCategorySheet($sheet, $row, $catName, $catRecipes, $client);
        }

        // Menu Cost summary sheet
        $summarySheet = $spreadsheet->createSheet();
        $summarySheet->setTitle('Menu Cost');
        $summarySheet->setRightToLeft(true);
        $rowS = 1;
        $this->writeBrandingRow($summarySheet, $rowS, $client, $menu, 12);
        $rowS += 2;
        $this->writeSummarySheet($summarySheet, $rowS, $grouped, $categories);

        $filename = 'menu_' . str_replace(' ', '_', $menu->name) . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    // ── Full Menu PDF ────────────────────────────────────

    public function streamMenuPdf(MenuEngineeringMenu $menu, Client $client): StreamedResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $recipes = MenuRecipe::where('menu_id', $menu->id)
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->where('exclude_from_menu', false)
            ->with('items.ingredient:id,name')
            ->get();

        $categories = MenuCategory::where('menu_id', $menu->id)
            ->orderBy('sort_order')->pluck('name')->toArray();

        $grouped = [];
        foreach ($recipes as $r) {
            $cat = $r->category ?: 'أخرى';
            $grouped[$cat][] = $r;
        }

        $html = '<html><head><meta charset="utf-8"><style>
            body { font-family: dejavusans; direction: rtl; font-size: 8px; }
            .brand { text-align: center; font-size: 14px; font-weight: bold; color: #1e3a5f; margin-bottom: 4px; }
            .menu-title { text-align: center; font-size: 12px; color: #333; margin-bottom: 10px; }
            .cat-title { font-size: 11px; font-weight: bold; color: #1e3a5f; background: #e8f0fe; padding: 5px 8px; margin: 10px 0 4px 0; border-radius: 3px; }
            .recipe-title { font-size: 9px; font-weight: bold; color: #333; margin: 6px 0 2px 0; padding: 3px 5px; background: #f9f9f9; border-right: 3px solid #1e3a5f; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
            th { background: #1e3a5f; color: white; font-weight: bold; padding: 3px 4px; border: 1px solid #2d4a7a; font-size: 7px; }
            td { padding: 2px 4px; border: 1px solid #ccc; font-size: 7px; }
            tr:nth-child(even) { background: #f8fafc; }
            tr:nth-child(odd) { background: #ffffff; }
            .totals-row td { background: #fff3cd !important; font-weight: bold; }
            .subtotal-row td { background: #e8f0fe !important; font-weight: bold; }
            .overall-row td { background: #d4edda !important; font-weight: bold; font-size: 8px; }
            .summary-title { font-size: 13px; font-weight: bold; color: #1e3a5f; text-align: center; margin: 15px 0 8px 0; }
            .footer { text-align: center; font-size: 7px; color: #999; margin-top: 10px; }
        </style></head><body>';

        $html .= '<div class="brand">Curve — نظام إدارة التكاليف</div>';
        $html .= '<div class="menu-title">' . e($menu->name) . ' — ' . date('Y-m-d') . '</div>';

        foreach ($categories as $catName) {
            if (empty($grouped[$catName])) continue;
            $html .= $this->buildCategoryHtml($catName, $grouped[$catName]);
        }
        foreach ($grouped as $catName => $catRecipes) {
            if (in_array($catName, $categories)) continue;
            $html .= $this->buildCategoryHtml($catName, $catRecipes);
        }

        // Summary
        $html .= '<div class="summary-title">ملخص تكلفة المنيو (Menu Cost)</div>';
        $html .= '<table><thead><tr>
            <th>الصنف</th><th>التصنيف</th><th>التكلفة</th><th>سعر البيع</th><th>Food Cost %</th><th>Cost/Portion</th>
        </tr></thead><tbody>';
        $overallCost = 0; $overallPrice = 0;
        foreach ($grouped as $catName => $catRecipes) {
            $html .= '<tr class="subtotal-row"><td colspan="6" style="text-align:right;font-weight:bold;">' . e($catName) . '</td></tr>';
            foreach ($catRecipes as $r) {
                $tc = $r->total_cost;
                $sp = (float) ($r->selling_price ?? 0);
                $cp = $sp > 0 ? round(($tc / $sp) * 100, 2) : 0;
                $cpp = $r->portions > 0 ? round($tc / $r->portions, 4) : 0;
                $html .= '<tr><td>' . e($r->name) . '</td><td>' . e($catName) . '</td>
                    <td style="text-align:left">' . number_format($tc, 2) . '</td>
                    <td style="text-align:left">' . number_format($sp, 2) . '</td>
                    <td style="text-align:left">' . $cp . '%</td>
                    <td style="text-align:left">' . number_format($cpp, 4) . '</td></tr>';
                $overallCost += $tc; $overallPrice += $sp;
            }
        }
        $overallPct = $overallPrice > 0 ? round(($overallCost / $overallPrice) * 100, 2) : 0;
        $html .= '<tr class="overall-row"><td colspan="2">الإجمالي</td>
            <td style="text-align:left">' . number_format($overallCost, 2) . '</td>
            <td style="text-align:left">' . number_format($overallPrice, 2) . '</td>
            <td style="text-align:left">' . $overallPct . '%</td>
            <td></td></tr>';
        $html .= '</tbody></table>';

        $html .= '<div class="footer">تم التصدير بواسطة Curve — نظام إدارة التكاليف</div>';
        $html .= '</body></html>';

        $mpdf = $this->createMpdf();
        if ($client->logo && Storage::disk('public')->exists($client->logo)) {
            $mpdf->SetWatermarkImage(Storage::disk('public')->path($client->logo), 0.12);
            $mpdf->showWatermarkImage = true;
        }
        $mpdf->WriteHTML($html);
        $content = $mpdf->Output('', 'S');
        $filename = 'menu_' . str_replace(' ', '_', $menu->name) . '.pdf';
        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, ['Content-Type' => 'application/pdf']);
    }

    // ── Report Summary Excel ─────────────────────────────

    public function streamReportExcel(string $clientId, ?string $branchId, ?string $menuId, Client $client): StreamedResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $query = MenuRecipe::where('client_id', $clientId)
            ->where('status', 'active')
            ->where('exclude_from_menu', false);
        if ($branchId) $query->where('branch_id', $branchId);
        if ($menuId) $query->where('menu_id', $menuId);
        $recipes = $query->get();

        $grouped = [];
        $overallCost = 0; $overallPrice = 0;
        foreach ($recipes as $r) {
            $tc = $r->total_cost;
            $sp = (float) ($r->selling_price ?? 0);
            $cp = $sp > 0 ? round(($tc / $sp) * 100, 2) : 0;
            $cat = $r->category ?: 'أخرى';
            $grouped[$cat][] = [
                'name' => $r->name, 'total_cost' => $tc, 'selling_price' => $sp, 'cost_pct' => $cp, 'status' => $r->status,
            ];
            $overallCost += $tc; $overallPrice += $sp;
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setRightToLeft(true);
        $sheet->setTitle('تقرير التكاليف');

        $row = 1;
        $this->writeBrandingRow($sheet, $row, $client, null, 10);
        $row += 2;

        // Overall summary
        $sheet->mergeCells('A' . $row . ':F' . $row);
        $sheet->setCellValue('A' . $row, 'ملخص التقرير');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF1e3a5f');
        $row++;
        $overallPct = $overallPrice > 0 ? round(($overallCost / $overallPrice) * 100, 2) : 0;
        $summaryData = [
            ['إجمالي التكلفة', number_format($overallCost, 2) . ' ج'],
            ['إجمالي سعر البيع', number_format($overallPrice, 2) . ' ج'],
            ['نسبة التكلفة الإجمالية', $overallPct . '%'],
        ];
        foreach ($summaryData as $sd) {
            $sheet->setCellValue('A' . $row, $sd[0]);
            $sheet->setCellValue('B' . $row, $sd[1]);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;
        }

        $row++;

        // Per category tables
        foreach ($grouped as $catName => $items) {
            $sheet->mergeCells('A' . $row . ':F' . $row);
            $sheet->setCellValue('A' . $row, $catName);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FF1e3a5f');
            $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFe8f0fe');
            $row++;

            $headers = ['الصنف', 'التكلفة', 'سعر البيع', 'نسبة التكلفة', 'الحالة'];
            foreach ($headers as $ci => $h) {
                $col = Coordinate::stringFromColumnIndex($ci + 1);
                $sheet->setCellValue($col . $row, $h);
                $sheet->getStyle($col . $row)->getFont()->setBold(true)->setSize(9)->getColor()->setARGB('FFFFFFFF');
                $sheet->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1e3a5f');
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            $row++;

            foreach ($items as $it) {
                $sheet->setCellValue('A' . $row, $it['name']);
                $sheet->setCellValue('B' . $row, number_format($it['total_cost'], 2));
                $sheet->setCellValue('C' . $row, number_format($it['selling_price'], 2));
                $pctCell = 'D' . $row;
                $sheet->setCellValue($pctCell, $it['cost_pct'] . '%');
                if ($it['cost_pct'] > 35) {
                    $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB('FFdc3545');
                }
                $sheet->setCellValue('E' . $row, $it['status']);
                $row++;
            }
            $row++;
        }

        $filename = 'تقرير_تكاليف_المنيو.xlsx';
        $writer = new Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    // ── Report Summary PDF ───────────────────────────────

    public function streamReportPdf(string $clientId, ?string $branchId, ?string $menuId, Client $client): StreamedResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $query = MenuRecipe::where('client_id', $clientId)
            ->where('status', 'active')
            ->where('exclude_from_menu', false);
        if ($branchId) $query->where('branch_id', $branchId);
        if ($menuId) $query->where('menu_id', $menuId);
        $recipes = $query->get();

        $grouped = [];
        $overallCost = 0; $overallPrice = 0;
        foreach ($recipes as $r) {
            $tc = $r->total_cost;
            $sp = (float) ($r->selling_price ?? 0);
            $cp = $sp > 0 ? round(($tc / $sp) * 100, 2) : 0;
            $cat = $r->category ?: 'أخرى';
            $grouped[$cat][] = [
                'name' => $r->name, 'total_cost' => $tc, 'selling_price' => $sp, 'cost_pct' => $cp, 'status' => $r->status,
            ];
            $overallCost += $tc; $overallPrice += $sp;
        }

        $html = '<html><head><meta charset="utf-8"><style>
            body { font-family: dejavusans; direction: rtl; font-size: 8px; }
            .brand { text-align: center; font-size: 14px; font-weight: bold; color: #1e3a5f; }
            .title { text-align: center; font-size: 11px; color: #333; margin-bottom: 8px; }
            .cat-title { font-size: 10px; font-weight: bold; color: #1e3a5f; background: #e8f0fe; padding: 4px 6px; margin: 8px 0 3px 0; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
            th { background: #1e3a5f; color: white; font-weight: bold; padding: 3px 4px; border: 1px solid #2d4a7a; font-size: 7px; }
            td { padding: 2px 4px; border: 1px solid #ccc; font-size: 7px; }
            tr:nth-child(even) { background: #f8fafc; }
            .cost-high { color: #dc3545; font-weight: bold; }
            .footer { text-align: center; font-size: 7px; color: #999; margin-top: 8px; }
        </style></head><body>';

        $html .= '<div class="brand">Curve — نظام إدارة التكاليف</div>';
        $html .= '<div class="title">تقرير تكاليف المنيو — ' . date('Y-m-d') . '</div>';

        $overallPct = $overallPrice > 0 ? round(($overallCost / $overallPrice) * 100, 2) : 0;
        $html .= '<table><tr><th>إجمالي التكلفة</th><th>إجمالي سعر البيع</th><th>نسبة التكلفة الإجمالية</th></tr>';
        $html .= '<tr><td style="text-align:center">' . number_format($overallCost, 2) . ' ج</td>
            <td style="text-align:center">' . number_format($overallPrice, 2) . ' ج</td>
            <td style="text-align:center;' . ($overallPct > 35 ? 'font-weight:bold;color:#dc3545' : '') . '">' . $overallPct . '%</td></tr></table>';

        foreach ($grouped as $catName => $items) {
            $html .= '<div class="cat-title">' . e($catName) . '</div>';
            $html .= '<table><thead><tr><th>الصنف</th><th>التكلفة</th><th>سعر البيع</th><th>نسبة التكلفة</th><th>الحالة</th></tr></thead><tbody>';
            foreach ($items as $it) {
                $pctClass = $it['cost_pct'] > 35 ? ' class="cost-high"' : '';
                $html .= '<tr><td>' . e($it['name']) . '</td>
                    <td style="text-align:left">' . number_format($it['total_cost'], 2) . ' ج</td>
                    <td style="text-align:left">' . number_format($it['selling_price'], 2) . ' ج</td>
                    <td style="text-align:left"' . $pctClass . '>' . $it['cost_pct'] . '%</td>
                    <td>' . $it['status'] . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<div class="footer">تم التصدير بواسطة Curve — نظام إدارة التكاليف</div>';
        $html .= '</body></html>';

        $mpdf = $this->createMpdf();
        $mpdf->WriteHTML($html);
        $content = $mpdf->Output('', 'S');
        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename ?? 'تقرير_تكاليف_المنيو.pdf', ['Content-Type' => 'application/pdf']);
    }

    // ── Private helpers ──────────────────────────────────

    private function writeBrandingRow($sheet, int &$row, Client $client, ?MenuEngineeringMenu $menu, int $colCount): void
    {
        $colLetter = Coordinate::stringFromColumnIndex($colCount);
        $sheet->mergeCells('A' . $row . ':' . $colLetter . $row);
        $sheet->setCellValue('A' . $row, 'Curve — نظام إدارة التكاليف');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF1e3a5f');
        $sheet->getRowDimension($row)->setRowHeight(30);

        if ($client->logo && Storage::disk('public')->exists($client->logo)) {
            $drawing = new Drawing();
            $drawing->setPath(Storage::disk('public')->path($client->logo));
            $drawing->setHeight(35);
            $drawing->setCoordinates('A' . $row);
            $drawing->setOffsetX(5);
            $drawing->setOffsetY(2);
            $drawing->setWorksheet($sheet);
        }
        $row++;

        if ($menu) {
            $sheet->mergeCells('A' . $row . ':' . $colLetter . $row);
            $sheet->setCellValue('A' . $row, $menu->name . ' — ' . date('Y-m-d'));
            $sheet->getStyle('A' . $row)->getFont()->setSize(10)->getColor()->setARGB('FF666666');
            $row++;
        }
    }

    private function writeCategorySheet($sheet, int &$row, string $catName, array $recipes, Client $client): void
    {
        $colCount = count(self::HEADERS);
        $colLetter = Coordinate::stringFromColumnIndex($colCount);

        // Category header
        $sheet->mergeCells('A' . $row . ':' . $colLetter . $row);
        $sheet->setCellValue('A' . $row, 'التصنيف: ' . $catName);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF1e3a5f');
        $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFe8f0fe');
        $sheet->getRowDimension($row)->setRowHeight(24);
        $row++;

        foreach ($recipes as $r) {
            // Recipe title
            $recipeInfo = e($r->name) . '  |  حصص: ' . e((string)($r->portions ?? '—')) . '  |  سعر البيع: ' . number_format((float)($r->selling_price ?? 0), 2) . ' ج  |  الحالة: ' . e($r->status ?? '—');
            $sheet->mergeCells('A' . $row . ':' . $colLetter . $row);
            $sheet->setCellValue('A' . $row, $recipeInfo);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FF333333');
            $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFf0f0f0');
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;

            // Column headers
            foreach (self::HEADERS as $ci => $h) {
                $col = Coordinate::stringFromColumnIndex($ci + 1);
                $sheet->setCellValue($col . $row, $h);
                $sheet->getStyle($col . $row)->getFont()->setBold(true)->setSize(9)->getColor()->setARGB('FFFFFFFF');
                $sheet->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1e3a5f');
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle($col . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            }
            $row++;

            // Item data
            foreach ($r->items as $item) {
                $vals = [
                    $item->ingredient?->name ?? '—',
                    (float)$item->qty,
                    $item->purchase_unit ?? '—',
                    (float)$item->purchase_unit_price,
                    $item->recipe_unit ?? '—',
                    (float)$item->conversion_factor,
                    (float)$item->yield_pct,
                    (float)$item->ep_cost,
                    (float)$item->line_total,
                ];
                foreach ($vals as $ci => $val) {
                    $col = Coordinate::stringFromColumnIndex($ci + 1);
                    $sheet->setCellValue($col . $row, $val);
                    $sheet->getStyle($col . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                }
                $row++;
            }

            // Recipe totals row
            $tc = $r->total_cost;
            $cpp = $r->portions > 0 ? round($tc / $r->portions, 4) : 0;
            $sp = (float)($r->selling_price ?? 0);
            $fcp = $sp > 0 ? round(($tc / $sp) * 100, 2) : 0;
            $totalText = 'إجمالي: ' . number_format($tc, 2) . ' ج  |  تكلفة/حصة: ' . number_format($cpp, 4) . ' ج  |  FC%: ' . $fcp . '%';
            $sheet->mergeCells('A' . $row . ':' . $colLetter . $row);
            $sheet->setCellValue('A' . $row, $totalText);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(9)->getColor()->setARGB('FF856404');
            $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFfff3cd');
            $sheet->getRowDimension($row)->setRowHeight(18);
            $row++;

            $row++; // empty row between recipes
        }

        // Category subtotal
        $catCost = array_sum(array_map(fn($r) => $r->total_cost, $recipes));
        $sheet->mergeCells('A' . $row . ':' . $colLetter . $row);
        $sheet->setCellValue('A' . $row, 'إجمالي التصنيف "' . $catName . '": ' . number_format($catCost, 2) . ' ج');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FF1e3a5f');
        $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFd4edda');
        $row++;
    }

    private function writeSummarySheet($sheet, int &$row, array $grouped, array $categories): void
    {
        $headers = ['الصنف', 'التصنيف', 'التكلفة', 'سعر البيع', 'Food Cost %', 'تكلفة/حصة'];
        $colCount = count($headers);
        $colLetter = Coordinate::stringFromColumnIndex($colCount);

        // Title
        $sheet->mergeCells('A' . $row . ':' . $colLetter . $row);
        $sheet->setCellValue('A' . $row, 'ملخص تكلفة المنيو (Menu Cost)');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(13)->getColor()->setARGB('FF1e3a5f');
        $sheet->getRowDimension($row)->setRowHeight(28);
        $row += 2;

        // Headers
        foreach ($headers as $ci => $h) {
            $col = Coordinate::stringFromColumnIndex($ci + 1);
            $sheet->setCellValue($col . $row, $h);
            $sheet->getStyle($col . $row)->getFont()->setBold(true)->setSize(9)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1e3a5f');
            $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($col . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        $row++;

        $overallCost = 0; $overallPrice = 0;

        foreach ($categories as $catName) {
            if (empty($grouped[$catName])) continue;
            // Category label row
            $sheet->mergeCells('A' . $row . ':' . $colLetter . $row);
            $sheet->setCellValue('A' . $row, $catName);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(9)->getColor()->setARGB('FF1e3a5f');
            $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFe8f0fe');
            $row++;

            foreach ($grouped[$catName] as $r) {
                $tc = $r->total_cost;
                $sp = (float)($r->selling_price ?? 0);
                $cp = $sp > 0 ? round(($tc / $sp) * 100, 2) : 0;
                $cpp = $r->portions > 0 ? round($tc / $r->portions, 4) : 0;

                $sheet->setCellValue('A' . $row, $r->name);
                $sheet->setCellValue('B' . $row, $catName);
                $sheet->setCellValue('C' . $row, $tc);
                $sheet->setCellValue('D' . $row, $sp);
                $sheet->setCellValue('E' . $row, $cp . '%');
                $sheet->setCellValue('F' . $row, $cpp);

                for ($ci = 0; $ci < $colCount; $ci++) {
                    $sheet->getStyle(Coordinate::stringFromColumnIndex($ci + 1) . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                }
                $row++;
                $overallCost += $tc; $overallPrice += $sp;
            }
        }

        // Handle uncategorized
        foreach ($grouped as $catName => $catRecipes) {
            if (in_array($catName, $categories)) continue;
            $sheet->mergeCells('A' . $row . ':' . $colLetter . $row);
            $sheet->setCellValue('A' . $row, $catName);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(9)->getColor()->setARGB('FF1e3a5f');
            $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFe8f0fe');
            $row++;
            foreach ($catRecipes as $r) {
                $tc = $r->total_cost; $sp = (float)($r->selling_price ?? 0);
                $cp = $sp > 0 ? round(($tc / $sp) * 100, 2) : 0;
                $cpp = $r->portions > 0 ? round($tc / $r->portions, 4) : 0;
                $sheet->setCellValue('A' . $row, $r->name);
                $sheet->setCellValue('B' . $row, $catName);
                $sheet->setCellValue('C' . $row, $tc);
                $sheet->setCellValue('D' . $row, $sp);
                $sheet->setCellValue('E' . $row, $cp . '%');
                $sheet->setCellValue('F' . $row, $cpp);
                for ($ci = 0; $ci < $colCount; $ci++) {
                    $sheet->getStyle(Coordinate::stringFromColumnIndex($ci + 1) . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                }
                $row++;
                $overallCost += $tc; $overallPrice += $sp;
            }
        }

        // Overall total
        $overallPct = $overallPrice > 0 ? round(($overallCost / $overallPrice) * 100, 2) : 0;
        $sheet->mergeCells('A' . $row . ':B' . $row);
        $sheet->setCellValue('A' . $row, 'الإجمالي');
        $sheet->setCellValue('C' . $row, $overallCost);
        $sheet->setCellValue('D' . $row, $overallPrice);
        $sheet->setCellValue('E' . $row, $overallPct . '%');
        for ($ci = 0; $ci < $colCount; $ci++) {
            $sheet->getStyle(Coordinate::stringFromColumnIndex($ci + 1) . $row)->getFont()->setBold(true);
            $sheet->getStyle(Coordinate::stringFromColumnIndex($ci + 1) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFd4edda');
            $sheet->getStyle(Coordinate::stringFromColumnIndex($ci + 1) . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        $row++;

        // Column widths
        $widths = [35, 18, 14, 14, 14, 14];
        foreach ($widths as $ci => $w) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($ci + 1))->setWidth($w);
        }
    }

    private function buildCategoryHtml(string $catName, array $recipes): string
    {
        $html = '<div class="cat-title">' . e($catName) . '</div>';

        foreach ($recipes as $r) {
            $html .= '<div class="recipe-title">' . e($r->name) . ' — حصص: ' . e((string)($r->portions ?? '—')) . ' | سعر البيع: ' . number_format((float)($r->selling_price ?? 0), 2) . ' ج | الحالة: ' . e($r->status ?? '—') . '</div>';
            $html .= '<table><thead><tr>';
            foreach (self::HEADERS as $h) {
                $html .= '<th>' . e($h) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($r->items as $item) {
                $html .= '<tr><td>' . e($item->ingredient?->name ?? '—') . '</td>
                    <td style="text-align:left">' . number_format((float)$item->qty, 3) . '</td>
                    <td>' . e($item->purchase_unit ?? '—') . '</td>
                    <td style="text-align:left">' . number_format((float)$item->purchase_unit_price, 2) . '</td>
                    <td>' . e($item->recipe_unit ?? '—') . '</td>
                    <td style="text-align:left">' . number_format((float)$item->conversion_factor, 3) . '</td>
                    <td style="text-align:left">' . (float)$item->yield_pct . '</td>
                    <td style="text-align:left">' . number_format((float)$item->ep_cost, 4) . '</td>
                    <td style="text-align:left">' . number_format((float)$item->line_total, 4) . '</td></tr>';
            }
            $tc = $r->total_cost;
            $sp = (float)($r->selling_price ?? 0);
            $fcp = $sp > 0 ? round(($tc / $sp) * 100, 2) : 0;
            $cpp = $r->portions > 0 ? round($tc / $r->portions, 4) : 0;
            $html .= '<tr class="totals-row"><td colspan="9" style="text-align:right">
                إجمالي: ' . number_format($tc, 2) . ' ج | تكلفة/حصة: ' . number_format($cpp, 4) . ' ج | Food Cost: ' . $fcp . '%</td></tr>';
            $html .= '</tbody></table>';
        }

        return $html;
    }

    private function createMpdf(): Mpdf
    {
        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'default_font' => 'dejavusans',
            'directionality' => 'rtl',
            'autoLangToFont' => true,
            'autoScriptToLang' => true,
            'margin_top' => 15,
            'margin_bottom' => 10,
            'margin_left' => 8,
            'margin_right' => 8,
        ]);
    }

    private function sanitizeTitle(string $title): string
    {
        // PhpSpreadsheet sheet titles max 31 chars, no special chars
        $clean = preg_replace('/[\/\\\?\*\[\]:]/', '', $title);
        return mb_substr($clean, 0, 31);
    }
}
