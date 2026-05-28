<?php

namespace App\Models\Financial;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAdvance extends Model
{
    use HasTenant;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'employee_id', 'date', 'amount', 'notes',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'amount' => 'float',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(FinancialEmployee::class, 'employee_id');
    }
}
