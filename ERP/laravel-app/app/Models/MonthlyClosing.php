<?php
namespace App\Models;
use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyClosing extends Model
{
    use HasTenant;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'id', 'client_id', 'warehouse_id', 'item_id', 'month',
        'opening_qty', 'opening_value',
        'purchases_qty', 'purchases_value',
        'internal_in_qty', 'internal_out_qty',
        'consumption_qty',
        'in_qty', 'in_value', 'out_qty', 'avg_cost',
        'branch_dispatches',
        'closing_qty_theoretical', 'closing_qty_actual', 'physical_count',
        'closing_value', 'diff_qty', 'diff_value',
        'is_locked', 'locked_by', 'locked_at',
    ];
    protected $casts = [
        'is_locked'         => 'boolean',
        'locked_at'         => 'datetime',
        'opening_qty'       => 'float',
        'opening_value'     => 'float',
        'purchases_qty'     => 'float',
        'purchases_value'   => 'float',
        'internal_in_qty'   => 'float',
        'internal_out_qty'  => 'float',
        'consumption_qty'   => 'float',
        'in_qty'            => 'float',
        'in_value'          => 'float',
        'out_qty'           => 'float',
        'avg_cost'          => 'float',
        'branch_dispatches' => 'array',
        'closing_qty_theoretical' => 'float',
        'closing_qty_actual'      => 'float',
        'physical_count'          => 'float',
        'closing_value'     => 'float',
        'diff_qty'          => 'float',
        'diff_value'        => 'float',
    ];

    public function client(): BelongsTo    { return $this->belongsTo(Client::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function item(): BelongsTo      { return $this->belongsTo(Item::class); }
    public function lockedBy(): BelongsTo  { return $this->belongsTo(User::class, 'locked_by'); }
}
