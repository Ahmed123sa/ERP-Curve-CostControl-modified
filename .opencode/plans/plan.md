# Technical Debt Elimination Plan

> خطة إزالة الديون التقنية الـ 6 — جراحة برمجية بدون تدمير للبيانات أو سير العمل الحالي.

---

## المبدأ الأساسي

**لا تغيير في business logic ولا في SQL queries ولا في transaction boundaries.** كل التغييرات هي:
- **Structural**: استخراج كود من Controller إلى Service (نفس المنطق، ملف مختلف)
- **Additive**: إضافة Tests, Caching, Queue Jobs (كود جديد ما يمسش الموجود)
- **Cleanup**: نقل/حذف ملفات مهملة (مالهاش علاقة بالداتا)

---

## القرارات المتفق عليها

| البند | التأثير على البيانات | نوع التغيير |
|-------|----------------------|-------------|
| Queue | **zero** — نفس الـ function, نفس الـ calculations | Additive |
| Caching | **zero** — read-only optimization | Additive |
| VoucherController | **zero** — نفس الـ SQL queries, نفس الـ transactions | Structural |
| Tests | **zero** — SQLite in-memory | Additive |
| Scratch Files | **zero** — filesystem فقط | Cleanup |
| ERP/backend/ | **zero** — filesystem فقط | Cleanup |

---

## P0 — فوري (أداء)

### 1. Queue Infrastructure

**الهدف:** نقل العمليات الثقيلة (Monthly Closing, Closing Report) للخلفية.

**الملفات الجديدة:**
- `app/Jobs/ProcessMonthlyClosing.php`
- `app/Jobs/GenerateFinancialClosingReport.php`

**التعديلات:**
- `app/Http/Controllers/ClosingController.php` — dispatch job بدل استدعاء مباشر
- `app/Http/Controllers/Financial/ClosingReportController.php` — dispatch job
- `.env`: `QUEUE_CONNECTION=database`

**التنفيذ:**
```bash
php artisan make:job ProcessMonthlyClosing
php artisan make:job GenerateFinancialClosingReport
php artisan queue:table
php artisan migrate
```

**الكود:**
```php
// Jobs/ProcessMonthlyClosing.php
class ProcessMonthlyClosing implements ShouldQueue
{
    public function __construct(
        private string $clientId,
        private string $warehouseId,
        private string $month
    ) {}

    public function handle(CostCalculationService $calc): void
    {
        $calc->generateMonthlyClosing($this->clientId, $this->warehouseId, $this->month);
    }
}
```

**الخطر:** المستخدم مش هياخد response فوري بالنتيجة — هياخد "جاري إنشاء التقفيل". لو عايز notification، دي P3.

---

### 2. Caching Layer

**الهدف:** تقليل ضرب الداتابيز للqueries المتكررة.

**التعديلات:**
- `app/Services/CostCalculationService.php` — `weightedAverageCost()`, `currentStock()`
- `app/Services/Financial/ClosingReportService.php` — `list()`

**الكود:**
```php
// في CostCalculationService
public function weightedAverageCost(...): float
{
    $cacheKey = "wac:{$clientId}:{$warehouseId}:{$itemId}:{$asOfDate ?? 'now'}";
    return Cache::remember($cacheKey, 3600, function () use (...) {
        // نفس الكود الموجود بالظبط
        $query = StockLedger::where(...);
        ...
    });
}
```

**TTLs:**
| الدالة | TTL | السبب |
|--------|-----|-------|
| `weightedAverageCost()` | 3600s (1h) | السعر المتوسط نادراً ما يتغير |
| `currentStock()` | 300s (5min) | المخزون بيتحرك كتير |
| `ClosingReportService::list()` | 300s (5min) | تقارير |
| Dashboard KPIs | 600s (10min) | لو موجودة |

**ملاحظة:** أول إصدار من غير `Cache::forget()` — الاعتماد على expiry بس.

---

## P1 — تنظيف (أمن)

### 3. Scratch Files → `_scratch/`

نقل 9 ملفات مهملة إلى `_scratch/`:

