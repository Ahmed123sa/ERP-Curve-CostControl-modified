<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasTenant;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'id', 'client_id', 'user_id',
        'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'ip_address',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function user(): BelongsTo  { return $this->belongsTo(User::class); }
}