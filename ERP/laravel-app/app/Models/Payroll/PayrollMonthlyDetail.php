<?php

namespace App\Models\Payroll;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollMonthlyDetail extends Model
{
    use HasTenant;

    protected $table = 'payroll_monthly_details';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'payroll_id', 'employee_id',
        'base_salary_snapshot', 'daily_wage_snapshot', 'hourly_wage_snapshot',
        'work_days', 'absence_days', 'absence_amount',
        'overtime_hours', 'overtime_amount',
        'rest_day_ot_days', 'rest_day_ot_amount',
        'rest_days_taken', 'double_shift_days', 'double_shift_amount',
        'advance_amount', 'bonus_total', 'total_deductions', 'net_salary',
    ];

    protected $casts = [
        'base_salary_snapshot' => 'float',
        'daily_wage_snapshot' => 'float',
        'hourly_wage_snapshot' => 'float',
        'work_days' => 'integer',
        'absence_days' => 'integer',
        'absence_amount' => 'float',
        'overtime_hours' => 'float',
        'overtime_amount' => 'float',
        'rest_day_ot_days' => 'integer',
        'rest_day_ot_amount' => 'float',
        'rest_days_taken' => 'integer',
        'double_shift_days' => 'integer',
        'double_shift_amount' => 'float',
        'advance_amount' => 'float',
        'bonus_total' => 'float',
        'total_deductions' => 'float',
        'net_salary' => 'float',
    ];

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(PayrollMonthly::class, 'payroll_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class, 'employee_id');
    }

    public function bonusItems(): HasMany
    {
        return $this->hasMany(PayrollBonusItem::class, 'payroll_detail_id');
    }
}
