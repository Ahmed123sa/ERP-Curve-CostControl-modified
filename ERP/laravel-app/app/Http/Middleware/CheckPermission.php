<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'غير مصرح');
        }

        // Super-admin bypasses all permission checks
        if ($user->hasRole('super-admin')) {
            return $next($request);
        }

        if (!$user->can($permission)) {
            abort(403, 'ليس لديك صلاحية للوصول إلى هذه الميزة');
        }

        return $next($request);
    }
}
