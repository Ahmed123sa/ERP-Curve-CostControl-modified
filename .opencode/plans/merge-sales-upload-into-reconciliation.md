# Merge Sales Upload into Reconciliation — Plan

## Goal
Merge the standalone "رفع مبيعات" tab functionality into the reconciliation page modal, with professional recipe linking, size display, and backend key fix.

## Files Changed

| # | File | Change |
|---|------|--------|
| 1 | `ERP/frontend/src/app/(app)/menu-engineering/sales-upload/page.tsx` | **Deleted** — standalone page removed |
| 2 | `ERP/frontend/src/app/(app)/menu-engineering/layout.tsx` | Removed `رفع مبيعات` tab |
| 3 | `ERP/laravel-app/app/Http/Controllers/MenuEngineering/MenuSalesImportController.php` | Fixed unmatched key from `$name` → `$name . '|' . $sizeVal` (2 locations: `upload()` and `process()`) |
| 4 | `ERP/frontend/src/app/(app)/menu-engineering/reconciliation/page.tsx` | Imported `SearchableSelect`, replaced `<select>` with it, added size display (`اسم (حجم)`), added `overrideKey()` helper for composite keys |
| 5 | `PROJECT_MAP.md` | Added section documenting this change |

## Verification
1. Delete `ERP/frontend/src/app/(app)/menu-engineering/sales-upload/` directory
2. Backend: `$unmatchedRows` key uses composite `$name . '|' . $sizeVal` — no overwrites
3. Frontend reconciliation modal: `<SearchableSelect>` for recipe linking, size shown as `(حجم)` suffix
4. `uploadOverrides` uses composite key matching backend
