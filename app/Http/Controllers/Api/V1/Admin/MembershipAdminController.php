<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MembershipAdminController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    // GET /api/v1/admin/membership-plans
    public function index(): JsonResponse
    {
        $plans = DB::table('membership_plans')
            ->where('channel_id', $this->channelId())
            ->orderBy('price_amount')
            ->get()
            ->map(fn($p) => [
                'id'              => $p->id,
                'name'            => $p->name,
                'slug'            => $p->slug,
                'description'     => $p->description,
                'price_amount'    => (float) $p->price_amount,
                'price_currency'  => $p->price_currency,
                'billing_cycle'   => $p->billing_cycle,
                'stripe_price_id' => $p->stripe_price_id,
                'badge_label'     => $p->badge_label,
                'badge_color'     => $p->badge_color,
                'is_free_tier'    => (bool) ($p->price_amount == 0),
                'features'        => $p->features ? json_decode($p->features, true) : [],
                'active_count'    => DB::table('memberships')
                    ->where('membership_plan_id', $p->id)
                    ->where('status', 'active')
                    ->count(),
            ]);

        return response()->json(['data' => $plans]);
    }

    // POST /api/v1/admin/membership-plans
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'          => 'required|string|max:120',
            'price_amount'  => 'required|numeric|min:0',
            'billing_cycle' => 'required|in:monthly,yearly,lifetime',
        ]);

        $id = DB::table('membership_plans')->insertGetId([
            'channel_id'      => $this->channelId(),
            'name'            => $request->name,
            'slug'            => Str::slug($request->name),
            'description'     => $request->description,
            'price_amount'    => $request->price_amount,
            'price_currency'  => $request->get('price_currency', 'GBP'),
            'billing_cycle'   => $request->billing_cycle,
            'stripe_price_id' => $request->stripe_price_id,
            'badge_label'     => $request->badge_label,
            'badge_color'     => $request->badge_color,
            'features'        => $request->features ? json_encode($request->features) : null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return response()->json(['message' => 'Plan created.', 'id' => $id], 201);
    }

    // PATCH /api/v1/admin/membership-plans/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $data = array_filter([
            'name'            => $request->name,
            'description'     => $request->description,
            'price_amount'    => $request->price_amount,
            'billing_cycle'   => $request->billing_cycle,
            'stripe_price_id' => $request->stripe_price_id,
            'badge_label'     => $request->badge_label,
            'badge_color'     => $request->badge_color,
            'features'        => $request->features ? json_encode($request->features) : null,
            'updated_at'      => now(),
        ], fn($v) => $v !== null);

        DB::table('membership_plans')->where('id', $id)->update($data);
        return response()->json(['message' => 'Updated.']);
    }

    // DELETE /api/v1/admin/membership-plans/{id}
    public function destroy(int $id): JsonResponse
    {
        $count = DB::table('memberships')->where('membership_plan_id', $id)->where('status', 'active')->count();
        abort_if($count > 0, 422, "{$count} active members use this plan.");
        DB::table('membership_plans')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // GET /api/v1/admin/memberships
    public function members(Request $request): JsonResponse
    {
        $query = DB::table('memberships')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->join('membership_plans', 'memberships.membership_plan_id', '=', 'membership_plans.id')
            ->where('users.channel_id', $this->channelId())
            ->select([
                'memberships.id', 'memberships.status',
                'memberships.start_date', 'memberships.end_date',
                'memberships.stripe_subscription_id', 'memberships.created_at',
                'users.display_name', 'users.email',
                'membership_plans.name as plan_name',
                'membership_plans.price_amount', 'membership_plans.billing_cycle',
            ]);

        if ($request->filled('status'))  $query->where('memberships.status', $request->status);
        if ($request->filled('plan_id')) $query->where('memberships.membership_plan_id', $request->plan_id);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('users.email', 'like', "%{$s}%")
                  ->orWhere('users.display_name', 'like', "%{$s}%")
            );
        }

        $query->orderByDesc('memberships.created_at');
        $perPage = min((int) $request->get('per_page', 20), 100);
        $paged   = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paged->items())->map(fn($m) => [
                'id'                     => $m->id,
                'status'                 => $m->status,
                'display_name'           => $m->display_name,
                'email'                  => $m->email,
                'plan_name'              => $m->plan_name,
                'price_amount'           => (float) $m->price_amount,
                'billing_cycle'          => $m->billing_cycle,
                'start_date'             => $m->start_date,
                'end_date'               => $m->end_date,
                'stripe_subscription_id' => $m->stripe_subscription_id,
                'created_at'             => $m->created_at,
            ]),
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

    // POST /api/v1/admin/memberships/{id}/cancel
    public function cancelMembership(Request $request, int $id): JsonResponse
    {
        DB::table('memberships')->where('id', $id)->update([
            'status'     => 'canceled',
            'end_date'   => now()->toDateString(),
            'updated_at' => now(),
        ]);
        AuditLog::log('membership_canceled', 'membership', $id, null, ['actor' => $request->user()->display_name]);
        return response()->json(['message' => 'Membership canceled.']);
    }
}
