<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;

class ActivityObserver
{
    private array $trackableModels = [
        'App\Models\Item',
        'App\Models\Warehouse',
        'App\Models\Branch',
        'App\Models\DispatchOrder',
        'App\Models\MonthlyClosing',
        'App\Models\BranchWarehouseSource',
    ];

    public function created(Model $model): void
    {
        if ($this->isTrackable($model)) {
            ActivityLogger::log(
                action: 'create',
                entityType: $model->getMorphClass(),
                entityId: $model->getKey(),
                newValues: $model->getAttributes()
            );
        }
    }

    public function updated(Model $model): void
    {
        if ($this->isTrackable($model) && $model->wasChanged()) {
            ActivityLogger::log(
                action: 'update',
                entityType: $model->getMorphClass(),
                entityId: $model->getKey(),
                oldValues: $model->getOriginal(),
                newValues: $model->getChanges()
            );
        }
    }

    public function deleted(Model $model): void
    {
        if ($this->isTrackable($model)) {
            ActivityLogger::log(
                action: 'delete',
                entityType: $model->getMorphClass(),
                entityId: $model->getKey(),
                oldValues: $model->getOriginal()
            );
        }
    }

    private function isTrackable(Model $model): bool
    {
        return in_array(get_class($model), $this->trackableModels);
    }
}