<?php

namespace App\Models\Financial;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialDailyEntry extends Model
{
    use HasTenant;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'date', 'total_sales', 'total_expenses', 'net_daily', 'notes',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'total_sales' => 'float',
        'total_expenses' => 'float',
        'net_daily' => 'float',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(FinancialDailyEntryDetail::class, 'daily_entry_id');
    }
}
