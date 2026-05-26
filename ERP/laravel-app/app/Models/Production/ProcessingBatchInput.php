<?php
namespace App\Models\Production;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingBatchInput extends Model
{
    protected $table = 'processing_batch_inputs';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'batch_id', 'item_id', 'qty', 'cost_per_kg', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'qty'         => 'float',
            'cost_per_kg' => 'float',
            'line_total'  => 'float',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class, 'batch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Item::class, 'item_id');
    }
}
