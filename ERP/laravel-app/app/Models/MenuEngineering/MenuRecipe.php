<?php
namespace App\Models\MenuEngineering;

use App\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MenuRecipe extends Model
{
    use SoftDeletes;

    protected $table = 'menu_engineering_recipes';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'client_id', 'branch_id', 'menu_id', 'name', 'code', 'category', 'recipe_type',
        'portions', 'selling_price', 'target_food_cost_pct', 'prep_instructions',
        'status', 'version', 'created_by',
        'exclude_from_reconciliation', 'exclude_from_menu',
    ];

    protected $casts = [
        'portions' => 'decimal:2',
        'selling_price' => 'decimal:4',
        'target_food_cost_pct' => 'decimal:2',
        'exclude_from_reconciliation' => 'boolean',
        'exclude_from_menu' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id ??= (string) Str::uuid());
    }

    public function items()
    {
        return $this->hasMany(MenuRecipeItem::class, 'recipe_id')->orderBy('sort_order');
    }

    public function versions()
    {
        return $this->hasMany(MenuRecipeVersion::class, 'recipe_id');
    }

    public function getTotalCostAttribute(): float
    {
        return (float) $this->items()->sum('line_total');
    }

    public function getCostPerPortionAttribute(): float
    {
        $p = (float) $this->portions;
        return $p > 0 ? round($this->total_cost / $p, 4) : 0;
    }

    public function getFoodCostPctAttribute(): float
    {
        $price = (float) $this->selling_price;
        return $price > 0 ? round(($this->total_cost / $price) * 100, 2) : 0;
    }

    public function getMarginPctAttribute(): float
    {
        return round(100 - $this->food_cost_pct, 2);
    }

    public function getIdealSellingPriceAttribute(): float
    {
        $target = (float) ($this->target_food_cost_pct ?: 30);
        return $target > 0 ? round($this->total_cost / ($target / 100), 2) : 0;
    }
}
