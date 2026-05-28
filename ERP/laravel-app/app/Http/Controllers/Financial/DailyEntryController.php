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
            ->get(['id', 'name', 'code', 'sort_order']);

        return response()->json(['categories' => $cats]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $category = FinancialExpenseCategory::create([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return response()->json(['category' => $category, 'message' => 'تم إضافة الفئة']);
    }

    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $category = FinancialExpenseCategory::findOrFail($id);
        $category->update($data);

        return response()->json(['category' => $category, 'message' => 'تم تحديث الفئة']);
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
        ]);

        $entry = \App\Models\Financial\FinancialDailyEntry::where('client_id', $clientId)
            ->findOrFail($id);

        $data['date'] = $entry->date;
        $updated = $this->service->store($clientId, $data);

        return response()->json(['entry' => $updated, 'message' => 'تم تحديث اليومية']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $this->service->destroy($clientId, $id);

        return response()->json(['message' => 'تم حذف اليومية']);
    }
}
