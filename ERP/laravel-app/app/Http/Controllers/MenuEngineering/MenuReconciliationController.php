<?php
namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\MenuEngineering\MenuRecipe;
use App\Models\MenuEngineering\MenuSale;
use App\Services\MenuEngineering\MenuReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        $inlineSales = $data['sales'] ?? []; // recipe_id => qty_sold

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
}
