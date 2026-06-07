<?php
namespace App\Models\MenuEngineering;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MenuReconciliation extends Model
{
    protected $table = 'menu_engineering_reconciliations';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'client_id', 'branch_id', 'from_date', 'to_date', 'sales_data',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'sales_data' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id ??= (string) Str::uuid());
    }

    public function items()
    {
        return $this->hasMany(MenuReconciliationItem::class, 'reconciliation_id');
    }
}
