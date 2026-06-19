<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\StockLedger;
use App\Models\Item;
use App\Models\Warehouse;
use App\Services\StockLedgerService;
use Illuminate\Support\Facades\Schema;

class StockLedgerServiceTest extends TestCase
{
    private string $clientId;
    private StockLedgerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestTables();
        $this->clientId = (string) \Illuminate\Support\Str::uuid();
        $this->service = app(StockLedgerService::class);
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

    public function test_post_creates_ledger_entry(): void
    {
        $item = Item::factory()->create(['client_id' => $this->clientId]);
        $warehouse = Warehouse::factory()->create(['client_id' => $this->clientId]);

        $ledger = $this->service->post(
            clientId: $this->clientId,
            whId: $warehouse->id,
            itemId: $item->id,
            date: '2026-01-15',
            movementType: 'in',
            qty: 100,
            totalCost: 5000,
            unitCost: 50,
            refType: 'voucher',
            refId: 'test-ref-123',
            voucherType: 'purchase'
        );

        $this->assertDatabaseHas('stock_ledger', [
            'id' => $ledger->id,
            'client_id' => $this->clientId,
            'warehouse_id' => $warehouse->id,
            'item_id' => $item->id,
            'qty' => 100,
            'total_cost' => 5000,
            'unit_cost' => 50,
            'movement_type' => 'in',
            'voucher_type' => 'purchase',
            'ref_type' => 'voucher',
            'ref_id' => 'test-ref-123',
        ]);
    }

    public function test_post_uses_absolute_qty(): void
    {
        $item = Item::factory()->create(['client_id' => $this->clientId]);
        $warehouse = Warehouse::factory()->create(['client_id' => $this->clientId]);

        $ledger = $this->service->post(
            clientId: $this->clientId,
            whId: $warehouse->id,
            itemId: $item->id,
            date: '2026-01-15',
            movementType: 'out',
            qty: -50,
            totalCost: 2500,
            unitCost: 50,
            refType: 'voucher',
            refId: 'test-ref-456',
            voucherType: 'production'
        );

        $this->assertEquals(50, $ledger->qty);
    }

    public function test_post_transfer_creates_two_ledger_entries(): void
    {
        $item = Item::factory()->create(['client_id' => $this->clientId]);
        $from = Warehouse::factory()->create(['client_id' => $this->clientId, 'type' => 'main']);
        $to = Warehouse::factory()->create(['client_id' => $this->clientId, 'type' => 'sub']);

        [$out, $in] = $this->service->postTransfer(
            clientId: $this->clientId,
            fromWarehouseId: $from->id,
            toWarehouseId: $to->id,
            itemId: $item->id,
            date: '2026-01-20',
            qty: 30,
            unitCost: 40,
            refId: 'transfer-ref-789'
        );

        $this->assertEquals('transfer_out', $out->movement_type);
        $this->assertEquals($from->id, $out->warehouse_id);
        $this->assertEquals('transfer_in', $in->movement_type);
        $this->assertEquals($to->id, $in->warehouse_id);
        $this->assertEquals(1200, $out->total_cost);
        $this->assertEquals(1200, $in->total_cost);
    }
}
