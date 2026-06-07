<?php
namespace App\Models\MenuEngineering;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ColumnMapping extends Model
{
    use HasTenant;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'client_id',
        'branch_id',
        'column_mapping',
    ];

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Client::class);
    }
}
