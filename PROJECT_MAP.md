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
- `diffs()` / `exportDiffs()` — Per-warehouse diffs table + Excel export (created 2026-06-05)

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
| `/reports/diffs` | Diffs page | Per-warehouse diffs table + Excel export |

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

## Surgical Changes Applied (2026-05-21 — Branding + RBAC + Backup + Settings)

### 22. Branding: Cost Pro → Curve
- **Files**:
  - `ERP/laravel-app/.env` — `APP_NAME=Laravel` → `APP_NAME=Curve`
  - `ERP/frontend/src/app/layout.tsx` — `Cost Pro — نظام كوست كنترول` → `Curve — نظام إدارة التكاليف`
  - `ERP/frontend/src/app/login/page.tsx` — Branding text + logo placeholder
  - `ERP/frontend/src/components/ui/AppShell.tsx` — Branding text + logo placeholder
  - `ERP/laravel-app/app/Services/ReportExportService.php` — Footer (PDF + Excel) branded with Curve
  - `ERP/README.md` — Updated title
- **Note**: All `Cost Pro` references replaced with `Curve`. Logo loaded dynamically from API.

### 23. Logo Settings (UI Upload)
- **Migration**: `2026_05_21_000000_add_logo_to_clients.php` — added `logo` column (nullable string) to `clients` table
- **Controller**: `app/Http/Controllers/SettingsController.php` — `POST /api/settings/logo`, `GET /api/settings/logo`, `DELETE /api/settings/logo`
- **Storage**: Uploaded to `storage/app/public/logos/` — served via `Storage::url()`
- **Frontend**: `src/app/(app)/settings/page.tsx` — upload + preview + delete logo
- **UX**: AppShell sidebar + login page fetch logo dynamically on mount/client change

### 24. RBAC: Spatie Permissions + Users Management
- **Package**: `spatie/laravel-permission ^7.4` — activated (was previously installed but unused)
- **Migration**: `2026_05_21_150443_create_permission_tables.php` — permissions, roles, model_has_*, role_has_* tables (with varchar(125) for name/guard_name to fit index limits with utf8mb4)
- **Roles**: `super-admin`, `cost-controller`, `viewer` — 23 module permissions seeded
- **Sync**: Existing users auto-assigned Spatie roles based on legacy `users.role` column
- **Middleware**: `app/Http/Middleware/CheckPermission.php` — `permission:{module}` middleware, registered in `bootstrap/app.php` as `permission` alias. Super-admin bypasses all checks.
- **Routes**: All route groups in `api.php` wrapped with `middleware('permission:module')`
- **AuthController**: Login/me response now includes `permissions` array
- **Users API**: `UserController` — CRUD with client assignment + role/permission sync
- **Frontend Users Page**: `src/app/(app)/users/page.tsx` — table + create/edit modal with role + client checkboxes
- **Frontend Sidebar Filtering**: `AppShell.tsx` — NAV items filtered by `user.permissions` using `PERMISSION_MAP`
- **User model**: Added `HasRoles` trait (Spatie)

### 25. Backup System
- **Migration**: `2026_05_21_200000_create_backup_settings_table.php` — `backup_settings` singleton table
- **Command**: `app/Console/Commands/DatabaseBackup.php` — `php artisan backup:run` — mysqldump → gzip → local path + optional email + cleanup old
- **Mail**: `app/Mail/BackupMail.php` — Mailable with gzip attachment
- **Controller**: `BackupSettingsController` — `GET/PUT /api/settings/backup`, `POST /api/settings/backup/run`
- **Frontend**: Backup tab in `/settings` — configure local path, email, retention, Google Drive (placeholder), run button

### 26. Name Correction
- `ERP/backend/database/seeders/DatabaseSeeder.php:29` — `أحمد محمود` → `أحمد علي`
- `ERP/README.md:99` — `أحمد محمود` → `أحمد علي`

