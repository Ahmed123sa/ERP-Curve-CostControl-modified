<?php
namespace App\Models\Production;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MarketPrice extends Model
{
    use HasTenant;
    protected $table = 'production_market_prices';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'client_id', 'item_name', 'date', 'price',
    ];

    protected function casts(): array
    {
        return [
            'date'  => 'date:Y-m-d',
            'price' => 'float',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::orderedUuid();
            }
        });
    }
}