```
ERP/laravel-app/analyze_xls.php        →  _scratch/
ERP/laravel-app/analyze_detail.php     →  _scratch/
ERP/laravel-app/analyze_density.php    →  _scratch/
ERP/laravel-app/check_financial_perms.php → _scratch/
ERP/laravel-app/debug_test.php         →  _scratch/
ERP/laravel-app/fix_locations.php      →  _scratch/
ERP/laravel-app/run_test.php           →  _scratch/
ERP/laravel-app/tmp_check_dispatch.php →  _scratch/   (ملاحظة: الملف في project root مش داخل laravel-app)
```

### 4. حذف `ERP/backend/`

الـ directory ده dead — `vendor/` بس من غير autoload.

---

## P2 — جراحة (Refactoring + Testing)

### 5. Test Infrastructure

**الملفات الجديدة:**
- `tests/Unit/Services/StockLedgerServiceTest.php`
- `tests/Unit/Services/CostCalculationServiceTest.php`
- `tests/Feature/Voucher/VoucherUploadConfirmTest.php`

**Factories جديدة:**
- `database/factories/ItemFactory.php`
- `database/factories/WarehouseFactory.php`

**أول Test:**
```php
class StockLedgerServiceTest extends TestCase
{
    public function test_post_creates_ledger_entry(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $service = app(StockLedgerService::class);

        $ledger = $service->post(
            clientId: $item->client_id,
            whId: $warehouse->id,
            itemId: $item->id,
            date: '2026-01-15',
            movementType: 'in',
            qty: 100,
            totalCost: 5000,
            unitCost: 50,
            refType: 'voucher',
            refId: 'test-123',
            voucherType: 'purchase'
        );

        $this->assertDatabaseHas('stock_ledger', [
            'id' => $ledger->id,
            'item_id' => $item->id,
            'qty' => 100,
            'total_cost' => 5000,
        ]);
    }
}
```

### 6. VoucherController Refactoring

**الملف الجديد:**
- `app/Services/VoucherProcessingService.php`

**الخطوات:**
1. تحديد 3 blocks مكررة في `confirm()`, `manual()`, `update()`:
   - **Line validation**: `if ($qty >= 0 && $qty < 0.001) { skip }`
   - **Cost calculation**: اختيار `market_price` vs `unit_cost` vs `last_avg_cost`
   - **Ledger posting**: `$this->ledger->post()` + DispatchOrder

2. استخراج `VoucherProcessingService`:
   ```php
   class VoucherProcessingService
   {
       public function processVoucherLines(
           array $lines, string $clientId, string $userId,
           string $warehouseId, string $orderDate,
           string $voucherType, string $locationRaw
       ): array { /* Logic منقول */ }

       public function reverseAndRepost(
           string $orderId, array $newLines
       ): array { /* Logic من update() */ }
   }
   ```

3. Controller يبقى طبقة رقيقة:
   ```php
   public function confirm(VoucherConfirmRequest $request): JsonResponse
   {
       return $this->processingService->confirmVouchers(
           $request->vouchers, $request->user()
       );
   }
   ```

---

## الجدول الزمني

| Phase | Items | الوقت المقدر |
|-------|-------|-------------|
| **Phase 1** | Queue + Cache | ~3-4 ساعات |
| **Phase 2** | Scratch Files + ERP/backend/ | ~15 دقيقة |
| **Phase 3** | Tests + VoucherController | ~8-10 ساعات |

---

## ملفات الـ Git

### جديدة (لم تخلق بعد)
```
app/Jobs/ProcessMonthlyClosing.php
app/Jobs/GenerateFinancialClosingReport.php
database/migrations/xxxx_xx_xx_xxxxxx_create_jobs_table.php
app/Services/VoucherProcessingService.php
tests/Unit/Services/StockLedgerServiceTest.php
tests/Unit/Services/CostCalculationServiceTest.php
tests/Feature/Voucher/VoucherUploadConfirmTest.php
database/factories/ItemFactory.php
database/factories/WarehouseFactory.php
```

### معدلة
```
app/Services/CostCalculationService.php          ← Cache
app/Services/Financial/ClosingReportService.php   ← Cache
app/Http/Controllers/ClosingController.php        ← Queue dispatch
app/Http/Controllers/Financial/ClosingReportController.php ← Queue dispatch
.env                                              ← QUEUE_CONNECTION
app/Http/Controllers/VoucherController.php        ← استخراج Service
```
