<?php

namespace App\Console\Commands;

use App\Models\MenuEngineering\MenuEngineeringMenu;
use App\Models\MenuEngineering\MenuRecipe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanMenuEngineeringDuplicates extends Command
{
    protected $signature = 'menu:clean-duplicates';
    protected $description = 'Clean duplicate recipes where same name exists with NULL and non-NULL branch_id/menu_id';

    public function handle(): int
    {
        $this->info('Finding duplicate recipes (same name, same branch, same or NULL menu)...');

        $pairs = DB::select('
            SELECT r1.id as id1, r2.id as id2, r1.name,
                   (SELECT COUNT(*) FROM menu_engineering_recipe_items WHERE recipe_id = r1.id) as items1,
                   (SELECT COUNT(*) FROM menu_engineering_recipe_items WHERE recipe_id = r2.id) as items2
            FROM menu_engineering_recipes r1
            JOIN menu_engineering_recipes r2
              ON r1.client_id = r2.client_id AND r1.name = r2.name AND r1.id < r2.id
            WHERE (r1.branch_id IS NULL OR r2.branch_id IS NULL OR r1.branch_id = r2.branch_id)
              AND (r1.menu_id IS NULL OR r2.menu_id IS NULL OR r1.menu_id = r2.menu_id)
        ');

        if (empty($pairs)) {
            $this->info('No duplicate recipes found.');
        } else {
            $removed = 0;
            foreach ($pairs as $p) {
                $keepId = $p->items1 >= $p->items2 ? $p->id1 : $p->id2;
                $delId  = $p->items1 >= $p->items2 ? $p->id2 : $p->id1;

                $del = MenuRecipe::find($delId);
                if ($del) {
                    $this->line("  Removing duplicate '{$p->name}' (id={$delId}), keeping id={$keepId}");
                    $del->items()->delete();
                    $del->forceDelete();
                    $removed++;
                }
            }
            $this->info("Removed {$removed} duplicate recipes.");
        }

        $menuDupes = MenuEngineeringMenu::selectRaw('client_id, branch_id, name, COUNT(*) as cnt')
            ->groupByRaw('client_id, branch_id, name')
            ->having('cnt', '>', 1)
            ->get();

        if ($menuDupes->isNotEmpty()) {
            $this->warn("Found {$menuDupes->count()} duplicate menu groups.");
            foreach ($menuDupes as $group) {
                $menus = MenuEngineeringMenu::where('client_id', $group->client_id)
                    ->where('branch_id', $group->branch_id)
                    ->where('name', $group->name)
                    ->orderBy('created_at')
                    ->get();

                $keep = $menus->shift();
                foreach ($menus as $dup) {
                    MenuRecipe::where('menu_id', $dup->id)->update(['menu_id' => $keep->id]);
                    $dup->delete();
                }
            }
            $this->info('Duplicate menus merged.');
        }

        $this->info('Done.');
        return Command::SUCCESS;
    }
}
