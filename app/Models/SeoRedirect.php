<?php
// ─────────────────────────────────────────────────────────────────────────────
// FILE: app/Models/SeoRedirect.php
// ─────────────────────────────────────────────────────────────────────────────
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoRedirect extends Model
{
    protected $fillable = [
        'old_path', 'new_path', 'http_code', 'is_active', 'hit_count', 'notes',
    ];

    protected $casts = ['is_active' => 'boolean'];
}
