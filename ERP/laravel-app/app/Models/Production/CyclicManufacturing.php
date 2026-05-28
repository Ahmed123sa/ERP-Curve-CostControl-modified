<?php
namespace App\Models\Production;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CyclicManufacturing extends Model
{
    use HasTenant;

    protected $table = 'cyclic_manufacturing';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'item_id', 'month',
        'total_output_qty', 'total_input_cost', 'avg_unit_cost',
        'output_ratio', 'output_qty_json', 'posted_to_production',
    ];

    protected function casts(): array
    {
        return [
            'total_output_qty'      => 'float',
            'total_input_cost'      => 'float',
            'avg_unit_cost'         => 'float',
            'output_ratio'          => 'float',
            'output_qty_json'       => 'array',
            'posted_to_production'  => 'boolean',
        ];
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(CyclicManufacturingInput::class, 'cyclic_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Item::class, 'item_id');
    }
}
