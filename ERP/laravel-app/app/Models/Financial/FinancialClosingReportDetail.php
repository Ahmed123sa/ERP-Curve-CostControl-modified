<?php

namespace App\Models\Financial;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialClosingReportDetail extends Model
{
    use HasTenant;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'closing_report_id',
        'line_type', 'name', 'amount', 'percentage', 'sort_order',
    ];

    protected $casts = [
        'amount' => 'float',
        'percentage' => 'float',
        'sort_order' => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(FinancialClosingReport::class, 'closing_report_id');
    }
}
