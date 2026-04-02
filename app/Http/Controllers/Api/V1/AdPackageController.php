<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AdPackage;
use Illuminate\Http\JsonResponse;

class AdPackageController extends Controller
{
    /** GET /api/v1/ad-packages */
    public function index(): JsonResponse
    {
        $packages = AdPackage::active()
            ->orderBy('sort_order')
            ->get()
            ->map(fn($p) => $this->format($p));

        return response()->json(['data' => $packages]);
    }

    /** GET /api/v1/ad-packages/{slug} */
    public function show(string $slug): JsonResponse
    {
        $package = AdPackage::active()->where('slug', $slug)->firstOrFail();
        return response()->json(['data' => $this->format($package)]);
    }

    private function format(AdPackage $p): array
    {
        return [
            'id'              => $p->id,
            'slug'            => $p->slug,
            'name'            => $p->name,
            'tagline'         => $p->tagline,
            'description'     => $p->description,
            'category'        => $p->category,
            'placement'       => $p->placement,
            'platform'        => $p->platform,
            'price_amount'    => (float) $p->price_amount,
            'price_currency'  => $p->price_currency,
            'formatted_price' => $p->formatted_price,
            'duration_days'   => $p->duration_days,
            'dimensions'      => $p->dimensions,
            'is_featured'     => $p->is_featured,
            'features'        => $p->features ?? [],
            'icon_emoji'      => $p->icon_emoji,
        ];
    }
}
