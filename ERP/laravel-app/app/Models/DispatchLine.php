<?php
namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchLine extends Model
{
    use HasTenant;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'order_id', 'item_id', 'warehouse_id',
        'qty', 'total_cost', 'unit_cost'
    ];

    protected $casts = [
        'qty'        => 'float',
        'total_cost' => 'float',
        'unit_cost'  => 'float'
    ];

    public function order(): BelongsTo     { return $this->belongsTo(DispatchOrder::class, 'order_id'); }
    public function item(): BelongsTo      { return $this->belongsTo(Item::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
}
