<?php
namespace App\Models;
use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Item extends Model
{
    use HasTenant;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','client_id','default_warehouse_id','name','unit','category','is_active', 'default_cost', 'sort_order'];

    public function client(): BelongsTo     { return $this->belongsTo(Client::class); }
    public function warehouse(): BelongsTo  { return $this->belongsTo(Warehouse::class, 'default_warehouse_id'); }
    public function ledgerEntries(): HasMany { return $this->hasMany(StockLedger::class); }
    public function mappings(): HasMany     { return $this->hasMany(ItemMapping::class); }
}
