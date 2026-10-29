<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleReviewsException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('not_configured', 'Google reviews are not configured yet.');
    }

    public static function unavailable(): self
    {
        return new self('unavailable', 'Google reviews are temporarily unavailable.');
    }
}
