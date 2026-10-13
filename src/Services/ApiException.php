<?php

namespace DreamLayer\UnoPIM\Services;

class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct(match ($status) {
            401, 403 => 'DreamLayer connection needs attention. Reconnect in settings.',
            402      => "You're out of image credits. Add credits to continue.",
            409      => 'This request identity already has different input.',
            429      => 'DreamLayer is busy. This same request will resume.',
            default  => 'DreamLayer is temporarily unavailable. This same request will resume.',
        });
    }
}
