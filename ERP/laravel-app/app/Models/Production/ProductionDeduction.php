<?php
namespace App\Models\Production;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

class ProductionDeduction extends Model
{
    use HasTenant;

    protected $table = 'production_deductions';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'recipe_id', 'month', 'deduct',
    ];

    protected function casts(): array
    {
        return [
            'deduct' => 'boolean',
        ];
    }
}
