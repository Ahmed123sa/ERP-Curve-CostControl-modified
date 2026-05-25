<?php
namespace App\Models\Production;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Slaughter extends Model
{
    use HasTenant;

    protected $table = 'slaughters';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'date', 'animal_name',
        'live_weight', 'price_per_kg', 'transport_slaughter_cost',
        'total_cost', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date'                     => 'date:Y-m-d',
            'live_weight'              => 'float',
            'price_per_kg'             => 'float',
            'transport_slaughter_cost' => 'float',
            'total_cost'               => 'float',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SlaughterItem::class, 'slaughter_id')->orderBy('sort_order');
    }
}
