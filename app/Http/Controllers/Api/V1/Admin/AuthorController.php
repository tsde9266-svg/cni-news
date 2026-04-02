<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthorController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    // ── GET /api/v1/admin/authors ─────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('author_profiles as ap')
            ->join('users as u', 'u.id', '=', 'ap.user_id')
            ->where('u.channel_id', $this->channelId())
            ->whereNull('u.deleted_at')
            ->whereNull('ap.deleted_at')
            ->select([
                'ap.id',
                'ap.user_id',
                'ap.pen_name',
                'ap.byline',
                'ap.bio_short',
                'ap.is_monetised',
                'ap.is_active',
                'ap.can_self_publish',
                'ap.default_rate_type',
                'ap.default_rate_amount',
                'ap.rate_currency',
                'ap.created_at',
                'u.email',
                'u.first_name',
                'u.last_name',
                'u.display_name',
                DB::raw('(SELECT COUNT(*) FROM articles WHERE author_user_id = u.id) as article_count'),
            ]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('ap.pen_name', 'like', "%{$s}%")
                  ->orWhere('u.email', 'like', "%{$s}%")
                  ->orWhere('u.display_name', 'like', "%{$s}%")
            );
        }

        if ($request->filled('monetised')) {
            $query->where('ap.is_monetised', $request->monetised === 'true');
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $paged   = $query->orderByDesc('ap.created_at')->paginate($perPage);

        return response()->json([
            'data' => $paged->items(),
            'meta' => [
                'total'        => $paged->total(),
                'current_page' => $paged->currentPage(),
                'last_page'    => $paged->lastPage(),
                'per_page'     => $paged->perPage(),
            ],
        ]);
    }

    // ── PATCH /api/v1/admin/authors/{id}/toggle-monetise ─────────────────
    public function toggleMonetise(Request $request, int $id): JsonResponse
    {
        $profile = DB::table('author_profiles')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$profile) return response()->json(['error' => 'Author not found.'], 404);

        $new = !$profile->is_monetised;
        DB::table('author_profiles')->where('id', $id)->update([
            'is_monetised' => $new,
            'updated_at'   => now(),
        ]);

        return response()->json([
            'message'      => $new ? 'Author monetisation enabled.' : 'Author monetisation disabled.',
            'is_monetised' => $new,
        ]);
    }

    // ── PATCH /api/v1/admin/authors/{id}/set-rate ─────────────────────────
    public function setRate(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'rate_type'   => ['required', 'in:per_article,per_word,per_view,flat_monthly'],
            'rate_amount' => ['required', 'numeric', 'min:0', 'max:9999.9999'],
            'currency'    => ['nullable', 'string', 'size:3'],
        ]);

        DB::table('author_profiles')->where('id', $id)->update([
            'default_rate_type'   => $request->rate_type,
            'default_rate_amount' => $request->rate_amount,
            'rate_currency'       => $request->currency ?? 'GBP',
            'updated_at'          => now(),
        ]);

        return response()->json(['message' => 'Rate updated.']);
    }

    // ── POST /api/v1/admin/authors/{id}/set-self-publish ──────────────────
    public function setSelfPublish(Request $request, int $id): JsonResponse
    {
        $request->validate(['can_self_publish' => ['required', 'boolean']]);

        DB::table('author_profiles')->where('id', $id)->update([
            'can_self_publish' => $request->can_self_publish,
            'updated_at'       => now(),
        ]);

        return response()->json([
            'message'          => 'Self-publish setting updated.',
            'can_self_publish' => $request->can_self_publish,
        ]);
    }

    // ── GET /api/v1/admin/author-earnings ────────────────────────────────
    public function earnings(Request $request): JsonResponse
    {
        $query = DB::table('author_earnings as ae')
            ->join('author_profiles as ap', 'ap.id', '=', 'ae.author_profile_id')
            ->join('users as u', 'u.id', '=', 'ap.user_id')
            ->leftJoin('articles as a', 'a.id', '=', 'ae.article_id')
            ->where('u.channel_id', $this->channelId())
            ->select([
                'ae.id',
                'ae.author_profile_id',
                'ae.article_id',
                'ae.earning_type',
                'ae.amount',
                'ae.currency',
                'ae.description',
                'ae.status',
                'ae.earned_at',
                'ap.pen_name',
                'u.email as author_email',
                'u.display_name as author_name',
                'a.slug as article_slug',
            ]);

        if ($request->filled('status')) {
            $query->where('ae.status', $request->status);
        }
        if ($request->filled('author_id')) {
            $query->where('ap.user_id', $request->author_id);
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $paged   = $query->orderByDesc('ae.earned_at')->paginate($perPage);

        return response()->json([
            'data' => $paged->items(),
            'meta' => [
                'total'        => $paged->total(),
                'current_page' => $paged->currentPage(),
                'last_page'    => $paged->lastPage(),
            ],
        ]);
    }

    // ── POST /api/v1/admin/author-earnings/{id}/approve ───────────────────
    public function approveEarning(Request $request, int $id): JsonResponse
    {
        $rows = DB::table('author_earnings')->where('id', $id)->update([
            'status'              => 'approved',
            'approved_by_user_id' => $request->user()->id,
            'updated_at'          => now(),
        ]);

        if (!$rows) return response()->json(['error' => 'Earning not found.'], 404);

        return response()->json(['message' => 'Earning approved.']);
    }

    // ── POST /api/v1/admin/author-payouts ─────────────────────────────────
    public function createPayout(Request $request): JsonResponse
    {
        $request->validate([
            'author_profile_id' => ['required', 'integer'],
            'amount'            => ['required', 'numeric', 'min:0.01'],
            'currency'          => ['nullable', 'string', 'size:3'],
            'method'            => ['nullable', 'in:bank_transfer,paypal,stripe_connect,cheque'],
            'notes'             => ['nullable', 'string', 'max:500'],
            'period_from'       => ['nullable', 'date'],
            'period_to'         => ['nullable', 'date'],
        ]);

        $id = DB::table('author_payouts')->insertGetId([
            'author_profile_id'    => $request->author_profile_id,
            'processed_by_user_id' => $request->user()->id,
            'amount'               => $request->amount,
            'currency'             => $request->currency ?? 'GBP',
            'method'               => $request->method ?? 'bank_transfer',
            'status'               => 'pending',
            'notes'                => $request->notes,
            'period_from'          => $request->period_from,
            'period_to'            => $request->period_to,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        return response()->json(['message' => 'Payout created.', 'id' => $id], 201);
    }

    // ── GET /api/v1/admin/contributor-applications ────────────────────────
    public function applications(Request $request): JsonResponse
    {
        $query = DB::table('contributor_applications as ca')
            ->leftJoin('users as u', 'u.id', '=', 'ca.user_id')
            ->select([
                'ca.id',
                'ca.user_id',
                'ca.full_name',
                'ca.email',
                'ca.phone',
                'ca.writing_experience',
                'ca.sample_article_url',
                'ca.topics_of_interest',
                'ca.preferred_language',
                'ca.wants_payment',
                'ca.status',
                'ca.review_notes',
                'ca.reviewed_at',
                'ca.created_at',
            ]);

        if ($request->filled('status')) {
            $query->where('ca.status', $request->status);
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $paged   = $query->orderByDesc('ca.created_at')->paginate($perPage);

        return response()->json([
            'data' => $paged->items(),
            'meta' => [
                'total'        => $paged->total(),
                'current_page' => $paged->currentPage(),
                'last_page'    => $paged->lastPage(),
            ],
        ]);
    }

    // ── POST /api/v1/admin/contributor-applications/{id}/review ──────────
    public function reviewApplication(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'in:approved,rejected,on_hold'],
            'notes'  => ['nullable', 'string', 'max:500'],
        ]);

        DB::table('contributor_applications')->where('id', $id)->update([
            'status'               => $request->status,
            'review_notes'         => $request->notes,
            'reviewed_by_user_id'  => $request->user()->id,
            'reviewed_at'          => now(),
            'updated_at'           => now(),
        ]);

        return response()->json(['message' => "Application {$request->status}."]);
    }
}
