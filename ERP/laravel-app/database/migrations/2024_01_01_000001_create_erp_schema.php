<?php
// ============================================================
// Migration: 2024_01_01_000001_create_erp_schema.php
// الـ Schema الكامل للنظام — شغّله مرة واحدة
// ============================================================

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Clients (Tenants) ──────────────────────────────
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');                          // مستر شريمب
            $table->string('slug')->unique();                // mister-shrimp
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── 2. Users already exist from Laravel's default migration ──
        // Skip users table creation - it's already created by 0001_01_01_000000_create_users_table

        // موظف ممكن يشتغل على أكثر من عميل
        Schema::create('client_user', function (Blueprint $table) {
            $table->uuid('client_id');
            $table->uuid('user_id');
            $table->boolean('is_primary')->default(false);  // العميل الأساسي للموظف
            $table->primary(['client_id', 'user_id']);
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // ── 3. Items (الأصناف) ───────────────────────────────
        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('name');                         // موزريلا نصف دسم
            $table->string('unit');                         // كيلو / عدد / كيس
            $table->string('category')->nullable();         // البان وجبن / دواجن / إلخ
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->unique(['client_id', 'name']);
        });

        // ── 4. Warehouses (المخازن) ──────────────────────────
        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('name');                         // مخزن مركزي / مخزن مستر شريمب
            $table->enum('type', ['main', 'sub'])->default('main');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });

        // ── 5. Branches (الفروع) ─────────────────────────────
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('name');                         // ماي بروست / مستر شريمب
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });

        // فرع ممكن يسحب من مخزنين — لكل صنف مصدره
        Schema::create('branch_warehouse_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('warehouse_id');
            $table->uuid('item_id')->nullable();            // null = ينطبق على كل الأصناف
            $table->integer('priority')->default(1);        // 1 = أول مصدر، 2 = بديل
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();
        });

        // ── 6. Dispatch Orders (أذون الصرف والمشتريات) ──────
        Schema::create('dispatch_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->enum('type', [
                'purchase',   // وارد مخزن — مشتريات
                'dispatch',   // إذن صرف — منصرف لفرع
                'transfer',   // تحويل بين مخازن
                'withdrawal', // مسحوبات
                'production', // الإنتاج اليومي
                'external_sale' // مبيعات خارجية
            ]);
            $table->date('date');
            $table->uuid('warehouse_id')->nullable();       // المخزن المصدر (للشراء)
            $table->uuid('branch_id')->nullable();          // الفرع (للصرف)
            $table->uuid('created_by');                     // المستخدم اللي أضافه
            $table->enum('status', ['draft', 'confirmed'])->default('confirmed');
            $table->string('source_file')->nullable();      // اسم ملف الـ Excel المرفوع
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
        });

        // سطور الإذن — كل صنف في سطر
        Schema::create('dispatch_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('item_id');
            $table->uuid('warehouse_id');                   // المخزن المصدر للسطر ده
            $table->decimal('qty', 12, 3);                  // الكمية
            $table->decimal('total_cost', 14, 2)->default(0); // الـ cost الكلي (للمشتريات)
            $table->decimal('unit_cost', 12, 4)->default(0);  // يتحسب: total_cost ÷ qty
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('dispatch_orders')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        });

        // ── 7. Stock Ledger (دفتر الحركة — القلب) ───────────
        // كل حركة بتتسجل هنا — الرصيد دايماً SUM من هنا
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('warehouse_id');
            $table->uuid('item_id');
            $table->date('date');
            $table->enum('movement_type', ['in', 'out', 'transfer_in', 'transfer_out']);
            $table->decimal('qty', 12, 3);                  // موجب = in، سالب = out
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->string('ref_type');                     // dispatch_order / closing_adjustment
            $table->uuid('ref_id');                         // الـ UUID للوثيقة المصدر
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('item_id')->references('id')->on('items');

            // Index للأداء
            $table->index(['client_id', 'warehouse_id', 'item_id', 'date']);
        });

        // ── 8. Item Mappings (ذاكرة ربط الأسماء) ─────────────
        // نفس فكرة mapping_memory.json بس في DB
        Schema::create('item_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('source_name', 128);                  // "موتزريلا نص دسم" (في الإذن)
            $table->uuid('item_id');                        // الصنف الفعلي في الـ DB
            $table->string('context', 64)->nullable();          // اسم المخزن أو الفرع
            $table->integer('confidence')->default(100);    // 100=confirmed / 95=auto
            $table->integer('usage_count')->default(1);     // كام مرة استُخدم
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            $table->unique(['client_id', 'source_name', 'context']);
        });

        // نفس الفكرة لربط أسماء المخازن والفروع
        Schema::create('location_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('source_name', 128);                  // "وارد مخزن" (في الإذن)
            $table->string('target_type');                  // warehouse / branch
            $table->uuid('target_id');                      // الـ UUID
            $table->integer('confidence')->default(100);
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->unique(['client_id', 'source_name']);
        });

        // ── 9. Monthly Closing (التقفيل الشهري) ──────────────
        Schema::create('monthly_closings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('warehouse_id');
            $table->uuid('item_id');
            $table->string('month', 7);                        // 2024-04

            // أول المدة
            $table->decimal('opening_qty', 12, 3)->default(0);
            $table->decimal('opening_value', 14, 2)->default(0);

            // الوارد
            $table->decimal('in_qty', 12, 3)->default(0);
            $table->decimal('in_value', 14, 2)->default(0);

            // المنصرف (لكل الفروع)
            $table->decimal('out_qty', 12, 3)->default(0);

            // آخر المدة
            $table->decimal('closing_qty_theoretical', 12, 3)->default(0); // أول + وارد - منصرف
            $table->decimal('closing_qty_actual', 12, 3)->nullable();       // جرد فعلي
            $table->decimal('closing_value', 14, 2)->default(0);

            // الفرق
            $table->decimal('diff_qty', 12, 3)->default(0);    // نظري - فعلي
            $table->decimal('diff_value', 14, 2)->default(0);   // الفرق × متوسط السعر

            // المتوسط المرجح
            $table->decimal('avg_cost', 12, 4)->default(0);

            $table->boolean('is_locked')->default(false);       // بعد الإقفال مش ينعدل
            $table->uuid('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('item_id')->references('id')->on('items');
            $table->unique(['client_id', 'warehouse_id', 'item_id', 'month']);
        });

        // ── 10. Row-Level Security في PostgreSQL ──────────────
        // بيمنع أي موظف يشوف بيانات عميل تاني حتى لو في bug
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_closings');
        Schema::dropIfExists('location_mappings');
        Schema::dropIfExists('item_mappings');
        Schema::dropIfExists('stock_ledger');
        Schema::dropIfExists('dispatch_lines');
        Schema::dropIfExists('dispatch_orders');
        Schema::dropIfExists('branch_warehouse_sources');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('items');
        Schema::dropIfExists('client_user');
        Schema::dropIfExists('clients');
        // Note: users table is NOT dropped here since it's managed by Laravel's default migration
    }
};
