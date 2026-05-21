<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Admin User ────────────────────────────────────────
        $adminId = Str::uuid();
        DB::table('users')->insert([
            'id'         => $adminId,
            'name'       => 'Admin',
            'email'      => 'admin@erp.local',
            'password'   => Hash::make('admin123'),
            'role'       => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $costUserId = Str::uuid();
        DB::table('users')->insert([
            'id'         => $costUserId,
            'name'       => 'أحمد محمود',
            'email'      => 'ahmed@erp.local',
            'password'   => Hash::make('123456'),
            'role'       => 'cost_controller',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── Client: مستر شريمب ────────────────────────────────
        $clientId = Str::uuid();
        DB::table('clients')->insert([
            'id'         => $clientId,
            'name'       => 'مستر شريمب',
            'slug'       => 'mister-shrimp',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ربط الموظفين بالعميل
        DB::table('client_user')->insert([
            ['client_id' => $clientId, 'user_id' => $adminId,    'is_primary' => true],
            ['client_id' => $clientId, 'user_id' => $costUserId, 'is_primary' => true],
        ]);

        // ── Warehouses ────────────────────────────────────────
        $whMainId    = Str::uuid();
        $whShrimp Id = Str::uuid();
        $whDamyat Id = Str::uuid();

        DB::table('warehouses')->insert([
            ['id' => $whMainId,   'client_id' => $clientId, 'name' => 'مخزن مركزي',       'type' => 'main', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $whShrimpId, 'client_id' => $clientId, 'name' => 'مخزن مستر شريمب', 'type' => 'sub',  'created_at' => now(), 'updated_at' => now()],
            ['id' => $whDamyatId, 'client_id' => $clientId, 'name' => 'مخزن دمياط',       'type' => 'sub',  'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Branches ──────────────────────────────────────────
        $brMybrId   = Str::uuid();
        $brMixId    = Str::uuid();
        $brShrId    = Str::uuid();
        $brDamId    = Str::uuid();
        $brSahelId  = Str::uuid();

        DB::table('branches')->insert([
            ['id' => $brMybrId,  'client_id' => $clientId, 'name' => 'ماي بروست',       'created_at' => now(), 'updated_at' => now()],
            ['id' => $brMixId,   'client_id' => $clientId, 'name' => 'مسترميكس بلقاس', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $brShrId,   'client_id' => $clientId, 'name' => 'مستر شريمب',      'created_at' => now(), 'updated_at' => now()],
            ['id' => $brDamId,   'client_id' => $clientId, 'name' => 'مستر مكس دمياط', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $brSahelId, 'client_id' => $clientId, 'name' => 'مستر ميكس الساحل', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Items (من الشيت الفعلي) ───────────────────────────
        $itemsData = [
            ['name' => 'لبن',               'unit' => 'كيلو',     'category' => 'البان وجبن'],
            ['name' => 'موزريلا كريب',      'unit' => 'كيلو',     'category' => 'البان وجبن'],
            ['name' => 'مكس ثلاثي',         'unit' => 'كيلو',     'category' => 'البان وجبن'],
            ['name' => 'موزريلا صوابع',     'unit' => 'كيلو',     'category' => 'البان وجبن'],
            ['name' => 'موزريلا نصف دسم',   'unit' => 'كيلو',     'category' => 'البان وجبن'],
            ['name' => 'كول سلو كبير',      'unit' => 'عدد',      'category' => 'معمل'],
            ['name' => 'كول سلو وسط',       'unit' => 'عدد',      'category' => 'معمل'],
            ['name' => 'كول سلو صغير',      'unit' => 'عدد',      'category' => 'معمل'],
            ['name' => 'ثومية كبير',         'unit' => 'عدد',      'category' => 'معمل'],
            ['name' => 'ثومية وسط',          'unit' => 'عدد',      'category' => 'معمل'],
            ['name' => 'ثومية صغير',         'unit' => 'عدد',      'category' => 'معمل'],
            ['name' => 'فرايز',              'unit' => 'كيلو',     'category' => 'دواجن وجاهز'],
            ['name' => 'استربس عادي',        'unit' => 'عدد',      'category' => 'دواجن وجاهز'],
            ['name' => 'برجر 150 جم',        'unit' => 'عدد',      'category' => 'دواجن وجاهز'],
            ['name' => 'برجر 100 جم',        'unit' => 'عدد',      'category' => 'دواجن وجاهز'],
            ['name' => 'فراخ عادي (قطع)',   'unit' => 'كيلو',     'category' => 'دواجن وجاهز'],
            ['name' => 'دقيق كريب',          'unit' => 'كيلو',     'category' => 'جاف'],
            ['name' => 'دقيق بيتزا',         'unit' => 'كيلو',     'category' => 'جاف'],
            ['name' => 'مناديل سحب',         'unit' => 'علبة',     'category' => 'مستلزمات'],
            ['name' => 'مناديل سفرة',        'unit' => 'علبة',     'category' => 'مستلزمات'],
            ['name' => 'صابون بيريل',        'unit' => 'عدد',      'category' => 'نظافة'],
            ['name' => 'خس',                 'unit' => 'كيلو',     'category' => 'خضروات'],
            ['name' => 'جزر',                'unit' => 'كيلو',     'category' => 'خضروات'],
            ['name' => 'طماطم',              'unit' => 'كيلو',     'category' => 'خضروات'],
            ['name' => 'وايت صوص',           'unit' => 'كيلو',     'category' => 'معمل'],
            ['name' => 'ريد صوص',            'unit' => 'كيلو',     'category' => 'معمل'],
            ['name' => 'أرز مستوي',          'unit' => 'كيلو',     'category' => 'معمل'],
        ];

        foreach ($itemsData as $item) {
            DB::table('items')->insert([
                'id'         => Str::uuid(),
                'client_id'  => $clientId,
                'name'       => $item['name'],
                'unit'       => $item['unit'],
                'category'   => $item['category'],
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info('✅ Seeded: 1 client, 3 warehouses, 5 branches, ' . count($itemsData) . ' items');
        $this->command->info('   Login: admin@erp.local / admin123');
        $this->command->info('   Login: ahmed@erp.local / 123456');
    }
}
