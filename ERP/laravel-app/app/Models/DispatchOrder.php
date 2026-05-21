<?php
namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class DispatchOrder extends Model
{
    use HasTenant;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'id','client_id','type','date',
        'warehouse_id','branch_id','created_by',
        'status','source_file','notes',
    ];
    protected $casts = ['date' => 'date:Y-m-d'];

    public function client(): BelongsTo    { return $this->belongsTo(Client::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function branch(): BelongsTo    { return $this->belongsTo(Branch::class); }
    public function creator(): BelongsTo   { return $this->belongsTo(User::class, 'created_by'); }
    public function lines(): HasMany       { return $this->hasMany(DispatchLine::class, 'order_id'); }

    public function getTotalCostAttribute(): float
    {
        return $this->lines->sum('total_cost');
    }
}
