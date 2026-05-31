<?php

namespace App\Models\Financial;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FinancialClosingReportDetail extends Model
{
    use HasTenant;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'closing_report_id',
        'line_type', 'row_type', 'name', 'amount', 'percentage',
        'formula_config', 'parent_id', 'category_id', 'sort_order',
        'row_key',
    ];

    protected $casts = [
        'amount' => 'float',
        'percentage' => 'float',
        'sort_order' => 'integer',
        'formula_config' => 'json',
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

    public function report(): BelongsTo
    {
        return $this->belongsTo(FinancialClosingReport::class, 'closing_report_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinancialClosingReportDetailItem::class, 'detail_id');
    }
}
