<?php
namespace App\Models\MenuEngineering;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MenuEngineeringMenu extends Model
{
    use HasTenant;
    protected $table = 'menu_engineering_menus';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'client_id', 'branch_id', 'name', 'sort_order',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id ??= (string) Str::uuid());
    }

    public function recipes()
    {
        return $this->hasMany(MenuRecipe::class, 'menu_id');
    }
}
