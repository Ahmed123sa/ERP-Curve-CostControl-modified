<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientModule extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'client_id', 'module_key', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
