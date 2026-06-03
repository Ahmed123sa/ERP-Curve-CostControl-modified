<?php
namespace App\Models\Production;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyProduction extends Model
{
    use HasTenant;

    protected $table = 'daily_production';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'recipe_id', 'date', 'qty', 'warehouse_id', 'notes', 'size_index',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'qty'  => 'float',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Warehouse::class, 'warehouse_id');
    }
}
