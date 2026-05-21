<?php
namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationMapping extends Model
{
    use HasTenant;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','client_id','source_name','target_type','target_id','confidence'];

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
}
