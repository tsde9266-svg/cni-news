<?php
// ─────────────────────────────────────────────────────────────────────────────
// LANGUAGE SEEDER
// File: database/seeders/LanguageSeeder.php
// ─────────────────────────────────────────────────────────────────────────────
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// ─────────────────────────────────────────────────────────────────────────────
// MEMBERSHIP PLAN SEEDER
// File: database/seeders/MembershipPlanSeeder.php
// ─────────────────────────────────────────────────────────────────────────────

class MembershipPlanSeeder extends Seeder
{
    public function run(): void
    {
        $channelId = DB::table('channels')->where('slug', 'cni-news')->value('id');

        $plans = [
            [
                'name'               => 'Free',
                'slug'               => 'free',
                'description'        => 'Basic access to CNI News — no payment required.',
                'price_amount'       => 0.00,
                'billing_cycle'      => 'lifetime',
                'is_free_tier'       => true,
                'badge_label'        => 'Free',
                'badge_color'        => '#6B7280',
                'sort_order'         => 1,
                'features'           => json_encode([
                    'ad_free'           => false,
                    'exclusive_content' => false,
                    'download_articles' => false,
                    'early_access'      => false,
                    'live_stream_hd'    => false,
                    'member_badge'      => false,
                    'custom_label'      => 'Free Reader',
                ]),
            ],
            [
                'name'               => 'Gold',
                'slug'               => 'gold',
                'description'        => 'Support CNI News and enjoy an ad-free experience.',
                'price_amount'       => 3.99,
                'billing_cycle'      => 'monthly',
                'is_free_tier'       => false,
                'badge_label'        => 'Gold',
                'badge_color'        => '#C9A84C',
                'sort_order'         => 2,
                'features'           => json_encode([
                    'ad_free'           => true,
                    'exclusive_content' => false,
                    'download_articles' => true,
                    'early_access'      => false,
                    'live_stream_hd'    => false,
                    'member_badge'      => true,
                    'custom_label'      => 'Gold Member',
                ]),
            ],
            [
                'name'               => 'Platinum',
                'slug'               => 'platinum',
                'description'        => 'Full premium access — exclusive content, HD streams, early access.',
                'price_amount'       => 7.99,
                'billing_cycle'      => 'monthly',
                'is_free_tier'       => false,
                'badge_label'        => 'Platinum',
                'badge_color'        => '#6D6D8A',
                'sort_order'         => 3,
                'features'           => json_encode([
                    'ad_free'           => true,
                    'exclusive_content' => true,
                    'download_articles' => true,
                    'early_access'      => true,
                    'live_stream_hd'    => true,
                    'member_badge'      => true,
                    'custom_label'      => 'Platinum Member',
                ]),
            ],
        ];

        foreach ($plans as $plan) {
            DB::table('membership_plans')->updateOrInsert(
                ['slug' => $plan['slug'], 'channel_id' => $channelId],
                array_merge($plan, [
                    'channel_id'          => $channelId,
                    'is_active'           => true,
                    'is_publicly_visible' => true,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ])
            );
        }
    }
}