### 27. FullSystemSeeder update
- Added `$user->assignRole('super-admin')` after user creation in seeder

--- 

## Files Created (2026-05-21)
- `database/migrations/2026_05_21_000000_add_logo_to_clients.php`
- `database/migrations/2026_05_21_150443_create_permission_tables.php`
- `database/migrations/2026_05_21_200000_create_backup_settings_table.php`
- `database/seeders/PermissionSeeder.php`
- `app/Http/Controllers/SettingsController.php`
- `app/Http/Controllers/BackupSettingsController.php`
- `app/Http/Controllers/UserController.php`
- `app/Http/Middleware/CheckPermission.php`
- `app/Console/Commands/DatabaseBackup.php`
- `app/Mail/BackupMail.php`
- `app/Models/BackupSetting.php`
- `config/permission.php`
- `frontend/src/app/(app)/settings/page.tsx`
- `frontend/src/app/(app)/users/page.tsx`

## Files Modified (2026-05-21)
- `ERP/laravel-app/.env` — APP_NAME
- `ERP/laravel-app/app/Models/User.php` — added HasRoles trait
- `ERP/laravel-app/app/Http/Controllers/AuthController.php` — added permissions to login/me response
- `ERP/laravel-app/app/Services/ReportExportService.php` — Curve branding in exports
- `ERP/laravel-app/bootstrap/app.php` — registered CheckPermission middleware as `permission`
- `ERP/laravel-app/routes/api.php` — permission middleware on all route groups + user + backup routes
- `ERP/laravel-app/database/seeders/FullSystemSeeder.php` — assign super-admin role
- `ERP/frontend/src/app/layout.tsx` — title change
- `ERP/frontend/src/app/login/page.tsx` — Curve + logo
- `ERP/frontend/src/components/ui/AppShell.tsx` — Curve + logo + permission-based nav filtering
- `ERP/frontend/src/lib/store.ts` — added `permissions` to User interface
- `ERP/README.md` — title + name fix
- `ERP/backend/database/seeders/DatabaseSeeder.php` — name fix

---

## Surgical Changes Applied (2026-06-06 — Merge Sales Upload into Reconciliation)

### 28. Remove standalone sales-upload page + tab
- **Files deleted**: `ERP/frontend/src/app/(app)/menu-engineering/sales-upload/page.tsx`
- **Files edited**: `ERP/frontend/src/app/(app)/menu-engineering/layout.tsx`
- **Change**: Removed `{ href: '/menu-engineering/sales-upload', label: 'رفع مبيعات' }` tab from layout. Sales upload is now accessible only via the "رفع مبيعات" button inside the reconciliation page modal.

### 29. Backend fix — Unmatched items key collision (duplicate summing)
- **File**: `ERP/laravel-app/app/Http/Controllers/MenuEngineering/MenuSalesImportController.php` (lines 113, 444)
- **Root cause**: Both `upload()` and `process()` methods used `$name` as the key for `$unmatchedRows[]`. When two different sizes of the same item appeared (e.g., "بطاطس" كبير vs صغير), the second entry overwrote the first instead of being treated as a separate unmatched item.
- **Fix**: Changed key from `$name` to `$name . '|' . $sizeVal` (composite key). Also added `qty_sold` accumulation when same key appears again.
- **Impact**: Items with same name but different sizes now appear as separate rows in the unmatched table. Export keys in frontend changed from `source_name` to composite `source_name|size`.

### 30. Frontend — SearchableSelect + size display in reconciliation modal
- **File**: `ERP/frontend/src/app/(app)/menu-engineering/reconciliation/page.tsx`
- **Changes**:
  1. Replaced native `<select>` with `<SearchableSelect>` for both matched and unmatched recipe linking dropdowns — enables search/filter for large recipe lists.
  2. Display size next to name: `اسم (حجم)` format in both tables; removed separate "الحجم" column from unmatched table.
  3. Changed `uploadOverrides` key from `source_name` to composite `source_name|size` to align with backend fix.
  4. Added `overrideKey()` helper function.
