<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupSettingsController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClosingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Financial\AdvanceController;
use App\Http\Controllers\Financial\ClosingReportController;
use App\Http\Controllers\Financial\DailyEntryController;
use App\Http\Controllers\Financial\MonthlySummaryController;
use App\Http\Controllers\InventoryUploadController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\MappingController;
use App\Http\Controllers\MenuEngineering\MenuCategoryController;
use App\Http\Controllers\MenuEngineering\MenuEngineeringMenuController;
use App\Http\Controllers\MenuEngineering\MenuExportController;
use App\Http\Controllers\MenuEngineering\MenuIngredientController;
use App\Http\Controllers\MenuEngineering\MenuRecipeController;
use App\Http\Controllers\MenuEngineering\MenuReconciliationController;
use App\Http\Controllers\MenuEngineering\MenuReportController;
use App\Http\Controllers\MenuEngineering\MenuSalesImportController;
use App\Http\Controllers\MenuEngineering\MenuUnitConversionController;
use App\Http\Controllers\MenuEngineering\SmartAnalyticsController;
use App\Http\Controllers\Payroll\AttendanceController;
use App\Http\Controllers\Payroll\PayrollEmployeeController;
use App\Http\Controllers\Payroll\PayrollMonthlyController;
use App\Http\Controllers\Production\DailyProductionController;
use App\Http\Controllers\Production\MarketPriceController;
use App\Http\Controllers\Production\ProcessingBatchController;
use App\Http\Controllers\Production\ProductionPostController;
use App\Http\Controllers\Production\RecipeController;
use App\Http\Controllers\Production\SlaughterController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
        Route::get('/dashboard/diffs-by-warehouse', [DashboardController::class, 'diffsByWarehouse']);
        Route::get('/dashboard/top-diff-items', [DashboardController::class, 'topDiffItems']);
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
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);
        Route::get('/permissions', function () {
            return response()->json(Permission::pluck('name'));
        });
        Route::get('/roles', function () {
            return response()->json(Role::pluck('name'));
        });
    });

    // ── Reports (التقارير) ──────────────────────────────
    Route::middleware('permission:reports.financial')->group(function () {
        Route::get('/reports/grand-summary', [ReportController::class, 'grandSummary']);
        Route::get('/reports/grand-summary/export', [ReportController::class, 'exportMatrix']);
        Route::get('/reports/grand-summary/export-pdf', [ReportController::class, 'exportMatrixPdf']);
        Route::get('/reports/branch-performance', [ReportController::class, 'branchPerformance']);
        Route::get('/reports/financial-details', [ReportController::class, 'financialDetails']);
        Route::get('/reports/financial-details/export', [ReportController::class, 'exportFinancialDetails']);
        Route::get('/reports/financial-details/export-pdf', [ReportController::class, 'exportFinancialPdf']);
        Route::get('/reports/warehouse-daily', [ReportController::class, 'warehouseDaily']);
        Route::get('/reports/branch-daily', [ReportController::class, 'branchDaily']);
        Route::get('/reports/diffs', [ReportController::class, 'diffs']);
        Route::get('/reports/diffs/export', [ReportController::class, 'exportDiffs']);
    });

    // ── Settings (الإعدادات) ──────────────────────────────
    Route::middleware('permission:settings')->group(function () {
        Route::get('/settings/logo', [SettingsController::class, 'getLogo']);
        Route::post('/settings/logo', [SettingsController::class, 'logo']);
        Route::delete('/settings/logo', [SettingsController::class, 'deleteLogo']);
        Route::get('/settings/backup', [BackupSettingsController::class, 'show']);
        Route::put('/settings/backup', [BackupSettingsController::class, 'update']);
        Route::post('/settings/backup/run', [BackupSettingsController::class, 'run']);
    });

    // جرد من إكسيل
    Route::middleware('permission:items')->group(function () {
        Route::post('/inventory/parse', [InventoryUploadController::class, 'parse']);
        Route::post('/inventory/confirm', [InventoryUploadController::class, 'confirm']);
    });

    // ── Production Module ──────────────────────────────
    Route::prefix('production')->middleware('permission:production')->group(function () {
        Route::apiResource('recipes', RecipeController::class);
        Route::post('recipes/sync-costs', [RecipeController::class, 'syncCosts']);
        Route::get('daily', [DailyProductionController::class, 'index']);
        Route::post('daily', [DailyProductionController::class, 'store']);
        Route::get('deductions', [DailyProductionController::class, 'deductions']);
        Route::post('deductions/toggle', [DailyProductionController::class, 'toggleDeduction']);
        Route::get('post-preview', [ProductionPostController::class, 'preview']);
        Route::post('post', [ProductionPostController::class, 'post']);
        Route::get('market-prices/scrape', [MarketPriceController::class, 'scrape']);
        Route::get('market-prices', [MarketPriceController::class, 'index']);
        Route::get('market-prices/items', [MarketPriceController::class, 'items']);
        Route::post('market-prices/items', [MarketPriceController::class, 'addItem']);
        Route::delete('market-prices/items/{id}', [MarketPriceController::class, 'removeItem']);
        Route::post('market-prices', [MarketPriceController::class, 'updatePrices']);
        Route::get('market-prices/latest', [MarketPriceController::class, 'latest']);
        Route::apiResource('slaughter', SlaughterController::class)->except(['edit', 'create']);
        Route::post('slaughter/{slaughter}/post', [SlaughterController::class, 'postToProduction']);
        Route::get('processing/summary/export', [ProcessingBatchController::class, 'exportSummary']);
        Route::get('processing/summary', [ProcessingBatchController::class, 'summary']);
        Route::post('processing/summary/post-to-daily', [ProcessingBatchController::class, 'postToDaily']);
        Route::post('processing/summary/sync-item-cost', [ProcessingBatchController::class, 'syncSummaryItemCost']);
        Route::post('processing/delete-month', [ProcessingBatchController::class, 'deleteByMonth']);
        Route::apiResource('processing', ProcessingBatchController::class)->except(['edit', 'create']);
        Route::post('processing/{processing}/sync-costs', [ProcessingBatchController::class, 'syncOutputC
osts']);
    });

    // ── Menu Engineering Module ──────────────────────────
    Route::prefix('menu-engineering')->middleware('permission:menu-engineering')->group(function () {
        Route::apiResource('menus', MenuEngineeringMenuController::class);
        Route::get('/menus/{menu}/export-excel', [MenuExportController::class, 'exportMenuExcel']);
        Route::get('/menus/{menu}/export-pdf', [MenuExportController::class, 'exportMenuPdf']);
        Route::post('/menus/{menu}/copy', [MenuEngineeringMenuController::class, 'copy']);
        Route::apiResource('categories', MenuCategoryController::class);
        Route::post('/categories/{category}/copy', [MenuCategoryController::class, 'copy']);
        Route::get('/ingredients', [MenuIngredientController::class, 'index']);
        Route::get('/unit-conversions', [MenuUnitConversionController::class, 'index']);
        Route::post('/recipes/bulk-update-item-quantity', [MenuRecipeController::class, 'bulkUpdateItemQuantity']);
        Route::get('/recipes/orphaned-count', [MenuRecipeController::class, 'orphanedCount']);
        Route::apiResource('recipes', MenuRecipeController::class);
        Route::post('/recipes/bulk-copy', [MenuRecipeController::class, 'bulkCopy']);
        Route::post('/recipes/bulk-move-category', [MenuRecipeController::class, 'bulkMoveCategory']);
        Route::post('/recipes/bulk-add-item', [MenuRecipeController::class, 'bulkAddItem']);
        Route::post('/recipes/bulk-replace-item', [MenuRecipeController::class, 'bulkReplaceItem']);
        Route::post('/recipes/bulk-delete-item', [MenuRecipeController::class, 'bulkDeleteItem']);
        Route::post('/recipes/{recipe}/sync-items', [MenuRecipeController::class, 'syncItems']);
        Route::post('/recipes/{recipe}/copy', [MenuRecipeController::class, 'copy']);
        Route::get('/recipes/{recipe}/versions', [MenuRecipeController::class, 'versions']);
        Route::post('/recipes/{recipe}/versions', [MenuRecipeController::class, 'createVersion']);
        Route::get('/report/summary', [MenuReportController::class, 'summary']);
        Route::get('/report/summary/export-excel', [MenuReportController::class, 'exportExcel']);
        Route::get('/report/summary/export-pdf', [MenuReportController::class, 'exportPdf']);
        Route::get('/sales', [MenuReconciliationController::class, 'indexSales']);
        Route::post('/sales', [MenuReconciliationController::class, 'storeSale']);
        Route::post('/upload-sales', [MenuSalesImportController::class, 'upload']);
        Route::post('/upload-sales/preview-columns', [MenuSalesImportController::class, 'previewColumns']);
        Route::post('/upload-sales/process', [MenuSalesImportController::class, 'process']);
        Route::post('/confirm-sales', [MenuSalesImportController::class, 'confirm']);
        Route::get('/upload-sales/session/{session}', [MenuSalesImportController::class, 'getSession']);
        Route::put('/upload-sales/session/{session}/items/{item}', [MenuSalesImportController::class, 'updateSessionItem']);
        Route::post('/confirm-sales-from-session', [MenuSalesImportController::class, 'confirmFromSession']);
        Route::delete('/upload-sales/session/{session}', [MenuSalesImportController::class, 'deleteSession']);
        Route::post('/reconcile', [MenuReconciliationController::class, 'detailedReconcile']);
        Route::post('/reconcile/detailed', [MenuReconciliationController::class, 'detailedReconcile']);
        Route::post('/reconciliations', [MenuReconciliationController::class, 'storeReconciliation']);
        Route::get('/reconciliations', [MenuReconciliationController::class, 'indexReconciliations']);
        Route::get('/reconciliations/{id}', [MenuReconciliationController::class, 'showReconciliation']);
        Route::delete('/reconciliations/{id}', [MenuReconciliationController::class, 'deleteReconciliation']);
        Route::get('/reconciliations/{id}/export', [MenuReconciliationController::class, 'exportReconciliation']);

        // Smart Analytics
        Route::prefix('analytics')->group(function () {
            Route::get('/inventory-alerts', [SmartAnalyticsController::class, 'inventoryAlerts']);
            Route::get('/top-purchases', [SmartAnalyticsController::class, 'topPurchases']);
            Route::get('/price-changes', [SmartAnalyticsController::class, 'priceChanges']);
            Route::get('/cost-impact', [SmartAnalyticsController::class, 'costImpact']);
            Route::get('/cost-contribution', [SmartAnalyticsController::class, 'costContribution']);
            Route::get('/stock-value', [SmartAnalyticsController::class, 'stockValue']);
        });
    });

    // ── Financial Module ──────────────────────────────────
    Route::prefix('financial')->middleware('permission:financial.daily')->group(function () {
        Route::get('/categories', [DailyEntryController::class, 'categories']);
        Route::post('/categories', [DailyEntryController::class, 'storeCategory']);
        Route::put('/categories/reorder', [DailyEntryController::class, 'reorderCategories']);
        Route::put('/categories/{id}', [DailyEntryController::class, 'updateCategory']);
        Route::delete('/categories/{id}', [DailyEntryController::class, 'destroyCategory']);
        Route::get('/daily-entries', [DailyEntryController::class, 'index']);
        Route::post('/daily-entries', [DailyEntryController::class, 'store']);
        Route::get('/daily-entries/export/excel', [DailyEntryController::class, 'exportExcel']);
        Route::get('/daily-entries/export/single-day', [DailyEntryController::class, 'exportSingleDay']);
        Route::get('/daily-entries/export/warehouse-incoming', [DailyEntryController::class, 'exportWarehouseIncoming']);
        Route::get('/daily-entries/items', [DailyEntryController::class, 'items']);
        Route::get('/daily-entries/{id}', [DailyEntryController::class, 'show']);
        Route::put('/daily-entries/{id}', [DailyEntryController::class, 'update']);
        Route::delete('/daily-entries/{id}', [DailyEntryController::class, 'destroy']);
    });

    Route::prefix('financial')->middleware('permission:financial.monthly')->group(function () {
        Route::get('/monthly-summaries', [MonthlySummaryController::class, 'index']);
        Route::post('/monthly-summaries/generate', [MonthlySummaryController::class, 'generate']);
        Route::post('/monthly-summaries/{id}/finalize', [MonthlySummaryController::class, 'finalize']);
    });

    Route::prefix('financial')->middleware('permission:financial.closing')->group(function () {
        Route::get('/closing-reports', [ClosingReportController::class, 'index']);
        Route::post('/closing-reports/generate', [ClosingReportController::class, 'generate']);
        Route::get('/closing-reports/{id}', [ClosingReportController::class, 'show']);
        Route::get('/closing-reports/{id}/export-excel', [ClosingReportController::class, 'exportExcel']);
        Route::get('/closing-reports/{id}/export-pdf', [ClosingReportController::class, 'exportPdf']);
        Route::post('/closing-reports/{id}/approve', [ClosingReportController::class, 'approve']);
        Route::post('/closing-reports/{id}/close', [ClosingReportController::class, 'close']);
        Route::post('/closing-reports/{id}/reopen', [ClosingReportController::class, 'reopen']);
        Route::post('/closing-reports/{id}/details', [ClosingReportController::class, 'addDetail']);
        Route::delete('/closing-reports/details/{detailId}', [ClosingReportController::class, 'deleteDetail']);
        Route::put('/closing-reports/details/{detailId}/formula', [ClosingReportController::class, 'updateFormula']);
        Route::put('/closing-reports/details/{detailId}', [ClosingReportController::class, 'updateDetail']);
        Route::post('/closing-reports/details/{detailId}/items', [ClosingReportController::class, 'addDetailItem']);
        Route::delete('/closing-reports/details/items/{itemId}', [ClosingReportController::class, 'deleteDetailItem']);
        Route::get('/closing-reports/details/{detailId}/entries', [ClosingReportController::class, 'getDetailEntries']);
    });

    Route::prefix('financial')->middleware('permission:financial.advances')->group(function () {
        Route::get('/employees', [AdvanceController::class, 'employees']);
        Route::post('/employees', [AdvanceController::class, 'storeEmployee']);
        Route::put('/employees/{id}', [AdvanceController::class, 'updateEmployee']);
        Route::get('/advances/export/excel', [AdvanceController::class, 'exportExcel']);
        Route::get('/advances', [AdvanceController::class, 'index']);
        Route::post('/advances', [AdvanceController::class, 'store']);
        Route::delete('/advances/{id}', [AdvanceController::class, 'destroy']);
    });

    // ── Payroll Module ──────────────────────────────────
    Route::prefix('payroll')->middleware('permission:payroll.manage')->group(function () {
        // Employees
        Route::get('/employees', [PayrollEmployeeController::class, 'index']);
        Route::post('/employees', [PayrollEmployeeController::class, 'store']);
        Route::put('/employees/{id}', [PayrollEmployeeController::class, 'update']);
        Route::delete('/employees/{id}', [PayrollEmployeeController::class, 'destroy']);
        // Attendance
        Route::get('/attendance', [AttendanceController::class, 'index']);
        Route::post('/attendance', [AttendanceController::class, 'store']);
        Route::delete('/attendance/{id}', [AttendanceController::class, 'destroy']);
        Route::get('/attendance/export', [AttendanceController::class, 'exportExcel']);
        Route::get('/employee-advances', [AttendanceController::class, 'employeeAdvances']);
        // Monthly payroll
        Route::get('/monthly', [PayrollMonthlyController::class, 'index']);
        Route::get('/monthly/{id}', [PayrollMonthlyController::class, 'show']);
        Route::post('/monthly/calculate', [PayrollMonthlyController::class, 'calculate']);
        Route::post('/monthly/{id}/approve', [PayrollMonthlyController::class, 'approve']);
        Route::post('/monthly/{id}/update-base-days', [PayrollMonthlyController::class, 'updateBaseDays']);
        Route::delete('/monthly/{id}', [PayrollMonthlyController::class, 'destroy']);
        Route::post('/monthly/bonus/{detailId}', [PayrollMonthlyController::class, 'updateBonus']);
        Route::post('/monthly/detail/{detailId}/update-cell', [PayrollMonthlyController::class, 'updateCell']);
        Route::get('/monthly/{id}/export-excel', [PayrollMonthlyController::class, 'exportExcel']);
        Route::get('/monthly/{id}/payslip/{employeeId}', [PayrollMonthlyController::class, 'exportPayslipPdf']);
    });
});
