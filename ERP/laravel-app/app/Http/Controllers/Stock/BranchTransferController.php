<?php
namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\DispatchOrder;
use App\Models\DispatchLine;
use App\Models\Item;
use App\Models\MonthlyClosing;
use App\Models\Warehouse;
use App\Services\CostCalculationService;
use App\Services\StockLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchTransferController extends Controller
{
    public function __construct(
        private StockLedgerService     $ledger,
        private CostCalculationService $calc,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));

        $orders = DispatchOrder::where('client_id', $clientId)
            ->whereIn('type', ['branch_transfer', 'branch_return'])
            ->where('date', 'like', $month . '%')
            ->withCount('lines')
            ->with(['lines.item:id,name,unit', 'warehouse:id,name', 'branch:id,name'])
            ->orderByDesc('date')
            ->get();

        return response()->json($orders);
    }

    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_branch_id' => 'required|string',
            'to_branch_id'   => 'required|string',
            'date'           => 'required|date',
            'items'          => 'required|array|min:1',
            'items.*.item_id' => 'required|string',
            'items.*.qty'    => 'required|numeric|min:0.001',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        if ($data['from_branch_id'] === $data['to_branch_id']) {
            return response()->json(['message' => 'لا يمكن التحويل لنفس الفرع'], 422);
        }

        return $this->executeTransfer($request, $data, 'branch_transfer');
    }

    public function return(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id'    => 'required|string',
            'warehouse_id' => 'required|string',
            'date'         => 'required|date',
            'items'        => 'required|array|min:1',
            'items.*.item_id' => 'required|string',
            'items.*.qty'  => 'required|numeric|min:0.001',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        return $this->executeTransfer($request, $data, 'branch_return');
    }

    private function executeTransfer(Request $request, array $data, string $type): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $userId   = $request->user()->id;

        $fromWhId = $this->resolveBranchWarehouseId($clientId, $data['from_branch_id'] ?? $data['branch_id']);
        $toWhId   = $type === 'branch_return'
            ? $data['warehouse_id']
            : $this->resolveBranchWarehouseId($clientId, $data['to_branch_id']);

        if (!$fromWhId) {
            return response()->json(['message' => 'لم يتم التعرف على الفرع المصدر'], 422);
        }
        if (!$toWhId) {
            return response()->json(['message' => $type === 'branch_return' ? 'المخزن الهدف غير موجود' : 'لم يتم التعرف على الفرع الهدف'], 422);
        }

        if ($fromWhId === $toWhId) {
            return response()->json(['message' => 'المصدر والهدف متطابقان'], 422);
        }

        DB::transaction(function () use ($clientId, $userId, $data, $type, $fromWhId, $toWhId, $request) {
            $order = DispatchOrder::create([
                'client_id'    => $clientId,
                'type'         => $type,
                'date'         => $data['date'],
                'warehouse_id' => $type === 'branch_return' ? $data['warehouse_id'] : null,
                'branch_id'    => $data['from_branch_id'] ?? $data['branch_id'],
                'created_by'   => $userId,
                'status'       => 'confirmed',
            ]);

            foreach ($data['items'] as $line) {
                $qty      = (float) $line['qty'];
                $unitCost = (float) ($line['unit_cost'] ?? 0);
                if ($unitCost <= 0) {
                    $item = Item::where('id', $line['item_id'])->where('client_id', $clientId)->first();
                    $unitCost = $item ? (float) $item->default_cost : 0;
                }
                $totalCost = round($qty * $unitCost, 2);

                DispatchLine::create([
                    'order_id'     => $order->id,
                    'item_id'      => $line['item_id'],
                    'warehouse_id' => $fromWhId,
                    'qty'          => $qty,
                    'total_cost'   => $totalCost,
                    'unit_cost'    => $unitCost,
                    'date'         => $data['date'],
                ]);

                if ($type === 'branch_transfer') {
                    $this->ledger->postTransfer(
                        clientId:       $clientId,
                        fromWarehouseId: $fromWhId,
                        toWarehouseId:   $toWhId,
                        itemId:         $line['item_id'],
                        date:           $data['date'],
                        qty:            $qty,
                        unitCost:       $unitCost,
                        refId:          $order->id,
                    );
                } else {
                    $this->ledger->post($clientId, $fromWhId, $line['item_id'], $data['date'], 'out', $qty, $totalCost, $unitCost, 'dispatch_order', $order->id, $type);
                    $this->ledger->post($clientId, $toWhId, $line['item_id'], $data['date'], 'in', $qty, $totalCost, $unitCost, 'dispatch_order', $order->id, $type);
                }
            }

            $this->updateMonthlyClosing($clientId, $fromWhId, $toWhId, $data['items'], substr($data['date'], 0, 7));
        });

        return response()->json(['message' => 'تم الحفظ بنجاح']);
    }

    public function destroy(string $id): JsonResponse
    {
        $order = DispatchOrder::findOrFail($id);

        if (!in_array($order->type, ['branch_transfer', 'branch_return'])) {
            return response()->json(['message' => 'نوع العملية غير صحيح'], 400);
        }

        $clientId = $order->client_id;
        $month    = substr($order->date, 0, 7);
        $order->load('lines');

        $fromWhId = $order->type === 'branch_transfer'
            ? $this->resolveBranchWarehouseId($clientId, $order->branch_id)
            : null;
        $itemIds = $order->lines->pluck('item_id')->unique()->toArray();
        $warehouseIds = $order->lines->pluck('warehouse_id')->unique()->toArray();

        if ($order->warehouse_id) {
            $warehouseIds[] = $order->warehouse_id;
        }
        $warehouseIds = array_unique(array_filter($warehouseIds));

        DB::transaction(function () use ($order) {
            $this->ledger->reverseOrder($order->id);
            $order->lines()->delete();
            $order->delete();
        });

        $this->calcAfterDelete($clientId, $warehouseIds, $itemIds, $month);

        return response()->json(['message' => 'تم حذف العملية وعكس حركات المخزون']);
    }

    private function resolveBranchWarehouseId(string $clientId, ?string $branchId): ?string
    {
        if (!$branchId) return null;

        if (Warehouse::where('id', $branchId)->exists()) {
            return $branchId;
        }

        $branch = \App\Models\Branch::find($branchId);
        if ($branch) {
            $wh = Warehouse::where('client_id', $clientId)
                ->where('name', $branch->name)
                ->where('type', 'branch')
                ->first();
            if ($wh) return $wh->id;
        }

        return $branchId;
    }

    private function updateMonthlyClosing(string $clientId, string $fromWhId, string $toWhId, array $items, string $month): void
    {
        $allWhIds = array_unique([$fromWhId, $toWhId]);
        foreach ($allWhIds as $whId) {
            foreach ($items as $line) {
                $summary = $this->calc->itemMonthSummary($clientId, $whId, $line['item_id'], $month);
                MonthlyClosing::updateOrCreate(
                    ['client_id' => $clientId, 'warehouse_id' => $whId, 'item_id' => $line['item_id'], 'month' => $month],
                    [
                        'opening_qty'             => $summary['opening_qty'],
                        'opening_value'           => $summary['opening_value'],
                        'purchases_qty'           => $summary['purchases_qty'],
                        'purchases_value'         => $summary['purchases_value'],
                        'internal_in_qty'         => $summary['internal_in_qty'],
                        'in_qty'                  => $summary['in_qty'],
                        'in_value'                => $summary['in_value'],
                        'internal_out_qty'        => $summary['internal_out_qty'],
                        'consumption_qty'         => $summary['consumption_qty'],
                        'out_qty'                 => $summary['out_qty'],
                        'avg_cost'                => $summary['avg_cost'],
                        'closing_qty_theoretical' => $summary['closing_qty_theoretical'],
                        'closing_value'           => $summary['closing_value'],
                        'branch_dispatches'       => $summary['branch_dispatches'],
                    ]
                );
            }
        }
    }

    private function calcAfterDelete(string $clientId, array $warehouseIds, array $itemIds, string $month): void
    {
        foreach ($warehouseIds as $whId) {
            foreach ($itemIds as $itemId) {
                $summary = $this->calc->itemMonthSummary($clientId, $whId, $itemId, $month);
                if ($summary['opening_qty'] == 0 && $summary['in_qty'] == 0 && $summary['out_qty'] == 0) {
                    MonthlyClosing::where('client_id', $clientId)
                        ->where('warehouse_id', $whId)->where('item_id', $itemId)->where('month', $month)->delete();
                } else {
                    MonthlyClosing::updateOrCreate(
                        ['client_id' => $clientId, 'warehouse_id' => $whId, 'item_id' => $itemId, 'month' => $month],
                        [
                            'opening_qty'             => $summary['opening_qty'],
                            'opening_value'           => $summary['opening_value'],
                            'purchases_qty'           => $summary['purchases_qty'],
                            'purchases_value'         => $summary['purchases_value'],
                            'internal_in_qty'         => $summary['internal_in_qty'],
                            'in_qty'                  => $summary['in_qty'],
                            'in_value'                => $summary['in_value'],
                            'internal_out_qty'        => $summary['internal_out_qty'],
                            'consumption_qty'         => $summary['consumption_qty'],
                            'out_qty'                 => $summary['out_qty'],
                            'avg_cost'                => $summary['avg_cost'],
                            'closing_qty_theoretical' => $summary['closing_qty_theoretical'],
                            'closing_value'           => $summary['closing_value'],
                            'branch_dispatches'       => $summary['branch_dispatches'],
                        ]
                    );
                }
            }
        }
    }
}
