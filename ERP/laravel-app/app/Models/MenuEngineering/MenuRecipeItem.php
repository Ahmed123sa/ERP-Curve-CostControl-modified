<?php
namespace App\Models\MenuEngineering;

use App\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MenuRecipeItem extends Model
{
    protected $table = 'menu_engineering_recipe_items';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'recipe_id', 'ingredient_id', 'qty', 'weight_g', 'volume_ml',
        'purchase_unit', 'purchase_unit_price', 'recipe_unit',
        'conversion_factor', 'yield_pct', 'ep_cost', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'weight_g' => 'decimal:4',
        'volume_ml' => 'decimal:4',
        'purchase_unit_price' => 'decimal:4',
        'conversion_factor' => 'decimal:6',
        'yield_pct' => 'decimal:2',
        'ep_cost' => 'decimal:4',
        'line_total' => 'decimal:4',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id ??= (string) Str::uuid());
    }

    public function recipe()
    {
        return $this->belongsTo(MenuRecipe::class, 'recipe_id');
    }

    public function ingredient()
    {
        return $this->belongsTo(Item::class, 'ingredient_id');
    }
}
