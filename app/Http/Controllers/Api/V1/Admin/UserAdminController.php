<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserAdminController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    // GET /api/v1/admin/users
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('users')
            ->leftJoin('user_role_map', 'users.id', '=', 'user_role_map.user_id')
            ->leftJoin('roles', 'user_role_map.role_id', '=', 'roles.id')
            ->leftJoin('memberships', function ($join) {
                $join->on('memberships.user_id', '=', 'users.id')
                     ->where('memberships.status', 'active');
            })
            ->leftJoin('membership_plans', 'memberships.membership_plan_id', '=', 'membership_plans.id')
            ->where('users.channel_id', $this->channelId())
            ->whereNull('users.deleted_at')
            ->select([
                'users.id', 'users.email', 'users.display_name',
                'users.first_name', 'users.last_name', 'users.status',
                'users.created_at', 'users.last_login_at',
                DB::raw("GROUP_CONCAT(DISTINCT roles.slug ORDER BY roles.slug SEPARATOR ',') as role_slugs"),
                'membership_plans.name as membership_plan',
            ])
            ->groupBy(
                'users.id','users.email','users.display_name',
                'users.first_name','users.last_name','users.status',
                'users.created_at','users.last_login_at',
                'membership_plans.name'
            );

        // Filters
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('users.email', 'like', "%{$s}%")
                  ->orWhere('users.display_name', 'like', "%{$s}%")
            );
        }

        if ($request->filled('status'))  $query->where('users.status', $request->status);
        if ($request->filled('role')) {
            $query->where('roles.slug', $request->role);
        }

        $sortable = ['created_at','last_login_at','display_name','email'];
        $sort     = in_array($request->sort, $sortable) ? "users.{$request->sort}" : 'users.created_at';
        $order    = $request->order === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $order);

        $perPage = min((int) $request->get('per_page', 20), 100);
        $paged   = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paged->items())->map(fn($u) => $this->userRow($u)),
            'meta' => [
                'current_page' => $paged->currentPage(),
                'last_page'    => $paged->lastPage(),
                'per_page'     => $paged->perPage(),
                'total'        => $paged->total(),
                'from'         => $paged->firstItem(),
                'to'           => $paged->lastItem(),
            ],
        ]);
    }

    // GET /api/v1/admin/users/{id}
    public function show(int $id): JsonResponse
    {
        $user = DB::table('users')
            ->where('id', $id)
            ->where('channel_id', $this->channelId())
            ->first();

        abort_if(! $user, 404, 'User not found.');

        $roles = DB::table('roles')
            ->join('user_role_map', 'roles.id', '=', 'user_role_map.role_id')
            ->where('user_role_map.user_id', $id)
            ->pluck('roles.slug')
            ->toArray();

        $membership = DB::table('memberships')
            ->join('membership_plans', 'memberships.membership_plan_id', '=', 'membership_plans.id')
            ->where('memberships.user_id', $id)
            ->where('memberships.status', 'active')
            ->select('membership_plans.name','membership_plans.slug','memberships.status','memberships.end_date')
            ->first();

        $articleCount = DB::table('articles')
            ->where('author_user_id', $id)
            ->whereNull('deleted_at')
            ->count();

        return response()->json(['data' => [
            'id'           => $user->id,
            'email'        => $user->email,
            'display_name' => $user->display_name,
            'first_name'   => $user->first_name,
            'last_name'    => $user->last_name,
            'status'       => $user->status,
            'timezone'     => $user->timezone,
            'created_at'   => $user->created_at,
            'last_login_at'=> $user->last_login_at,
            'roles'        => $roles,
            'membership'   => $membership,
            'article_count'=> $articleCount,
        ]]);
    }

    // PATCH /api/v1/admin/users/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'display_name' => 'sometimes|string|max:120',
            'first_name'   => 'sometimes|string|max:80',
            'last_name'    => 'sometimes|string|max:80',
            'email'        => 'sometimes|email|unique:users,email,'.$id,
            'timezone'     => 'sometimes|string',
            'status'       => 'sometimes|in:active,suspended',
        ]);

        $data = $request->only(['display_name','first_name','last_name','email','timezone','status']);

        // Prevent admin from modifying themselves in dangerous ways
        if ($request->user()->id === $id && isset($data['status'])) {
            unset($data['status']);
        }

        DB::table('users')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));

        AuditLog::log('user_updated', 'user', $id, null, ['actor' => $request->user()->display_name]);

        return response()->json(['message' => 'Updated.']);
    }

    // POST /api/v1/admin/users/{id}/suspend
    public function suspend(Request $request, int $id): JsonResponse
    {
        abort_if($request->user()->id === $id, 422, 'Cannot suspend yourself.');

        DB::table('users')->where('id', $id)->update(['status' => 'suspended', 'updated_at' => now()]);
        // Revoke all tokens
        DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete();

        AuditLog::log('user_suspended', 'user', $id, null, ['actor' => $request->user()->display_name]);

        return response()->json(['message' => 'User suspended.']);
    }

    // POST /api/v1/admin/users/{id}/activate
    public function activate(Request $request, int $id): JsonResponse
    {
        DB::table('users')->where('id', $id)->update(['status' => 'active', 'updated_at' => now()]);
        AuditLog::log('user_activated', 'user', $id, null, ['actor' => $request->user()->display_name]);
        return response()->json(['message' => 'User activated.']);
    }

    // POST /api/v1/admin/users/{id}/assign-role
    public function assignRole(Request $request, int $id): JsonResponse
    {
        $request->validate(['role' => 'required|string']);

        $roleId = DB::table('roles')->where('slug', $request->role)->value('id');
        abort_if(! $roleId, 422, 'Unknown role: ' . $request->role);

        DB::table('user_role_map')->updateOrInsert(
            ['user_id' => $id, 'role_id' => $roleId, 'channel_id' => $this->channelId()],
            ['created_at' => now(), 'updated_at' => now()]
        );

        AuditLog::log('role_assigned', 'user', $id, null, [
            'role'  => $request->role,
            'actor' => $request->user()->display_name,
        ]);

        return response()->json(['message' => 'Role assigned.']);
    }

    // POST /api/v1/admin/users/{id}/remove-role
    public function removeRole(Request $request, int $id): JsonResponse
    {
        $request->validate(['role' => 'required|string']);

        $roleId = DB::table('roles')->where('slug', $request->role)->value('id');
        if ($roleId) {
            DB::table('user_role_map')
                ->where('user_id', $id)
                ->where('role_id', $roleId)
                ->delete();
        }

        AuditLog::log('role_removed', 'user', $id, null, [
            'role'  => $request->role,
            'actor' => $request->user()->display_name,
        ]);

        return response()->json(['message' => 'Role removed.']);
    }

    private function userRow(object $u): array
    {
        return [
            'id'              => $u->id,
            'email'           => $u->email,
            'display_name'    => $u->display_name,
            'first_name'      => $u->first_name  ?? '',
            'last_name'       => $u->last_name   ?? '',
            'status'          => $u->status,
            'roles'           => $u->role_slugs  ? explode(',', $u->role_slugs) : [],
            'membership_plan' => $u->membership_plan ?? null,
            'created_at'      => $u->created_at,
            'last_login_at'   => $u->last_login_at ?? null,
        ];
    }
}
