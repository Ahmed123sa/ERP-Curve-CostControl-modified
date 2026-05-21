<?php
namespace App\Models\MenuEngineering;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MenuSale extends Model
{
    protected $table = 'menu_engineering_sales';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'client_id', 'branch_id', 'recipe_id', 'qty_sold',
        'selling_price', 'sale_date', 'notes',
    ];

    protected $casts = [
        'qty_sold' => 'decimal:2',
        'selling_price' => 'decimal:4',
        'sale_date' => 'date',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id ??= (string) Str::uuid());
    }

    public function recipe()
    {
        return $this->belongsTo(MenuRecipe::class, 'recipe_id');
    }
}
