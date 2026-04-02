<?php
// ─────────────────────────────────────────────────────────────────────────────
// LANGUAGE SEEDER
// File: database/seeders/LanguageSeeder.php
// ─────────────────────────────────────────────────────────────────────────────
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;


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
