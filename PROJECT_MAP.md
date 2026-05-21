# PROJECT_MAP.md — ERP_CostControl

## Overview
نظام ERP لكوست كنترول المطاعم — إدارة المخزون، المشتريات، الصرف، التقفيل الشهري، التقارير المالية.
Multi-tenant (client_id isolation) مع Laravel 11 API + Next.js 14 Frontend.

---

## Architecture

### Main Application: `ERP/laravel-app/` (Laravel 11 API)
**Source of truth.** All business logic lives here.

### Stale/Duplicate: `ERP/backend/`
Older copy. Do NOT modify. Changes go into `ERP/laravel-app/`.

### Frontend: `ERP/frontend/` (Next.js 14 + React)

---

## Database Schema (Key Tables)
| Table | Purpose |
|---|---|
| `clients` | Multi-tenant companies |
| `items` | Raw materials (الخامات/الأصناف) with `default_cost`, `default_warehouse_id` |
| `warehouses` | Main/sub warehouses and branches (type: main/sub/branch) |
| `branches` | Restaurant branches (linked to warehouses via `branch_warehouse_sources`) |
| `dispatch_orders` | All vouchers: purchase, dispatch, transfer, withdrawal, production, opening, final |
| `dispatch_lines` | Line items per voucher |
| `stock_ledger` | **Heart of the system** — every stock movement tracked here |
| `monthly_closings` | Monthly closing records per item+warehouse |
| `item_mappings` | Auto-mapping memory for Excel import names → items |
| `location_mappings` | Auto-mapping memory for location names → warehouses/branches |

---

## Key Services (in `laravel-app/app/Services/`)

### `StockLedgerService.php`
- `post()` — Record a ledger entry (in/out/transfer)
- `postTransfer()` — Two ledger entries for inter-warehouse transfer
- `reverseOrder()` — Delete all ledger entries for an order (on delete)
- `balance()` — Get stock balance for item+warehouse at a date

### `CostCalculationService.php`
- **`currentStock()`** — ⚠️ BUG: sums ALL qty regardless of movement_type direction
- `weightedAverageCost()` — Weighted avg = (opening value + incoming value) / (opening qty + incoming qty)
- `currentStockValue()` — qty × avg_cost
- `itemMonthSummary()` — Full monthly summary with purchases, internal transfers, consumption
- `generateMonthlyClosing()` — Generate monthly_closings records

### `VoucherParserService.php`
- Parse Excel files into voucher structures
- Auto-detect voucher type from location name
- `detectVoucherType()` — purchase/dispatch/withdrawal/production/opening/etc

### `MappingService.php`
- Auto-map source names → items via memory, exact match, fuzzy match
- `findLocation()` — ⚠️ BUG: checks `$loc->type === 'branch'` on Warehouse (no branch type in Warehouse schema)

---

## Key Controllers (in `laravel-app/app/Http/Controllers/`)

### `VoucherController.php`
- `upload()` — Parse Excel, map items/locations, return preview
- `confirm()` — Save vouchers + ledger entries + **update item default_cost on purchase** + save mappings
- `manual()` — Manual grid entry for all voucher types + price update
- `resolveWarehouseId()` — Resolve source warehouse for dispatch order
- `resolveBranchTargetWhId()` — Map branch UUID → warehouse UUID for stock_ledger

### `ClosingController.php`
- `index()` — Get closing data for one warehouse
- `allWarehouses()` — All warehouses in one view
- `generate()` — Generate monthly closing
- `updateActual()` / `bulkUpdateActual()` — Update actual inventory counts
- `syncPhysicalToActual()` — Sync physical count → actual count
- `lock()` — Lock the month

### `StockController.php`
- `current()` — Current stock quantities (calls `currentStock()` + `weightedAverageCost()`)
- `movement()` — Item movement history
- `opening()` — Get existing opening balances for pre-fill
- `warehouseSummary()` — Total stock value for warehouse

