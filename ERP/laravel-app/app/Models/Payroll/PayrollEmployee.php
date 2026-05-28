<?php

namespace App\Models\Payroll;

use App\Models\Financial\FinancialEmployee;
use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEmployee extends Model
{
    use HasTenant;

    protected $table = 'payroll_employees';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'name', 'job_title', 'base_salary', 'shift_hours',
        'daily_wage', 'hourly_wage', 'is_active', 'financial_employee_id',
    ];

    protected $casts = [
        'base_salary' => 'float',
        'shift_hours' => 'float',
        'daily_wage' => 'float',
        'hourly_wage' => 'float',
        'is_active' => 'boolean',
    ];

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'employee_id');
    }

    public function financialEmployee(): BelongsTo
    {
        return $this->belongsTo(FinancialEmployee::class, 'financial_employee_id');
    }
}
