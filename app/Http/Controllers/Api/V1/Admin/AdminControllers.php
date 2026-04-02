<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ── PromoCodeController ────────────────────────────────────────────────────
class PromoCodeController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('promo_codes')
            ->where('channel_id', $this->channelId())
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $query->where('code', 'like', '%' . $request->search . '%');
        }
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $paged = $query->paginate(min((int)$request->get('per_page', 20), 100));

        return response()->json([
            'data' => collect($paged->items())->map(fn($p) => [
                'id'             => $p->id,
                'code'           => $p->code,
                'description'    => $p->description,
                'discount_type'  => $p->discount_type,
                'discount_value' => (float) $p->discount_value,
                'max_uses'       => $p->max_uses,
                'uses_count'     => $p->uses_count,
                'valid_from'     => $p->valid_from,
                'valid_until'    => $p->valid_until,
                'is_active'      => (bool) $p->is_active,
                'created_at'     => $p->created_at,
            ]),
            'meta' => [
                'current_page' => $paged->currentPage(), 'last_page' => $paged->lastPage(),
                'per_page' => $paged->perPage(), 'total' => $paged->total(),
                'from' => $paged->firstItem(), 'to' => $paged->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code'           => 'required|string|max:40|unique:promo_codes,code',
            'discount_type'  => 'required|in:percentage,fixed_amount',
            'discount_value' => 'required|numeric|min:0',
        ]);

        $id = DB::table('promo_codes')->insertGetId([
            'channel_id'           => $this->channelId(),
            'created_by_user_id'   => $request->user()->id,
            'code'                 => strtoupper($request->code),
            'description'          => $request->description,
            'discount_type'        => $request->discount_type,
            'discount_value'       => $request->discount_value,
            'max_uses'             => $request->max_uses,
            'max_uses_per_user'    => $request->get('max_uses_per_user', 1),
            'valid_from'           => $request->valid_from,
            'valid_until'          => $request->valid_until,
            'is_active'            => true,
            'uses_count'           => 0,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        AuditLog::log('promo_code_created', 'promo_code', $id, null, ['code' => $request->code]);
        return response()->json(['message' => 'Promo code created.', 'id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = array_filter([
            'description'  => $request->description,
            'discount_value'=> $request->discount_value,
            'max_uses'     => $request->max_uses,
            'valid_from'   => $request->valid_from,
            'valid_until'  => $request->valid_until,
            'is_active'    => $request->has('is_active') ? (int)$request->boolean('is_active') : null,
            'updated_at'   => now(),
        ], fn($v) => $v !== null);

        DB::table('promo_codes')->where('id', $id)->update($data);
        return response()->json(['message' => 'Updated.']);
    }

    public function destroy(int $id): JsonResponse
    {
        DB::table('promo_codes')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    public function deactivate(int $id): JsonResponse
    {
        DB::table('promo_codes')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
        return response()->json(['message' => 'Deactivated.']);
    }
}

// ── PaymentsAdminController ────────────────────────────────────────────────
class PaymentsAdminController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('payments')
            ->join('users', 'payments.user_id', '=', 'users.id')
            ->where('users.channel_id', $this->channelId())
            ->select([
                'payments.id', 'payments.status', 'payments.gateway',
                'payments.amount', 'payments.discount_amount', 'payments.amount_paid',
                'payments.currency', 'payments.payment_method_brand',
                'payments.payment_method_last4', 'payments.receipt_url',
                'payments.paid_at', 'payments.created_at',
                'users.display_name', 'users.email',
            ]);

        if ($request->filled('status'))  $query->where('payments.status', $request->status);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('users.email', 'like', "%{$s}%")
                  ->orWhere('users.display_name', 'like', "%{$s}%")
                  ->orWhere('payments.gateway_transaction_id', 'like', "%{$s}%")
            );
        }

        $query->orderByDesc('payments.created_at');
        $perPage = min((int) $request->get('per_page', 20), 100);
        $paged   = $query->paginate($perPage);

        // Monthly revenue summary
        $monthRevenue = DB::table('payments')
            ->join('users', 'payments.user_id', '=', 'users.id')
            ->where('users.channel_id', $this->channelId())
            ->where('payments.status', 'succeeded')
            ->whereYear('payments.paid_at', now()->year)
            ->whereMonth('payments.paid_at', now()->month)
            ->sum('payments.amount_paid');

        return response()->json([
            'data' => collect($paged->items())->map(fn($p) => [
                'id'                  => $p->id,
                'display_name'        => $p->display_name,
                'email'               => $p->email,
                'amount'              => (float) $p->amount,
                'discount_amount'     => (float) $p->discount_amount,
                'amount_paid'         => (float) $p->amount_paid,
                'currency'            => $p->currency,
                'status'              => $p->status,
                'gateway'             => $p->gateway,
                'card'                => $p->payment_method_brand
                    ? "{$p->payment_method_brand} •••• {$p->payment_method_last4}"
                    : null,
                'receipt_url'         => $p->receipt_url,
                'paid_at'             => $p->paid_at,
                'created_at'          => $p->created_at,
            ]),
            'meta' => [
                'current_page'   => $paged->currentPage(), 'last_page' => $paged->lastPage(),
                'per_page'       => $paged->perPage(), 'total' => $paged->total(),
                'from'           => $paged->firstItem(), 'to' => $paged->lastItem(),
                'month_revenue'  => round((float)$monthRevenue, 2),
            ],
        ]);
    }
}

