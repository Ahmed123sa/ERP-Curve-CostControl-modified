<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_engineering_unit_conversions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('from_unit', 20);
            $table->string('to_unit', 20);
            $table->decimal('factor', 15, 6);
            $table->uuid('client_id')->nullable();
            $table->timestamps();
            $table->unique(['from_unit', 'to_unit', 'client_id'], 'menu_unit_conv_unique');
        });

        $conversions = [
            ['kg', 'g', 1000], ['g', 'kg', 0.001],
            ['liter', 'ml', 1000], ['ml', 'liter', 0.001],
            ['kg', 'lb', 2.20462], ['lb', 'kg', 0.453592],
            ['dozen', 'each', 12], ['each', 'dozen', 0.083333],
            ['case', 'each', 1], ['each', 'case', 1],
            ['kg', 'each', 1], ['each', 'kg', 1],
            ['liter', 'each', 1], ['each', 'liter', 1],
        ];
        foreach ($conversions as [$from, $to, $factor]) {
            DB::table('menu_engineering_unit_conversions')->insert([
                'id' => (string) Str::uuid(),
                'from_unit' => $from, 'to_unit' => $to, 'factor' => $factor,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('menu_engineering_recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('branch_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('category', 50)->nullable();
            $table->string('recipe_type', 20)->default('simple');
            $table->decimal('portions', 10, 2)->default(1);
            $table->decimal('selling_price', 15, 4)->nullable();
            $table->decimal('target_food_cost_pct', 5, 2)->nullable();
            $table->text('prep_instructions')->nullable();
            $table->string('status', 20)->default('draft');
            $table->integer('version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('client_id');
            $table->index('branch_id');
            $table->index('status');
        });

        Schema::create('menu_engineering_recipe_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recipe_id');
            $table->uuid('ingredient_id');
            $table->decimal('qty', 15, 4);
            $table->decimal('weight_g', 15, 4)->nullable();
            $table->decimal('volume_ml', 15, 4)->nullable();
            $table->string('purchase_unit', 20);
            $table->decimal('purchase_unit_price', 15, 4);
            $table->string('recipe_unit', 20);
            $table->decimal('conversion_factor', 15, 6);
            $table->decimal('yield_pct', 7, 2)->default(100);
            $table->decimal('ep_cost', 15, 4)->default(0);
            $table->decimal('line_total', 15, 4)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->foreign('recipe_id')->references('id')->on('menu_engineering_recipes')->cascadeOnDelete();
            $table->index('ingredient_id');
        });

        Schema::create('menu_engineering_recipe_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recipe_id');
            $table->integer('version_number');
            $table->json('snapshot');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->foreign('recipe_id')->references('id')->on('menu_engineering_recipes')->cascadeOnDelete();
            $table->unique(['recipe_id', 'version_number'], 'menu_recipe_ver_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_engineering_recipe_versions');
        Schema::dropIfExists('menu_engineering_recipe_items');
        Schema::dropIfExists('menu_engineering_recipes');
        Schema::dropIfExists('menu_engineering_unit_conversions');
    }
};