- **Impact**: Professional searchable dropdown, clearer name display, correct handling of same-name-different-size items.

## Client Portal — Phase 1 (2026-06-14)

### Database
1. **New table `client_modules`**: `id` (uuid PK), `client_id` (36), `module_key` (100), `is_active`, timestamps. Unique key on `(client_id, module_key)`.
2. **`clients.primary_color`**: nullable string(7), default `#2563eb`, added after `logo`.

### Models
3. **`ClientModule`** (`app/Models/ClientModule.php`): BelongsTo Client, fillable `[id, client_id, module_key, is_active]`, casts `is_active => boolean`.
4. **`Client.php`**: Added `primary_color` to `$fillable`, added `modules()` HasMany relationship.

### Controller
5. **`ClientModuleController`** (`app/Http/Controllers/ClientModuleController.php`):
   - `index()` — GET `/api/client/modules` → returns current client's active module keys
   - `settings()` — GET `/api/client/settings` → returns `{ id, name, logo, primary_color }`
   - `allModules()` — GET `/api/client/all-modules` → returns all available modules with default flags

### Routes
6. **`api.php`**: Added 3 new routes under `auth:sanctum` (no specific permission — client-wide):
   - `GET /client/modules`
   - `GET /client/settings`
   - `GET /client/all-modules`

### Frontend — Dark/Light Mode
7. **Installed `next-themes`** via npm (with `--legacy-peer-deps` due to date-fns conflict).
8. **`ThemeProvider.tsx`** (`components/ui/`): Wraps `next-themes/ThemeProvider` with `attribute="class"`, `defaultTheme="light"`, `enableSystem`, `disableTransitionOnChange`.
9. **`ThemeToggle.tsx`** (`components/ui/`): Sun/Moon icon button using `useTheme()`, with mount guard to prevent hydration mismatch.
10. **`providers.tsx`**: Added `<ThemeProvider>` wrapper around `<QueryClientProvider>`.
11. **`layout.tsx`**: Added `suppressHydrationWarning` to `<html>`.
12. **`globals.css`**: Removed hardcoded `body { background: #f9fafb }` — dark mode now uses CSS variables correctly.

### Frontend — Module-Aware Sidebar
13. **`AppShell.tsx`**:
    - Added `HREF_MODULE` map (href → module_key) for module-based filtering.
    - Added `hasModule(href)` check to NAV items — items from unsubscribed modules are hidden.
    - Admin-only sections (clients, users, settings, warehouses, branches, payroll, production) bypass module check.
    - Fetches `/api/client/modules` and `/api/client/settings` on client change.
    - Active link color uses `primary_color` from settings (inline style with opacity background).
    - Added `<ThemeToggle>` in sidebar footer next to logout button.
    - Added dark mode Tailwind classes (`dark:bg-gray-900`, `dark:text-gray-200`, etc.) to sidebar, header, and PageHeader.

