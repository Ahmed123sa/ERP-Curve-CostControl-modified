<?php

namespace App\Models\Financial;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FinancialClosingReportDetailItem extends Model
{
    use HasTenant;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'closing_report_id', 'detail_id',
        'name', 'amount', 'sort_order',
    ];

    protected $casts = [
        'amount' => 'float',
        'sort_order' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(FinancialClosingReportDetail::class, 'detail_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(FinancialClosingReport::class, 'closing_report_id');
    }
}
