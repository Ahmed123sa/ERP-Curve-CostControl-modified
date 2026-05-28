<?php

namespace App\Models\Financial;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialClosingReport extends Model
{
    use HasTenant;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'month', 'year',
        'total_sales', 'total_purchases', 'total_expenses',
        'net_cash_profit', 'net_profit', 'percentages_json', 'status',
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'total_sales' => 'float',
        'total_purchases' => 'float',
        'total_expenses' => 'float',
        'net_cash_profit' => 'float',
        'net_profit' => 'float',
        'percentages_json' => 'json',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(FinancialClosingReportDetail::class, 'closing_report_id');
    }
}
