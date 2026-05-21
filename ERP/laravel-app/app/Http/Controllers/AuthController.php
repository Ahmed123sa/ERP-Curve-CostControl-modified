<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->with('clients')->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages(['email' => 'البريد أو كلمة المرور غلط']);
        }

        // العميل الافتراضي = الأول في القائمة أو الـ primary
        $primaryClient = $user->clients()->wherePivot('is_primary', true)->first()
            ?? $user->clients()->first();

        // حفظ العميل الحالي في قاعدة البيانات لضمان استقرار العزل
        if ($primaryClient) {
            $user->update(['current_client_id' => $primaryClient->id]);
        }

        $token = $user->createToken('erp-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'                => $user->id,
                'name'              => $user->name,
                'email'             => $user->email,
                'role'              => $user->role,
                'clients'           => $user->clients,
                'current_client_id' => $primaryClient?->id,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'تم تسجيل الخروج']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('clients'));
    }

    public function switchClient(Request $request, string $clientId): JsonResponse
    {
        $user = $request->user();

        // تأكد إن الموظف ده عنده صلاحية على العميل ده
        abort_unless(
            $user->clients()->where('clients.id', $clientId)->exists(),
            403,
            'مش عندك صلاحية على العميل ده'
        );

        $user->update(['current_client_id' => $clientId]);

        return response()->json(['message' => 'تم التبديل', 'current_client_id' => $clientId]);
    }
}
