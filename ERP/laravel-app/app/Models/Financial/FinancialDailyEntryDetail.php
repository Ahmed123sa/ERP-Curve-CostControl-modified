<?php

namespace App\Models\Financial;

use App\Models\Item;
use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialDailyEntryDetail extends Model
{
    use HasTenant;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'daily_entry_id', 'expense_category_id',
        'amount', 'description', 'quantity', 'item_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'quantity' => 'float',
    ];

    public function dailyEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialDailyEntry::class, 'daily_entry_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialExpenseCategory::class, 'expense_category_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
