# Fix: Dispatch Order Branch Warehouse Recovery on Edit (IMPLEMENTED)

## Root Cause

When `update()` is called for a dispatch order whose `branch_id` was saved as `null` (old orders created before the `manual()` preservation fix), the branch detection chain could fail. Even when detection succeeds (via `$request->warehouse_id` pointing to a branch-type warehouse), the detected ID is a **Warehouse ID** rather than a **Branch ID**. This causes `resolveWarehouseId()` steps a and 4 (`BranchWarehouseSource` lookups) to fail because they use the Warehouse ID instead of the Branch ID.

Additionally, orders saved with old code have lines storing the main warehouse as the source for ALL items, even those whose `default_warehouse_id` points to a sub-warehouse (e.g., مخزن مستر شريمب).

## Changes Applied

All changes in `ERP/laravel-app/app/Http/Controllers/VoucherController.php`:

### 1. Add `use App\Models\Branch;` import

### 2. Resolve Warehouse ID → Branch ID in `update()` (after line 831)

After the detection chain, convert a Warehouse ID (type 'branch') to the actual Branch ID via name lookup:

```php
        // Resolve Warehouse ID → Branch ID so BranchWarehouseSource lookups work
        if ($dispatchBranchId) {
            $detectedWh = Warehouse::find($dispatchBranchId);
            if ($detectedWh && $detectedWh->type === 'branch') {
                $branchForWh = Branch::where('name', $detectedWh->name)->first();
                if ($branchForWh) {
                    $dispatchBranchId = $branchForWh->id;
                }
            }
        }
```

### 3. Recover from old stock_ledger in `update()` (after line 851)

When header-based detection fails entirely, recover branch context from old ledger entries before reversal:

```php
        // Recovery from old stock_ledger if header-based branch detection failed
        if ($request->type === 'dispatch' && !$isBranchDispatch && $order->type === 'dispatch') {
            $oldLedgerIn = StockLedger::where('ref_type', 'dispatch_order')
                ->where('ref_id', $order->id)
                ->where('movement_type', 'in')
                ->where('voucher_type', 'dispatch')
                ->first();
            if ($oldLedgerIn) {
                $recoveredWhId = $oldLedgerIn->warehouse_id;
                $wh = Warehouse::find($recoveredWhId);
                if ($wh) {
                    $branch = Branch::where('name', $wh->name)->first();
                    $recoveredBranchId = $branch ? $branch->id : $recoveredWhId;
                    $dispatchBranchId = $recoveredBranchId;
                    $isBranchDispatch = true;
                    $branchDispatchLoc = ['type' => 'branch', 'id' => $dispatchBranchId];
                    $branchTargetWhId = $this->resolveBranchTargetWhId($clientId, $dispatchBranchId);
                }
            }
        }
```

Also adds `$recoveredWhId` to `$allWarehouseIds` for closing recalculation.

### 4. Always resolve source for dispatch orders (line 931+)

Changed the line warehouse resolution to always try `resolveWarehouseId()` for dispatch orders. When no branch context, falls back to `default_warehouse_id` → keyword matching → main warehouse:

```php
                if ($request->type === 'dispatch') {
                    if ($branchDispatchLoc) {
                        $lineWhId = $this->resolveWarehouseId($clientId, $branchDispatchLoc, $line['item_id'])
                            ?? $dispatchLineWhFallback;
                    } else {
                        // Resolve source using item properties when no branch context
                        $item = Item::find($line['item_id']);
                        $lineWhId = null;
                        if ($item && $item->default_warehouse_id) {
                            $lineWhId = $item->default_warehouse_id;
                        }
                        if (!$lineWhId && $item) {
                            $swh = Warehouse::where('client_id', $clientId)
                                ->where('type', 'sub')
                                ->where(function($q) use ($item) {
                                    $q->where('name', 'like', '%' . $item->name . '%')
                                      ->orWhereRaw('? like concat("%", name, "%")', [$item->name]);
                                })
                                ->first();
                            if ($swh) $lineWhId = $swh->id;
                        }
                        $lineWhId = $lineWhId ?: $dispatchLineWhFallback;
                    }
                } else {
                    $lineWhId = ($line['warehouse_id'] ?? null) ?: $dispatchLineWhFallback;
                }
```

### 5. Preserve branch_id on order (inside transaction)

After order update, if branch context was recovered, save it to the order for future edits:

```php
            // Preserve detected branch context so future edits recover correctly
            if ($request->type === 'dispatch' && $branchTargetWhId && !$order->branch_id) {
                $order->update(['branch_id' => $dispatchBranchId]);
            }
```

Adds `$dispatchBranchId` to the transaction `use` clause.

### 6. Same Branch ID resolution in `manual()` (after line 457)

Applied the same Warehouse ID → Branch ID conversion in `manual()` for consistency.

## Testing

1. Find a dispatch order with `branch_id IS NULL` (150 such orders exist)
2. Edit it via the frontend and save
3. Verify line `warehouse_id` values:
   - Items with `default_warehouse_id = 4ca7e364...` (مخزن مستر شريمب) → resolved to 4ca7e364...
   - Items with `default_warehouse_id = 1755abb6...` (مخزن رئيسي) → resolved to 1755abb6...
4. Verify stock_ledger 'out' movements come from correct source warehouses
5. Check MonthlyClosing for correct values
6. Verify main warehouse no longer shows `منصرف فروع` for sub-warehouse items
7. Verify branch `avg_cost` reflects correct weighted average

## Files Changed

- `ERP/laravel-app/app/Http/Controllers/VoucherController.php`
