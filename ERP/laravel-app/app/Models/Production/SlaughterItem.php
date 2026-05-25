<?php
namespace App\Models\Production;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaughterItem extends Model
{
    protected $table = 'slaughter_items';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'slaughter_id', 'item_id', 'warehouse_id', 'unit',
        'weight', 'selling_price', 'total',
        'allocation_pct', 'actual_cost_per_kg', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'weight'            => 'float',
            'selling_price'     => 'float',
            'total'             => 'float',
            'allocation_pct'    => 'float',
            'actual_cost_per_kg' => 'float',
        ];
    }

    public function slaughter(): BelongsTo
    {
        return $this->belongsTo(Slaughter::class, 'slaughter_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Item::class, 'item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Warehouse::class, 'warehouse_id');
    }
}