### `ReportController.php`
- `grandSummary()` — Matrix report (all items × all locations)
- `branchPerformance()` — Branch-level performance report
- `financialDetails()` / `exportFinancialDetails()` — Financial values report (المستلم الفعلي)

### `DashboardController.php`
- KPIs, top diffs

---

## Frontend Pages (`ERP/frontend/src/app/(app)/`)

| Route | Component | Purpose |
|---|---|---|
| `/dashboard` | `dashboard/page.tsx` | KPIs + top diffs |
| `/vouchers/purchase` | `VoucherGrid` (type=purchase) | Manual purchase entry |
| `/vouchers/dispatch` | `VoucherGrid` (type=dispatch) | Manual dispatch entry |
| `/vouchers/upload` | `VoucherUpload` | Excel upload + preview + confirm |
| `/vouchers/history` | (list) | Voucher history |
| `/closing` | `ClosingPage` | Monthly closing matrix + detail |
| `/stock` | `StockPage` | Current stock **⚠️ calls api.get('/stock') but API route is /stock/current** |
| `/stock/movement` | Movement tracking | Item movement history |
| `/stock/opening` | `OpeningBalancePage` | Opening balances entry |
| `/stock/closing` | `ClosingBalancePage` | Final inventory (physical count) |
| `/reports/financial-details` | Financial details | Financial values report |
| `/reports/grand-summary` | Grand summary | Matrix report |

---

## Data Flow: Price Integration

1. **Purchase Voucher Entry** (manual or Excel):
   - User enters: item | qty | total_cost
   - System calculates: unit_cost = total_cost / qty
   - `StockLedgerService::post()` records `in` entry with unit_cost & total_cost
   - `Item::default_cost` updated with new unit_cost (line 216-219 `VoucherController::confirm()`)
   - **⚠️ No logging of price changes**

2. **Weighted Average Cost**:
   - `CostCalculationService::weightedAverageCost()` = total_value / total_qty of all `in`/`transfer_in` entries

3. **Monthly Closing**:
   - Uses weighted avg for closing_value, diff_value calculations
   - Opening qty/value carried from previous month(s)

---

## Known Issues (Identified)

### CRITICAL
1. **`StockLedger.php:11`** — `use HasTenant;` duplicated twice
2. **`CostCalculationService.php:70`** — `currentStock()` sums ALL `qty` regardless of `movement_type`. Should be: in/transfer_in = +qty, out/transfer_out = -qty
3. **`frontend/src/app/(app)/stock/page.tsx:17`** — calls `api.get('/stock', ...)` but API route is `GET /api/stock/current`

### MEDIUM
4. **`MappingService.php:144`** — `findLocation()` checks `$loc->type === 'branch'` on Warehouse model, but Warehouse type enum is only `main`/`sub`. Branches are in separate `branches` table.
5. **`VoucherController.php:216-219`** — Price update on purchase has no logging
6. **`CostCalculationService.php:148-153`** — Fallback avg_cost logic may incorrectly use `default_cost` when it shouldn't

### LOW
7. **`VoucherController::upload()`** — Warehouse suggestion logic for location types is incomplete
8. **`VoucherController::manual()`** — Opening balance replacement uses `like` for date matching (fragile)

---

## Duplicate Applications

- **`ERP/backend/`** — Older/stale copy of Laravel app. NOT in use.
- **`ERP/laravel-app/`** — Current, active application. ALL changes go here.

---

## Surgical Fixes Applied (2026-05-17)

### 1. `StockLedger.php:11` — Duplicate trait `use HasTenant;` removed
- **File**: `ERP/laravel-app/app/Models/StockLedger.php`
- **Root cause**: Two identical `use HasTenant;` lines on same line
- **Fix**: Removed duplicate

