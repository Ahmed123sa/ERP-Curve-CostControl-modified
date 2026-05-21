<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait HasTenant
{
    protected static function bootHasTenant()
    {
        // إضافة العميل الحالي وتوليد UUID تلقائياً عند الإنشاء
        static::creating(function ($model) {
            // توليد UUID لو مش موجود
            if (empty($model->id)) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }

            if (Auth::check() && !$model->client_id) {
                $model->client_id = Auth::user()->current_client_id;
            }
        });

        // فلترة البيانات تلقائياً بناءً على العميل المختار
        static::addGlobalScope('client', function (Builder $builder) {
            if (Auth::check()) {
                $builder->where('client_id', Auth::user()->current_client_id);
            }
        });
    }

    /**
     * تجاوز الفلترة عند الحاجة (مثلاً للتقارير المجمعة للمدير)
     */
    public function scopeAllClients($query)
    {
        return $query->withoutGlobalScope('client');
    }
}
