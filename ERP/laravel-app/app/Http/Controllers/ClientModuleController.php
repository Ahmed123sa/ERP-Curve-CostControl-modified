<?php
namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientModuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $modules = ClientModule::where('client_id', $clientId)
            ->where('is_active', true)
            ->pluck('module_key');

        return response()->json($modules);
    }

    public function settings(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $client = Client::where('id', $clientId)->first(['id', 'name', 'logo', 'primary_color']);

        if (! $client) {
            return response()->json(['message' => 'Client not found'], 404);
        }

        $client->logo_url = $client->logo ? (str_starts_with($client->logo, 'http') ? $client->logo : Storage::url($client->logo)) : null;

        return response()->json($client);
    }

    public function allModules(): JsonResponse
    {
        $modules = [
            ['key' => 'dashboard',              'label' => 'لوحة التحكم',           'default' => true],
            ['key' => 'vouchers.purchase',       'label' => 'المشتريات',            'default' => true],
            ['key' => 'vouchers.dispatch',       'label' => 'أذون الصرف',           'default' => true],
            ['key' => 'vouchers.upload',         'label' => 'رفع Excel',            'default' => true],
            ['key' => 'vouchers.history',        'label' => 'سجل الحركات',          'default' => true],
            ['key' => 'reports.financial',       'label' => 'التقارير المالية',     'default' => true],
            ['key' => 'reports.diffs',           'label' => 'الفروق والهدر',        'default' => true],
            ['key' => 'reports.food-cost',       'label' => 'Food Cost %',          'default' => true],
            ['key' => 'closing',                 'label' => 'التقفيل الشهري',       'default' => true],
            ['key' => 'inventory',               'label' => 'المخزون',              'default' => true],
            ['key' => 'items',                   'label' => 'الأصناف',              'default' => true],
            ['key' => 'mappings',                'label' => 'ربط الأسماء',          'default' => true],
            ['key' => 'menu-engineering',        'label' => 'هندسة القائمة',        'default' => true],
            ['key' => 'analytics',               'label' => 'التحليلات الذكية',     'default' => true],
            ['key' => 'expenses',                'label' => 'المصروفات',            'default' => true],
            ['key' => 'financial.daily',         'label' => 'اليومية',              'default' => false],
            ['key' => 'financial.monthly',       'label' => 'التجميع الشهري',       'default' => false],
            ['key' => 'financial.closing',       'label' => 'التقفيل المالي',       'default' => false],
            ['key' => 'financial.advances',      'label' => 'السلف',                'default' => false],
        ];

        return response()->json($modules);
    }
}
