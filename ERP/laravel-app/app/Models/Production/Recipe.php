<?php
namespace App\Models\Production;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recipe extends Model
{
    use HasTenant;

    protected $table = 'production_recipes';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'item_id', 'name', 'unit',
        'qty_per_portion', 'production_qty', 'selling_price',
        'output_warehouse_id', 'notes',
    ];

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class, 'recipe_id');
    }

    public function outputItem(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Item::class, 'item_id');
    }

    public function outputWarehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Warehouse::class, 'output_warehouse_id');
    }
}
