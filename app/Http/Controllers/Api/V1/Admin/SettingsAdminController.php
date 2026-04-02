<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