// ── LiveStreamAdminController ──────────────────────────────────────────────
class LiveStreamAdminController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('live_streams')
            ->where('channel_id', $this->channelId())
            ->orderByDesc('scheduled_start_at');

        if ($request->filled('status')) $query->where('status', $request->status);

        $paged = $query->paginate(min((int)$request->get('per_page', 20), 100));

        return response()->json([
            'data' => collect($paged->items())->map(fn($s) => [
                'id' => $s->id, 'title' => $s->title, 'description' => $s->description,
                'status' => $s->status, 'primary_platform' => $s->primary_platform,
                'platform_stream_id' => $s->platform_stream_id,
                'scheduled_start_at' => $s->scheduled_start_at,
                'actual_start_at' => $s->actual_start_at, 'actual_end_at' => $s->actual_end_at,
                'is_public' => (bool) $s->is_public, 'peak_viewers' => (int) $s->peak_viewers,
            ]),
            'meta' => [
                'current_page' => $paged->currentPage(), 'last_page' => $paged->lastPage(),
                'per_page' => $paged->perPage(), 'total' => $paged->total(),
                'from' => $paged->firstItem(), 'to' => $paged->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['title' => 'required|string|max:255', 'primary_platform' => 'required|in:youtube,facebook,custom_rtmp']);
        $id = DB::table('live_streams')->insertGetId([
            'channel_id'         => $this->channelId(),
            'title'              => $request->title,
            'description'        => $request->description,
            'primary_platform'   => $request->primary_platform,
            'platform_stream_id' => $request->platform_stream_id,
            'scheduled_start_at' => $request->scheduled_start_at,
            'status'             => 'scheduled',
            'is_public'          => $request->boolean('is_public', true),
            'peak_viewers'       => 0,
            'created_at'         => now(), 'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Stream created.', 'id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = array_filter([
            'title'              => $request->title,
            'description'        => $request->description,
            'platform_stream_id' => $request->platform_stream_id,
            'scheduled_start_at' => $request->scheduled_start_at,
            'is_public'          => $request->has('is_public') ? (int)$request->boolean('is_public') : null,
            'updated_at'         => now(),
        ], fn($v) => $v !== null);
        DB::table('live_streams')->where('id', $id)->update($data);
        return response()->json(['message' => 'Updated.']);
    }

    public function goLive(Request $request, int $id): JsonResponse
    {
        DB::table('live_streams')->where('id', $id)->update([
            'status' => 'live', 'actual_start_at' => now(), 'updated_at' => now(),
        ]);
        AuditLog::log('live_stream_started', 'live_stream', $id, null, ['actor' => $request->user()->display_name]);
        return response()->json(['message' => 'Stream is now live.']);
    }

    public function end(Request $request, int $id): JsonResponse
    {
        DB::table('live_streams')->where('id', $id)->update([
            'status' => 'ended', 'actual_end_at' => now(), 'updated_at' => now(),
        ]);
        AuditLog::log('live_stream_ended', 'live_stream', $id, null, ['actor' => $request->user()->display_name]);
        return response()->json(['message' => 'Stream ended.']);
    }

    public function destroy(int $id): JsonResponse
    {
        DB::table('live_streams')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }
}

