<?php

namespace App\Models\Payroll;

use App\Models\Client;
use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected $appends = ['client_name'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function getClientNameAttribute(): string
    {
        return $this->client?->name ?? '';
    }

    public function details(): HasMany
    {
        return $this->hasMany(PayrollMonthlyDetail::class, 'payroll_id');
    }
}
