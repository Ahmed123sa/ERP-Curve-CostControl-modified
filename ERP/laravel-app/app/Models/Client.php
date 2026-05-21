<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{HasMany, BelongsToMany};

class Client extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','name','slug','is_active'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_user')
            ->withPivot('is_primary')->withTimestamps();
    }
    public function warehouses(): HasMany { return $this->hasMany(Warehouse::class); }
    public function branches(): HasMany   { return $this->hasMany(Branch::class); }
    public function items(): HasMany      { return $this->hasMany(Item::class); }
}
