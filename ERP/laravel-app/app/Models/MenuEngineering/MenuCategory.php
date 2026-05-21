<?php
namespace App\Models\MenuEngineering;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MenuCategory extends Model
{
    protected $table = 'menu_engineering_categories';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['client_id', 'menu_id', 'name', 'sort_order'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id ??= (string) Str::uuid());
    }
}
