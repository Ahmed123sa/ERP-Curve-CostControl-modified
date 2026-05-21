<?php
namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemMapping extends Model
{
    use HasTenant;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','client_id','source_name','item_id','context','confidence','usage_count'];

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function item(): BelongsTo   { return $this->belongsTo(Item::class); }
}
