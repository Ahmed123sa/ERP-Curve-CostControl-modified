<?php
namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\Production\DailyProduction;
use App\Models\Production\Recipe;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyProductionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));
        $start = Carbon::parse($month . '-01');
        $end = $start->copy()->endOfMonth();

        $recipes = Recipe::where('client_id', $clientId)
            ->with(['outputItem:id,name,unit', 'outputWarehouse:id,name'])
            ->orderBy('name')
            ->get();

        $entries = DailyProduction::where('client_id', $clientId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy(fn($e) => $e->recipe_id . '|' . $e->date->format('Y-m-d'));

        $data = [];
        foreach ($entries as $key => $items) {
            $parts = explode('|', $key);
            $recipeId = $parts[0];
            $date = $parts[1];
            $day = (int) Carbon::parse($date)->format('d');
            if (!isset($data[$recipeId])) $data[$recipeId] = [];
            $data[$recipeId][$day] = (float) $items->sum('qty');
        }

        return response()->json([
            'month'   => $month,
            'recipes' => $recipes,
            'data'    => $data,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $data = $request->validate([
            'month'    => 'required|date_format:Y-m',
            'entries'  => 'required|array',
            'entries.*.recipe_id' => 'required|exists:production_recipes,id',
            'entries.*.day'       => 'required|integer|between:1,31',
            'entries.*.qty'       => 'required|numeric|min:0',
        ]);

        $month = $data['month'];
        $yearMonth = explode('-', $month);
        $year = $yearMonth[0];
        $monthNum = $yearMonth[1];

        foreach ($data['entries'] as $entry) {
            $date = sprintf('%s-%s-%02d', $year, $monthNum, (int) $entry['day']);
            if ($entry['qty'] > 0) {
                DailyProduction::updateOrCreate(
                    [
                        'client_id' => $clientId,
                        'recipe_id' => $entry['recipe_id'],
                        'date'      => $date,
                    ],
                    ['qty' => $entry['qty']]
                );
            } else {
                DailyProduction::where('client_id', $clientId)
                    ->where('recipe_id', $entry['recipe_id'])
                    ->where('date', $date)
                    ->delete();
            }
        }

        return response()->json(['message' => 'تم حفظ الإنتاج اليومي']);
    }
}
