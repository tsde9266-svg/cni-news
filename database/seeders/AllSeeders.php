<?php
// ─────────────────────────────────────────────────────────────────────────────
// LANGUAGE SEEDER
// File: database/seeders/LanguageSeeder.php
// ─────────────────────────────────────────────────────────────────────────────
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['code' => 'en', 'name' => 'English',  'direction' => 'ltr', 'is_active' => true],
            ['code' => 'ur', 'name' => 'Urdu',     'direction' => 'rtl', 'is_active' => true],
            ['code' => 'pa', 'name' => 'Punjabi',  'direction' => 'rtl', 'is_active' => true],
            ['code' => 'mi', 'name' => 'Mirpuri',  'direction' => 'rtl', 'is_active' => true],
        ];

        foreach ($languages as $lang) {
            DB::table('languages')->updateOrInsert(['code' => $lang['code']], array_merge($lang, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CHANNEL SEEDER
// File: database/seeders/ChannelSeeder.php
// ─────────────────────────────────────────────────────────────────────────────
// namespace Database\Seeders;  ← (remove duplicate namespace in real files)

class ChannelSeeder extends Seeder
{
    public function run(): void
    {
        $englishId = DB::table('languages')->where('code', 'en')->value('id');

        DB::table('channels')->updateOrInsert(['slug' => 'cni-news'], [
            'name'                => 'CNI News Network',
            'slug'                => 'cni-news',
            'description'         => 'UK-based multilingual news platform for South Asian diaspora',
            'primary_language_id' => $englishId,
            'is_active'           => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ROLE & PERMISSION SEEDER
// File: database/seeders/RolePermissionSeeder.php
// ─────────────────────────────────────────────────────────────────────────────

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // ── Roles ──────────────────────────────────────────────────────────
        $roles = [
            ['slug' => 'super_admin',    'name' => 'Super Admin',    'is_system_role' => true,  'description' => 'Full unrestricted access'],
            ['slug' => 'admin',          'name' => 'Admin',          'is_system_role' => true,  'description' => 'Channel admin'],
            ['slug' => 'editor',         'name' => 'Editor',         'is_system_role' => true,  'description' => 'Approves and publishes content'],
            ['slug' => 'journalist',     'name' => 'Journalist',     'is_system_role' => true,  'description' => 'Creates articles'],
            ['slug' => 'contributor',    'name' => 'Contributor',    'is_system_role' => false, 'description' => 'Submits draft articles'],
            ['slug' => 'moderator',      'name' => 'Moderator',      'is_system_role' => false, 'description' => 'Moderates comments'],
            ['slug' => 'member',         'name' => 'Member',         'is_system_role' => false, 'description' => 'Paid or free subscriber'],
            ['slug' => 'advertiser',     'name' => 'Advertiser',     'is_system_role' => false, 'description' => 'Manages ad campaigns'],
            ['slug' => 'event_manager',  'name' => 'Event Manager',  'is_system_role' => false, 'description' => 'Creates and manages events'],
            ['slug' => 'hr_admin',       'name' => 'HR Admin',       'is_system_role' => false, 'description' => 'Manages employees and press cards'],
            ['slug' => 'finance_admin',  'name' => 'Finance Admin',  'is_system_role' => false, 'description' => 'Views payments and invoices'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(['slug' => $role['slug']], array_merge($role, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }

        // ── Permissions ────────────────────────────────────────────────────
        $permissions = [
            // Articles
            ['module' => 'articles', 'key' => 'article.create',        'description' => 'Create article drafts'],
            ['module' => 'articles', 'key' => 'article.edit_own',      'description' => 'Edit own articles'],
            ['module' => 'articles', 'key' => 'article.edit_any',      'description' => 'Edit any article'],
            ['module' => 'articles', 'key' => 'article.delete',        'description' => 'Delete / archive articles'],
            ['module' => 'articles', 'key' => 'article.publish',       'description' => 'Publish and schedule articles'],
            ['module' => 'articles', 'key' => 'article.review',        'description' => 'Move articles to pending review'],
            ['module' => 'articles', 'key' => 'article.set_breaking',  'description' => 'Mark article as breaking news'],
            // Media
            ['module' => 'media',    'key' => 'media.upload',          'description' => 'Upload media assets'],
            ['module' => 'media',    'key' => 'media.delete',          'description' => 'Delete media assets'],
            // Users
            ['module' => 'users',    'key' => 'users.view',            'description' => 'View user list'],
            ['module' => 'users',    'key' => 'users.manage',          'description' => 'Create/edit/suspend users'],
            ['module' => 'users',    'key' => 'users.assign_roles',    'description' => 'Assign roles to users'],
            // Memberships
            ['module' => 'memberships', 'key' => 'memberships.view',   'description' => 'View membership data'],
            ['module' => 'memberships', 'key' => 'memberships.manage', 'description' => 'Edit plans, promo codes'],
            ['module' => 'memberships', 'key' => 'memberships.refund', 'description' => 'Issue refunds'],
            // Ads
            ['module' => 'ads',      'key' => 'ads.manage',            'description' => 'Full ad campaign management'],
            ['module' => 'ads',      'key' => 'ads.view_own',          'description' => 'View own ad campaigns'],
            // Events
            ['module' => 'events',   'key' => 'events.manage',         'description' => 'Create/edit/cancel events'],
            ['module' => 'events',   'key' => 'events.view',           'description' => 'View event registrations'],
            // Live streams
            ['module' => 'live',     'key' => 'live_stream.manage',    'description' => 'Schedule/start/end live streams'],
            // HR
            ['module' => 'hr',       'key' => 'employees.manage',      'description' => 'Manage employee records'],
            ['module' => 'hr',       'key' => 'cards.issue',           'description' => 'Issue/revoke press cards'],
            // Security
            ['module' => 'security', 'key' => 'security.manage',       'description' => 'Manage security incidents'],
            // Comments
            ['module' => 'comments', 'key' => 'comments.moderate',     'description' => 'Approve/reject/delete comments'],
            // Analytics
            ['module' => 'analytics','key' => 'analytics.view',        'description' => 'View analytics dashboard'],
            // Settings
            ['module' => 'settings', 'key' => 'settings.manage',       'description' => 'Edit site settings'],
        ];

        foreach ($permissions as $perm) {
            DB::table('permissions')->updateOrInsert(['key' => $perm['key']], array_merge($perm, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }

        // ── Assign permissions to roles ────────────────────────────────────
        $rolePermissions = [
            'super_admin'  => ['*'], // wildcard handled in middleware
            'admin'        => [
                'article.create','article.edit_any','article.delete','article.publish',
                'article.set_breaking','media.upload','media.delete',
                'users.view','users.manage','users.assign_roles',
                'memberships.view','memberships.manage','memberships.refund',
                'ads.manage','events.manage','events.view',
                'live_stream.manage','employees.manage','cards.issue',
                'security.manage','comments.moderate','analytics.view','settings.manage',
            ],
            'editor'       => [
                'article.create','article.edit_any','article.publish','article.review',
                'article.set_breaking','article.delete','media.upload','comments.moderate',
            ],
            'journalist'   => [
                'article.create','article.edit_own','article.review','media.upload',
            ],
            'contributor'  => ['article.create','media.upload'],
            'moderator'    => ['comments.moderate'],
            'advertiser'   => ['ads.view_own'],
            'event_manager'=> ['events.manage','events.view','media.upload'],
            'hr_admin'     => ['employees.manage','cards.issue','users.view'],
            'finance_admin'=> ['memberships.view','memberships.refund','analytics.view'],
        ];

        foreach ($rolePermissions as $roleSlug => $permKeys) {
            if ($roleSlug === 'super_admin') continue; // handled in middleware directly

            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) continue;

            foreach ($permKeys as $key) {
                $permId = DB::table('permissions')->where('key', $key)->value('id');
                if (! $permId) continue;

                DB::table('role_permission_map')->updateOrInsert([
                    'role_id'       => $roleId,
                    'permission_id' => $permId,
                ]);
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CATEGORY SEEDER
// File: database/seeders/CategorySeeder.php
// ─────────────────────────────────────────────────────────────────────────────

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $channelId = DB::table('channels')->where('slug', 'cni-news')->value('id');

        $categories = [
            // Top-level sections — match the CNI nav
            ['slug' => 'pakistan',  'default_name' => 'Pakistan',  'position' => 1],
            ['slug' => 'kashmir',   'default_name' => 'Kashmir',   'position' => 2],
            ['slug' => 'world',     'default_name' => 'World',     'position' => 3],
            ['slug' => 'overseas',  'default_name' => 'Overseas',  'position' => 4],
            ['slug' => 'articles',  'default_name' => 'Articles',  'position' => 5],
            ['slug' => 'sports',    'default_name' => 'Sports',    'position' => 6],
            ['slug' => 'culture',   'default_name' => 'Culture',   'position' => 7],
            ['slug' => 'videos',    'default_name' => 'Videos',    'position' => 8],
            ['slug' => 'events',    'default_name' => 'Events',    'position' => 9],
            ['slug' => 'ads',       'default_name' => 'Ads',       'position' => 10],
        ];

        foreach ($categories as $cat) {
            $exists = DB::table('categories')
                ->where('channel_id', $channelId)
                ->where('slug', $cat['slug'])
                ->exists();

            if (! $exists) {
                DB::table('categories')->insert(array_merge($cat, [
                    'channel_id'  => $channelId,
                    'parent_id'   => null,
                    'is_featured' => false,
                    'is_active'   => true,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]));
            }
        }
    }
}

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

// ─────────────────────────────────────────────────────────────────────────────
// SUPER ADMIN SEEDER
// File: database/seeders/SuperAdminSeeder.php
// ─────────────────────────────────────────────────────────────────────────────

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $channelId = DB::table('channels')->where('slug', 'cni-news')->value('id');
        $langId    = DB::table('languages')->where('code', 'en')->value('id');
        $roleId    = DB::table('roles')->where('slug', 'super_admin')->value('id');

        $userId = DB::table('users')->insertGetId([
            'channel_id'          => $channelId,
            'email'               => 'admin@cni.co.uk',
            'password_hash'       => bcrypt('ChangeMe2025!'), // CHANGE ON FIRST LOGIN
            'first_name'          => 'CNI',
            'last_name'           => 'Admin',
            'display_name'        => 'CNI Admin',
            'preferred_language_id' => $langId,
            'timezone'            => 'Europe/London',
            'is_email_verified'   => true,
            'status'              => 'active',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        DB::table('user_role_map')->insert([
            'user_id'    => $userId,
            'role_id'    => $roleId,
            'channel_id' => null, // null = global super admin
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
