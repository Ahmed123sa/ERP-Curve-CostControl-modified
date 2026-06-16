<?php
namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\MonthlyClosing;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Models\MenuEngineering\MenuEngineeringMenu;
use App\Models\MenuEngineering\MenuRecipe;
use App\Models\Financial\FinancialDailyEntry;
use App\Models\Financial\FinancialMonthlySummary;
use App\Services\CostCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientReportController extends Controller
{
    public function __construct(private CostCalculationService $calc) {}

    private function getClientId(Request $request): ?string
    {
        return $request->user()->current_client_id;
    }

    public function purchases(Request $request): JsonResponse|StreamedResponse
    {
        $clientId = $this->getClientId($request);

        if (! $clientId) {
            return response()->json([]);
        }

        $month = $request->month ?? now()->format('Y-m');
        $start = now()->parse($month . '-01')->toDateString();
        $end = now()->parse($month . '-01')->endOfMonth()->toDateString();
        $format = $request->format ?? 'json';

        $rows = StockLedger::where('client_id', $clientId)
            ->where('voucher_type', 'purchase')
            ->where('movement_type', 'in')
            ->whereBetween('date', [$start, $end])
            ->select('item_id', DB::raw('SUM(qty) as total_qty'), DB::raw('SUM(total_cost) as total_value'))
            ->groupBy('item_id')
            ->orderByDesc('total_value')
            ->get()
            ->map(function ($r) {
                $item = Item::find($r->item_id);
                return [
                    'item_name' => $item?->name ?? '—',
                    'unit' => $item?->unit ?? '—',
                    'total_qty' => (float) $r->total_qty,
                    'total_value' => (float) $r->total_value,
                ];
            });

        if ($format === 'json') {
            return response()->json($rows);
        }

        $header = ['الصنف', 'الوحدة', 'الكمية', 'الإجمالي'];
        $data = $rows->map(fn($r) => [$r['item_name'], $r['unit'], $r['total_qty'], $r['total_value']])->toArray();

        if ($format === 'xlsx') {
            return $this->streamExcel(array_merge([$header], $data), "مشتريات_{$month}.xlsx");
        }
        return $this->streamPdf($this->tableHtml($header, $data, "تقرير المشتريات - {$month}"), "مشتريات_{$month}.pdf");
    }

    public function menus(Request $request): JsonResponse
    {
        $clientId = $this->getClientId($request);

        if (! $clientId) {
            return response()->json([]);
        }

        $branchId = $request->branch_id;

        $menus = MenuEngineeringMenu::where('client_id', $clientId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'branch_id']);

        return response()->json($menus);
    }

    public function menuEngineering(Request $request): JsonResponse|StreamedResponse
    {
        $clientId = $this->getClientId($request);

        if (! $clientId) {
            return response()->json([]);
        }

        $format = $request->format ?? 'json';
        $menuId = $request->menu_id;
        $branchId = $request->branch_id;

        $query = MenuRecipe::where('client_id', $clientId)
            ->where('status', 'active')->where('exclude_from_menu', false)
            ->where('selling_price', '>', 0);

        if ($menuId) {
            $query->where('menu_id', $menuId);
        } elseif ($branchId) {
            $menuIds = MenuEngineeringMenu::where('client_id', $clientId)
                ->where('branch_id', $branchId)->pluck('id');
            $query->whereIn('menu_id', $menuIds);
        }

        $recipes = $query->with('items')->get();

        $rows = $recipes->map(function ($r) {
            $cost = (float) $r->items->sum('line_total');
            $price = (float) $r->selling_price;
            $fc = $price > 0 ? round(($cost / $price) * 100, 1) : 0;
            return [
                'recipe_name' => $r->name,
                'total_cost' => $cost,
                'selling_price' => $price,
                'fc_pct' => $fc,
                'profit_margin' => round(100 - $fc, 1),
            ];
        })->sortBy('fc_pct')->values();

        if ($format === 'json') {
            $costs = $rows->pluck('total_cost');
            $prices = $rows->pluck('selling_price');
            $fcs = $rows->pluck('fc_pct');
            $totalCost = $costs->sum();
            $totalPrice = $prices->sum();
            $avgFc = $rows->count() > 0 ? round($fcs->avg(), 1) : 0;

            return response()->json([
                'recipes' => $rows,
                'summary' => [
                    'total_recipes' => $rows->count(),
                    'total_cost' => round($totalCost, 2),
                    'total_selling_price' => round($totalPrice, 2),
                    'avg_fc_pct' => $avgFc,
                    'min_fc_pct' => $fcs->count() > 0 ? $fcs->min() : 0,
                    'max_fc_pct' => $fcs->count() > 0 ? $fcs->max() : 0,
                ],
            ]);
        }

        $header = ['الوصفة', 'التكلفة', 'سعر البيع', 'نسبة التكلفة', 'الربحية'];
        $data = $rows->map(fn($r) => [$r['recipe_name'], $r['total_cost'], $r['selling_price'], $r['fc_pct'] . '%', $r['profit_margin'] . '%'])->toArray();

        if ($format === 'xlsx') {
            return $this->streamExcel(array_merge([$header], $data), 'menu_engineering.xlsx');
        }
        return $this->streamPdf($this->tableHtml($header, $data, 'تقرير Menu Engineering'), 'menu_engineering.pdf');
    }

    public function expenses(Request $request): JsonResponse|StreamedResponse
    {
        $clientId = $this->getClientId($request);

        if (! $clientId) {
            return response()->json([]);
        }

        $month = $request->month ?? now()->format('Y-m');
        $year = (int) now()->parse($month . '-01')->format('Y');
        $m = (int) now()->parse($month . '-01')->format('m');
        $format = $request->format ?? 'json';

        $dailyEntries = FinancialDailyEntry::where('client_id', $clientId)
            ->whereYear('date', $year)->whereMonth('date', $m)
            ->orderBy('date')
            ->get(['date', 'total_sales', 'total_expenses', 'net_daily']);

        if ($format === 'json') {
            return response()->json([
                'daily' => $dailyEntries,
                'summary' => [
                    'total_sales' => (float) $dailyEntries->sum('total_sales'),
                    'total_expenses' => (float) $dailyEntries->sum('total_expenses'),
                    'net_total' => (float) $dailyEntries->sum('net_daily'),
                ],
            ]);
        }

        $header = ['التاريخ', 'المبيعات', 'المصروفات', 'الصافي'];
        $data = $dailyEntries->map(fn($e) => [$e->date, $e->total_sales, $e->total_expenses, $e->net_daily])->toArray();

        if ($format === 'xlsx') {
            return $this->streamExcel(array_merge([$header], $data), "مصروفات_{$month}.xlsx");
        }
        return $this->streamPdf($this->tableHtml($header, $data, "تقرير المصروفات - {$month}"), "مصروفات_{$month}.pdf");
    }

    public function financial(Request $request): JsonResponse|StreamedResponse
    {
        $clientId = $this->getClientId($request);

        if (! $clientId) {
            return response()->json([]);
        }

        $month = $request->month ?? now()->format('Y-m');
        $year = (int) now()->parse($month . '-01')->format('Y');
        $m = (int) now()->parse($month . '-01')->format('m');
        $format = $request->format ?? 'json';

        $warehouses = Warehouse::where('client_id', $clientId)->where('is_active', true)->get(['id', 'name', 'type']);
        $whIds = $warehouses->pluck('id');

        $closings = MonthlyClosing::where('client_id', $clientId)
            ->where('month', $month)
            ->whereIn('warehouse_id', $whIds)
            ->selectRaw('warehouse_id, SUM(opening_value) as opening, SUM(purchases_value) as purchases, SUM(diff_value) as diff')
            ->groupBy('warehouse_id')
            ->get()
            ->keyBy('warehouse_id');

        $whData = $warehouses->map(function ($wh) use ($closings) {
            $c = $closings->get($wh->id);
            return [
                'name' => $wh->name,
                'type' => $wh->type,
                'opening' => (float) ($c->opening ?? 0),
                'purchases' => (float) ($c->purchases ?? 0),
                'diff' => (float) ($c->diff ?? 0),
            ];
        });

        $finSummary = FinancialMonthlySummary::where('client_id', $clientId)
            ->where('month', $m)->where('year', $year)->first();

        $result = [
            'warehouses' => $whData,
            'financial' => $finSummary ? [
                'total_sales' => (float) $finSummary->total_sales,
                'total_expenses' => (float) $finSummary->total_expenses,
                'net_total' => (float) $finSummary->net_total,
            ] : null,
        ];

        if ($format === 'json') {
            return response()->json($result);
        }

        $header = ['المخزن', 'النوع', 'أول المدة', 'المشتريات', 'الفروق'];
        $data = $whData->map(fn($w) => [$w['name'], $w['type'], $w['opening'], $w['purchases'], $w['diff']])->toArray();

        if ($format === 'xlsx') {
            return $this->streamExcel(array_merge([$header], $data), "مالي_{$month}.xlsx");
        }
        return $this->streamPdf($this->tableHtml($header, $data, "التقرير المالي - {$month}"), "مالي_{$month}.pdf");
    }

    private function streamExcel(array $rows, string $filename): StreamedResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setRightToLeft(true);
        $colCount = count($rows[0] ?? []);
        $sheet->mergeCells(Coordinate::stringFromColumnIndex(1) . '1:' . Coordinate::stringFromColumnIndex(max($colCount, 1)) . '1');
        $sheet->setCellValue('A1', 'Curve Cost Control System');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF1e3a5f');
        $dataStart = 2;
        foreach ($rows as $ri => $row) {
            foreach ($row as $ci => $val) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . ($ri + $dataStart), $val);
            }
        }
        $writer = new Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function streamPdf(string $html, string $filename): StreamedResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);
        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4-L',
            'default_font' => 'dejavusans', 'directionality' => 'rtl',
            'autoLangToFont' => true, 'autoScriptToLang' => true,
        ]);
        $mpdf->WriteHTML($html);
        $content = $mpdf->Output('', 'S');
        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, ['Content-Type' => 'application/pdf']);
    }

    private function tableHtml(array $headers, array $rows, string $title): string
    {
        $h = implode('', array_map(fn($c) => "<th>{$c}</th>", $headers));
        $r = implode('', array_map(fn($row) => '<tr>' . implode('', array_map(fn($c) => "<td>{$c}</td>", $row)) . '</tr>', $rows));
        return "<h2>{$title}</h2><table border='1' cellpadding='5' style='border-collapse:collapse;width:100%'><thead><tr>{$h}</tr></thead><tbody>{$r}</tbody></table>";
    }
}
