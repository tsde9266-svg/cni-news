<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $paged = $query->paginate(min((int) $request->get('per_page', 20), 100));

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
                'per_page'     => $paged->perPage(),     'total'     => $paged->total(),
                'from'         => $paged->firstItem(),   'to'        => $paged->lastItem(),
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
            'channel_id'         => $this->channelId(),
            'created_by_user_id' => $request->user()->id,
            'code'               => strtoupper($request->code),
            'description'        => $request->description,
            'discount_type'      => $request->discount_type,
            'discount_value'     => $request->discount_value,
            'max_uses'           => $request->max_uses,
            'max_uses_per_user'  => $request->get('max_uses_per_user', 1),
            'valid_from'         => $request->valid_from,
            'valid_until'        => $request->valid_until,
            'is_active'          => true,
            'uses_count'         => 0,
            'created_at'         => now(), 'updated_at' => now(),
        ]);

        AuditLog::log('promo_code_created', 'promo_code', $id, null, ['code' => $request->code]);
        return response()->json(['message' => 'Promo code created.', 'id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = array_filter([
            'description'    => $request->description,
            'discount_value' => $request->discount_value,
            'max_uses'       => $request->max_uses,
            'valid_from'     => $request->valid_from,
            'valid_until'    => $request->valid_until,
            'is_active'      => $request->has('is_active') ? (int) $request->boolean('is_active') : null,
            'updated_at'     => now(),
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
