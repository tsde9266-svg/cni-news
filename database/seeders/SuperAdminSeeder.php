<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $channelId = DB::table('channels')->where('slug', 'cni-news')->value('id');
        $langId    = DB::table('languages')->where('code', 'en')->value('id');
        $roleId    = DB::table('roles')->where('slug', 'super_admin')->value('id');

        // Check if admin already exists
        $existingId = DB::table('users')->where('email', 'admin@cni.co.uk')->value('id');

        if ($existingId) {
            // Update existing — don't duplicate
            DB::table('users')->where('id', $existingId)->update([
                'channel_id'            => $channelId,
                'password_hash'         => bcrypt('ChangeMe2025!'),
                'first_name'            => 'CNI',
                'last_name'             => 'Admin',
                'display_name'          => 'CNI Admin',
                'preferred_language_id' => $langId,
                'timezone'              => 'Europe/London',
                'is_email_verified'     => true,
                'is_phone_verified'     => false,
                'status'                => 'active',
                'updated_at'            => now(),
            ]);
            $userId = $existingId;
        } else {
            $userId = DB::table('users')->insertGetId([
                'channel_id'            => $channelId,
                'email'                 => 'admin@cni.co.uk',
                'password_hash'         => bcrypt('ChangeMe2025!'),
                'first_name'            => 'CNI',
                'last_name'             => 'Admin',
                'display_name'          => 'CNI Admin',
                'preferred_language_id' => $langId,
                'timezone'              => 'Europe/London',
                'is_email_verified'     => true,
                'is_phone_verified'     => false,
                'status'                => 'active',
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        }

        // Assign super_admin role (avoid duplicate)
        DB::table('user_role_map')->updateOrInsert(
            ['user_id' => $userId, 'role_id' => $roleId],
            ['channel_id' => null, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->command->info("Super admin ready: admin@cni.co.uk / ChangeMe2025!");
    }
}
