<?php

namespace App\Jobs;

use App\Services\Financial\ClosingReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateFinancialClosingReport implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $clientId,
        private int $month,
        private int $year,
    ) {}

    public function handle(ClosingReportService $service): void
    {
        $service->generate($this->clientId, $this->month, $this->year);
    }
}
