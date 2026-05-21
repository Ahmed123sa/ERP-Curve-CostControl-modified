<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Client;
use App\Models\Warehouse;
use App\Models\Branch;
use App\Models\BranchWarehouseSource;
use App\Models\LocationMapping;
use App\Models\StockLedger;
use App\Models\DispatchLine;
use App\Models\DispatchOrder;
use App\Models\MonthlyClosing;
use App\Models\ItemMapping;
use App\Models\Item;
use App\Models\User;
use App\Models\ActivityLog;

class FullSystemSeeder extends Seeder
{
    public function run(): void
    {
        // ===== 1. مسح كل البيانات القديمة بالترتيب العكسي =====
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        ActivityLog::truncate();
        StockLedger::truncate();
        DispatchLine::truncate();
        DispatchOrder::truncate();
        MonthlyClosing::truncate();
        ItemMapping::truncate();
        LocationMapping::truncate();
        BranchWarehouseSource::truncate();
        DB::table('client_user')->truncate();
        Branch::truncate();
        Warehouse::truncate();
        Item::truncate();
        User::truncate();
        Client::truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // ===== 2. إنشاء البيانات الأساسية =====
        $clientId = (string) Str::uuid();
        $userId   = (string) Str::uuid();

        // العميل
        $client = Client::create([
            'id'        => $clientId,
            'name'      => 'مستر شريمب',
            'slug'      => 'mister-shrimp',
            'is_active' => true,
        ]);

        // المستخدم
        $user = User::create([
            'id'                => $userId,
            'email'             => 'admin@erp.local',
            'name'              => 'المسؤول',
            'password'          => bcrypt('admin123'),
            'role'              => 'admin',
            'current_client_id' => $client->id,
        ]);
        $user->clients()->syncWithoutDetaching([$client->id => ['is_primary' => true]]);

        // المخزن الرئيسي + فرعي
        $mainWarehouse = Warehouse::create([
            'id'        => (string) Str::uuid(),
            'client_id' => $client->id,
            'type'      => 'main',
            'name'      => 'المخزن الرئيسي',
            'is_active' => true,
        ]);

        $subWarehouse1 = Warehouse::create([
            'id'        => (string) Str::uuid(),
            'client_id' => $client->id,
            'type'      => 'sub',
            'name'      => 'مخزن شريمب',
            'is_active' => true,
        ]);

        $subWarehouse2 = Warehouse::create([
            'id'        => (string) Str::uuid(),
            'client_id' => $client->id,
            'type'      => 'sub',
            'name'      => 'مخزن ماي بروست',
            'is_active' => true,
        ]);

        // الفروع
        $branch1 = Branch::create([
            'id'        => (string) Str::uuid(),
            'client_id' => $client->id,
            'name'      => 'فرع مستر شريمب',
            'is_active' => true,
        ]);

        $branch2 = Branch::create([
            'id'        => (string) Str::uuid(),
            'client_id' => $client->id,
            'name'      => 'فرع ماي بروست',
            'is_active' => true,
        ]);

        // ربط الفروع بالمخازن
        BranchWarehouseSource::create([
            'id'           => (string) Str::uuid(),
            'branch_id'    => $branch1->id,
            'warehouse_id' => $mainWarehouse->id,
            'item_id'      => null,
            'priority'     => 1,
        ]);
        BranchWarehouseSource::create([
            'id'           => (string) Str::uuid(),
            'branch_id'    => $branch1->id,
            'warehouse_id' => $subWarehouse1->id,
            'item_id'      => null,
            'priority'     => 2,
        ]);

        BranchWarehouseSource::create([
            'id'           => (string) Str::uuid(),
            'branch_id'    => $branch2->id,
            'warehouse_id' => $mainWarehouse->id,
            'item_id'      => null,
            'priority'     => 1,
        ]);
        BranchWarehouseSource::create([
            'id'           => (string) Str::uuid(),
            'branch_id'    => $branch2->id,
            'warehouse_id' => $subWarehouse2->id,
            'item_id'      => null,
            'priority'     => 2,
        ]);

        // Location Mappings
        $this->createMapping($client, 'وارد مخزن', 'warehouse', $mainWarehouse->id);
        $this->createMapping($client, 'فرع مستر شريمب', 'branch', $branch1->id);
        $this->createMapping($client, 'فرع ماي بروست', 'branch', $branch2->id);
        $this->createMapping($client, 'ورشة مستر شريمب', 'branch', $branch1->id);
        $this->createMapping($client, 'ورشة ماي بروست', 'branch', $branch2->id);
        $this->createMapping($client, 'مستر شريمب', 'branch', $branch1->id);
        $this->createMapping($client, 'ماي بروست', 'branch', $branch2->id);
        $this->createMapping($client, 'مخزن شريمب', 'warehouse', $subWarehouse1->id);
        $this->createMapping($client, 'مخزن ماي بروست', 'warehouse', $subWarehouse2->id);
        $this->createMapping($client, 'انتاج', 'warehouse', $subWarehouse1->id);
        $this->createMapping($client, 'وارد مخزن شريمب', 'warehouse', $subWarehouse1->id);
        $this->createMapping($client, 'وارد مخزن ماي بروست', 'warehouse', $subWarehouse2->id);
    }

    private function createMapping($client, string $sourceName, string $targetType, string $targetId): void
    {
        LocationMapping::create([
            'id'          => (string) Str::uuid(),
            'client_id'   => $client->id,
            'source_name' => $sourceName,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'confidence'  => 100,
        ]);
    }
}