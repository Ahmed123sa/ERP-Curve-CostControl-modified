<?php
// ============================================================
// Models — كل الـ models في ملف واحد للمرجعية
// في المشروع الفعلي: كل model في ملفه في app/Models/
// ============================================================

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, BelongsToMany};
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

// ── Client (Tenant) ──────────────────────────────────────────
class Client extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','name','slug','is_active'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_user')
            ->withPivot('is_primary')
            ->withTimestamps();
    }
    public function warehouses(): HasMany { return $this->hasMany(Warehouse::class); }
    public function branches(): HasMany   { return $this->hasMany(Branch::class); }
    public function items(): HasMany      { return $this->hasMany(Item::class); }
}

// ── User ─────────────────────────────────────────────────────
class User extends Authenticatable
{
    use HasApiTokens;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','name','email','password','role'];
    protected $hidden   = ['password'];

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_user')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    // العميل الحالي المختار للموظف
    public function getCurrentClientAttribute(): ?Client
    {
        $id = session('current_client_id') ?? $this->clients()->wherePivot('is_primary', true)->first()?->id;
        return $this->clients()->find($id);
    }
}

// ── Item ─────────────────────────────────────────────────────
class Item extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','client_id','name','unit','category','is_active'];

    public function client(): BelongsTo    { return $this->belongsTo(Client::class); }
    public function ledgerEntries(): HasMany { return $this->hasMany(StockLedger::class); }
    public function mappings(): HasMany    { return $this->hasMany(ItemMapping::class); }
}

// ── Warehouse ────────────────────────────────────────────────
class Warehouse extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','client_id','name','type','is_active'];

    public function client(): BelongsTo        { return $this->belongsTo(Client::class); }
    public function ledgerEntries(): HasMany   { return $this->hasMany(StockLedger::class); }
    public function branchSources(): HasMany   { return $this->hasMany(BranchWarehouseSource::class); }
}

// ── Branch ───────────────────────────────────────────────────
class Branch extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','client_id','name','is_active'];

    public function client(): BelongsTo  { return $this->belongsTo(Client::class); }
    public function sources(): HasMany   { return $this->hasMany(BranchWarehouseSource::class); }

    // المخازن المصدر للفرع ده
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'branch_warehouse_sources')
            ->withPivot('item_id', 'priority')
            ->orderByPivot('priority');
    }
}

// ── BranchWarehouseSource ────────────────────────────────────
class BranchWarehouseSource extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','branch_id','warehouse_id','item_id','priority'];

    public function branch(): BelongsTo   { return $this->belongsTo(Branch::class); }
    public function warehouse(): BelongsTo{ return $this->belongsTo(Warehouse::class); }
    public function item(): BelongsTo    { return $this->belongsTo(Item::class); }
}

// ── DispatchOrder ────────────────────────────────────────────
class DispatchOrder extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'id','client_id','type','date',
        'warehouse_id','branch_id','created_by',
        'status','source_file','notes',
    ];
    protected $casts = ['date' => 'date'];

    public function client(): BelongsTo   { return $this->belongsTo(Client::class); }
    public function warehouse(): BelongsTo{ return $this->belongsTo(Warehouse::class); }
    public function branch(): BelongsTo   { return $this->belongsTo(Branch::class); }
    public function creator(): BelongsTo  { return $this->belongsTo(User::class, 'created_by'); }
    public function lines(): HasMany      { return $this->hasMany(DispatchLine::class, 'order_id'); }

    public function getTotalCostAttribute(): float
    {
        return $this->lines->sum('total_cost');
    }
}

// ── DispatchLine ─────────────────────────────────────────────
class DispatchLine extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','order_id','item_id','warehouse_id','qty','total_cost','unit_cost'];
    protected $casts    = ['qty'=>'float','total_cost'=>'float','unit_cost'=>'float'];

    public function order(): BelongsTo    { return $this->belongsTo(DispatchOrder::class, 'order_id'); }
    public function item(): BelongsTo     { return $this->belongsTo(Item::class); }
    public function warehouse(): BelongsTo{ return $this->belongsTo(Warehouse::class); }
}

// ── StockLedger ──────────────────────────────────────────────
class StockLedger extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'id','client_id','warehouse_id','item_id',
        'date','movement_type','qty',
        'unit_cost','total_cost',
        'ref_type','ref_id',
    ];
    protected $casts = ['date'=>'date','qty'=>'float','unit_cost'=>'float','total_cost'=>'float'];

    public function client(): BelongsTo   { return $this->belongsTo(Client::class); }
    public function warehouse(): BelongsTo{ return $this->belongsTo(Warehouse::class); }
    public function item(): BelongsTo     { return $this->belongsTo(Item::class); }
}

// ── ItemMapping ──────────────────────────────────────────────
class ItemMapping extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','client_id','source_name','item_id','context','confidence','usage_count'];

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function item(): BelongsTo   { return $this->belongsTo(Item::class); }
}

// ── LocationMapping ──────────────────────────────────────────
class LocationMapping extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','client_id','source_name','target_type','target_id','confidence'];

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
}

// ── MonthlyClosing ───────────────────────────────────────────
class MonthlyClosing extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'id','client_id','warehouse_id','item_id','month',
        'opening_qty','opening_value',
        'in_qty','in_value',
        'out_qty',
        'avg_cost',
        'closing_qty_theoretical','closing_qty_actual',
        'closing_value',
        'diff_qty','diff_value',
        'is_locked','locked_by','locked_at',
    ];
    protected $casts = [
        'is_locked'  => 'boolean',
        'locked_at'  => 'datetime',
        'opening_qty'=> 'float', 'opening_value'=>'float',
        'in_qty'     => 'float', 'in_value'=>'float',
        'out_qty'    => 'float', 'avg_cost'=>'float',
        'closing_qty_theoretical'=>'float',
        'closing_qty_actual'    =>'float',
        'closing_value'=>'float',
        'diff_qty'=>'float','diff_value'=>'float',
    ];

    public function client(): BelongsTo   { return $this->belongsTo(Client::class); }
    public function warehouse(): BelongsTo{ return $this->belongsTo(Warehouse::class); }
    public function item(): BelongsTo     { return $this->belongsTo(Item::class); }
    public function lockedBy(): BelongsTo { return $this->belongsTo(User::class, 'locked_by'); }
}
