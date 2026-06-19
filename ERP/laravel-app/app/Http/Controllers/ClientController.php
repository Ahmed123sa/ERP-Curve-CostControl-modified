<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ClientController extends Controller
{
    /**
     * عرض شركة واحدة
     */
    public function show(Client $client): JsonResponse
    {
        $client->loadCount('users');
        $client->logo_url = $client->logo ? (str_starts_with($client->logo, 'http') ? $client->logo : Storage::url($client->logo)) : null;
        return response()->json($client);
    }

    /**
     * عرض الشركات التي يمتلك المستخدم صلاحية الوصول إليها
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // لو مدير نظام (Admin) يشوف كل الشركات، لو كوست كنترول يشوف الشركات بتاعته بس
        if ($user->role === 'admin') {
            $clients = Client::withCount('users')->get();
        } else {
            $clients = $user->clients()->withCount('users')->get();
        }

        $clients->each(function ($c) {
            $c->logo_url = $c->logo ? (str_starts_with($c->logo, 'http') ? $c->logo : Storage::url($c->logo)) : null;
        });

        return response()->json($clients);
    }

    /**
     * إضافة شركة جديدة للنظام
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'          => 'required|string|max:255',
            'slug'          => 'required|string|max:100|unique:clients,slug',
            'logo'          => 'sometimes|nullable|string|max:255',
            'primary_color' => 'sometimes|nullable|string|max:9',
            'is_active'     => 'sometimes|boolean',
        ]);

        $clientId = (string) Str::uuid();

        return DB::transaction(function () use ($request, $clientId) {
            $client = Client::create([
                'id'            => $clientId,
                'name'          => $request->name,
                'slug'          => Str::slug($request->slug),
                'is_active'     => $request->boolean('is_active', true),
                'logo'          => $request->input('logo'),
                'primary_color' => $request->input('primary_color'),
            ]);

            // ربط المستخدم الحالي بالشركة الجديدة كشركة أساسية له
            $request->user()->clients()->attach($clientId, ['is_primary' => true]);
            $request->user()->update(['current_client_id' => $clientId]);

            return response()->json($client, 201);
        });
    }

    /**
     * تحديث بيانات الشركة
     */
    public function update(Request $request, Client $client): JsonResponse
    {
        $request->validate([
            'name'          => 'sometimes|string|max:255',
            'slug'          => 'sometimes|string|max:100|unique:clients,slug,'.$client->id,
            'is_active'     => 'sometimes|boolean',
            'logo'          => 'sometimes|nullable|string|max:255',
            'primary_color' => 'sometimes|nullable|string|max:9',
        ]);

        $client->update($request->only(['name', 'slug', 'is_active', 'logo', 'primary_color']));

        return response()->json($client);
    }

    /**
     * رفع شعار الشركة
     */
    public function uploadLogo(Request $request, Client $client): JsonResponse
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ]);

        if (!$request->user()->isClient()) {
            abort_unless(
                $request->user()->clients()->where('clients.id', $client->id)->exists(),
                403,
                'ليس لديك صلاحية الوصول لهذه الشركة'
            );
        }

        if ($client->logo) {
            Storage::disk('public')->delete($client->logo);
        }

        $path = $request->file('logo')->store('logos', 'public');
        $client->update(['logo' => $path]);

        return response()->json([
            'message' => 'تم رفع الشعار بنجاح',
            'logo_url' => Storage::url($path),
            'logo'     => $path,
        ]);
    }

    /**
     * حذف الشركة — شرط: لا يكون للشركة أي أصناف أو مخازن أو فروع مرتبطة
     */
    public function destroy(Client $client): JsonResponse
    {
        $hasData = $client->items()->exists() || $client->warehouses()->exists() || $client->branches()->exists();
        if ($hasData) {
            return response()->json([
                'message' => 'لا يمكن حذف الشركة لأنها تحتوي على أصناف، مخازن أو فروع. قم بحذفها أولاً.'
            ], 409);
        }

        $client->delete();
        return response()->json(['message' => 'تم حذف الشركة']);
    }
}
