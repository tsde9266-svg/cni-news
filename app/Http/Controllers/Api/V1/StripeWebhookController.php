<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AdBooking;
use App\Models\Membership;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    /**
     * POST /api/v1/webhooks/stripe
     *
     * Handles Stripe webhook events to keep membership status in sync.
     * Add this URL in Stripe Dashboard → Webhooks.
     *
     * Required events to subscribe to in Stripe:
     *   - invoice.payment_succeeded
     *   - invoice.payment_failed
     *   - customer.subscription.deleted
     *   - customer.subscription.updated
     */
    public function handle(Request $request): Response
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret    = config('services.stripe.webhook_secret');

        // Verify webhook signature
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);
            return response('Invalid signature.', 400);
        } catch (\Exception $e) {
            return response('Webhook error: ' . $e->getMessage(), 400);
        }

        // Route event to handler
        match ($event->type) {
            'invoice.payment_succeeded'       => $this->onPaymentSucceeded($event->data->object),
            'invoice.payment_failed'          => $this->onPaymentFailed($event->data->object),
            'customer.subscription.deleted'   => $this->onSubscriptionCanceled($event->data->object),
            'customer.subscription.updated'   => $this->onSubscriptionUpdated($event->data->object),
            'checkout.session.completed'      => $this->onCheckoutCompleted($event->data->object),
            default                           => null,
        };

        return response('Webhook handled.', 200);
    }

    private function onPaymentSucceeded(object $invoice): void
    {
        $stripeSubId = $invoice->subscription;
        if (! $stripeSubId) return;

        $membership = Membership::where('stripe_subscription_id', $stripeSubId)->first();
        if (! $membership) return;

        // Activate membership and extend end date
        $membership->update([
            'status'    => 'active',
            'end_date'  => now()->addMonth()->toDateString(),
            'auto_renew'=> true,
        ]);

        // Record payment
        Payment::create([
            'user_id'                => $membership->user_id,
            'payable_type'           => 'membership',
            'payable_id'             => $membership->id,
            'membership_id'          => $membership->id,
            'gateway'                => 'stripe',
            'gateway_transaction_id' => $invoice->payment_intent,
            'gateway_invoice_id'     => $invoice->id,
            'amount'                 => $invoice->amount_paid / 100,
            'discount_amount'        => 0,
            'amount_paid'            => $invoice->amount_paid / 100,
            'currency'               => strtoupper($invoice->currency),
            'status'                 => 'succeeded',
            'receipt_url'            => $invoice->hosted_invoice_url,
            'paid_at'                => now(),
        ]);

        Log::info('Stripe payment succeeded', ['membership_id' => $membership->id]);
    }

    private function onPaymentFailed(object $invoice): void
    {
        $stripeSubId = $invoice->subscription;
        if (! $stripeSubId) return;

        $membership = Membership::where('stripe_subscription_id', $stripeSubId)->first();
        if (! $membership) return;

        $membership->update(['status' => 'pending_payment']);

        Log::warning('Stripe payment failed', ['membership_id' => $membership->id]);
    }

    private function onSubscriptionCanceled(object $subscription): void
    {
        $membership = Membership::where('stripe_subscription_id', $subscription->id)->first();
        if (! $membership) return;

        $membership->update([
            'status'       => 'canceled',
            'auto_renew'   => false,
            'canceled_at'  => now(),
        ]);

        Log::info('Stripe subscription canceled', ['membership_id' => $membership->id]);
    }

    /**
     * Handles one-time Stripe Checkout payments (e.g. ad bookings).
     * Metadata type dispatches to the right handler.
     */
    private function onCheckoutCompleted(object $session): void
    {
        $type = $session->metadata->type ?? null;

        if ($type === 'ad_booking') {
            $reference = $session->metadata->booking_reference ?? null;
            if (! $reference) return;

            $booking = AdBooking::where('reference', $reference)->first();
            if (! $booking) return;

            $booking->update([
                'payment_status'              => 'paid',
                'booking_status'              => 'pending_review',
                'stripe_payment_intent_id'    => $session->payment_intent,
                'paid_at'                     => now(),
                'receipt_url'                 => $session->receipt_url ?? null,
            ]);

            Log::info('AdBooking paid', ['reference' => $reference]);
        }
    }

    private function onSubscriptionUpdated(object $subscription): void
    {
        $membership = Membership::where('stripe_subscription_id', $subscription->id)->first();
        if (! $membership) return;

        $statusMap = [
            'active'   => 'active',
            'trialing' => 'trialing',
            'past_due' => 'pending_payment',
            'canceled' => 'canceled',
            'unpaid'   => 'pending_payment',
            'paused'   => 'paused',
        ];

        $newStatus = $statusMap[$subscription->status] ?? 'pending_payment';
        $membership->update(['status' => $newStatus]);
    }
}
