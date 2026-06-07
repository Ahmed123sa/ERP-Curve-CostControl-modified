<?php
namespace App\Models\MenuEngineering;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MenuReconciliationItem extends Model
{
    protected $table = 'menu_engineering_reconciliation_items';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'reconciliation_id', 'ingredient_id', 'ingredient_name', 'unit',
        'opening_qty', 'purchases_qty', 'closing_actual',
        'actual_received', 'sales_qty', 'waste_qty', 'diff_qty',
    ];

    protected $casts = [
        'opening_qty' => 'decimal:4',
        'purchases_qty' => 'decimal:4',
        'closing_actual' => 'decimal:4',
        'actual_received' => 'decimal:4',
        'sales_qty' => 'decimal:4',
        'waste_qty' => 'decimal:4',
        'diff_qty' => 'decimal:4',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id ??= (string) Str::uuid());
    }

    public function reconciliation()
    {
        return $this->belongsTo(MenuReconciliation::class, 'reconciliation_id');
    }
}
