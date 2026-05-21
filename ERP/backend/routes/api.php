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

    // ── Clients (admin only) ──────────────────────────────
    Route::apiResource('clients', ClientController::class);

    // ── Items (الأصناف) ───────────────────────────────────
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
    Route::delete('/vouchers/{order}', [VoucherController::class, 'destroy']); // حذف (بيعكس الـ ledger)

    // ── Stock ─────────────────────────────────────────────
    Route::get('/stock/current', [StockController::class, 'current']);            // رصيد حالي
    Route::get('/stock/movement', [StockController::class, 'movement']);          // حركة صنف
    Route::get('/stock/warehouse-summary', [StockController::class, 'warehouseSummary']); // ملخص مخزن

    // ── Inventory (رفع جرد) ──────────────────────────────
    Route::post('/inventory/parse', [VoucherController::class, 'parseInventory']);   // تحليل ملف الجرد
    Route::post('/inventory/confirm', [VoucherController::class, 'confirmInventory']); // تأكيد وحفظ الجرد

    // ── Closing (التقفيل الشهري) ──────────────────────────
    Route::get('/closing', [ClosingController::class, 'index']);                   // عرض التقفيل
    Route::post('/closing/generate', [ClosingController::class, 'generate']);      // توليد التقفيل تلقائياً
    Route::patch('/closing/{closing}/actual', [ClosingController::class, 'updateActual']); // تسجيل جرد فعلي
    Route::post('/closing/lock', [ClosingController::class, 'lock']);              // إقفال الشهر
    Route::get('/closing/export', [ClosingController::class, 'export']);           // تصدير Excel

    // ── Mappings (إدارة ربط الأسماء) ─────────────────────
    Route::get('/mappings', [MappingController::class, 'index']);
    Route::post('/mappings/item', [MappingController::class, 'updateItem']);
    Route::post('/mappings/location', [MappingController::class, 'updateLocation']);
    Route::delete('/mappings/item/{id}', [MappingController::class, 'deleteItem']);
});
