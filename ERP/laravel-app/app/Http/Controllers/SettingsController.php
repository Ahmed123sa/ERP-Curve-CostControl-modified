<?php
namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function logo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ]);

        $current = Setting::where('key', 'system_logo')->first();
        if ($current && $current->value) {
            Storage::disk('public')->delete($current->value);
        }

        $path = $request->file('logo')->store('system-logo', 'public');
        Setting::updateOrCreate(
            ['key' => 'system_logo'],
            ['value' => $path]
        );

        return response()->json([
            'message' => 'تم رفع الشعار بنجاح',
            'logo_url' => Storage::url($path),
        ]);
    }

    public function getLogo(): JsonResponse
    {
        $setting = Setting::where('key', 'system_logo')->first();
        $path = $setting?->value;

        return response()->json([
            'logo_url' => $path ? Storage::url($path) : null,
        ]);
    }

    public function deleteLogo(): JsonResponse
    {
        $setting = Setting::where('key', 'system_logo')->first();

        if ($setting && $setting->value) {
            Storage::disk('public')->delete($setting->value);
            $setting->update(['value' => null]);
        }

        return response()->json(['message' => 'تم حذف الشعار']);
    }
}
