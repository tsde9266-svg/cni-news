<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AdBooking;
use App\Models\AdPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AdBookingController extends Controller
{
    /**
     * POST /api/v1/ad-bookings
     *
     * Creates an ad booking and returns a Stripe Checkout URL.
     * No authentication required — open to any advertiser.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_slug'     => 'required|string|exists:ad_packages,slug',
            'advertiser_name'  => 'required|string|max:150',
            'advertiser_email' => 'required|email|max:255',
            'advertiser_phone' => 'nullable|string|max:30',
            'company_name'     => 'nullable|string|max:150',
            'company_website'  => 'nullable|url|max:255',
            'campaign_title'   => 'nullable|string|max:200',
            'creative_url'     => 'nullable|url|max:500',
            'click_url'        => 'nullable|url|max:500',
            'brief_text'       => 'nullable|string|max:2000',
            'start_date'       => 'required|date|after_or_equal:tomorrow',
        ]);

        $package = AdPackage::active()->where('slug', $data['package_slug'])->firstOrFail();

        // Create booking record
        $booking = AdBooking::create([
            'ad_package_id'    => $package->id,
            'advertiser_name'  => $data['advertiser_name'],
            'advertiser_email' => $data['advertiser_email'],
            'advertiser_phone' => $data['advertiser_phone'] ?? null,
            'company_name'     => $data['company_name'] ?? null,
            'company_website'  => $data['company_website'] ?? null,
            'campaign_title'   => $data['campaign_title'] ?? null,
            'creative_url'     => $data['creative_url'] ?? null,
            'click_url'        => $data['click_url'] ?? null,
            'brief_text'       => $data['brief_text'] ?? null,
            'start_date'       => $data['start_date'],
            'price_amount'     => $package->price_amount,
            'price_currency'   => $package->price_currency,
            'booking_status'   => 'pending_payment',
            'payment_status'   => 'pending',
            'ip_address'       => $request->ip(),
        ]);

        // Create Stripe Checkout Session
        try {
            $stripe      = new \Stripe\StripeClient(config('services.stripe.secret'));
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

            $session = $stripe->checkout->sessions->create([
                'mode'          => 'payment',
                'payment_method_types' => ['card'],
                'line_items'    => [[
                    'price_data' => [
                        'currency'     => strtolower($package->price_currency),
                        'product_data' => [
                            'name'        => 'CNI News — ' . $package->name,
                            'description' => $package->tagline ?? $package->name,
                        ],
                        'unit_amount'  => (int) ($package->price_amount * 100),
                    ],
                    'quantity'   => 1,
                ]],
                'customer_email'    => $booking->advertiser_email,
                'success_url'       => $frontendUrl . '/advertise/confirmation/' . $booking->reference . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'        => $frontendUrl . '/advertise/book/' . $package->slug . '?cancelled=1',
                'metadata'          => [
                    'type'               => 'ad_booking',
                    'booking_reference'  => $booking->reference,
                    'booking_id'         => (string) $booking->id,
                ],
            ]);

            $booking->update(['stripe_checkout_session_id' => $session->id]);

            return response()->json([
                'reference'    => $booking->reference,
                'checkout_url' => $session->url,
            ], 201);

        } catch (\Exception $e) {
            Log::error('AdBooking: Stripe session creation failed', [
                'reference' => $booking->reference,
                'error'     => $e->getMessage(),
            ]);
            // Don't expose Stripe error to client; booking record stays for recovery
            return response()->json([
                'message' => 'Payment setup failed. Please try again or contact us.',
            ], 502);
        }
    }

    /**
     * GET /api/v1/ad-bookings/{reference}
     *
     * Public status check — advertiser can track their booking.
     */
    public function show(string $reference): JsonResponse
    {
        $booking = AdBooking::with('package')
            ->where('reference', $reference)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'reference'      => $booking->reference,
                'package_name'   => $booking->package->name,
                'campaign_title' => $booking->campaign_title,
                'start_date'     => $booking->start_date?->toDateString(),
                'end_date'       => $booking->end_date?->toDateString(),
                'price_amount'   => (float) $booking->price_amount,
                'price_currency' => $booking->price_currency,
                'payment_status' => $booking->payment_status,
                'booking_status' => $booking->booking_status,
                'paid_at'        => $booking->paid_at?->toIso8601String(),
            ],
        ]);
    }
}
