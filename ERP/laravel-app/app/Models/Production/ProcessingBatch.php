<?php
namespace App\Models\Production;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessingBatch extends Model
{
    use HasTenant;

    protected $table = 'processing_batches';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'date', 'name', 'processes',
        'total_input_cost', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date'             => 'date:Y-m-d',
            'processes'        => 'array',
            'total_input_cost' => 'float',
        ];
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(ProcessingBatchInput::class, 'batch_id');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(ProcessingBatchOutput::class, 'batch_id');
    }
}
