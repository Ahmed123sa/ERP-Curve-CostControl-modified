<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
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

        return response()->json($clients);
    }

    /**
     * إضافة شركة جديدة للنظام
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:100|unique:clients,slug',
        ]);

        $clientId = (string) Str::uuid();

        return DB::transaction(function () use ($request, $clientId) {
            $client = Client::create([
                'id'        => $clientId,
                'name'      => $request->name,
                'slug'      => Str::slug($request->slug),
                'is_active' => true,
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
            'name'      => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        $client->update($request->only(['name', 'is_active']));

        return response()->json($client);
    }

    /**
     * حذف الشركة (يجب الحذر هنا لأنه سيحذف كل البيانات المرتبطة)
     */
    public function destroy(Client $client): JsonResponse
    {
        // يفضل هنا عمل Soft Delete أو منع الحذف لو في حركات مخزنية
        $client->delete();
        return response()->json(['message' => 'تم حذف الشركة وكافة بياناتها']);
    }
}
