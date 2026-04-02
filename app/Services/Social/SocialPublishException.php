<?php

namespace App\Services\Social;

/**
 * SocialPublishException
 *
 * Typed exception for social platform publish failures.
 * Carries enough context for the SocialPublishJob to decide
 * whether to retry, how long to wait, and what to log.
 */
class SocialPublishException extends \RuntimeException
{
    public function __construct(
        string              $message,
        public readonly bool  $retryable          = false,
        public readonly array $errorData          = [],
        public readonly bool  $requiresReconnect  = false,
        public readonly int   $retryDelayMinutes  = 0,
    ) {
        parent::__construct($message);
    }
}
