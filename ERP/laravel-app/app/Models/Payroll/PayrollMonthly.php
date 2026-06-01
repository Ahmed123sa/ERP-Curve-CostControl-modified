<?php

namespace App\Models\Payroll;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollMonthly extends Model
{
    use HasTenant;

    protected $table = 'payroll_monthly';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'month', 'year', 'status', 'salary_base_days',
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'salary_base_days' => 'integer',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(PayrollMonthlyDetail::class, 'payroll_id');
    }
}
