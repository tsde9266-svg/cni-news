<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ArticleTranslation;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\PromoCodeUse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ─────────────────────────────────────────────────────────────────────────────
// SearchController  GET /api/v1/search
// ─────────────────────────────────────────────────────────────────────────────
class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:200']]);

        $langId  = DB::table('languages')->where('code', $request->get('lang', 'en'))->value('id') ?? 1;
        $perPage = min((int) $request->get('per_page', 10), 30);

        // Use Meilisearch via Scout if configured, fall back to MySQL FULLTEXT
        try {
            $results = ArticleTranslation::search($request->q)
                ->where('language_id', $langId)
                ->paginate($perPage);
        } catch (\Exception) {
            // MySQL FULLTEXT fallback
            $results = ArticleTranslation::where('language_id', $langId)
                ->whereFullText(['title', 'summary'], $request->q)
                ->paginate($perPage);
        }

        $articleIds = $results->pluck('article_id')->toArray();

        $articles = \App\Models\Article::with([
            'translations' => fn($q) => $q->where('language_id', $langId),
            'mainCategory',
            'author',
            'featuredImage',
        ])
        ->whereIn('id', $articleIds)
        ->published()
        ->get();

        return response()->json([
            'data' => \App\Http\Resources\ArticleResource::collection($articles),
            'meta' => [
                'query'        => $request->q,
                'total'        => $results->total(),
                'current_page' => $results->currentPage(),
                'last_page'    => $results->lastPage(),
            ],
        ]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MembershipController
// ─────────────────────────────────────────────────────────────────────────────
class MembershipController extends Controller
{
    // GET /api/v1/memberships/plans
    public function plans(Request $request): JsonResponse
    {
        $channelId = DB::table('channels')->where('slug', 'cni-news')->value('id');

        $plans = MembershipPlan::where('channel_id', $channelId)
            ->where('is_active', true)
            ->where('is_publicly_visible', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn($plan) => [
                'id'             => $plan->id,
                'name'           => $plan->name,
                'slug'           => $plan->slug,
                'description'    => $plan->description,
                'price_amount'   => $plan->price_amount,
                'price_currency' => $plan->price_currency,
                'billing_cycle'  => $plan->billing_cycle,
                'badge_label'    => $plan->badge_label,
                'badge_color'    => $plan->badge_color,
                'features'       => $plan->features ?? [],
                'is_free_tier'   => $plan->is_free_tier,
                'formatted_price'=> $plan->formattedPrice(),
            ]);

        return response()->json(['data' => $plans]);
    }

    // POST /api/v1/memberships/validate-promo
    public function validatePromo(Request $request): JsonResponse
    {
        $request->validate([
            'code'    => ['required', 'string'],
            'plan_id' => ['required', 'integer', 'exists:membership_plans,id'],
        ]);

        $promo = PromoCode::where('code', strtoupper($request->code))
            ->where('is_active', true)
            ->first();

        if (! $promo || ! $promo->isValid($request->user()->id)) {
            return response()->json([
                'valid'   => false,
                'message' => 'This promo code is invalid or has expired.',
            ]);
        }

        if ($promo->applicable_plan_id && $promo->applicable_plan_id != $request->plan_id) {
            return response()->json([
                'valid'   => false,
                'message' => 'This promo code cannot be used with this plan.',
            ]);
        }

        $plan     = MembershipPlan::findOrFail($request->plan_id);
        $discount = $promo->calculateDiscount((float) $plan->price_amount);
        $final    = max(0, $plan->price_amount - $discount);

        return response()->json([
            'valid'          => true,
            'promo_code_id'  => $promo->id,
            'discount_type'  => $promo->discount_type,
            'discount_value' => $promo->discount_value,
            'discount_amount'=> $discount,
            'original_price' => $plan->price_amount,
            'final_price'    => $final,
            'message'        => $promo->discount_type === 'percentage'
                ? "{$promo->discount_value}% off applied!"
                : "£{$discount} discount applied!",
        ]);
    }

    // POST /api/v1/memberships/subscribe
    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'plan_id'       => ['required', 'integer', 'exists:membership_plans,id'],
            'promo_code_id' => ['nullable', 'integer', 'exists:promo_codes,id'],
            'payment_method_id' => ['nullable', 'string'], // Stripe PaymentMethod ID
        ]);

        $user = $request->user();
        $plan = MembershipPlan::findOrFail($request->plan_id);

        // Cancel any existing active subscription first
        Membership::where('user_id', $user->id)
            ->where('status', 'active')
            ->update(['status' => 'canceled', 'canceled_at' => now()]);

        $promo    = $request->filled('promo_code_id')
            ? PromoCode::find($request->promo_code_id)
            : null;
        $discount = $promo ? $promo->calculateDiscount((float) $plan->price_amount) : 0;
        $amountPaid = max(0, $plan->price_amount - $discount);

        $membership = DB::transaction(function () use ($user, $plan, $promo, $discount, $amountPaid, $request) {

            $membership = Membership::create([
                'user_id'            => $user->id,
                'membership_plan_id' => $plan->id,
                'promo_code_id'      => $promo?->id,
                'status'             => $plan->isFreePlan() ? 'active' : 'pending_payment',
                'start_date'         => now()->toDateString(),
                'end_date'           => $plan->billing_cycle === 'monthly'
                    ? now()->addMonth()->toDateString()
                    : ($plan->billing_cycle === 'yearly' ? now()->addYear()->toDateString() : null),
                'auto_renew'         => ! $plan->isFreePlan(),
            ]);

            if (! $plan->isFreePlan()) {
                // Record payment placeholder — actual charge via Stripe webhook
                Payment::create([
                    'user_id'        => $user->id,
                    'payable_type'   => 'membership',
                    'payable_id'     => $membership->id,
                    'membership_id'  => $membership->id,
                    'gateway'        => 'stripe',
                    'amount'         => $plan->price_amount,
                    'discount_amount'=> $discount,
                    'amount_paid'    => $amountPaid,
                    'currency'       => $plan->price_currency,
                    'status'         => 'pending',
                ]);
            }

            // Record promo code use
            if ($promo) {
                PromoCodeUse::create([
                    'promo_code_id' => $promo->id,
                    'user_id'       => $user->id,
                ]);
                $promo->increment('uses_count');
            }

            return $membership;
        });

        return response()->json([
            'data'    => ['membership_id' => $membership->id, 'status' => $membership->status],
            'message' => $plan->isFreePlan()
                ? 'Free plan activated.'
                : 'Subscription created. Complete payment to activate.',
        ], 201);
    }

    // POST /api/v1/memberships/cancel
    public function cancel(Request $request): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $membership = Membership::where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->firstOrFail();

        $membership->update([
            'status'        => 'canceled',
            'auto_renew'    => false,
            'canceled_at'   => now(),
            'cancel_reason' => $request->reason,
        ]);

        return response()->json(['message' => 'Subscription cancelled. Access continues until end of billing period.']);
    }

    // GET /api/v1/my/membership
    public function myMembership(Request $request): JsonResponse
    {
        $membership = Membership::with('plan')
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (! $membership) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'plan'          => $membership->plan->name,
                'slug'          => $membership->plan->slug,
                'status'        => $membership->status,
                'start_date'    => $membership->start_date,
                'end_date'      => $membership->end_date,
                'auto_renew'    => $membership->auto_renew,
                'badge_label'   => $membership->plan->badge_label,
                'badge_color'   => $membership->plan->badge_color,
                'features'      => $membership->plan->features ?? [],
                'is_free'       => $membership->plan->isFreePlan(),
            ],
        ]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PublicController  — misc public endpoints
