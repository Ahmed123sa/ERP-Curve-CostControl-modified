<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\ClosingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MappingController;
use App\Http\Controllers\StockController;

// ── Auth (بدون login) ─────────────────────────────────────
Route::post('/auth/login', [AuthController::class, 'login']);

// ── Protected Routes ──────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // تغيير العميل الحالي للموظف
    Route::post('/auth/switch-client/{clientId}', [AuthController::class, 'switchClient']);

    // ── Dashboard ─────────────────────────────────────────
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/kpis', [DashboardController::class, 'kpis']);
    Route::get('/dashboard/diffs', [DashboardController::class, 'topDiffs']);
    Route::get('/dashboard/monthly-trend', [DashboardController::class, 'monthlyTrend']);
    Route::get('/dashboard/warehouse-summary', [DashboardController::class, 'warehouseSummary']);
    Route::get('/dashboard/export', [DashboardController::class, 'export']);

    // ── Clients (admin only) ──────────────────────────────
    Route::apiResource('clients', ClientController::class);

    // ── Items (الأصناف) ───────────────────────────────────
    Route::delete('/items/bulk', [ItemController::class, 'bulkDelete']);
    Route::apiResource('items', ItemController::class);
    Route::post('/items/import', [ItemController::class, 'import']); // رفع قائمة أصناف من Excel

    // ── Warehouses ────────────────────────────────────────
    Route::apiResource('warehouses', WarehouseController::class);

    // ── Branches ─────────────────────────────────────────
    Route::apiResource('branches', BranchController::class);
    // ربط فرع بمخازنه
    Route::post('/branches/{branch}/sources', [BranchController::class, 'updateSources']);
    Route::get('/branches/{branch}/sources', [BranchController::class, 'sources']);

    // ── Vouchers (الأذون) ─────────────────────────────────
    Route::get('/vouchers', [VoucherController::class, 'index']);
    Route::post('/vouchers/upload', [VoucherController::class, 'upload']);    // رفع Excel
    Route::post('/vouchers/confirm', [VoucherController::class, 'confirm']);  // تأكيد بعد المراجعة
    Route::post('/vouchers/manual', [VoucherController::class, 'manual']);    // إدخال يدوي
    Route::get('/vouchers/{order}', [VoucherController::class, 'show']);
    Route::put('/vouchers/{order}', [VoucherController::class, 'update']);     // تعديل (بيعكس الـ ledger وبيحفظ من تاني)
    Route::delete('/vouchers/{order}', [VoucherController::class, 'destroy']); // حذف (بيعكس الـ ledger)

    // ── Stock ─────────────────────────────────────────────
    Route::get('/stock/current', [StockController::class, 'current']);            // رصيد حالي
    Route::get('/stock/movement', [StockController::class, 'movement']);          // حركة صنف
    Route::get('/stock/opening', [StockController::class, 'opening']);            // أرصدة افتتاحية لموقع/تاريخ
    Route::get('/stock/warehouse-summary', [StockController::class, 'warehouseSummary']); // ملخص مخزن

