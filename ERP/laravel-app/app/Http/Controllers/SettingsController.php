<?php
namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function logo(Request $request): JsonResponse
    {
        $user = $request->user();
        $client = $user->clients()->findOrFail($user->current_client_id);

        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ]);

        if ($client->logo) {
            Storage::disk('public')->delete($client->logo);
        }

        $path = $request->file('logo')->store('logos', 'public');
        $client->update(['logo' => $path]);

        return response()->json([
            'message' => 'تم رفع الشعار بنجاح',
            'logo_url' => Storage::url($path),
        ]);
    }

    public function getLogo(Request $request): JsonResponse
    {
        $user = $request->user();
        $client = $user->clients()->findOrFail($user->current_client_id);

        return response()->json([
            'logo_url' => $client->logo ? Storage::url($client->logo) : null,
        ]);
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $user = $request->user();
        $client = $user->clients()->findOrFail($user->current_client_id);

        if ($client->logo) {
            Storage::disk('public')->delete($client->logo);
            $client->update(['logo' => null]);
        }

        return response()->json(['message' => 'تم حذف الشعار']);
    }
}
