<?php
namespace App\Models;
use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use App\Models\Financial\FinancialExpenseCategory;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Item extends Model
{
    use HasTenant;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','client_id','default_warehouse_id','name','unit','category','expense_category_id','is_active', 'default_cost', 'sort_order', 'min_stock_level', 'linked_branch_id'];

    public function client(): BelongsTo     { return $this->belongsTo(Client::class); }
    public function warehouse(): BelongsTo  { return $this->belongsTo(Warehouse::class, 'default_warehouse_id'); }
    public function linkedBranch(): BelongsTo { return $this->belongsTo(Branch::class, 'linked_branch_id'); }
    public function ledgerEntries(): HasMany { return $this->hasMany(StockLedger::class); }
    public function mappings(): HasMany     { return $this->hasMany(ItemMapping::class); }
    public function expenseCategory(): BelongsTo { return $this->belongsTo(FinancialExpenseCategory::class, 'expense_category_id'); }
}
