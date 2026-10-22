<?php

namespace DreamLayer\UnoPIM\Services;

class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct(match ($status) {
            401, 403 => trans('dreamlayer::app.message-d0468dd7a0'),
            402 => trans('dreamlayer::app.out-of-credits'),
            409 => trans('dreamlayer::app.message-c3f83b5ad4'),
            429 => trans('dreamlayer::app.message-3856ee0f55'),
            default => trans('dreamlayer::app.message-595f464cf0'),
        });
    }
}
