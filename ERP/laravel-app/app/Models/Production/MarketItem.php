<?php
namespace App\Models\Production;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MarketItem extends Model
{
    protected $table = 'production_market_items';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'client_id', 'item_name', 'unit', 'sort_order',
    ];

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
