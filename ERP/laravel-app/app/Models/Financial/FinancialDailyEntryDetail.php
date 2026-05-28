<?php

namespace App\Models\Financial;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialDailyEntryDetail extends Model
{
    use HasTenant;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'daily_entry_id', 'expense_category_id', 'amount', 'description',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function dailyEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialDailyEntry::class, 'daily_entry_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialExpenseCategory::class, 'expense_category_id');
    }
}