### 2. `CostCalculationService::currentStock()` — Wrong stock balance calculation
- **File**: `ERP/laravel-app/app/Services/CostCalculationService.php:56-76`
- **Root cause**: `$query->sum('qty')` summed ALL qty regardless of movement_type direction (in=add, out=subtract). StockLedger stores all qty as positive values with movement_type indicating direction.
- **Fix**: Changed to use `SUM(CASE WHEN movement_type IN ('in','transfer_in') THEN qty ELSE 0 END) - SUM(CASE WHEN movement_type IN ('out','transfer_out') THEN qty ELSE 0 END)` — same pattern as `StockLedgerService::balance()`
- **Impact**: Fixes ALL dependent methods: `currentStockValue()`, `itemMonthSummary()`, `generateMonthlyClosing()`, `StockController::current()`, `StockController::warehouseSummary()`

### 3. Frontend Stock Page — Wrong API endpoint
- **File**: `ERP/frontend/src/app/(app)/stock/page.tsx:17`
- **Root cause**: Called `api.get('/stock', ...)` but API route is `GET /api/stock/current`
- **Fix**: Changed to `api.get('/stock/current', ...)`

### 4. `MappingService::findLocation()` — Dead branch type check on Warehouse
- **File**: `ERP/laravel-app/app/Services/MappingService.php:120-176`
- **Root cause**: Checked `$loc->type === 'branch'` on Warehouse results, but Warehouses only have types `main`/`sub`. Branches are a separate `branches` table.
- **Fix**: Split search into two phases: search Warehouses first (returns type `warehouse`), then search Branches (returns type `branch`). Removed dead `($loc->type === 'branch')` check.

### 5. Price update logging on purchase vouchers
- **File**: `ERP/laravel-app/app/Http/Controllers/VoucherController.php:218-237`
- **Root cause**: When a purchase voucher confirmed, `Item::default_cost` was updated but price changes were never logged
- **Fix**: Added `ActivityLogger::log()` call with `action: 'price_updated'`, recording old cost, new cost, source voucher ID
- Also added `use App\Models\Item;` and `use App\Services\ActivityLogger;` imports

### 6. Warehouse suggestion in Voucher Upload preview
- **File**: `ERP/laravel-app/app/Http/Controllers/VoucherController.php:55-64`
- **Root cause**: Condition `$location['type'] !== 'main'` was wrong — type is either `'warehouse'` or `'branch'`, not `'main'`
- **Fix**: Changed to explicit check: if type is `warehouse`/`main`/`sub` → use location's own id; otherwise (branch) → use main warehouse

