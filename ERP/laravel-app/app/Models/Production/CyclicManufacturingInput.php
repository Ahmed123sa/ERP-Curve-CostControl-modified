<?php
namespace App\Models\Production;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CyclicManufacturingInput extends Model
{
    protected $table = 'cyclic_manufacturing_inputs';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'cyclic_id', 'item_id', 'unit', 'cost_per_unit',
        'qty_json', 'total_qty', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'cost_per_unit' => 'float',
            'qty_json'      => 'array',
            'total_qty'     => 'float',
            'line_total'    => 'float',
        ];
    }

    public function cyclic(): BelongsTo
    {
        return $this->belongsTo(CyclicManufacturing::class, 'cyclic_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Item::class, 'item_id');
    }
}
