<?php

namespace App\Models\Financial;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialMonthlySummary extends Model
{
    use HasTenant;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'month', 'year',
        'total_sales', 'total_expenses', 'net_total', 'status',
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'total_sales' => 'float',
        'total_expenses' => 'float',
        'net_total' => 'float',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(FinancialMonthlySummaryDetail::class, 'summary_id');
    }
}
