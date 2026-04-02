<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        if ($request->filled('status')) $query->where('payments.status', $request->status);
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

        $monthRevenue = DB::table('payments')
            ->join('users', 'payments.user_id', '=', 'users.id')
            ->where('users.channel_id', $this->channelId())
            ->where('payments.status', 'succeeded')
            ->whereYear('payments.paid_at', now()->year)
            ->whereMonth('payments.paid_at', now()->month)
            ->sum('payments.amount_paid');

        return response()->json([
            'data' => collect($paged->items())->map(fn($p) => [
                'id'              => $p->id,
                'display_name'    => $p->display_name,
                'email'           => $p->email,
                'amount'          => (float) $p->amount,
                'discount_amount' => (float) $p->discount_amount,
                'amount_paid'     => (float) $p->amount_paid,
                'currency'        => $p->currency,
                'status'          => $p->status,
                'gateway'         => $p->gateway,
                'card'            => $p->payment_method_brand
                    ? "{$p->payment_method_brand} •••• {$p->payment_method_last4}" : null,
                'receipt_url'     => $p->receipt_url,
                'paid_at'         => $p->paid_at,
                'created_at'      => $p->created_at,
            ]),
            'meta' => [
                'current_page'  => $paged->currentPage(), 'last_page' => $paged->lastPage(),
                'per_page'      => $paged->perPage(),     'total'     => $paged->total(),
                'from'          => $paged->firstItem(),   'to'        => $paged->lastItem(),
                'month_revenue' => round((float) $monthRevenue, 2),
            ],
        ]);
    }
}
