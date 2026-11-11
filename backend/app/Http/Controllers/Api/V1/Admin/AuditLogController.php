<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeAuditLog;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    public function index(): JsonResponse
    {
        $logs = AdministrativeAuditLog::query()
            ->latest('created_at')
            ->latest('id')
            ->paginate(50, [
                'id',
                'actor_user_id',
                'actor_role',
                'target_user_id',
                'target_role',
                'action',
                'context',
                'ip_address',
                'user_agent',
                'created_at',
            ]);

        return response()->json($logs);
    }
}
