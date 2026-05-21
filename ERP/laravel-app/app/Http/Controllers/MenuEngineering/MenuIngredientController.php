<?php
namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuIngredientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $items = Item::where('client_id', $clientId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'default_cost']);

        return response()->json($items->map(fn($i) => [
            'id'           => $i->id,
            'name'         => $i->name,
            'unit'         => $i->unit ?? 'each',
            'default_cost' => (float) ($i->default_cost ?? 0),
        ]));
    }
}
