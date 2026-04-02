<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class SocialAccount extends Model
{
    protected $fillable = [
        'channel_id',
        'connected_by_user_id',
        'platform',
        'account_name',
        'platform_account_id',
        'platform_username',
        'profile_picture_url',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'oauth_token_secret_encrypted',
        'token_expires_at',
        'refresh_token_expires_at',
        'platform_meta',
        'is_active',
        'deactivation_reason',
        'last_used_at',
        'last_refreshed_at',
    ];

    protected $hidden = [
        'access_token_encrypted',
        'refresh_token_encrypted',
        'oauth_token_secret_encrypted',
    ];

    protected $casts = [
        'is_active'                  => 'boolean',
        'token_expires_at'           => 'datetime',
        'refresh_token_expires_at'   => 'datetime',
        'last_used_at'               => 'datetime',
        'last_refreshed_at'          => 'datetime',
        'platform_meta'              => 'array',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    public function feedItems(): HasMany
    {
        return $this->hasMany(SocialFeedItem::class);
    }

    public function quotaUsage(): HasMany
    {
        return $this->hasMany(YoutubeQuotaUsage::class);
    }

    // ── Token helpers ──────────────────────────────────────────────────────

    public function getAccessToken(): string
    {
        return Crypt::decryptString($this->access_token_encrypted);
    }

    public function getRefreshToken(): ?string
    {
        if (!$this->refresh_token_encrypted) return null;
        return Crypt::decryptString($this->refresh_token_encrypted);
    }

    public function getOauthTokenSecret(): ?string
    {
        if (!$this->oauth_token_secret_encrypted) return null;
        return Crypt::decryptString($this->oauth_token_secret_encrypted);
    }

    public function setAccessToken(string $token): void
    {
        $this->access_token_encrypted = Crypt::encryptString($token);
    }

    public function setRefreshToken(string $token): void
    {
        $this->refresh_token_encrypted = Crypt::encryptString($token);
    }

    public function setOauthTokenSecret(string $secret): void
    {
        $this->oauth_token_secret_encrypted = Crypt::encryptString($secret);
    }

    /** True if access token is expired or expires within 5 minutes */
    public function isTokenExpired(): bool
    {
        if (!$this->token_expires_at) return false;
        return $this->token_expires_at->subMinutes(5)->isPast();
    }

    /** True if the refresh token itself has expired (TikTok: 365 days) */
    public function isRefreshTokenExpired(): bool
    {
        if (!$this->refresh_token_expires_at) return false;
        return $this->refresh_token_expires_at->isPast();
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        return ($this->platform_meta ?? [])[$key] ?? $default;
    }

    public function setMeta(string $key, mixed $value): void
    {
        $meta = $this->platform_meta ?? [];
        $meta[$key] = $value;
        $this->platform_meta = $meta;
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }
}
