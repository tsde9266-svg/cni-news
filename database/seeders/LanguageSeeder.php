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