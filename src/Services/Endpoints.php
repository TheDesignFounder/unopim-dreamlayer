<?php

namespace DreamLayer\UnoPIM\Services;

/** Operator-owned endpoints; never populated from browser input or API responses. */
class Endpoints
{
    public static function api(): string
    {
        return self::validate(config('dreamlayer.api_url', Client::BASE_URL), '/v1');
    }

    public static function platform(): string
    {
        return self::validate(config('dreamlayer.platform_url', 'https://dreamlayer.io'), '');
    }

    private static function validate(string $url, string $path): string
    {
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        $local = $parts && ($parts['scheme'] ?? '') === 'http'
            && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]', 'host.docker.internal'], true)
            && app()->environment(['local', 'testing']) && config('dreamlayer.allow_local_http') === true;
        if (! $parts || empty($parts['host']) || (($parts['scheme'] ?? '') !== 'https' && ! $local)
            || ($parts['path'] ?? '') !== $path || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \RuntimeException('Invalid DreamLayer endpoint configuration. Use HTTPS; local HTTP requires explicit development configuration.');
        }

        return $url;
    }
}
