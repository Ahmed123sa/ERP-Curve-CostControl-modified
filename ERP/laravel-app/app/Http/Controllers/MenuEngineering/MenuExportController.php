<?php
namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\MenuEngineering\MenuEngineeringMenu;
use App\Services\MenuEngineering\MenuExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MenuExportController extends Controller
{
    public function __construct(private MenuExportService $export) {}

    public function exportMenuExcel(Request $request, string $menu): StreamedResponse
    {
        $client = $request->user()->clients()->findOrFail($request->user()->current_client_id);
        $menuModel = MenuEngineeringMenu::where('client_id', $client->id)->findOrFail($menu);
        return $this->export->streamMenuExcel($menuModel, $client);
    }

    public function exportMenuPdf(Request $request, string $menu): StreamedResponse
    {
        $client = $request->user()->clients()->findOrFail($request->user()->current_client_id);
        $menuModel = MenuEngineeringMenu::where('client_id', $client->id)->findOrFail($menu);
        return $this->export->streamMenuPdf($menuModel, $client);
    }
}
