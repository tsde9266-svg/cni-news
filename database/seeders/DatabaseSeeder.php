<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LanguageSeeder::class,
            ChannelSeeder::class,
            RolePermissionSeeder::class,
            CategorySeeder::class,
            MembershipPlanSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