// ── EventAdminController ───────────────────────────────────────────────────
class EventAdminController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('events')
            ->where('channel_id', $this->channelId())
            ->whereNull('deleted_at')
            ->orderByDesc('starts_at');

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('search')) $query->where('title', 'like', '%'.$request->search.'%');

        $paged = $query->paginate(min((int)$request->get('per_page', 20), 100));

        return response()->json([
            'data' => collect($paged->items())->map(fn($e) => [
                'id' => $e->id, 'title' => $e->title, 'description' => $e->description,
                'location_name' => $e->location_name, 'city' => $e->city, 'country' => $e->country,
                'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at,
                'status' => $e->status, 'is_public' => (bool)$e->is_public,
                'ticket_price' => (float)$e->ticket_price, 'max_capacity' => $e->max_capacity,
            ]),
            'meta' => [
                'current_page' => $paged->currentPage(), 'last_page' => $paged->lastPage(),
                'per_page' => $paged->perPage(), 'total' => $paged->total(),
                'from' => $paged->firstItem(), 'to' => $paged->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['title' => 'required|string|max:255', 'starts_at' => 'required|date']);
        $id = DB::table('events')->insertGetId([
            'channel_id'       => $this->channelId(),
            'organizer_user_id'=> $request->user()->id,
            'title'            => $request->title,
            'description'      => $request->description,
            'location_name'    => $request->location_name,
            'address'          => $request->address,
            'city'             => $request->city,
            'country'          => $request->get('country', 'GB'),
            'starts_at'        => $request->starts_at,
            'ends_at'          => $request->ends_at,
            'status'           => $request->get('status', 'draft'),
            'is_public'        => $request->boolean('is_public', true),
            'ticket_price'     => $request->get('ticket_price', 0),
            'max_capacity'     => $request->max_capacity,
            'created_at'       => now(), 'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Event created.', 'id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = array_filter([
            'title'         => $request->title,
            'description'   => $request->description,
            'location_name' => $request->location_name,
            'city'          => $request->city,
            'starts_at'     => $request->starts_at,
            'ends_at'       => $request->ends_at,
            'status'        => $request->status,
            'ticket_price'  => $request->ticket_price,
            'max_capacity'  => $request->max_capacity,
            'updated_at'    => now(),
        ], fn($v) => $v !== null);
        DB::table('events')->where('id', $id)->update($data);
        return response()->json(['message' => 'Updated.']);
    }

    public function destroy(int $id): JsonResponse
    {
        DB::table('events')->where('id', $id)->update(['deleted_at' => now()]);
        return response()->json(['message' => 'Deleted.']);
    }
}

// ── SettingsAdminController ────────────────────────────────────────────────
class SettingsAdminController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    public function index(): JsonResponse
    {
        $rows = DB::table('site_settings')->where('channel_id', $this->channelId())->get();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row->key] = json_decode($row->value, true) ?? $row->value;
        }
        return response()->json(['data' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        $channelId = $this->channelId();
        foreach ($request->all() as $key => $value) {
            DB::table('site_settings')->updateOrInsert(
                ['channel_id' => $channelId, 'key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]
            );
        }
        return response()->json(['message' => 'Settings saved.']);
    }
}

// ── SeoRedirectAdminController ─────────────────────────────────────────────
class SeoRedirectAdminController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('seo_redirects')
            ->where('channel_id', $this->channelId())
            ->orderByDesc('hit_count');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('old_path', 'like', "%{$s}%")
                  ->orWhere('new_path', 'like', "%{$s}%")
            );
        }
        if ($request->has('active')) $query->where('is_active', $request->boolean('active'));

        $paged = $query->paginate(min((int)$request->get('per_page', 25), 100));

        return response()->json([
            'data' => collect($paged->items())->map(fn($r) => [
                'id' => $r->id, 'old_path' => $r->old_path, 'new_path' => $r->new_path,
                'http_code' => $r->http_code, 'hit_count' => $r->hit_count, 'is_active' => (bool)$r->is_active,
            ]),
            'meta' => [
                'current_page' => $paged->currentPage(), 'last_page' => $paged->lastPage(),
                'per_page' => $paged->perPage(), 'total' => $paged->total(),
                'from' => $paged->firstItem(), 'to' => $paged->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['old_path' => 'required|string|max:500', 'new_path' => 'required|string|max:500']);
        $id = DB::table('seo_redirects')->insertGetId([
            'channel_id' => $this->channelId(),
            'old_path'   => $request->old_path,
            'new_path'   => $request->new_path,
            'http_code'  => $request->get('http_code', 301),
            'is_active'  => true,
            'hit_count'  => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Redirect created.', 'id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        DB::table('seo_redirects')->where('id', $id)->update(array_filter([
            'old_path'   => $request->old_path,
            'new_path'   => $request->new_path,
            'http_code'  => $request->http_code,
            'is_active'  => $request->has('is_active') ? (int)$request->boolean('is_active') : null,
            'updated_at' => now(),
        ], fn($v) => $v !== null));
        return response()->json(['message' => 'Updated.']);
    }

    public function destroy(int $id): JsonResponse
    {
        DB::table('seo_redirects')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }
}

// ── AuditLogController ─────────────────────────────────────────────────────
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('audit_logs')
            ->leftJoin('users', 'audit_logs.actor_user_id', '=', 'users.id')
            ->select(['audit_logs.*', 'users.display_name as actor_name', 'users.email as actor_email'])
            ->orderByDesc('audit_logs.created_at');

        if ($request->filled('action'))  $query->where('audit_logs.action', 'like', '%'.$request->action.'%');
        if ($request->filled('user_id')) $query->where('audit_logs.actor_user_id', $request->user_id);

        $paged = $query->paginate(min((int)$request->get('per_page', 30), 100));

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
                'per_page' => $paged->perPage(), 'total' => $paged->total(),
                'from' => $paged->firstItem(), 'to' => $paged->lastItem(),
            ],
        ]);
    }
}
