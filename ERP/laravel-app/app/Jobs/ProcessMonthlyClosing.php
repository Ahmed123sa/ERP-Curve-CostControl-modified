<?php

namespace App\Jobs;

use App\Services\CostCalculationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMonthlyClosing implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $clientId,
        private string $warehouseId,
        private string $month,
    ) {}

    public function handle(CostCalculationService $calc): void
    {
        $calc->generateMonthlyClosing($this->clientId, $this->warehouseId, $this->month);
    }
}
