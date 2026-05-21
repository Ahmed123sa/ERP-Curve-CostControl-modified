<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use App\Observers\ActivityObserver;
use App\Models\Item;
use App\Models\Warehouse;
use App\Models\Branch;
use App\Models\DispatchOrder;
use App\Models\MonthlyClosing;
use App\Models\BranchWarehouseSource;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // تسجيل ActivityObserver لكل الموديلات المتابعة
        Item::observe(ActivityObserver::class);
        Warehouse::observe(ActivityObserver::class);
        Branch::observe(ActivityObserver::class);
        DispatchOrder::observe(ActivityObserver::class);
        MonthlyClosing::observe(ActivityObserver::class);
        BranchWarehouseSource::observe(ActivityObserver::class);
    }
}