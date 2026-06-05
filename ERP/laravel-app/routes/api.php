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
    Route::middleware('permission:dashboard')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/dashboard/kpis', [DashboardController::class, 'kpis']);
        Route::get('/dashboard/diffs', [DashboardController::class, 'topDiffs']);
        Route::get('/dashboard/monthly-trend', [DashboardController::class, 'monthlyTrend']);
        Route::get('/dashboard/warehouse-summary', [DashboardController::class, 'warehouseSummary']);
        Route::get('/dashboard/export', [DashboardController::class, 'export']);
        Route::get('/dashboard/smart-summary', [DashboardController::class, 'smartSummary']);
    });

    // ── Clients ───────────────────────────────────────────
    Route::middleware('permission:clients')->group(function () {
        Route::apiResource('clients', ClientController::class);
    });

    // ── Items (الأصناف) ───────────────────────────────────
    Route::middleware('permission:items')->group(function () {
        // Specific routes MUST come before apiResource to avoid matching {item}
        Route::delete('/items/bulk', [ItemController::class, 'bulkDelete']);
        Route::post('/items/import', [ItemController::class, 'import']);
        Route::post('/items/import-stock-levels', [ItemController::class, 'importStockLevels']);
        Route::get('/items/export', [ItemController::class, 'exportExcel']);
        Route::put('/items/{item}/move-bottom', [ItemController::class, 'moveBottom']);
        Route::put('/items/{item}/move-up', [ItemController::class, 'moveUp']);
        Route::apiResource('items', ItemController::class)->only(['index', 'store', 'update', 'destroy']);
    });

    // ── Warehouses ────────────────────────────────────────
    Route::middleware('permission:warehouses')->group(function () {
        Route::apiResource('warehouses', WarehouseController::class);
    });

    // ── Branches (بدون صلاحية مخازن عشان تستخدم في الأذون) ──
    Route::apiResource('branches', BranchController::class);
    Route::post('/branches/{branch}/sources', [BranchController::class, 'updateSources']);
    Route::get('/branches/{branch}/sources', [BranchController::class, 'sources']);

    // ── Vouchers (الأذون) ─────────────────────────────────
    Route::prefix('vouchers')->middleware('permission:vouchers.purchase')->group(function () {
        Route::get('/', [VoucherController::class, 'index']);
        Route::post('/upload', [VoucherController::class, 'upload']);
        Route::post('/confirm', [VoucherController::class, 'confirm']);
        Route::post('/manual', [VoucherController::class, 'manual']);
        Route::get('/{order}', [VoucherController::class, 'show']);
        Route::put('/{order}', [VoucherController::class, 'update']);
        Route::delete('/{order}', [VoucherController::class, 'destroy']);
    });

    // ── Stock ─────────────────────────────────────────────
    Route::middleware('permission:stock.current')->group(function () {
        Route::get('/stock/current', [StockController::class, 'current']);
        Route::get('/stock/movement', [StockController::class, 'movement']);
        Route::get('/stock/opening', [StockController::class, 'opening']);
        Route::get('/stock/warehouse-summary', [StockController::class, 'warehouseSummary']);
    });

    // ── Closing (التقفيل الشهري) ──────────────────────────
    Route::middleware('permission:closing')->group(function () {
        Route::get('/closing', [ClosingController::class, 'index']);
        Route::get('/closing/all', [ClosingController::class, 'allWarehouses']);
        Route::post('/closing/generate', [ClosingController::class, 'generate']);
        Route::post('/closing/bulk-actual', [ClosingController::class, 'bulkUpdateActual']);
        Route::post('/closing/sync-physical', [ClosingController::class, 'syncPhysicalToActual']);
        Route::patch('/closing/{closing}/actual', [ClosingController::class, 'updateActual']);
        Route::post('/closing/lock', [ClosingController::class, 'lock']);
        Route::post('/closing/unlock', [ClosingController::class, 'unlock']);
        Route::get('/closing/export', [ClosingController::class, 'export']);
        Route::get('/closing/export-pdf', [ClosingController::class, 'exportPdf']);
        Route::get('/closing/export-location', [ClosingController::class, 'exportLocationExcel']);
        Route::get('/closing/export-cycle', [ClosingController::class, 'exportCycle']);
    });

    // Edit Mode في التقفيل (نفس صلاحية تعديل الفاتورة)
    Route::middleware('permission:vouchers.purchase')->group(function () {
        Route::get('/closing/cell-orders', [ClosingController::class, 'cellOrders']);
        Route::get('/closing/monthly-orders', [ClosingController::class, 'monthlyOrders']);
        Route::patch('/closing/edit-daily-cell', [ClosingController::class, 'editDailyCell']);
        Route::patch('/closing/edit-cell-value', [ClosingController::class, 'editCellValue']);
    });

    // ── Mappings (إدارة ربط الأسماء) ─────────────────────
    Route::middleware('permission:mappings')->group(function () {
        Route::get('/mappings', [MappingController::class, 'index']);
        Route::post('/mappings/item', [MappingController::class, 'updateItem']);
        Route::post('/mappings/location', [MappingController::class, 'updateLocation']);
        Route::delete('/mappings/item/{id}', [MappingController::class, 'deleteItem']);
        Route::delete('/mappings/location/{id}', [MappingController::class, 'deleteLocation']);
        Route::post('/mappings/remap-item', [MappingController::class, 'remapItem']);
    });

    // ── Users (المستخدمين والصلاحيات) ───────────────────
    Route::middleware('permission:users')->group(function () {
        Route::get('/users', [\App\Http\Controllers\UserController::class, 'index']);
        Route::post('/users', [\App\Http\Controllers\UserController::class, 'store']);
        Route::put('/users/{id}', [\App\Http\Controllers\UserController::class, 'update']);
        Route::delete('/users/{id}', [\App\Http\Controllers\UserController::class, 'destroy']);
        Route::get('/permissions', function () {
            return response()->json(\Spatie\Permission\Models\Permission::pluck('name'));
        });
        Route::get('/roles', function () {
            return response()->json(\Spatie\Permission\Models\Role::pluck('name'));
        });
    });

    // ── Reports (التقارير) ──────────────────────────────
    Route::middleware('permission:reports.financial')->group(function () {
        Route::get('/reports/grand-summary', [\App\Http\Controllers\ReportController::class, 'grandSummary']);
        Route::get('/reports/grand-summary/export', [\App\Http\Controllers\ReportController::class, 'exportMatrix']);
        Route::get('/reports/grand-summary/export-pdf', [\App\Http\Controllers\ReportController::class, 'exportMatrixPdf']);
        Route::get('/reports/branch-performance', [\App\Http\Controllers\ReportController::class, 'branchPerformance']);
        Route::get('/reports/financial-details', [\App\Http\Controllers\ReportController::class, 'financialDetails']);
        Route::get('/reports/financial-details/export', [\App\Http\Controllers\ReportController::class, 'exportFinancialDetails']);
        Route::get('/reports/financial-details/export-pdf', [\App\Http\Controllers\ReportController::class, 'exportFinancialPdf']);
        Route::get('/reports/warehouse-daily', [\App\Http\Controllers\ReportController::class, 'warehouseDaily']);
        Route::get('/reports/branch-daily', [\App\Http\Controllers\ReportController::class, 'branchDaily']);
        Route::get('/reports/diffs', [\App\Http\Controllers\ReportController::class, 'diffs']);
        Route::get('/reports/diffs/export', [\App\Http\Controllers\ReportController::class, 'exportDiffs']);
    });

    // ── Settings (الإعدادات) ──────────────────────────────
    Route::middleware('permission:settings')->group(function () {
        Route::get('/settings/logo', [\App\Http\Controllers\SettingsController::class, 'getLogo']);
        Route::post('/settings/logo', [\App\Http\Controllers\SettingsController::class, 'logo']);
        Route::delete('/settings/logo', [\App\Http\Controllers\SettingsController::class, 'deleteLogo']);
        Route::get('/settings/backup', [\App\Http\Controllers\BackupSettingsController::class, 'show']);
        Route::put('/settings/backup', [\App\Http\Controllers\BackupSettingsController::class, 'update']);
        Route::post('/settings/backup/run', [\App\Http\Controllers\BackupSettingsController::class, 'run']);
    });

    // جرد من إكسيل
    Route::middleware('permission:items')->group(function () {
        Route::post('/inventory/parse', [\App\Http\Controllers\InventoryUploadController::class, 'parse']);
        Route::post('/inventory/confirm', [\App\Http\Controllers\InventoryUploadController::class, 'confirm']);
    });

    // ── Production Module ──────────────────────────────
    Route::prefix('production')->middleware('permission:production')->group(function () {
        Route::apiResource('recipes', \App\Http\Controllers\Production\RecipeController::class);
        Route::post('recipes/sync-costs', [\App\Http\Controllers\Production\RecipeController::class, 'syncCosts']);
        Route::get('daily', [\App\Http\Controllers\Production\DailyProductionController::class, 'index']);
        Route::post('daily', [\App\Http\Controllers\Production\DailyProductionController::class, 'store']);
        Route::get('deductions', [\App\Http\Controllers\Production\DailyProductionController::class, 'deductions']);
        Route::post('deductions/toggle', [\App\Http\Controllers\Production\DailyProductionController::class, 'toggleDeduction']);
        Route::get('post-preview', [\App\Http\Controllers\Production\ProductionPostController::class, 'preview']);
        Route::post('post', [\App\Http\Controllers\Production\ProductionPostController::class, 'post']);
        Route::get('market-prices/scrape', [\App\Http\Controllers\Production\MarketPriceController::class, 'scrape']);
        Route::get('market-prices', [\App\Http\Controllers\Production\MarketPriceController::class, 'index']);
        Route::get('market-prices/items', [\App\Http\Controllers\Production\MarketPriceController::class, 'items']);
        Route::post('market-prices/items', [\App\Http\Controllers\Production\MarketPriceController::class, 'addItem']);
        Route::delete('market-prices/items/{id}', [\App\Http\Controllers\Production\MarketPriceController::class, 'removeItem']);
        Route::post('market-prices', [\App\Http\Controllers\Production\MarketPriceController::class, 'updatePrices']);
        Route::get('market-prices/latest', [\App\Http\Controllers\Production\MarketPriceController::class, 'latest']);
        Route::apiResource('slaughter', \App\Http\Controllers\Production\SlaughterController::class)->except(['edit', 'create']);
        Route::post('slaughter/{slaughter}/post', [\App\Http\Controllers\Production\SlaughterController::class, 'postToProduction']);
        Route::get('processing/summary/export', [\App\Http\Controllers\Production\ProcessingBatchController::class, 'exportSummary']);
        Route::get('processing/summary', [\App\Http\Controllers\Production\ProcessingBatchController::class, 'summary']);
        Route::post('processing/summary/post-to-daily', [\App\Http\Controllers\Production\ProcessingBatchController::class, 'postToDaily']);
        Route::post('processing/summary/sync-item-cost', [\App\Http\Controllers\Production\ProcessingBatchController::class, 'syncSummaryItemCost']);
        Route::post('processing/delete-month', [\App\Http\Controllers\Production\ProcessingBatchController::class, 'deleteByMonth']);
        Route::apiResource('processing', \App\Http\Controllers\Production\ProcessingBatchController::class)->except(['edit', 'create']);
        Route::post('processing/{processing}/sync-costs', [\App\Http\Controllers\Production\ProcessingBatchController::class, 'syncOutputC
osts']);
    });

    // ── Menu Engineering Module ──────────────────────────
    Route::prefix('menu-engineering')->middleware('permission:menu-engineering')->group(function () {
        Route::apiResource('menus', \App\Http\Controllers\MenuEngineering\MenuEngineeringMenuController::class);
        Route::get('/menus/{menu}/export-excel', [\App\Http\Controllers\MenuEngineering\MenuExportController::class, 'exportMenuExcel']);
        Route::get('/menus/{menu}/export-pdf', [\App\Http\Controllers\MenuEngineering\MenuExportController::class, 'exportMenuPdf']);
        Route::post('/menus/{menu}/copy', [\App\Http\Controllers\MenuEngineering\MenuEngineeringMenuController::class, 'copy']);
        Route::apiResource('categories', \App\Http\Controllers\MenuEngineering\MenuCategoryController::class);
        Route::post('/categories/{category}/copy', [\App\Http\Controllers\MenuEngineering\MenuCategoryController::class, 'copy']);
        Route::get('/ingredients', [\App\Http\Controllers\MenuEngineering\MenuIngredientController::class, 'index']);
        Route::get('/unit-conversions', [\App\Http\Controllers\MenuEngineering\MenuUnitConversionController::class, 'index']);
        Route::post('/recipes/bulk-update-item-quantity', [\App\Http\Controllers\MenuEngineering\MenuRecipeController::class, 'bulkUpdateItemQuantity']);
        Route::apiResource('recipes', \App\Http\Controllers\MenuEngineering\MenuRecipeController::class);
        Route::post('/recipes/bulk-copy', [\App\Http\Controllers\MenuEngineering\MenuRecipeController::class, 'bulkCopy']);
        Route::post('/recipes/bulk-move-category', [\App\Http\Controllers\MenuEngineering\MenuRecipeController::class, 'bulkMoveCategory']);
        Route::post('/recipes/bulk-add-item', [\App\Http\Controllers\MenuEngineering\MenuRecipeController::class, 'bulkAddItem']);
        Route::post('/recipes/bulk-replace-item', [\App\Http\Controllers\MenuEngineering\MenuRecipeController::class, 'bulkReplaceItem']);
        Route::post('/recipes/bulk-delete-item', [\App\Http\Controllers\MenuEngineering\MenuRecipeController::class, 'bulkDeleteItem']);
        Route::post('/recipes/{recipe}/sync-items', [\App\Http\Controllers\MenuEngineering\MenuRecipeController::class, 'syncItems']);
        Route::post('/recipes/{recipe}/copy', [\App\Http\Controllers\MenuEngineering\MenuRecipeController::class, 'copy']);
        Route::get('/recipes/{recipe}/versions', [\App\Http\Controllers\MenuEngineering\MenuRecipeController::class, 'versions']);
        Route::post('/recipes/{recipe}/versions', [\App\Http\Controllers\MenuEngineering\MenuRecipeController::class, 'createVersion']);
        Route::get('/report/summary', [\App\Http\Controllers\MenuEngineering\MenuReportController::class, 'summary']);
        Route::get('/report/summary/export-excel', [\App\Http\Controllers\MenuEngineering\MenuReportController::class, 'exportExcel']);
        Route::get('/report/summary/export-pdf', [\App\Http\Controllers\MenuEngineering\MenuReportController::class, 'exportPdf']);
        Route::get('/sales', [\App\Http\Controllers\MenuEngineering\MenuReconciliationController::class, 'indexSales']);
        Route::post('/sales', [\App\Http\Controllers\MenuEngineering\MenuReconciliationController::class, 'storeSale']);
        Route::post('/reconcile', [\App\Http\Controllers\MenuEngineering\MenuReconciliationController::class, 'detailedReconcile']);
        Route::post('/reconcile/detailed', [\App\Http\Controllers\MenuEngineering\MenuReconciliationController::class, 'detailedReconcile']);

        // Smart Analytics
        Route::prefix('analytics')->group(function () {
            Route::get('/inventory-alerts', [\App\Http\Controllers\MenuEngineering\SmartAnalyticsController::class, 'inventoryAlerts']);
            Route::get('/top-purchases', [\App\Http\Controllers\MenuEngineering\SmartAnalyticsController::class, 'topPurchases']);
            Route::get('/price-changes', [\App\Http\Controllers\MenuEngineering\SmartAnalyticsController::class, 'priceChanges']);
            Route::get('/cost-impact', [\App\Http\Controllers\MenuEngineering\SmartAnalyticsController::class, 'costImpact']);
            Route::get('/cost-contribution', [\App\Http\Controllers\MenuEngineering\SmartAnalyticsController::class, 'costContribution']);
            Route::get('/stock-value', [\App\Http\Controllers\MenuEngineering\SmartAnalyticsController::class, 'stockValue']);
        });
    });

    // ── Financial Module ──────────────────────────────────
    Route::prefix('financial')->middleware('permission:financial.daily')->group(function () {
        Route::get('/categories', [\App\Http\Controllers\Financial\DailyEntryController::class, 'categories']);
        Route::post('/categories', [\App\Http\Controllers\Financial\DailyEntryController::class, 'storeCategory']);
        Route::put('/categories/reorder', [\App\Http\Controllers\Financial\DailyEntryController::class, 'reorderCategories']);
        Route::put('/categories/{id}', [\App\Http\Controllers\Financial\DailyEntryController::class, 'updateCategory']);
        Route::delete('/categories/{id}', [\App\Http\Controllers\Financial\DailyEntryController::class, 'destroyCategory']);
        Route::get('/daily-entries', [\App\Http\Controllers\Financial\DailyEntryController::class, 'index']);
        Route::post('/daily-entries', [\App\Http\Controllers\Financial\DailyEntryController::class, 'store']);
        Route::get('/daily-entries/export/excel', [\App\Http\Controllers\Financial\DailyEntryController::class, 'exportExcel']);
        Route::get('/daily-entries/export/single-day', [\App\Http\Controllers\Financial\DailyEntryController::class, 'exportSingleDay']);
        Route::get('/daily-entries/export/warehouse-incoming', [\App\Http\Controllers\Financial\DailyEntryController::class, 'exportWarehouseIncoming']);
        Route::get('/daily-entries/items', [\App\Http\Controllers\Financial\DailyEntryController::class, 'items']);
        Route::get('/daily-entries/{id}', [\App\Http\Controllers\Financial\DailyEntryController::class, 'show']);
        Route::put('/daily-entries/{id}', [\App\Http\Controllers\Financial\DailyEntryController::class, 'update']);
        Route::delete('/daily-entries/{id}', [\App\Http\Controllers\Financial\DailyEntryController::class, 'destroy']);
    });

    Route::prefix('financial')->middleware('permission:financial.monthly')->group(function () {
        Route::get('/monthly-summaries', [\App\Http\Controllers\Financial\MonthlySummaryController::class, 'index']);
        Route::post('/monthly-summaries/generate', [\App\Http\Controllers\Financial\MonthlySummaryController::class, 'generate']);
        Route::post('/monthly-summaries/{id}/finalize', [\App\Http\Controllers\Financial\MonthlySummaryController::class, 'finalize']);
    });

    Route::prefix('financial')->middleware('permission:financial.closing')->group(function () {
        Route::get('/closing-reports', [\App\Http\Controllers\Financial\ClosingReportController::class, 'index']);
        Route::post('/closing-reports/generate', [\App\Http\Controllers\Financial\ClosingReportController::class, 'generate']);
        Route::get('/closing-reports/{id}', [\App\Http\Controllers\Financial\ClosingReportController::class, 'show']);
        Route::get('/closing-reports/{id}/export-excel', [\App\Http\Controllers\Financial\ClosingReportController::class, 'exportExcel']);
        Route::get('/closing-reports/{id}/export-pdf', [\App\Http\Controllers\Financial\ClosingReportController::class, 'exportPdf']);
        Route::post('/closing-reports/{id}/approve', [\App\Http\Controllers\Financial\ClosingReportController::class, 'approve']);
        Route::post('/closing-reports/{id}/close', [\App\Http\Controllers\Financial\ClosingReportController::class, 'close']);
        Route::post('/closing-reports/{id}/reopen', [\App\Http\Controllers\Financial\ClosingReportController::class, 'reopen']);
        Route::post('/closing-reports/{id}/details', [\App\Http\Controllers\Financial\ClosingReportController::class, 'addDetail']);
        Route::delete('/closing-reports/details/{detailId}', [\App\Http\Controllers\Financial\ClosingReportController::class, 'deleteDetail']);
        Route::put('/closing-reports/details/{detailId}/formula', [\App\Http\Controllers\Financial\ClosingReportController::class, 'updateFormula']);
        Route::put('/closing-reports/details/{detailId}', [\App\Http\Controllers\Financial\ClosingReportController::class, 'updateDetail']);
        Route::post('/closing-reports/details/{detailId}/items', [\App\Http\Controllers\Financial\ClosingReportController::class, 'addDetailItem']);
        Route::delete('/closing-reports/details/items/{itemId}', [\App\Http\Controllers\Financial\ClosingReportController::class, 'deleteDetailItem']);
        Route::get('/closing-reports/details/{detailId}/entries', [\App\Http\Controllers\Financial\ClosingReportController::class, 'getDetailEntries']);
    });

    Route::prefix('financial')->middleware('permission:financial.advances')->group(function () {
        Route::get('/employees', [\App\Http\Controllers\Financial\AdvanceController::class, 'employees']);
        Route::post('/employees', [\App\Http\Controllers\Financial\AdvanceController::class, 'storeEmployee']);
        Route::put('/employees/{id}', [\App\Http\Controllers\Financial\AdvanceController::class, 'updateEmployee']);
        Route::get('/advances/export/excel', [\App\Http\Controllers\Financial\AdvanceController::class, 'exportExcel']);
        Route::get('/advances', [\App\Http\Controllers\Financial\AdvanceController::class, 'index']);
        Route::post('/advances', [\App\Http\Controllers\Financial\AdvanceController::class, 'store']);
        Route::delete('/advances/{id}', [\App\Http\Controllers\Financial\AdvanceController::class, 'destroy']);
    });

    // ── Payroll Module ──────────────────────────────────
    Route::prefix('payroll')->middleware('permission:payroll.manage')->group(function () {
        // Employees
        Route::get('/employees', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'index']);
        Route::post('/employees', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'store']);
        Route::put('/employees/{id}', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'update']);
        Route::delete('/employees/{id}', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'destroy']);
        // Attendance
        Route::get('/attendance', [\App\Http\Controllers\Payroll\AttendanceController::class, 'index']);
        Route::post('/attendance', [\App\Http\Controllers\Payroll\AttendanceController::class, 'store']);
        Route::delete('/attendance/{id}', [\App\Http\Controllers\Payroll\AttendanceController::class, 'destroy']);
        Route::get('/attendance/export', [\App\Http\Controllers\Payroll\AttendanceController::class, 'exportExcel']);
        Route::get('/employee-advances', [\App\Http\Controllers\Payroll\AttendanceController::class, 'employeeAdvances']);
        // Monthly payroll
        Route::get('/monthly', [\App\Http\Controllers\Payroll\PayrollMonthlyController::class, 'index']);
        Route::get('/monthly/{id}', [\App\Http\Controllers\Payroll\PayrollMonthlyController::class, 'show']);
        Route::post('/monthly/calculate', [\App\Http\Controllers\Payroll\PayrollMonthlyController::class, 'calculate']);
        Route::post('/monthly/{id}/approve', [\App\Http\Controllers\Payroll\PayrollMonthlyController::class, 'approve']);
        Route::post('/monthly/{id}/update-base-days', [\App\Http\Controllers\Payroll\PayrollMonthlyController::class, 'updateBaseDays']);
        Route::delete('/monthly/{id}', [\App\Http\Controllers\Payroll\PayrollMonthlyController::class, 'destroy']);
        Route::post('/monthly/bonus/{detailId}', [\App\Http\Controllers\Payroll\PayrollMonthlyController::class, 'updateBonus']);
        Route::post('/monthly/detail/{detailId}/update-cell', [\App\Http\Controllers\Payroll\PayrollMonthlyController::class, 'updateCell']);
        Route::get('/monthly/{id}/export-excel', [\App\Http\Controllers\Payroll\PayrollMonthlyController::class, 'exportExcel']);
        Route::get('/monthly/{id}/payslip/{employeeId}', [\App\Http\Controllers\Payroll\PayrollMonthlyController::class, 'exportPayslipPdf']);
    });
});
