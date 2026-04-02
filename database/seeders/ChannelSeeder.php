<?php
// ─────────────────────────────────────────────────────────────────────────────
// LANGUAGE SEEDER
// File: database/seeders/LanguageSeeder.php
// ─────────────────────────────────────────────────────────────────────────────
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;


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