// ─────────────────────────────────────────────────────────────────────────────
class PublicController extends Controller
{
    // GET /api/v1/live-streams  — active and upcoming
    public function liveStreams(Request $request): JsonResponse
    {
        $channelId = DB::table('channels')->where('slug', 'cni-news')->value('id');

        $streams = \App\Models\LiveStream::where('channel_id', $channelId)
            ->whereIn('status', ['live', 'scheduled'])
            ->orderByRaw("FIELD(status, 'live', 'scheduled')")
            ->orderBy('scheduled_start_at')
            ->get()
            ->map(fn($s) => [
                'id'                  => $s->id,
                'title'               => $s->title,
                'description'         => $s->description,
                'platform'            => $s->primary_platform,
                'platform_stream_id'  => $s->platform_stream_id,
                'status'              => $s->status,
                'scheduled_start_at'  => $s->scheduled_start_at,
                'actual_start_at'     => $s->actual_start_at,
                'is_live'             => $s->status === 'live',
            ]);

        return response()->json(['data' => $streams]);
    }

    // GET /api/v1/events  — upcoming public events
    public function events(Request $request): JsonResponse
    {
        $channelId = DB::table('channels')->where('slug', 'cni-news')->value('id');
        $langId    = DB::table('languages')->where('code', $request->get('lang', 'en'))->value('id') ?? 1;

        $events = \App\Models\Event::with(['translations' => fn($q) => $q->where('language_id', $langId)])
            ->where('channel_id', $channelId)
            ->where('status', 'published')
            ->where('is_public', true)
            ->upcoming()
            ->orderBy('starts_at')
            ->paginate(12);

        return response()->json([
            'data' => $events->map(fn($e) => [
                'id'            => $e->id,
                'title'         => $e->translations->first()?->title ?? $e->title,
                'location_name' => $e->location_name,
                'city'          => $e->city,
                'starts_at'     => $e->starts_at,
                'ends_at'       => $e->ends_at,
                'ticket_price'  => $e->ticket_price,
                'is_free'       => $e->ticket_price == 0,
            ]),
            'meta' => ['total' => $events->total()],
        ]);
    }

    // GET /api/v1/verify-card/{cardNumber}  — public card verification (QR scan)
    public function verifyCard(string $cardNumber): JsonResponse
    {
        $card = \App\Models\EmployeeCard::with(['employee.user'])
            ->where('card_number', $cardNumber)
            ->first();

        if (! $card) {
            return response()->json(['valid' => false, 'message' => 'Card not found.'], 404);
        }

        return response()->json([
            'valid'       => $card->isValid(),
            'name'        => $card->employee?->user?->display_name,
            'designation' => $card->employee?->designation,
            'department'  => $card->employee?->department,
            'card_type'   => $card->card_type,
            'status'      => $card->status,
            'expires'     => $card->expiry_date?->format('d M Y'),
            'message'     => $card->isValid() ? 'Card is valid.' : 'Card is ' . $card->status . '.',
        ]);
    }

    // GET /health
    public function health(): JsonResponse
    {
        $checks = [
            'status' => 'ok',
            'db'     => 'ok',
            'cache'  => 'ok',
        ];

        try {
            DB::connection()->getPdo();
        } catch (\Exception) {
            $checks['db']     = 'error';
            $checks['status'] = 'degraded';
        }

        try {
            \Cache::store()->ping();
        } catch (\Exception) {
            $checks['cache']  = 'unavailable';
        }

        return response()->json($checks, $checks['status'] === 'ok' ? 200 : 503);
    }
}
