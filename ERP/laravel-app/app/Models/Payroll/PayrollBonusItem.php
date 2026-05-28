<?php

namespace App\Models\Payroll;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollBonusItem extends Model
{
    use HasTenant;

    protected $table = 'payroll_bonus_items';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'payroll_detail_id', 'name', 'amount',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function payrollDetail(): BelongsTo
    {
        return $this->belongsTo(PayrollMonthlyDetail::class, 'payroll_detail_id');
    }
}
