<?php

namespace App\Models\Financial;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialMonthlySummaryDetail extends Model
{
    use HasTenant;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'summary_id', 'expense_category_id', 'total_amount',
    ];

    protected $casts = [
        'total_amount' => 'float',
    ];

    public function summary(): BelongsTo
    {
        return $this->belongsTo(FinancialMonthlySummary::class, 'summary_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialExpenseCategory::class, 'expense_category_id');
    }
}
