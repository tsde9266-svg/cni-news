<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── membership_plans ───────────────────────────────────────────────
        // Fully editable by super_admin — name, price, features, everything.
        // Tiers: Free, Gold, Platinum (or whatever admin configures)
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('channel_id');
            $table->string('name', 120);            // "Gold", "Platinum" — fully editable
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            $table->decimal('price_amount', 8, 2)->default(0.00); // 0 = Free tier
            $table->char('price_currency', 3)->default('GBP');
            $table->enum('billing_cycle', ['monthly', 'yearly', 'lifetime'])->default('monthly');
            $table->string('stripe_price_id', 200)->nullable(); // Stripe Price object ID
            $table->string('paypal_plan_id', 200)->nullable();  // PayPal plan ID
            $table->unsignedTinyInteger('max_devices')->nullable(); // null = unlimited
            $table->json('features')->nullable();
            /*
             * features JSON structure:
             * {
             *   "ad_free": true,
             *   "download_articles": false,
             *   "exclusive_content": true,
             *   "early_access": false,
             *   "member_badge": true,
             *   "live_stream_hd": true,
             *   "custom_label": "Gold Member"
             * }
             */
            $table->unsignedSmallInteger('sort_order')->default(0); // display order on pricing page
            $table->string('badge_color', 7)->nullable();           // hex color for member badge, e.g. #C9A84C
            $table->string('badge_label', 40)->nullable();          // "Gold", "Platinum" etc.
            $table->boolean('is_active')->default(true);
            $table->boolean('is_publicly_visible')->default(true);  // false = invite-only plans
            $table->boolean('is_free_tier')->default(false);        // marks the free plan
            $table->timestamps();

            $table->index(['channel_id', 'is_active']);
            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
        });

        // ── promo_codes ────────────────────────────────────────────────────
        // Admin generates promo/discount codes — better than Stripe coupons alone
        // because admin can manage them entirely from the CMS.
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('channel_id');
            $table->unsignedBigInteger('created_by_user_id'); // admin who created it
            $table->string('code', 40)->unique();              // e.g. CNI2025, RAMADAN50
            $table->string('description', 255)->nullable();   // internal note
            $table->enum('discount_type', ['percentage', 'fixed_amount'])->default('percentage');
            $table->decimal('discount_value', 8, 2);          // 50 = 50% or £5.00
            $table->char('currency', 3)->default('GBP');       // only used for fixed_amount
            $table->unsignedBigInteger('applicable_plan_id')->nullable(); // null = applies to any plan
            $table->unsignedInteger('max_uses')->nullable();   // null = unlimited
            $table->unsignedInteger('uses_count')->default(0); // tracks current usage
            $table->unsignedTinyInteger('max_uses_per_user')->default(1);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('stripe_coupon_id', 200)->nullable(); // synced to Stripe if needed
            $table->timestamps();

            $table->index(['code', 'is_active']);
            $table->index('channel_id');

            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users');
            $table->foreign('applicable_plan_id')->references('id')->on('membership_plans')->nullOnDelete();
        });

        // ── memberships ────────────────────────────────────────────────────
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('membership_plan_id');
            $table->unsignedBigInteger('promo_code_id')->nullable(); // which promo was used
            // Stripe
            $table->string('stripe_subscription_id', 200)->nullable();
            $table->string('stripe_customer_id', 200)->nullable();
            // PayPal
            $table->string('paypal_subscription_id', 200)->nullable();
            $table->enum('status', [
                'active',
                'trialing',
                'expired',
                'canceled',
                'pending_payment',
                'paused',
            ])->default('pending_payment');
            $table->timestamp('trial_ends_at')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('stripe_subscription_id');
            $table->index('membership_plan_id');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('membership_plan_id')->references('id')->on('membership_plans');
            $table->foreign('promo_code_id')->references('id')->on('promo_codes')->nullOnDelete();
        });

        // ── payments ───────────────────────────────────────────────────────
        // Full payment ledger. Immutable — never update, only insert.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('payable_type', 60);    // membership | ad_invoice
            $table->unsignedBigInteger('payable_id');
            $table->unsignedBigInteger('membership_id')->nullable(); // convenience FK
            $table->enum('gateway', ['stripe', 'paypal', 'manual'])->default('stripe');
            $table->string('gateway_transaction_id', 200)->nullable(); // Stripe PaymentIntent ID or PayPal order ID
            $table->string('gateway_invoice_id', 200)->nullable();     // Stripe Invoice ID
            $table->decimal('amount', 8, 2);
            $table->decimal('discount_amount', 8, 2)->default(0.00);  // from promo code
            $table->decimal('amount_paid', 8, 2);                      // amount - discount
            $table->char('currency', 3)->default('GBP');
            $table->enum('status', ['pending', 'succeeded', 'failed', 'refunded', 'partially_refunded'])->default('pending');
            $table->string('payment_method_type', 60)->nullable();     // card, paypal, bank_transfer
            $table->string('payment_method_last4', 4)->nullable();     // last 4 digits of card
            $table->string('payment_method_brand', 20)->nullable();    // visa, mastercard
            $table->string('receipt_url', 500)->nullable();
            $table->decimal('refund_amount', 8, 2)->default(0.00);
            $table->string('refund_reason', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('gateway_metadata')->nullable();              // raw gateway response
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['payable_type', 'payable_id']);
            $table->index('gateway_transaction_id');
            $table->index('paid_at');

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('membership_id')->references('id')->on('memberships')->nullOnDelete();
        });

        // ── promo_code_uses ────────────────────────────────────────────────
        // Track every use of a promo code (for per-user limits and reporting)
        Schema::create('promo_code_uses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promo_code_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->timestamp('used_at')->useCurrent();

            $table->index(['promo_code_id', 'user_id']);

            $table->foreign('promo_code_id')->references('id')->on('promo_codes')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
        });

        // ── membership_access_rules ────────────────────────────────────────
        // Defines which content requires a minimum plan (content gating)
        Schema::create('membership_access_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('content_type', ['article', 'video', 'live_stream', 'event', 'category']);
            $table->unsignedBigInteger('content_id')->nullable(); // null = rule applies to entire type
            $table->unsignedBigInteger('min_plan_id');
            $table->timestamps();

            $table->index(['content_type', 'content_id']);
            $table->foreign('min_plan_id')->references('id')->on('membership_plans')->cascadeOnDelete();
        });

        // ── invoices ───────────────────────────────────────────────────────
        // Human-readable invoice records (PDF generation source)
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('payment_id');
            $table->string('invoice_number', 40)->unique(); // CNI-2025-00001
            $table->decimal('subtotal', 8, 2);
            $table->decimal('discount', 8, 2)->default(0.00);
            $table->decimal('tax', 8, 2)->default(0.00);
            $table->decimal('total', 8, 2);
            $table->char('currency', 3)->default('GBP');
            $table->string('billing_name', 150)->nullable();
            $table->string('billing_email', 255)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('pdf_path', 500)->nullable(); // S3 path to generated PDF
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'issued_at']);

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('payment_id')->references('id')->on('payments');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('promo_code_uses');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('membership_access_rules');
        Schema::dropIfExists('membership_plans');
    }
};
