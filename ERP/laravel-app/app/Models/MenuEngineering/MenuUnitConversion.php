<?php
namespace App\Models\MenuEngineering;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MenuUnitConversion extends Model
{
    protected $table = 'menu_engineering_unit_conversions';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['from_unit', 'to_unit', 'factor', 'client_id'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id ??= (string) Str::uuid());
    }
}
