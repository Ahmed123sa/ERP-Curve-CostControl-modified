<?php
namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, BelongsToMany};

class Branch extends Model
{
    use HasTenant;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','client_id','name','is_active'];

    public function client(): BelongsTo  { return $this->belongsTo(Client::class); }
    public function sources(): HasMany   { return $this->hasMany(BranchWarehouseSource::class); }
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'branch_warehouse_sources')
            ->withPivot('item_id','priority')->orderByPivot('priority');
    }
}
