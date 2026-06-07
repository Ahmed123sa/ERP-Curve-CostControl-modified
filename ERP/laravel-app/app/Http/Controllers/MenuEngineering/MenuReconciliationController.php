<?php
namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Item;
use App\Models\Warehouse;
use App\Models\MenuEngineering\MenuRecipe;
use App\Models\MenuEngineering\MenuReconciliation;
use App\Models\MenuEngineering\MenuReconciliationItem;
use App\Models\MenuEngineering\MenuSale;
use App\Services\MenuEngineering\MenuReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MenuReconciliationController extends Controller
{
    public function __construct(
        private MenuReconciliationService $recon,
    ) {}

    // ── Sales CRUD ──

    public function indexSales(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $branchId = $request->branch_id;

        $query = MenuSale::where('client_id', $clientId);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $sales = $query->with('recipe:id,name')->orderBy('sale_date', 'desc')->get();

        return response()->json($sales);
    }

    public function storeSale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipe_id' => 'required|string|exists:menu_engineering_recipes,id',
            'branch_id' => 'required|string',
            'qty_sold' => 'required|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'sale_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $data['client_id'] = $request->user()->current_client_id;

        $sale = MenuSale::create($data);

        return response()->json(['data' => $sale], 201);
    }

    public function detailedReconcile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => 'required|string',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'sales' => 'nullable|array',
            'sales.*' => 'nullable|numeric|min:0',
        ]);

        $clientId = $request->user()->current_client_id;
        $inlineSales = $data['sales'] ?? [];

        $result = $this->recon->detailedReconcile(
            $clientId,
            $data['branch_id'],
            $data['from'],
            $data['to'],
            $inlineSales,
        );

        Log::info('MenuReconciliation::detailedReconcile', [
            'client_id' => $clientId,
            'branch_id' => $data['branch_id'],
            'from' => $data['from'], 'to' => $data['to'],
            'ingredients' => count($result['ingredient_ids']),
            'categories' => count($result['categories']),
            'inline_sales' => count($inlineSales),
        ]);

        return response()->json($result);
    }

    // ── Reconciliation CRUD ──

    public function indexReconciliations(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $branchId = $request->branch_id;

        $query = MenuReconciliation::where('client_id', $clientId);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $recons = $query->withCount('items')->orderBy('created_at', 'desc')->get()->map(function ($r) {
            $branch = Warehouse::find($r->branch_id);
            return [
                'id' => $r->id,
                'branch_name' => $branch?->name ?? '—',
                'from_date' => $r->from_date,
                'to_date' => $r->to_date,
                'items_count' => $r->items_count,
                'created_at' => $r->created_at,
            ];
        });

        return response()->json($recons);
    }

    public function showReconciliation(string $id): JsonResponse
    {
        $recon = MenuReconciliation::with('items')->findOrFail($id);

        return response()->json([
            'id' => $recon->id,
            'branch_id' => $recon->branch_id,
            'from_date' => $recon->from_date,
            'to_date' => $recon->to_date,
            'created_at' => $recon->created_at,
            'items' => $recon->items->map(function ($item) {
                return [
                    'ingredient_id' => $item->ingredient_id,
                    'ingredient_name' => $item->ingredient_name,
                    'unit' => $item->unit,
                    'opening_qty' => (float) $item->opening_qty,
                    'purchases_qty' => (float) $item->purchases_qty,
                    'closing_actual' => (float) $item->closing_actual,
                    'actual_received' => (float) $item->actual_received,
                    'sales_qty' => (float) $item->sales_qty,
                    'waste_qty' => (float) $item->waste_qty,
                    'diff_qty' => (float) $item->diff_qty,
                ];
            }),
            'sales_data' => $recon->sales_data,
        ]);
    }

    public function storeReconciliation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => 'required|string',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'items' => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|string',
            'items.*.ingredient_name' => 'required|string',
            'items.*.unit' => 'nullable|string',
            'items.*.opening_qty' => 'nullable|numeric|min:0',
            'items.*.purchases_qty' => 'nullable|numeric|min:0',
            'items.*.closing_actual' => 'nullable|numeric|min:0',
            'items.*.sales_qty' => 'nullable|numeric|min:0',
            'items.*.waste_qty' => 'nullable|numeric|min:0',
            'sales_data' => 'nullable|array',
        ]);

        $clientId = $request->user()->current_client_id;

        $recon = MenuReconciliation::create([
            'client_id' => $clientId,
            'branch_id' => $data['branch_id'],
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
            'sales_data' => $data['sales_data'] ?? null,
        ]);

        foreach ($data['items'] as $item) {
            $opening = (float) ($item['opening_qty'] ?? 0);
            $purchases = (float) ($item['purchases_qty'] ?? 0);
            $closing = (float) ($item['closing_actual'] ?? 0);
            $sales = (float) ($item['sales_qty'] ?? 0);
            $waste = (float) ($item['waste_qty'] ?? 0);
            $actualReceived = round($opening + $purchases - $closing, 4);
            $diff = round($sales - $actualReceived + $waste, 4);

            MenuReconciliationItem::create([
                'reconciliation_id' => $recon->id,
                'ingredient_id' => $item['ingredient_id'],
                'ingredient_name' => $item['ingredient_name'],
                'unit' => $item['unit'] ?? '',
                'opening_qty' => $opening,
                'purchases_qty' => $purchases,
                'closing_actual' => $closing,
                'actual_received' => $actualReceived,
                'sales_qty' => $sales,
                'waste_qty' => $waste,
                'diff_qty' => $diff,
            ]);
        }

        return response()->json(['id' => $recon->id], 201);
    }

    public function deleteReconciliation(string $id): JsonResponse
    {
        $recon = MenuReconciliation::findOrFail($id);
        $recon->delete();

        return response()->json(['message' => 'تم الحذف']);
    }

    public function exportReconciliation(string $id): StreamedResponse
    {
        $recon = MenuReconciliation::with('items')->findOrFail($id);
        $branch = Warehouse::find($recon->branch_id);
        $client = Client::find($recon->client_id);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setRightToLeft(true);

        // صف العلامة التجارية
        $sheet->mergeCells('A1:J1');
        $sheet->setCellValue('A1', $client->name ?? '');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF1e3a5f');
        $sheet->getRowDimension(1)->setRowHeight(35);

        if ($client && $client->logo && \Illuminate\Support\Facades\Storage::disk('public')->exists($client->logo)) {
            $drawing = new Drawing();
            $drawing->setPath(\Illuminate\Support\Facades\Storage::disk('public')->path($client->logo));
            $drawing->setHeight(35);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(5);
            $drawing->setOffsetY(2);
            $drawing->setWorksheet($sheet);
        }

        // صف العنوان
        $sheet->mergeCells('A2:J2');
        $sheet->setCellValue('A2', "تسوية {$branch?->name} من {$recon->from_date->format('Y-m-d')} إلى {$recon->to_date->format('Y-m-d')}");
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF1e3a5f');
        $sheet->getRowDimension(2)->setRowHeight(24);

        // الهيدر
        $headers = ['اسم الصنف', 'الوحدة', 'أول المدة', 'مشتريات', 'آخر مدة فعلي',
                     'المستلم الفعلي', 'بيع', 'هوالك', 'الفرق'];
        $colLetters = range('B', 'J');
        $sheet->setCellValue('A3', '#');
        foreach ($headers as $i => $h) {
            $sheet->setCellValue($colLetters[$i] . '3', $h);
        }
        $headerStyle = [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1e3a5f']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]],
        ];
        $sheet->getStyle('A3:J3')->applyFromArray($headerStyle);

        // البيانات
        $rowIdx = 4;
        $idx = 1;
        foreach ($recon->items as $item) {
            $sheet->setCellValue("A{$rowIdx}", $idx++);
            $sheet->setCellValue("B{$rowIdx}", $item->ingredient_name);
            $sheet->setCellValue("C{$rowIdx}", $item->unit);
            $sheet->setCellValue("D{$rowIdx}", $item->opening_qty > 0 ? (float) $item->opening_qty : '');
            $sheet->setCellValue("E{$rowIdx}", $item->purchases_qty > 0 ? (float) $item->purchases_qty : '');
            $sheet->setCellValue("F{$rowIdx}", $item->closing_actual > 0 ? (float) $item->closing_actual : '');
            $sheet->setCellValue("G{$rowIdx}", (float) $item->actual_received);
            $sheet->setCellValue("H{$rowIdx}", (float) $item->sales_qty);
            $sheet->setCellValue("I{$rowIdx}", $item->waste_qty > 0 ? (float) $item->waste_qty : '');
            $sheet->setCellValue("J{$rowIdx}", (float) $item->diff_qty);

            $sheet->getStyle("A{$rowIdx}:J{$rowIdx}")->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

            $rowIdx++;
        }

        $lastDataRow = $rowIdx - 1;

        // Conditional Formatting على عمود الفرق (J)
        $conditionalGreen = new Conditional();
        $conditionalGreen->setConditionType(Conditional::CONDITION_CELLIS);
        $conditionalGreen->setOperatorType(Conditional::OPERATOR_GREATERTHAN);
        $conditionalGreen->addCondition('0');
        $conditionalGreen->getStyle()->getFont()->getColor()->setARGB('FF16A34A');

        $conditionalRed = new Conditional();
        $conditionalRed->setConditionType(Conditional::CONDITION_CELLIS);
        $conditionalRed->setOperatorType(Conditional::OPERATOR_LESSTHAN);
        $conditionalRed->addCondition('0');
        $conditionalRed->getStyle()->getFont()->getColor()->setARGB('FFDC2626');

        $sheet->getStyle("J4:J{$lastDataRow}")->setConditionalStyles([$conditionalGreen, $conditionalRed]);

        // Alternating Row Colors
        $zebraStyle = new Conditional();
        $zebraStyle->setConditionType(Conditional::CONDITION_EXPRESSION);
        $zebraStyle->addCondition('MOD(ROW(),2)=0');
        $zebraStyle->getStyle()->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->setStartColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFF5F5FA'));
        $sheet->getStyle("A4:J{$lastDataRow}")->setConditionalStyles([$zebraStyle]);

        // Summary Row
        $summaryRow = $lastDataRow + 1;
        $sheet->setCellValue("A{$summaryRow}", '');
        $sheet->setCellValue("B{$summaryRow}", 'الإجمالي');
        $sheet->getStyle("B{$summaryRow}")->getFont()->setBold(true);
        $sheet->setCellValue("D{$summaryRow}", "=SUM(D4:D{$lastDataRow})");
        $sheet->setCellValue("E{$summaryRow}", "=SUM(E4:E{$lastDataRow})");
        $sheet->setCellValue("F{$summaryRow}", "=SUM(F4:F{$lastDataRow})");
        $sheet->setCellValue("G{$summaryRow}", "=SUM(G4:G{$lastDataRow})");
        $sheet->setCellValue("H{$summaryRow}", "=SUM(H4:H{$lastDataRow})");
        $sheet->setCellValue("I{$summaryRow}", "=SUM(I4:I{$lastDataRow})");
        $sheet->setCellValue("J{$summaryRow}", "=SUM(J4:J{$lastDataRow})");
        $sheet->getStyle("A{$summaryRow}:J{$summaryRow}")
            ->getFont()->setBold(true);
        $sheet->getStyle("A{$summaryRow}:J{$summaryRow}")
            ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->setStartColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFE8EEF7'));
        $sheet->getStyle("A{$summaryRow}:J{$summaryRow}")
            ->getBorders()->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM);

        // عرض الأعمدة
        $sheet->getColumnDimension('A')->setWidth(4);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(8);
        foreach (range('D', 'J') as $col) {
            $sheet->getColumnDimension($col)->setWidth(13);
        }

        // تنسيق الأرقام
        $sheet->getStyle("D3:J{$summaryRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0.00');

        // تجميد الصفوف
        $sheet->freezePane('B4');

        // فلتر تلقائي
        $sheet->setAutoFilter("A3:J{$lastDataRow}");

        // Page Setup
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(3, 3);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $monthName = $recon->from_date->format('Y-m');
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "{$branch?->name} {$monthName}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
