<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        if ($request->filled('search')) $query->where('title', 'like', '%' . $request->search . '%');

        $paged = $query->paginate(min((int) $request->get('per_page', 20), 100));

        return response()->json([
            'data' => collect($paged->items())->map(fn($e) => [
                'id'            => $e->id, 'title'        => $e->title,
                'description'   => $e->description, 'location_name' => $e->location_name,
                'city'          => $e->city, 'country' => $e->country,
                'starts_at'     => $e->starts_at, 'ends_at' => $e->ends_at,
                'status'        => $e->status, 'is_public' => (bool) $e->is_public,
                'ticket_price'  => (float) $e->ticket_price, 'max_capacity' => $e->max_capacity,
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
        $request->validate(['title' => 'required|string|max:255', 'starts_at' => 'required|date']);

        $id = DB::table('events')->insertGetId([
            'channel_id'        => $this->channelId(),
            'organizer_user_id' => $request->user()->id,
            'title'             => $request->title,
            'description'       => $request->description,
            'location_name'     => $request->location_name,
            'address'           => $request->address,
            'city'              => $request->city,
            'country'           => $request->get('country', 'GB'),
            'starts_at'         => $request->starts_at,
            'ends_at'           => $request->ends_at,
            'status'            => $request->get('status', 'draft'),
            'is_public'         => $request->boolean('is_public', true),
            'ticket_price'      => $request->get('ticket_price', 0),
            'max_capacity'      => $request->max_capacity,
            'created_at'        => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Event created.', 'id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = array_filter([
            'title'         => $request->title, 'description' => $request->description,
            'location_name' => $request->location_name, 'city' => $request->city,
            'starts_at'     => $request->starts_at, 'ends_at' => $request->ends_at,
            'status'        => $request->status, 'ticket_price' => $request->ticket_price,
            'max_capacity'  => $request->max_capacity, 'updated_at' => now(),
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
