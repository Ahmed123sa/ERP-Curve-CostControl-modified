<?php
namespace App\Models\Production;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingBatchOutput extends Model
{
    protected $table = 'processing_batch_outputs';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'batch_id', 'batch_day_id', 'item_id', 'qty',
        'effective_cost_per_kg', 'total_cost', 'allocation_pct',
    ];

    protected function casts(): array
    {
        return [
            'qty'                  => 'float',
            'effective_cost_per_kg' => 'float',
            'total_cost'           => 'float',
            'allocation_pct'       => 'float',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class, 'batch_id');
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatchDay::class, 'batch_day_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Item::class, 'item_id');
    }
}
