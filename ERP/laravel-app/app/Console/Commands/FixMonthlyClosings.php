<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\MonthlyClosing;
use Illuminate\Console\Command;

class FixMonthlyClosings extends Command
{
    protected $signature = 'closing:fix';
    protected $description = 'Fix negative closing values and corrupted diffs in monthly_closings';

    public function handle(): int
    {
        $total = MonthlyClosing::count();
        $fixed = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        MonthlyClosing::chunk(100, function ($records) use (&$fixed, $bar) {
            foreach ($records as $mc) {
                $changed = false;

                $oldTheoretical = $mc->closing_qty_theoretical;
                $oldClosingVal = $mc->closing_value;
                $oldDiffQty = $mc->diff_qty;
                $oldDiffVal = $mc->diff_value;

                $closingQty = (float) $mc->closing_qty_theoretical;

                if ($mc->avg_cost <= 0) {
                    $item = Item::find($mc->item_id);
                    $avgCost = (float) ($item->default_cost ?? 0);
                } else {
                    $avgCost = (float) $mc->avg_cost;
                }

                $closingValue = round($closingQty * $avgCost, 2);

                if ($closingQty !== $oldTheoretical || $closingValue !== $oldClosingVal) {
                    $mc->closing_qty_theoretical = $closingQty;
                    $mc->closing_value = $closingValue;
                    $changed = true;
                }

                if ($mc->closing_qty_actual !== null) {
                    $diffQty = round((float) $mc->closing_qty_actual - $closingQty, 3);
                    $diffVal = round($diffQty * $avgCost, 2);
                    if ($diffQty !== $oldDiffQty || $diffVal !== $oldDiffVal) {
                        $mc->diff_qty = $diffQty;
                        $mc->diff_value = $diffVal;
                        $changed = true;
                    }
                }

                if ($changed) {
                    $mc->save();
                    $fixed++;
                }
            }
            $bar->advance(count($records));
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done. $fixed records fixed out of $total total.");

        return self::SUCCESS;
    }
}
