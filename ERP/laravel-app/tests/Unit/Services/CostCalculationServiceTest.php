<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\StockLedger;
use App\Models\Item;
use App\Models\Warehouse;
use App\Services\CostCalculationService;
use Illuminate\Support\Facades\Schema;

class CostCalculationServiceTest extends TestCase
{
    private string $clientId;
    private string $warehouseId;
    private string $itemId;
    private CostCalculationService $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestTables();
        $this->clientId = (string) \Illuminate\Support\Str::uuid();
        $this->calc = app(CostCalculationService::class);

        $item = Item::factory()->create(['client_id' => $this->clientId]);
        $warehouse = Warehouse::factory()->create(['client_id' => $this->clientId]);
        $this->itemId = $item->id;
        $this->warehouseId = $warehouse->id;
    }

    private function createTestTables(): void
    {
        if (Schema::hasTable('items')) return;

        Schema::create('items', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('name');
            $table->string('unit');
            $table->boolean('is_active')->default(true);
            $table->decimal('default_cost', 12, 2)->default(0);
            $table->integer('sort_order')->nullable();
            $table->timestamps();
            $table->index('client_id');
        });

        Schema::create('warehouses', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('name');
            $table->string('type')->default('main');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('client_id');
        });

        Schema::create('stock_ledger', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('warehouse_id');
            $table->uuid('item_id');
            $table->date('date');
            $table->string('movement_type');
            $table->string('voucher_type');
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->string('ref_type')->nullable();
            $table->string('ref_id')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'warehouse_id', 'item_id']);
        });
    }

    public function test_weighted_average_cost_with_single_purchase(): void
    {
        StockLedger::create([
            'client_id' => $this->clientId,
            'warehouse_id' => $this->warehouseId,
            'item_id' => $this->itemId,
            'date' => '2026-01-01',
            'movement_type' => 'in',
            'voucher_type' => 'purchase',
            'qty' => 100,
            'unit_cost' => 50,
            'total_cost' => 5000,
        ]);

        $avg = $this->calc->weightedAverageCost($this->clientId, $this->warehouseId, $this->itemId);

        $this->assertEquals(50, $avg);
    }

    public function test_weighted_average_cost_multiple_purchases(): void
    {
        StockLedger::create([
            'client_id' => $this->clientId,
            'warehouse_id' => $this->warehouseId,
            'item_id' => $this->itemId,
            'date' => '2026-01-01',
            'movement_type' => 'in',
            'voucher_type' => 'purchase',
            'qty' => 100,
            'unit_cost' => 50,
            'total_cost' => 5000,
        ]);
        StockLedger::create([
            'client_id' => $this->clientId,
            'warehouse_id' => $this->warehouseId,
            'item_id' => $this->itemId,
            'date' => '2026-01-15',
            'movement_type' => 'in',
            'voucher_type' => 'purchase',
            'qty' => 50,
            'unit_cost' => 60,
            'total_cost' => 3000,
        ]);

        $avg = $this->calc->weightedAverageCost($this->clientId, $this->warehouseId, $this->itemId);

        $this->assertEqualsWithDelta(53.3333, $avg, 0.001);
    }

    public function test_weighted_average_cost_zero_qty_returns_zero(): void
    {
        $avg = $this->calc->weightedAverageCost($this->clientId, $this->warehouseId, $this->itemId);

        $this->assertEquals(0, $avg);
    }

    public function test_current_stock_with_in_and_out(): void
    {
        StockLedger::create([
            'client_id' => $this->clientId,
            'warehouse_id' => $this->warehouseId,
            'item_id' => $this->itemId,
            'date' => '2026-01-01',
            'movement_type' => 'in',
            'voucher_type' => 'purchase',
            'qty' => 200,
            'unit_cost' => 30,
            'total_cost' => 6000,
        ]);
        StockLedger::create([
            'client_id' => $this->clientId,
            'warehouse_id' => $this->warehouseId,
            'item_id' => $this->itemId,
            'date' => '2026-01-10',
            'movement_type' => 'out',
            'voucher_type' => 'production',
            'qty' => 80,
            'unit_cost' => 30,
            'total_cost' => 2400,
        ]);

        $balance = $this->calc->currentStock($this->clientId, $this->warehouseId, $this->itemId);

        $this->assertEquals(120, $balance);
    }
}
