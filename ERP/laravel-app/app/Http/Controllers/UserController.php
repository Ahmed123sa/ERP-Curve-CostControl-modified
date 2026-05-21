<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::with('clients:id,name')->get()->map(function ($u) {
            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'roles' => $u->getRoleNames(),
                'permissions' => $u->getAllPermissions()->pluck('name'),
                'clients' => $u->clients,
                'current_client_id' => $u->current_client_id,
            ];
        });
        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:admin,cost_controller,viewer',
            'client_ids' => 'required|array|min:1',
            'client_ids.*' => 'exists:clients,id',
        ]);

        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'current_client_id' => $request->client_ids[0],
        ]);

        // Attach clients
        $sync = [];
        foreach ($request->client_ids as $i => $cid) {
            $sync[$cid] = ['is_primary' => $i === 0];
        }
        $user->clients()->sync($sync);

        // Assign Spatie role based on legacy role
        $spatieRole = match ($request->role) {
            'admin' => 'super-admin',
            'cost_controller' => 'cost-controller',
            'viewer' => 'viewer',
        };
        $user->assignRole($spatieRole);

        return response()->json(['message' => 'تم إنشاء المستخدم', 'user' => $user->load('clients')]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'password' => 'sometimes|string|min:6',
            'role' => 'sometimes|in:admin,cost_controller,viewer',
            'client_ids' => 'sometimes|array|min:1',
            'client_ids.*' => 'exists:clients,id',
        ]);

        if ($request->has('name')) $user->name = $request->name;
        if ($request->has('email')) $user->email = $request->email;
        if ($request->has('password')) $user->password = Hash::make($request->password);
        if ($request->has('role')) {
            $user->role = $request->role;
            $spatieRole = match ($request->role) {
                'admin' => 'super-admin',
                'cost_controller' => 'cost-controller',
                'viewer' => 'viewer',
            };
            $user->syncRoles([$spatieRole]);
        }
        if ($request->has('client_ids')) {
            $sync = [];
            foreach ($request->client_ids as $i => $cid) {
                $sync[$cid] = ['is_primary' => $i === 0];
            }
            $user->clients()->sync($sync);
            if (!in_array($user->current_client_id, $request->client_ids)) {
                $user->current_client_id = $request->client_ids[0];
            }
        }

        $user->save();

        return response()->json(['message' => 'تم تحديث المستخدم', 'user' => $user->fresh()->load('clients')]);
    }

    public function destroy(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->clients()->detach();
        $user->delete();
        return response()->json(['message' => 'تم حذف المستخدم']);
    }
}
