<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\Financial\FinancialExpenseCategory;
use App\Services\Financial\DailyEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyEntryController extends Controller
{
    public function __construct(private DailyEntryService $service) {}

    public function categories(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $cats = FinancialExpenseCategory::where('client_id', $clientId)
            ->orWhereNull('client_id')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'code', 'sort_order', 'is_purchase']);

        return response()->json(['categories' => $cats]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
            'is_purchase' => 'nullable|boolean',
        ]);

        $category = FinancialExpenseCategory::create([
            'client_id' => $request->user()->current_client_id,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_purchase' => $data['is_purchase'] ?? false,
        ]);

        return response()->json(['category' => $category, 'message' => 'تم إضافة الفئة']);
    }

    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
            'is_purchase' => 'nullable|boolean',
        ]);

        $category = FinancialExpenseCategory::findOrFail($id);
        $category->update($data);

        return response()->json(['category' => $category, 'message' => 'تم تحديث الفئة']);
    }

    public function reorderCategories(Request $request): JsonResponse
    {
        $data = $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|uuid',
            'categories.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($data['categories'] as $item) {
            FinancialExpenseCategory::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['message' => 'تم إعادة الترتيب']);
    }

    public function destroyCategory(string $id): JsonResponse
    {
        $category = FinancialExpenseCategory::findOrFail($id);
        $category->delete();

        return response()->json(['message' => 'تم حذف الفئة']);
    }

    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));

        $data = $this->service->list($clientId, $month);

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'date' => 'required|date',
            'total_sales' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'details' => 'nullable|array',
            'details.*.expense_category_id' => 'required|uuid',
            'details.*.amount' => 'required|numeric|min:0',
            'details.*.description' => 'nullable|string|max:500',
            'details.*.quantity' => 'nullable|numeric|min:0',
            'details.*.item_id' => 'nullable|uuid|exists:items,id',
        ]);

        $entry = $this->service->store($clientId, $data);

        return response()->json(['entry' => $entry, 'message' => 'تم حفظ اليومية']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $entry = \App\Models\Financial\FinancialDailyEntry::where('client_id', $clientId)
            ->with('details.category')
            ->findOrFail($id);

        return response()->json(['entry' => $entry]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'total_sales' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'details' => 'nullable|array',
            'details.*.expense_category_id' => 'required|uuid',
            'details.*.amount' => 'required|numeric|min:0',
            'details.*.description' => 'nullable|string|max:500',
            'details.*.quantity' => 'nullable|numeric|min:0',
            'details.*.item_id' => 'nullable|uuid|exists:items,id',
        ]);

        $updated = $this->service->update($clientId, $id, $data);

        return response()->json(['entry' => $updated, 'message' => 'تم تحديث اليومية']);
    }

    public function items(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $categoryId = $request->query('category_id');

        $items = $this->service->itemsByCategory($clientId, $categoryId);

        return response()->json(['items' => $items]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $this->service->destroy($clientId, $id);

        return response()->json(['message' => 'تم حذف اليومية']);
    }

    public function exportExcel(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));

        return $this->service->exportExcel($clientId, $month);
    }

    public function exportSingleDay(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));
        $day = (int) $request->query('day', '1');

        return $this->service->exportSingleDay($clientId, $month, $day);
    }

    public function exportWarehouseIncoming(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));
        $day = $request->query('day') ? (int) $request->query('day') : null;

        return $this->service->exportWarehouseIncoming($clientId, $month, $day);
    }
}
