<?php

use Illuminate\Support\Facades\DB;

try {
    DB::statement("ALTER TABLE dispatch_orders MODIFY COLUMN type ENUM('purchase', 'dispatch', 'transfer', 'withdrawal', 'production', 'external_sale', 'opening', 'adjustment', 'return') NOT NULL");
    echo "Successfully updated dispatch_orders type enum.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
