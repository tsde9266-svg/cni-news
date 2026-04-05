<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RssImportSeeder extends Seeder
{
    public function run(): void
    {
        $channelId  = DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
        $languageId = DB::table('languages')->where('code', 'en')->value('id') ?? 1;

        // ── Bot user ───────────────────────────────────────────────────────
        if (!DB::table('users')->where('email', 'rss-bot@cninews.tv')->exists()) {
            DB::table('users')->insert([
                'channel_id'          => $channelId,
                'email'               => 'rss-bot@cninews.tv',
                'password_hash'       => Hash::make(Str::random(32)),
                'first_name'          => 'CNI',
                'last_name'           => 'News Bot',
                'display_name'        => 'CNI News',
                'is_email_verified'   => true,
                'status'              => 'active',
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
            $this->command->info('Created rss-bot user.');
        }

        // ── Categories ─────────────────────────────────────────────────────
        $categories = [
            ['slug' => 'world',         'name' => 'World News'],
            ['slug' => 'uk',            'name' => 'UK News'],
            ['slug' => 'politics',      'name' => 'Politics'],
            ['slug' => 'business',      'name' => 'Business'],
            ['slug' => 'technology',    'name' => 'Technology'],
            ['slug' => 'sport',         'name' => 'Sport'],
            ['slug' => 'entertainment', 'name' => 'Entertainment'],
            ['slug' => 'science',       'name' => 'Science & Environment'],
        ];

        foreach ($categories as $pos => $cat) {
            if (DB::table('categories')->where('channel_id', $channelId)->where('slug', $cat['slug'])->exists()) {
                continue;
            }

            $catId = DB::table('categories')->insertGetId([
                'channel_id'   => $channelId,
                'slug'         => $cat['slug'],
                'default_name' => $cat['name'],
                'position'     => $pos,
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            DB::table('category_translations')->insert([
                'category_id' => $catId,
                'language_id' => $languageId,
                'name'        => $cat['name'],
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $this->command->info("Created category: {$cat['name']}");
        }
    }
}
