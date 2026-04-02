<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('audit_logs')
            ->leftJoin('users', 'audit_logs.actor_user_id', '=', 'users.id')
            ->select([
                'audit_logs.*',
                'users.display_name as actor_name',
                'users.email as actor_email',
            ])
            ->orderByDesc('audit_logs.created_at');

        if ($request->filled('action'))  $query->where('audit_logs.action', 'like', '%' . $request->action . '%');
        if ($request->filled('user_id')) $query->where('audit_logs.actor_user_id', $request->user_id);

        $paged = $query->paginate(min((int) $request->get('per_page', 30), 100));

        return response()->json([
            'data' => collect($paged->items())->map(fn($l) => [
                'id'          => $l->id,
                'action'      => $l->action,
                'target_type' => $l->target_type,
                'target_id'   => $l->target_id,
                'actor_name'  => $l->actor_name ?? 'System',
                'actor_email' => $l->actor_email,
                'after_state' => $l->after_state ? json_decode($l->after_state, true) : null,
                'created_at'  => $l->created_at,
            ]),
            'meta' => [
                'current_page' => $paged->currentPage(), 'last_page' => $paged->lastPage(),
                'per_page'     => $paged->perPage(),     'total'     => $paged->total(),
                'from'         => $paged->firstItem(),   'to'        => $paged->lastItem(),
            ],
        ]);
    }
}
