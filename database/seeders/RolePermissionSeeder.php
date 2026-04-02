<?php
// ─────────────────────────────────────────────────────────────────────────────
// LANGUAGE SEEDER
// File: database/seeders/LanguageSeeder.php
// ─────────────────────────────────────────────────────────────────────────────
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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