### Seeding
14. **`ClientModuleSeeder`**: Seeds 13 default modules to all existing clients (MR MIX, Qasr EL3a'elat, ZAHRA).

## Client Portal — Phase 1.5: Client Users & Auth (2026-06-14)

### Database
15. **Migration `add_client_role_to_users`**: Extended `users.role` ENUM to include `'client'` value: `['admin','cost_controller','viewer','client']`. Uses raw `DB::statement` (MySQL ENUM limitation).

### Backend — User Model
16. **`User.php`**: Added `isClient(): bool` and `isInternal(): bool` helper methods.

### Backend — Auth
17. **`AuthController.php`**:
    - `login()` and `me()` responses now include `portal: 'internal' | 'client'` field based on `$user->role`.
    - `switchClient()` now blocks client users with 403 (`العميل لا يمكنه تبديل الشركة`).

### Backend — User Management
18. **`UserController.php`**:
    - `store()` and `update()` now accept `role: 'client'`.
    - Client users require exactly ONE `client_id` (`size:1` validation).
    - Client users are NOT assigned Spatie roles (no super-admin/cost-controller/viewer).
    - Clearing roles when switching from internal to client (`syncRoles([])`).
    - `index()` response includes `portal` field.

### Frontend — Auth Store & Login
19. **`store.ts`**: Added `portal?: 'internal' | 'client'` to User interface, widened `role` union to include `'client'`.
20. **`login/page.tsx`**: After successful login, users with `portal === 'client'` are redirected to `/client/dashboard` instead of `/dashboard` or `/select-client`.

### Frontend — Route Isolation
21. **`(app)/layout.tsx`**: Added guard — client users are redirected to `/client/dashboard` if they land on admin routes.
22. **`(client)/layout.tsx`** (NEW): Route group layout that:
    - Waits for Zustand hydration.
    - Redirects unauthenticated users to `/login`.
    - Redirects non-client users to `/dashboard`.
    - Wraps children with `ClientShell`.
23. **`(client)/dashboard/page.tsx`** (NEW): Full dashboard with 4 KpiCard (purchases, stock, diffs, warehouses) + DiffPieChart + TopDiffItems + TrendChart + Inventory alerts card + Menu snapshot card + Recent activity card. Fetches from 8 `/api/client/dashboard/*` endpoints.
24. **`(client)/stock/page.tsx`** (NEW): Current stock table — dropdown selects warehouse, displays items with qty, avg_cost, total value.
25. **`(client)/stock/movement/page.tsx`** (NEW): Item movement history — search + dropdown pick item, shows ledger entries with +/- qty, unit cost, total.
26. **`(client)/reports/financial-details/page.tsx`** (NEW): Warehouse financial summary table — opening, purchases, diffs per warehouse.
27. **`(client)/reports/diffs/page.tsx`** (NEW): Diffs report — same warehouse summary data with color-coded diff values.
28. **`(client)/reports/cost/page.tsx`** (NEW): Placeholder for cost analysis.

### Backend — Client Endpoints (Phase 2)
29. **`ClientDashboardController.php`** (UPDATED): Added 8 endpoints total:
    - `kpis`, `stockDistribution`, `monthlyTrend`, `trends` (alias), `topDiffItems` — Dashboard core charts
    - `alerts` — Low stock alerts (out of stock, below min, near threshold)
    - `menuSnapshot` — Most/least profitable menu recipes by food cost %
    - `recentActivity` — Last 5 stock ledger entries
    - `warehouses` — Active warehouses for the client
    - `currentStock` — Current stock per warehouse (uses CostCalculationService)
    - `warehouseSummary` — Financial summary per warehouse from monthly_closings
30. **`routes/api.php`** (UPDATED): 4 new routes (alerts, menu-snapshot, trends, recent-activity) + 3 stock/warehouse routes.

### Frontend — Client Shell
31. **`ClientShell.tsx`** (NEW — `components/ui/`):
    - Lighter sidebar compared to `AppShell.tsx`:
      - No client switcher (client belongs to ONE company).
      - No admin sections (clients, users, settings, warehouses, payroll, production).
      - Only module-filtered items: الرئيسية (Dashboard), المخزون, التقارير.
    - Fetches `/api/client/modules` + `/api/client/settings`.
    - Uses `primary_color` for active link styling.
    - Has ThemeToggle + logout in footer.
    - Dark mode ready.

### Frontend — Users Page
32. **`users/page.tsx`**:
    - Added `'عميل'` (green badge) option in role dropdown.
    - When `role=client` selected: company selector switches from multi-checkbox to single `<select>`.
    - Table role cell shows green badge for client type.
    - `portal` field preserved in UserItem interface.

### 21. `ProductionPostController` — Post date uses current date instead of month end
- **File**: `ERP/laravel-app/app/Http/Controllers/Production/ProductionPostController.php:79`
- **Root cause**: `$postDate = $end->toDateString()` (end of month, e.g., 2026-05-31). When today is before month-end (e.g., May 17), the DispatchOrder gets a future date. The `update()` form on the frontend validates `date <= today`, so editing/updating the posted production order fails with "The date field must be a date before or equal to today."
- **Fix**: Changed to `$postDate = now()->toDateString()` — records the production as happening on the actual posting date.
- **Impact**: Production orders now have today's date, not month-end. The update form works normally.

---

## Surgical Changes Applied (2026-05-23 — Item Import Fix + Closing Edit Mode)

### 22. Item Import — Header-name-based column reading
- **Files**: `ERP/laravel-app/app/Http/Controllers/ItemController.php:122-192`
- **Root cause**: `import()` used fixed index `$row[0]`, `$row[1]`, etc., which mismatched the Export column order. Exporting then re-importing the same Excel would corrupt data (e.g., reading the serial number `#` as the item name).
- **Fix**: Rewrote `import()` and `importStockLevels()` to read the first row as headers, build a column map by Arabic header name (`الاسم`, `الوحدة`, `السعر`, `الحد الأدنى`, `المخزن الافتراضي`, `التصنيف`), then read each data row by header name. Also added `category` import (was missing). Now 100% compatible with Export output.
- **Impact**: Export then re-import produces the exact same data. No data corruption.

### 23. Database — Removed `max_stock_level` and `reorder_qty`
- **File**: `ERP/laravel-app/database/migrations/2026_05_23_000000_drop_max_stock_and_reorder_from_items.php`
- **Change**: Dropped `max_stock_level` (decimal) and `reorder_qty` (decimal) columns from `items` table.
- **Impact**: Schema cleaned up. Both columns were unused. `min_stock_level` retained.

### 24. Edit Mode in Monthly Closing — Inline editing with reverse voucher sync
- **Files**: `ERP/laravel-app/app/Http/Controllers/ClosingController.php` (new methods), `ERP/laravel-app/routes/api.php` (routes), `ERP/frontend/src/app/(app)/closing/page.tsx` (UI)
- **Backend — New API endpoints**:
  - `GET /closing/cell-orders` — Returns order-level detail for a daily cell (item+warehouse+date)
  - `PATCH /closing/edit-daily-cell` — Updates DispatchLine + StockLedger qty/total_cost for one or more orders, then regenerates monthly_closings for the affected item. Preserves `is_locked`, `closing_qty_actual`, `physical_count`.
  - `PATCH /closing/edit-cell-value` — Same for total_cost (value) editing.
- **Backend — Logic**:
  1. Validates `is_locked` → 403 if locked
  2. `DB::transaction()`:
     - Update `DispatchLine` (qty + total_cost)
     - Update ALL `StockLedger` entries for (ref_id, item_id) — keeps source/target in sync
     - Log `ActivityLogger` for `closing_cell_edited` / `closing_value_edited`
     - For `purchase` type orders: update `Item::default_cost` + log `price_updated`
     - Call `itemMonthSummary()` + `MonthlyClosing::updateOrCreate()` for the affected item/warehouse/month
- **Frontend — Edit Mode**:
  - Toggle button "وضع التعديل" in toolbar (single location view only, same permission as vouchers)
  - Single-order cells → inline `<input>` that adds to `pendingEdits` on change
  - Multi-order cells → clickable `[5+3]` link that opens Popover with order breakdown
  - Value column (إجمالي المشتريات) → same Popover pattern with order-level value editing
  - "حفظ التعديلات" button → batch saves all pending edits via `Promise.allSettled`
  - Auto refreshes closing + daily + grand-summary queries after save
- **Impact**: Users can edit daily quantities and purchase values directly in the closing table. Changes propagate backwards to the source vouchers in history. Monthly closing regenerates automatically.

## Surgical Fix Applied (2026-05-30) — Stock Closing 500 on Generate

### 24. `CostCalculationService::itemMonthSummary()` — MySQL only_full_group_by violation
- **File**: `ERP/laravel-app/app/Services/CostCalculationService.php:207`
- **Root cause**: The `branchDispatches` query used `groupBy('branch_id', 'branch_name')` where `branch_id`/`branch_name` are SELECT aliases for `COALESCE(...)` expressions. MySQL with `sql_mode=only_full_group_by` (default since 5.7) rejects GROUP BY on aliases of expressions — only direct column references are allowed. The `having('branch_id', ...)` remained on the alias and was never broken — MySQL permits SELECT aliases in HAVING.
- **Fix**: Changed ONLY `groupBy('branch_id', 'branch_name')` → `groupBy(DB::raw('COALESCE(...)'), DB::raw('COALESCE(...)'))`. HAVING left unchanged as `->having('branch_id', '!=', $warehouseId)`.
- **Why not both**: Using `DB::raw('COALESCE(dest.id, ...)')` in HAVING causes `Unknown column 'dest.id' in 'having clause'` under `only_full_group_by`, because HAVING can only reference GROUP BY columns or aggregates — raw column references from JOINs are rejected there.
- **Impact**: Stock closing generation works regardless of MySQL `only_full_group_by` setting. No logic change — same COALESCE resolution, same output data. No other files touched. Zero risk to other modules.

## Surgical Fix Applied (2026-05-31) — Dashboard Month Picker + Negative Opening Fix

### 25. Dashboard — Missing month selector
- **File**: `ERP/frontend/src/app/(app)/dashboard/page.tsx:11`
- **Root cause**: Month was hardcoded as `const month = new Date().toISOString().slice(0, 7)` — no way to view past months. When calendar month changed, all dashboard data switched to the new month with no fallback.
- **Fix**: Replaced with `const [month, setMonth] = useState(new Date().toISOString().slice(0, 7))` and added `<input type="month">` in the PageHeader actions section, alongside the export button.
- **Impact**: User can now select any month. All dashboard queries (kpis, warehouse-summary) reactively update. Zero backend changes.

### 26. `CostCalculationService::currentStock()` — Negative stock protection
- **File**: `ERP/laravel-app/app/Services/CostCalculationService.php:76`
- **Root cause**: `currentStock()` returns `SUM(in_qty) - SUM(out_qty)`. When out exceeds in (no opening balance entered, or data entry gap), the result is negative. `itemMonthSummary()` then stores negative `opening_qty` in `monthly_closings`, and the dashboard shows negative `أول المدة` totals.
- **Fix**: Added `max(0, ...)` wrapper: `return round(max(0, (float) $result), 3)`. This clamps the stock balance to 0 — negative physical stock is a data error, not a valid business state. Also indirectly protects `itemMonthSummary()` fallback path where `opening_qty = currentStock(...)`.
- **Impact**: Dashboard opening values never show negative. Closings generated after this fix will store non-negative `opening_qty`. Existing negative data in `monthly_closings` needs regeneration (run "تحديث الحسابات" for the affected month). No impact on other modules.

## Surgical Fixes Applied (2026-06-04 — Dispatch Warehouse Resolution + Branch Movement Fix)

### 27. `VoucherController::update()` + `manual()` — Per-line warehouse resolution via `resolveWarehouseId()` for dispatch orders
- **Files**: `ERP/laravel-app/app/Http/Controllers/VoucherController.php` (`manual()` lines 459-556, `update()` lines 814-968)
- **Root cause**: 
  - When editing a dispatch order via `update()` or creating one via `manual()`, the source warehouse for each line was determined by the frontend's `warehouse_id` value (or a simple fallback). This bypassed `resolveWarehouseId()` which correctly resolves the source warehouse per item using: `default_warehouse_id` → keyword matching → branch default → main warehouse.
  - Items with `default_warehouse_id = مستر شريمب` (or other sub-warehouses) were incorrectly 'out'-ed from the main warehouse instead of their proper warehouse.
  - The branch target warehouse ('in' movement) was only posted when `$request->branch_id` was non-empty. If the order was previously edited and lost its `branch_id` (but still had `warehouse_id` as a branch-type warehouse), the branch 'in' movement was permanently lost after every `update()` call.
  - `$allWarehouseIds` (used for closing regeneration) did not include items' `default_warehouse_id` or the resolved branch target warehouse, so the closing for those warehouses was never updated.
- **Fix**:
  1. Added `resolveWarehouseId()` call per line in both `manual()` and `update()` for dispatch orders — same logic as `confirm()` (import). Source warehouse for each line is now resolved using item's `default_warehouse_id`, keyword matching, or main warehouse fallback.
  2. `branchTargetWhId` is resolved BEFORE the transaction by checking: (a) `$request->branch_id`, (b) `$request->warehouse_id` if it's a branch-type warehouse, (c) old `$order->branch_id`, (d) old `$order->warehouse_id` if branch-type. Used in both positive and negative qty branch posting conditions, replacing the old `!empty($request->branch_id)` check. Added `$branchTargetWhId !== $lineWhId` guard to prevent self-targeting.
  3. Added `$branchTargetWhId` and all items' `default_warehouse_id` to `$allWarehouseIds` before closing regeneration, ensuring ALL affected warehouses get their closing recalculated.
- **Impact**:
  - **Future saves**: Items dispatched to branches use their correct source warehouse (`default_warehouse_id`). Branch 'in' movements are always preserved. Closing regenerated for all affected warehouses.
  - **Existing corrections**: User must open each dispatch order previously saved with wrong data and click "حفظ". The `update()` method reverses old wrong entries and recreates them correctly.
   - **No regression**: Non-dispatch voucher types and non-branch dispatches unchanged (use the existing `else` path).

## Surgical Improvements Applied (2026-06-05) — Code Quality & Excel Enhancement

### ReportController.php
1. **Removed 3 unused imports**: `DispatchLine`, `DispatchOrder`, `DB` — none were referenced in the file.
2. **String concat → interpolation**: `'A' . $rowIdx` → `"A{$rowIdx}"` (10 occurrences) in `exportDiffs()`.
3. **Freeze pane B4**: Changed from `A4` to `B4` so the item name column (B) stays visible when scrolling horizontally.
4. **Page Setup**: Added Landscape orientation, Fit to Width (fitToWidth=1, fitToHeight=0), and print title rows (row 3 repeats at top).
5. **Conditional Formatting**: Replaced manual cell-by-cell font coloring on diff column (J) with `Conditional` rules — green for >0 (surplus), red for <0 (shortage). Colors update automatically when values change in Excel.
6. **Alternating Row Colors (zebra)**: Added conditional format `MOD(ROW(),2)=0` with light gray fill (`FFF5F5FA`) across data range.
7. **Summary Row**: Added row at bottom with bold font, blue-gray fill (`FFE8EEF7`), medium top border, and SUM formulas for columns D-J.

### ClosingController.php
8. **Removed unused import**: `Maatwebsite\Excel\Facades\Excel` (was never used).
9. **Added `StreamedResponse` import**: `use Symfony\Component\HttpFoundation\StreamedResponse;`.
10. **Added return types** to 4 export methods: `export()`, `exportPdf()`, `exportLocationExcel()`, `exportCycle()` — all `: StreamedResponse`.
11. **Fixed `exportLocationExcel()` error response**: Changed `return response()->json(...)` to `abort(422, ...)` for consistency with StreamedResponse return type.
12. **Simplified ternary in `cellOrders()`**: `$order ? ($order->id ? '#' . substr(...) : '—') : '—'` → `$order ? '#' . substr(...) : '—'` (inner `$order->id` always truthy).
13. **Client_id scope fix**: `Item::find($request->item_id)` → `Item::where('client_id', $clientId)->find($request->item_id)` in `editDailyCell()` (prevented cross-tenant data leak).
14. **Error message hidden**: `generate()` now logs full error via `Log::error()` and returns generic message instead of exposing `$e->getMessage()`.
15. **Extracted `ensureItemNotLocked()`**: Private method encapsulating the locked-month check (duplicated in `editDailyCell()` and `editCellValue()`).
16. **Extracted `regenerateItemClosing()`**: Private method encapsulating the item closing regeneration (duplicated identically in `editDailyCell()` and `editCellValue()` — ~25 lines each → single call).
17. **`editDailyCell()` reduced**: Previously ~135 lines, now ~100 lines after extraction.

---

## Dashboard Improvements Applied (2026-06-05)

### Backend — `DashboardController.php`
1. **`kpis()` — Removed `food_cost_pct`**: Eliminated entirely from response. `total_stock_value` added as 4th KPI.
2. **`kpis()` — Change % added**: `purchases_change`, `dispatched_change`, `diffs_change` — % change vs previous month.
3. **`kpis()` — Diffs filtered to warehouses**: `total_diffs` scoped to `type IN ('main','sub')` only. Branch diffs excluded.
4. **`monthlyTrend()` — Diffs filtered**: Removed branch closings from trend data.
5. **`warehouseSummary()` — Branch diff hidden**: Branches now return `diff = '—'` (string dash) instead of numeric.
6. **New endpoint — `diffsByWarehouse()`**: `GET /api/dashboard/diffs-by-warehouse` — aggregated diffs per warehouse for Pie chart.
7. **New endpoint — `topDiffItems()`**: `GET /api/dashboard/top-diff-items` — top 10 items by diff value (absolute).

### Backend — `ReportExportService.php`
8. **`dashboardKpis()` — Aligned with controller**: Diffs filtered to warehouses, `foodCostPct` removed.
9. **`exportDashboard()` — Removed Food Cost row**: Now exports only 3 KPI rows.

### Backend — Routes (`api.php`)
10. **Two new routes**: `dashboard/diffs-by-warehouse` and `dashboard/top-diff-items` added under `permission:dashboard` middleware.

### Frontend — New Components (`dashboard/components/`)
11. **`KpiCard.tsx`**: Reusable card with lucide icon in colored capsule, % change badge (▲/▼), optional sparkline AreaChart (recharts), loading state.
12. **`DashboardCharts.tsx`**: Contains `DiffPieChart` (donut pie by warehouse), `TopDiffItems` (horizontal progress bars per item), `TrendChart` (LineChart with 3 series — purchases/dispatched/diffs).
13. **`WarehouseTable.tsx`**: Clean table with `DiffBadge` component (green/amber/red based on thresholds).
14. **`BranchTable.tsx`**: Branch table with `'—'` for diff, colored badges for numeric diffs.

### Frontend — `dashboard/page.tsx`
15. **Removed**: Food Cost % card, old inline KPI cards, old BarChart, old inline tables.
16. **Added**: 4 `KpiCard` instances with lucide icons (ShoppingCart, ArrowUpDown, AlertTriangle, Package) + sparklines + change badges.
17. **Added**: Smart Analytics widgets redesigned with icon capsules.
18. **Added**: `DiffPieChart` + `TopDiffItems` row (2-column grid).
19. **Added**: `TrendChart` (LineChart) full-width.
20. **Added**: `WarehouseTable` + `BranchTable` row (2-column grid).
21. **Fixed**: `layout.tsx` — `Geist` font replaced with `Inter` (Geist not available in this Next.js version).

### Dependencies Installed
22. `lucide-react` (npm) — icon library.
23. `shadcn/ui@4.9.0` — initialized with `npx shadcn@4.9.0 init -d --force`. Components: `card`, `badge`, `tabs`, `progress`, `separator`, `button`.