### 7. `itemMonthSummary()` avg_cost fallback refinement
- **File**: `ERP/laravel-app/app/Services/CostCalculationService.php:148-153`
- **Root cause**: Fallback condition `$totalQty != 0 || $openingQty != 0` was too broad (always true when there's stock)
- **Fix**: Changed to `$totalQty > 0` — only fallback when there's actual positive stock with zero computed avg_cost

---

## Surgical Fixes Applied (2026-05-17 — Round 2)

### 8. `VoucherController::confirm()` — Wrong movement direction for non-purchase types
- **File**: `ERP/laravel-app/app/Http/Controllers/VoucherController.php:176-200`
- **Root cause**: The `else` branch treated EVERY non-dispatch type as `movement_type = 'in'`. Types `withdrawal`, `external_sale`, and `production` are OUT movements (consumption/removal from warehouse) but were recorded as IN — inflating inbound totals and deflating outbound.
- **Fix**: Added `match()` expression mapping each voucher type to correct movement direction:
  - `purchase`, `opening`, `adjustment`, `return` → `'in'`
  - `withdrawal`, `external_sale`, `production` → `'out'`
  - `transfer` → `postTransfer()` (two-sided entry)
  - `dispatch` → existing two-entry logic preserved

### 9. `VoucherParserService::detectVoucherType()` — Keyword priority inversion
- **File**: `ERP/laravel-app/app/Services/VoucherParserService.php:149-172`
- **Root cause**: Dispatch keywords (`['صرف', 'منصفر', 'فرع', 'تحويل', 'نقل', 'صادر']`) were checked BEFORE purchase keywords (`['وارد', 'مشتريات', 'شراء']`). Any location name containing BOTH dispatch and purchase keywords (e.g., "تحويل وارد") was misclassified as `dispatch` instead of `purchase`.
- **Fix**: Reordered priority: (1) multi-word types first, (2) purchase keywords, (3) other specific types, (4) dispatch keywords last as fallback.

### 10. DB ENUM — Missing `adjustment` and `return` types
- **File**: `ERP/laravel-app/database/migrations/2026_05_17_000002_add_adjustment_return_to_dispatch_orders_type.php` (NEW)
- **Root cause**: `dispatch_orders.type` ENUM only had `purchase, dispatch, transfer, withdrawal, production, external_sale, opening, final`. But `VoucherParserService::detectVoucherType()` can return `adjustment` and `return`, and both `VoucherConfirmRequest` and `VoucherManualRequest` validate these as allowed values. Any voucher of type `adjustment` or `return` passed validation but failed at SQL INSERT with a `QueryException`.
- **Fix**: New migration adds `'adjustment'` and `'return'` to the ENUM for both MySQL and PostgreSQL.

---

## Surgical Fixes Applied (2026-05-17 — Round 3: Purchase Duplicate Review)

### 11. `VoucherController::manual()` — Missing `return` in movement direction mapping
- **File**: `ERP/laravel-app/app/Http/Controllers/VoucherController.php:396`
- **Root cause**: `manual()` mapped only `['purchase', 'opening', 'adjustment']` to `movement_type = 'in'`, missing `'return'`. Meanwhile `confirm()` correctly mapped `'return' => 'in'` via match expression. This caused returns entered manually to record as `'out'` (decreasing stock) while returns uploaded via Excel recorded as `'in'` (increasing stock) — opposite effects depending on entry method.
- **Fix**: Added `'return'` to the `in` array: `['purchase', 'opening', 'adjustment', 'return']`

### 12. `ReportController::buildFinancialData()` — purchases_value reads from monthly_closings instead of stock_ledger
- **File**: `ERP/laravel-app/app/Http/Controllers/ReportController.php:280-293`
- **Root cause**: `buildFinancialData()` read `purchases_value` from `monthly_closings.purchases_value` rather than querying `stock_ledger` directly. The monthly_closings data can become desynced from the actual ledger (due to item skip logic, stale data from before voucher_type column migration, or `updateOrCreate` edge cases with the HasTenant global scope). Meanwhile the Dashboard KPI reads directly from `stock_ledger` and always shows the correct total. This caused the Financial Report to show DOUBLE the correct purchases value (exactly 2×) while the Dashboard showed the accurate number.
- **Fix**: Changed `buildFinancialData()` to query `stock_ledger` directly for `purchases_value`, `in_value`, `internal_in_value`, and `consumption_value` — using the same exact query as the Dashboard KPI (`voucher_type='purchase' AND movement_type='in'` within the month date range). Also added `use App\Models\StockLedger;` import.
- **Impact**: The Financial Report "إجمالي المشتريات" now matches the Dashboard KPI "إجمالي المشتريات" exactly, since both read from the same source (`stock_ledger`). Also fixes `in_value`, `internal_in_value`, `consumption_value` for consistency.

### 13. `CostCalculationService::itemMonthSummary()` — Missing `return` and `adjustment` voucher type categories
- **File**: `ERP/laravel-app/app/Services/CostCalculationService.php:137-154`
- **Root cause**: `itemMonthSummary()` only categorized `purchase`, `dispatch`, `opening`, `consumption`/`external_sale`/`withdrawal` types. `return` and `adjustment` entries existed in `stock_ledger` but were excluded from `in_qty`, `out_qty`, and `in_value` calculations. This caused:
  - `in_value` (total incoming value) to be understated — missing return/adjustment values
  - `out_qty` to be understated — missing return-out/adjustment-out quantities
  - `closing_qty_theoretical` to be incorrect — cascading to all dependent fields
  - The financial report's `المستلم الفعلي` (actual_received = opening + in_value - closing) became inaccurate because `in_value` was missing these entries, making `purchases_value` appear inflated relative to `actual_received`
- **Fix**: Added queries for `return` and `adjustment` (both `in` and `out` movement directions), and included them in `inQty`, `outQty`, and `inValueTotal` aggregations.

### 14. `CostCalculationService::itemMonthSummary()` — Dead `consumption` type reference, missing `production`
- **File**: `ERP/laravel-app/app/Services/CostCalculationService.php:138`
- **Root cause**: `consumptionQty` filtered for `voucher_type IN ('consumption', 'external_sale', 'withdrawal')`. However, `'consumption'` is NOT a valid `dispatch_orders.type` (the ENUM doesn't include it). The actual type is `'production'` (for production/معمل output). So production entries were never counted in `outQty`, understating outbound movements and inflating closing stock.
- **Fix**: Changed filter from `['consumption', 'external_sale', 'withdrawal']` to `['production', 'external_sale', 'withdrawal']`.

### 15. `ReportController::buildFinancialData()` — `inValue` double-counts opening entries
- **File**: `ERP/laravel-app/app/Http/Controllers/ReportController.php:283-288`
- **Root cause**: The `inValue` query (`movement_type IN ('in','transfer_in')` within month) included entries with `voucher_type='opening'`. But `opening_value` is already sourced separately from `monthly_closings`. The formula `actual_received = opening + inValue - closing` was adding opening entries TWICE — once via `opening_value` (from monthly_closings) and once via `inValue` (from stock_ledger). This caused `actual_received` to be ~2× opening for all locations (e.g., main warehouse: opening=698,156, inValue included 698,156 of opening entries, actual_received calculated as 698,156 + 698,156+… - 0 = 1,393,982 = 2× opening).
- **Fix**: Added `->where('voucher_type', '!=', 'opening')` to the `inValue` query, so opening entries are only counted once (via `opening_value` from monthly_closings).
- **Impact**: `actual_received = opening_value + (purchases + internal_in + returns + adjustments) - closing` now correctly avoids double-counting opening balances.

### 16. `VoucherController::manual()` & `VoucherController::confirm()` — Dispatch orders have zero cost + wrong target warehouse_id
- **File**: `ERP/laravel-app/app/Http/Controllers/VoucherController.php:170`, `:359`, `:415`
- **Root cause (cost)**: When creating dispatch orders, `total_cost` defaults to 0 because:
  - Frontend (`VoucherGrid.tsx:144-149`) shows cost input only for `type === 'purchase'` — dispatch has no cost field
  - Excel upload (`confirm()`) may not include a cost column for dispatch rows
  - Backend did not auto-calculate cost → `stock_ledger.total_cost = 0` for both OUT (source warehouse) and IN (branch) entries
  - `internalInValue = SUM(total_cost) WHERE voucher_type='dispatch' AND movement_type IN ('in','transfer_in')` always returned 0
- **Root cause (ID mismatch)**: The dispatch-in `stock_ledger` entry used `branch_id` from the `branches` table directly as `warehouse_id`. But `stock_ledger.warehouse_id` has FK to `warehouses.id`, and `branches.id` may differ from the corresponding `warehouses.id`. Even when FK is not enforced, the report query groups by `warehouse_id` from `monthly_closings` (which references `warehouses.id`), so entries stored with `branches.id` are invisible to the report.
- **Fix (cost)**: For dispatch orders with `cost <= 0`, auto-calculate `cost = qty × item->default_cost` by looking up the `Item` record. Applied in both `confirm()` (line 170) and `manual()` (line 359).
- **Fix (ID mapping)**: Added `resolveBranchTargetWhId(clientId, branchId)` helper (line 294) that maps branch UUID → correct warehouse UUID by: (1) checking if branch_id exists directly in `warehouses` table, (2) finding a warehouse with matching name and `type='branch'`, (3) falling back to branch_id as-is. Applied in both `confirm()` (line 193) and `manual()` (line 452).
- **Impact**: New dispatch orders will have correct `total_cost` and be stored under the correct `warehouse_id` that matches the report query. The financial report's `الوارد الداخلي` for branches will reflect the actual value of dispatched items. Existing dispatch orders are NOT retroactively updated — user must delete and recreate them or run a data cleanup script.

### 17. `VoucherController::destroy()` + `CostCalculationService::generateMonthlyClosing()` — System not dynamic after voucher deletion
- **File**: `ERP/laravel-app/app/Http/Controllers/VoucherController.php:499-517` and `ERP/laravel-app/app/Services/CostCalculationService.php:246-257`
- **Root cause (destroy)**: `destroy()` deleted the `stock_ledger` entries (via `reverseOrder()`) and the order itself, but did NOT regenerate `monthly_closings`. The matrix report and detailed closing report read from `monthly_closings`, which retained stale pre-deletion data. Deleting a voucher and re-checking the report showed the same numbers.
- **Root cause (generateMonthlyClosing)**: When a voucher deletion caused an item's monthly summary to become all-zero (opening_qty=0, in_qty=0, out_qty=0), `generateMonthlyClosing()` executed `continue` (skip) instead of deleting the stale record. The item's old `monthly_closings` row remained in the database indefinitely.
- **Fix (destroy)**: After the deletion transaction, collect all affected warehouse IDs (from lines, order.warehouse_id, and resolved branch warehouse ID), then call `generateMonthlyClosing()` for each — ensuring all recalculations happen immediately after deletion.
- **Fix (generateMonthlyClosing)**: Changed the all-zero check from `continue` (skip) to: delete the existing `MonthlyClosing` record for that item+warehouse+month first, then `continue`. This clears stale data when a previously-active item becomes zero-activity after a deletion.
- **Impact**: The system is now fully dynamic. Deleting any voucher immediately recalculates monthly_closings for all affected warehouses across all items. Stale records are cleaned up. Both the financial report (stock_ledger-based) and matrix/detailed reports (monthly_closings-based) stay in sync.

---

## Menu Engineering Module (2026-05-18)

### Structure
| Layer | Files |
|---|---|
| **Controllers** | `MenuRecipeController` (CRUD+sync+versions), `MenuCategoryController` (CRUD), `MenuIngredientController` (list items), `MenuUnitConversionController` (list convs), `MenuReportController` (summary), `MenuReconciliationController` (sales+reconcile) |
| **Models** | `MenuRecipe`, `MenuRecipeItem`, `MenuRecipeVersion`, `MenuUnitConversion`, `MenuCategory`, `MenuSale` |
| **Services** | `RecipeCostCalculationService` (EP cost, line total, totals), `MenuReconciliationService` (theoretical vs actual) |
| **Frontend** | `page.tsx` (SPA drill-down: Branches→Categories→Items→Sheet), `report/page.tsx` (cost analysis), `report layout` (tabs: وصفات, مكونات, تقارير) |

### Database Tables
| Table | Purpose |
|---|---|
| `menu_engineering_recipes` | Recipes with branch linkage, costing meta (portions, selling_price, target_food_cost_pct) |
| `menu_engineering_recipe_items` | Ingredient lines per recipe (qty, units, costs, weight/volume) |
| `menu_engineering_recipe_versions` | Historical snapshots |
| `menu_engineering_unit_conversions` | Unit conversion factors (kg↔g, liter↔ml, dozen↔each, etc.) |
| `menu_engineering_categories` | Recipe category labels |
| `menu_engineering_sales` *(NEW)* | Sales records per recipe/branch for reconciliation |

### Report Endpoint
- `GET /api/menu-engineering/report/summary?branch_id=xxx` — Aggregates active recipes grouped by category. Returns per-item (name, total_cost, selling_price, cost_pct) + per-category avg_cost + overall cost_pct. Logged via `Log::info`. 

### Reconciliation (Part 2 — Planning Phase)
- `POST /api/menu-engineering/sales` — Record sale of a recipe under a branch.
- `GET /api/menu-engineering/sales?branch_id=xxx` — List sales.
- `POST /api/menu-engineering/reconcile` — Body: `{branch_id, from, to}`. Returns:
  - `total_sales_value` — sum of qty_sold × price
  - `total_theoretical_qty` — sum of all theoretical ingredient usage
  - `rows[]` — per ingredient: `theoretical_qty`, `actual_qty` (sum of out movements from stock_ledger), `variance_qty`, `variance_pct`.
- Theoretical consumption = sale_qty × recipe_item.qty for each ingredient.
- Actual consumption = SUM of `stock_ledger` out movements in date range.
- **Next:** Frontend reconciliation UI (sales grid + variance table).

## Surgical Fixes Applied (2026-05-17 — Round 4: Dispatch Location + Production Post)

### 18. `VoucherController::manual()` — Empty string foreign keys causing '—' in history
- **File**: `ERP/laravel-app/app/Http/Controllers/VoucherController.php:398-412`
- **Root cause**: `DispatchOrder` created with `branch_id = ''` (empty string from frontend default) instead of `null`. The `branch()` BelongsTo relation finds no branch with id `''`, returns `null`. The history page rendering `v.branch?.name ?? v.warehouse?.name ?? '—'` shows `'—'`.
- **Fix**: Normalize empty strings to `null` for both `warehouse_id` and `branch_id` in `manual()`, `confirm()`, and `update()` methods. Added `$request->warehouse_id ?: null` conversion before DB insert.
- **Additional fix**: For dispatch orders in `manual()`, auto-resolve source warehouse from the first item's `default_warehouse_id` when `warehouse_id` is not provided, falling back to the main warehouse. This ensures `DispatchOrder.warehouse_id` is never null for dispatch type. Also falls through to `$line['warehouse_id']` resolution for DispatchLine and StockLedger entries.
- **Impact**: New dispatch orders will always have a `warehouse_id` set (resolved automatically). History page shows the warehouse name instead of `'—'`. Existing orders with empty strings remain broken until edited/recreated.

### 19. `frontend/src/app/(app)/vouchers/history/page.tsx:238` — Location display per voucher type
- **File**: `ERP/frontend/src/app/(app)/vouchers/history/page.tsx:237-239`
- **Root cause**: The location cell used the same logic for all types: `v.branch?.name ?? v.warehouse?.name ?? '—'`. For `dispatch` (إذن صرف فرع), the branch IS the target location. For other types (`purchase`, `production`, `opening`, etc.), the warehouse is the primary location. Branch name being checked first made non-dispatch orders show the wrong name when both were set.
- **Fix**: Type-aware rendering: for `dispatch` type, show `branch?.name ?? warehouse?.name ?? '—'` (target first); for all other types, show `warehouse?.name ?? branch?.name ?? '—'` (warehouse first).
- **Impact**: Location column now shows the correct contextual location per voucher type.

### 20. `RecipeController::store()` — Auto-fill output_warehouse_id from item's default_warehouse_id
- **File**: `ERP/laravel-app/app/Http/Controllers/Production/RecipeController.php:40-49`
- **Root cause**: When creating a recipe without specifying `output_warehouse_id`, the field was stored as `null`. The Item model has `default_warehouse_id` that indicates where each item is stored by default, but the recipe didn't leverage this on creation.
- **Fix**: Before creating the recipe, if `output_warehouse_id` is not provided, look up the output item's `default_warehouse_id` and use that as the recipe's output warehouse.
- **Impact**: New recipes auto-inherit the output item's default warehouse. Existing recipes are not affected.

### 21. `ProductionPostController` — Post date uses current date instead of month end
- **File**: `ERP/laravel-app/app/Http/Controllers/Production/ProductionPostController.php:79`
- **Root cause**: `$postDate = $end->toDateString()` (end of month, e.g., 2026-05-31). When today is before month-end (e.g., May 17), the DispatchOrder gets a future date. The `update()` form on the frontend validates `date <= today`, so editing/updating the posted production order fails with "The date field must be a date before or equal to today."
- **Fix**: Changed to `$postDate = now()->toDateString()` — records the production as happening on the actual posting date.
- **Impact**: Production orders now have today's date, not month-end. The update form works normally.
