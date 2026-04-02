<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * Append-only audit log. Never call update() or delete() on this model.
 */
class AuditLog extends Model
{
    public $timestamps  = false; // uses created_at only
    public $incrementing = true;

    protected $fillable = [
        'actor_user_id', 'action', 'target_type', 'target_id',
        'before_state', 'after_state', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state'  => 'array',
        'created_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($log) {
            $log->created_at  = now();
            $log->ip_address  ??= Request::ip();
            $log->user_agent  ??= Request::userAgent();

            // Auto-capture the currently authenticated user
            if (is_null($log->actor_user_id)) {
                $log->actor_user_id = auth()->id();
            }
        });

        // Prevent any updates or deletes
        static::updating(fn() => throw new \RuntimeException('AuditLog records are immutable.'));
        static::deleting(fn() => throw new \RuntimeException('AuditLog records cannot be deleted.'));
    }

    /**
     * Convenience static method used throughout controllers.
     *
     * Usage:
     *   AuditLog::log('article_published', 'article', $article->id, $before, $after);
     */
    public static function log(
        string $action,
        ?string $targetType  = null,
        ?int    $targetId    = null,
        mixed   $before      = null,
        mixed   $after       = null,
    ): self {
        return static::create([
            'action'       => $action,
            'target_type'  => $targetType,
            'target_id'    => $targetId,
            'before_state' => $before,
            'after_state'  => $after,
        ]);
    }
}
