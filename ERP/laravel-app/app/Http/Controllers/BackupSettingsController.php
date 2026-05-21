<?php
namespace App\Http\Controllers;

use App\Models\BackupSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class BackupSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = BackupSetting::first();
        if (!$settings) {
            $settings = BackupSetting::create([
                'local_path' => storage_path('backups'),
                'retention_days' => 7,
            ]);
        }
        return response()->json($settings->makeHidden('google_drive_token'));
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'local_path' => 'sometimes|string',
            'email_enabled' => 'sometimes|boolean',
            'email_to' => 'nullable|email',
            'google_drive_enabled' => 'sometimes|boolean',
            'retention_days' => 'sometimes|integer|min:1|max:365',
        ]);

        $settings = BackupSetting::first();
        if (!$settings) {
            return response()->json(['message' => 'الإعدادات غير موجودة'], 404);
        }

        $settings->update($request->only([
            'local_path', 'email_enabled', 'email_to',
            'google_drive_enabled', 'retention_days',
        ]));

        return response()->json(['message' => 'تم حفظ الإعدادات', 'settings' => $settings->makeHidden('google_drive_token')]);
    }

    public function run(): JsonResponse
    {
        try {
            Artisan::call('backup:run');
            $output = Artisan::output();
            return response()->json(['message' => 'تم إنشاء النسخة الاحتياطية', 'output' => $output]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'فشل إنشاء النسخة: ' . $e->getMessage()], 500);
        }
    }
}
