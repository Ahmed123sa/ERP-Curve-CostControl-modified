<?php
namespace App\Models\MenuEngineering;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuImportSession extends Model
{
    use HasTenant;

    protected $table = 'menu_import_sessions';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'client_id', 'branch_id', 'sale_date', 'file_name',
        'total_rows', 'status', 'half_categories', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'half_categories' => 'array',
            'expires_at' => 'datetime',
            'sale_date' => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuImportSessionItem::class, 'session_id');
    }
}
