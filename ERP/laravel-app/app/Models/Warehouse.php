<?php
namespace App\Models;
use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Warehouse extends Model
{
    use HasFactory, HasTenant;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','client_id','name','type','is_active'];

    public function client(): BelongsTo           { return $this->belongsTo(Client::class); }
    public function ledgerEntries(): HasMany    { return $this->hasMany(StockLedger::class); }
    public function branchSources(): HasMany    { return $this->hasMany(BranchWarehouseSource::class); }
    public function linkedBranches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_warehouse_sources', 'warehouse_id', 'branch_id');
    }
}
