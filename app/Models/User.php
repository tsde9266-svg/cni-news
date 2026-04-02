<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Filament\Panel;
use Filament\Models\Contracts\HasName;  // ← ADD THIS
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable implements FilamentUser, HasName
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'channel_id', 'email', 'phone', 'password_hash',
        'first_name', 'last_name', 'display_name',
        'avatar_media_id', 'preferred_language_id',
        'country', 'city', 'timezone',
        'is_email_verified', 'is_phone_verified', 'status',
    ];

    protected $hidden = ['password_hash', 'remember_token'];

    protected $casts = [
        'is_email_verified' => 'boolean',
        'is_phone_verified' => 'boolean',
        'last_login_at'     => 'datetime',
    ];

    // ── Laravel auth maps 'password' to our custom column ─────────────────
    public function getAuthPassword(): string
    {
        return $this->password_hash ?? '';
    }

    // ── Filament: display name in admin panel ──────────────────────────────
    public function getFilamentName(): string
    {
        return $this->display_name
            ?: trim("{$this->first_name} {$this->last_name}")
            ?: $this->email 
            ?: 'Unnamed User';  // ✅ Always returns string
    }

    // ── Filament: who can access the admin panel ───────────────────────────
    // Allow all staff roles — not just super_admin
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->status !== 'active') return false;

        return $this->isSuperAdmin()
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasRole('journalist')
            || $this->hasRole('hr_admin')
            || $this->hasRole('finance_admin')
            || $this->hasRole('moderator');
    }

    // ── Relationships ──────────────────────────────────────────────────────

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role_map')
                    ->withPivot('channel_id')
                    ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function activeMembership(): ?Membership
    {
        return $this->memberships()
                    ->where('status', 'active')
                    ->with('plan')
                    ->latest()
                    ->first();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'author_user_id');
    }

    // public function authorProfile(): ?AuthorProfile
    // {
    //     return $this->hasOne(AuthorProfile::class)->first();
    // }
    public function authorProfile(): HasOne
    {
        return $this->hasOne(AuthorProfile::class); // ← correct - return the relation object
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'avatar_media_id');
    }

    // ── RBAC helpers ───────────────────────────────────────────────────────

    public function hasRole(string $slug, ?int $channelId = null): bool
    {
        return $this->roles()
            ->where('slug', $slug)
            ->when($channelId, fn($q) => $q->wherePivot('channel_id', $channelId))
            ->exists();
    }

    public function hasPermission(string $key): bool
    {
        if ($this->isSuperAdmin()) return true;

        return $this->roles()
            ->whereHas('permissions', fn($q) => $q->where('key', $key))
            ->exists();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }
}
