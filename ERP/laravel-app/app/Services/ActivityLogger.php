<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    public static function log(
        string $action,
        string $entityType,
        string $entityId,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $user = Auth::user();

        // لو مفيش مستخدم مسجّل (مثلاً أثناء seeding أو console commands) — تخطى
        if (!$user) {
            return;
        }

        ActivityLog::create([
            'id'          => (string) \Illuminate\Support\Str::uuid(),
            'client_id'   => $user->current_client_id,
            'user_id'     => $user->id,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_values'  => $oldValues,
            'new_values'  => $newValues,
            'ip_address'  => request()->ip(),
        ]);
    }
}