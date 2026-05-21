<?php
namespace App\Models\MenuEngineering;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MenuRecipeVersion extends Model
{
    protected $table = 'menu_engineering_recipe_versions';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['recipe_id', 'version_number', 'snapshot', 'notes', 'created_by'];

    protected $casts = ['snapshot' => 'array'];

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