// ── Closing (التقفيل الشهري) ──────────────────────────
     Route::get('/closing', [ClosingController::class, 'index']);
     Route::get('/closing/all', [ClosingController::class, 'allWarehouses']);      // ← جديد: تقفيل شامل
     Route::post('/closing/generate', [ClosingController::class, 'generate']);
     Route::post('/closing/bulk-actual', [ClosingController::class, 'bulkUpdateActual']); // ← جديد: جرد فعلي بالجملة
     Route::post('/closing/sync-physical', [ClosingController::class, 'syncPhysicalToActual']); // ← جديد: مزامنة الجرد النهائي للماتريكس
     Route::patch('/closing/{closing}/actual', [ClosingController::class, 'updateActual']);
     Route::post('/closing/lock', [ClosingController::class, 'lock']);
     Route::get('/closing/export', [ClosingController::class, 'export']);
    Route::get('/closing/export-pdf', [ClosingController::class, 'exportPdf']);

    // ── Mappings (إدارة ربط الأسماء) ─────────────────────
    Route::get('/mappings', [MappingController::class, 'index']);
    Route::post('/mappings/item', [MappingController::class, 'updateItem']);
    Route::post('/mappings/location', [MappingController::class, 'updateLocation']);
    Route::delete('/mappings/item/{id}', [MappingController::class, 'deleteItem']);

    // ── Reports (التقارير الاحترافية) ─────────────────────
    Route::get('/reports/grand-summary', [\App\Http\Controllers\ReportController::class, 'grandSummary']);
    Route::get('/reports/grand-summary/export', [\App\Http\Controllers\ReportController::class, 'exportMatrix']);
    Route::get('/reports/grand-summary/export-pdf', [\App\Http\Controllers\ReportController::class, 'exportMatrixPdf']);
    Route::get('/reports/branch-performance', [\App\Http\Controllers\ReportController::class, 'branchPerformance']);
    Route::get('/reports/financial-details', [\App\Http\Controllers\ReportController::class, 'financialDetails']);
    Route::get('/reports/financial-details/export', [\App\Http\Controllers\ReportController::class, 'exportFinancialDetails']);
    Route::get('/reports/financial-details/export-pdf', [\App\Http\Controllers\ReportController::class, 'exportFinancialPdf']);

    // جرد من إكسيل (أول وآخر المدة)
    Route::post('/inventory/parse', [\App\Http\Controllers\InventoryUploadController::class, 'parse']);
    Route::post('/inventory/confirm', [\App\Http\Controllers\InventoryUploadController::class, 'confirm']);

    // ── Production Module (الإنتاج اليومي والوصفات) ────────
    Route::prefix('production')->group(function () {
        Route::apiResource('recipes', \App\Http\Controllers\Production\RecipeController::class);
        Route::get('daily', [\App\Http\Controllers\Production\DailyProductionController::class, 'index']);
        Route::post('daily', [\App\Http\Controllers\Production\DailyProductionController::class, 'store']);
        Route::get('post-preview', [\App\Http\Controllers\Production\ProductionPostController::class, 'preview']);
        Route::post('post', [\App\Http\Controllers\Production\ProductionPostController::class, 'post']);
    });

    // ── Menu Engineering Module ──────────────────────────
    Route::prefix('menu-engineering')->group(function () {
        Route::apiResource('menus', \App\Http\Controllers\MenuEngineering\MenuEngineeringMenuController::class);
        Route::apiResource('categories', \App\Http\Controllers\MenuEngineering\MenuCategoryController::class);
        Route::get('/ingredients', [\App\Http\Controllers\MenuEngineering\MenuIngredientController::class, 'index']);
        Route::get('/unit-conversions', [\App\Http\Controllers\MenuEngineering\MenuUnitConversionController::class, 'index']);
        Route::apiResource('recipes', \App\Http\Controllers\MenuEngineering\MenuRecipeController::class);
        Route::post('/recipes/{recipe}/sync-items', [\App\Http\Controllers\MenuEngineering\MenuRecipeController::class, 'syncItems']);
        Route::get('/recipes/{recipe}/versions', [\App\Http\Controllers\MenuEngineering\MenuRecipeController::class, 'versions']);
        Route::post('/recipes/{recipe}/versions', [\App\Http\Controllers\MenuEngineering\MenuRecipeController::class, 'createVersion']);
        Route::get('/report/summary', [\App\Http\Controllers\MenuEngineering\MenuReportController::class, 'summary']);
        Route::get('/sales', [\App\Http\Controllers\MenuEngineering\MenuReconciliationController::class, 'indexSales']);
        Route::post('/sales', [\App\Http\Controllers\MenuEngineering\MenuReconciliationController::class, 'storeSale']);
        Route::post('/reconcile', [\App\Http\Controllers\MenuEngineering\MenuReconciliationController::class, 'detailedReconcile']);
        Route::post('/reconcile/detailed', [\App\Http\Controllers\MenuEngineering\MenuReconciliationController::class, 'detailedReconcile']);
    });
});
