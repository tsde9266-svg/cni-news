<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdBooking;
use App\Models\AdPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdBookingAdminController extends Controller
{
    /** GET /api/v1/admin/ad-bookings */
    public function index(Request $request): JsonResponse
    {
        $q = AdBooking::with(['package', 'reviewer'])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $q->where('booking_status', $status);
        }
        if ($request->query('unpaid')) {
            $q->where('payment_status', '!=', 'paid');
        }

        return response()->json(['data' => $q->paginate(25)]);
    }

    /** GET /api/v1/admin/ad-bookings/{id} */
    public function show(int $id): JsonResponse
    {
        $booking = AdBooking::with(['package', 'reviewer'])->findOrFail($id);
        return response()->json(['data' => $booking]);
    }

    /**
     * POST /api/v1/admin/ad-bookings/{id}/confirm
     *
     * Admin confirms a pending_review booking.
     * Sets end_date = start_date + package.duration_days - 1
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $booking = AdBooking::with('package')->findOrFail($id);

        if ($booking->booking_status !== 'pending_review') {
            return response()->json(['message' => 'Booking is not awaiting review.'], 422);
        }

        $endDate = (clone $booking->start_date)
            ->addDays($booking->package->duration_days - 1);

        $booking->update([
            'booking_status'       => 'confirmed',
            'end_date'             => $endDate->toDateString(),
            'reviewed_by_user_id'  => $request->user()->id,
            'reviewed_at'          => now(),
            'admin_notes'          => $request->input('admin_notes'),
        ]);

        Log::info('AdBooking confirmed', ['reference' => $booking->reference, 'by' => $request->user()->id]);

        // TODO: dispatch a ConfirmAdBookingNotification mail to $booking->advertiser_email

        return response()->json(['data' => $booking->fresh()]);
    }

    /**
     * POST /api/v1/admin/ad-bookings/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|max:500']);

        $booking = AdBooking::findOrFail($id);

        if (! in_array($booking->booking_status, ['pending_review', 'confirmed'])) {
            return response()->json(['message' => 'Cannot reject this booking in its current state.'], 422);
        }

        $booking->update([
            'booking_status'      => 'rejected',
            'rejection_reason'    => $request->input('reason'),
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at'         => now(),
        ]);

        // TODO: dispatch a RejectedAdBookingNotification mail + Stripe refund if paid

        return response()->json(['data' => $booking->fresh()]);
    }

    /**
     * POST /api/v1/admin/ad-bookings/{id}/activate
     * Manually flip a confirmed booking to active (if scheduler not set up yet).
     */
    public function activate(int $id): JsonResponse
    {
        $booking = AdBooking::findOrFail($id);
        $booking->update(['booking_status' => 'active']);
        return response()->json(['data' => $booking->fresh()]);
    }

    // ── Ad Packages CRUD ──────────────────────────────────────────────────

    /** GET /api/v1/admin/ad-packages */
    public function packages(): JsonResponse
    {
        return response()->json([
            'data' => AdPackage::orderBy('sort_order')->get(),
        ]);
    }

    /** POST /api/v1/admin/ad-packages */
    public function storePackage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug'          => 'required|string|unique:ad_packages,slug',
            'name'          => 'required|string|max:150',
            'tagline'       => 'nullable|string|max:255',
            'description'   => 'nullable|string',
            'category'      => 'required|in:website,social,bundle',
            'placement'     => 'nullable|string|max:100',
            'platform'      => 'nullable|string|max:50',
            'price_amount'  => 'required|numeric|min:0',
            'price_currency'=> 'required|string|size:3',
            'duration_days' => 'required|integer|min:1',
            'dimensions'    => 'nullable|string|max:50',
            'is_featured'   => 'boolean',
            'is_active'     => 'boolean',
            'sort_order'    => 'integer',
            'icon_emoji'    => 'nullable|string',
            'features'      => 'nullable|array',
        ]);

        $package = AdPackage::create($data);
        return response()->json(['data' => $package], 201);
    }

    /** PATCH /api/v1/admin/ad-packages/{id} */
    public function updatePackage(Request $request, int $id): JsonResponse
    {
        $package = AdPackage::findOrFail($id);
        $data = $request->validate([
            'name'          => 'sometimes|string|max:150',
            'tagline'       => 'nullable|string|max:255',
            'description'   => 'nullable|string',
            'price_amount'  => 'sometimes|numeric|min:0',
            'is_featured'   => 'sometimes|boolean',
            'is_active'     => 'sometimes|boolean',
            'sort_order'    => 'sometimes|integer',
            'features'      => 'nullable|array',
        ]);
        $package->update($data);
        return response()->json(['data' => $package->fresh()]);
    }
}
