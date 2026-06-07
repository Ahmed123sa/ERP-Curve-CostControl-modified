<?php
namespace App\Models\MenuEngineering;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MenuImportSessionItem extends Model
{
    protected $table = 'menu_import_session_items';
    protected $keyType = 'string';
    public $incrementing = false;

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id ??= (string) Str::uuid());
    }

    protected $fillable = [
        'session_id', 'row_index', 'source_name', 'qty_sold',
        'category', 'size', 'recipe_id', 'recipe_name', 'status', 'confidence',
    ];

    protected function casts(): array
    {
        return [
            'qty_sold' => 'decimal:2',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(MenuImportSession::class, 'session_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(MenuRecipe::class, 'recipe_id');
    }
}
