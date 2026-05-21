<?php
namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\MenuEngineering\MenuUnitConversion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuUnitConversionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $conversions = MenuUnitConversion::whereNull('client_id')
            ->orWhere('client_id', $clientId)
            ->orderBy('from_unit')
            ->get(['from_unit', 'to_unit', 'factor']);

        $units = [];
        foreach ($conversions as $c) {
            $units[$c->from_unit] = true;
            $units[$c->to_unit] = true;
        }

        return response()->json([
            'conversions' => $conversions,
            'units' => array_keys($units),
        ]);
    }
}
