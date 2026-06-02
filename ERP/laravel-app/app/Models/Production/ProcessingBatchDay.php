<?php
namespace App\Models\Production;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessingBatchDay extends Model
{
    use HasTenant;

    protected $table = 'processing_batch_days';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'batch_id', 'date', 'processes', 'notes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'date'      => 'date:Y-m-d',
            'processes' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class, 'batch_id');
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(ProcessingBatchInput::class, 'batch_day_id');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(ProcessingBatchOutput::class, 'batch_day_id');
    }
}
