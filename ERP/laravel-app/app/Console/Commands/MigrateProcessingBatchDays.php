<?php

namespace App\Console\Commands;

use App\Models\Production\ProcessingBatch;
use App\Models\Production\ProcessingBatchInput;
use App\Models\Production\ProcessingBatchOutput;
use App\Models\Production\ProcessingBatchDay;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MigrateProcessingBatchDays extends Command
{
    protected $signature = 'processing:migrate-days';
    protected $description = 'Migrate existing processing batches to use batch_days structure';

    public function handle(): int
    {
        $batches = ProcessingBatch::with('inputs', 'outputs')
            ->orderBy('name')
            ->orderBy('date')
            ->get();

        $grouped = $batches->groupBy('name');
        $kept = 0;
        $migrated = 0;

        foreach ($grouped as $name => $group) {
            $primary = $group->shift();

            foreach ($group as $batch) {
                $processes = $batch->processes;
                if (is_string($processes)) $processes = json_decode($processes, true);
                $day = ProcessingBatchDay::create([
                    'id'        => (string) Str::orderedUuid(),
                    'client_id' => $batch->client_id,
                    'batch_id'  => $primary->id,
                    'date'      => $batch->date,
                    'processes' => $processes,
                    'notes'     => $batch->notes,
                    'sort_order'=> 0,
                ]);

                ProcessingBatchInput::where('batch_id', $batch->id)
                    ->update(['batch_id' => $primary->id, 'batch_day_id' => $day->id]);

                ProcessingBatchOutput::where('batch_id', $batch->id)
                    ->update(['batch_id' => $primary->id, 'batch_day_id' => $day->id]);

                $batch->delete();
                $migrated++;
            }

            $processes = $primary->processes;
            if (is_string($processes)) $processes = json_decode($processes, true);
            $day = ProcessingBatchDay::create([
                'id'        => (string) Str::orderedUuid(),
                'client_id' => $primary->client_id,
                'batch_id'  => $primary->id,
                'date'      => $primary->date,
                'processes' => $processes,
                'notes'     => $primary->notes,
                'sort_order'=> 0,
            ]);

            ProcessingBatchInput::where('batch_id', $primary->id)
                ->update(['batch_day_id' => $day->id]);

            ProcessingBatchOutput::where('batch_id', $primary->id)
                ->update(['batch_day_id' => $day->id]);

            $primary->update([
                'date' => null,
                'processes' => null,
            ]);

            $kept++;
        }

        $this->info("Kept $kept batches, migrated $migrated duplicates.");
        return Command::SUCCESS;
    }
}
