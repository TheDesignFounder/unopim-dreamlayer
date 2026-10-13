<?php

namespace DreamLayer\UnoPIM\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class PendingConnection
{
    private static function key(Request $request): string
    {
        return 'dreamlayer:device:'.hash('sha256', $request->session()->getId().'|'.auth('admin')->id());
    }

    public static function locked(Request $request, callable $callback): mixed
    {
        try {
            return Cache::lock(self::key($request).':lock', 40)->block(5, $callback);
        } catch (LockTimeoutException $e) {
            abort(429, 'Connection request already in progress.');
        }
    }

    public static function get(Request $request): ?array
    {
        $sealed = Cache::get(self::key($request));

        return $sealed ? json_decode(Crypt::decryptString($sealed), true) : null;
    }

    public static function put(Request $request, array $attempt): void
    {
        Cache::put(self::key($request), Crypt::encryptString(json_encode($attempt)), max(1, $attempt['expires_at'] - time()));
    }

    public static function forget(Request $request): void
    {
        Cache::forget(self::key($request));
        // Discard attempts created by older extension versions. Ordinary session
        // responses can overwrite each other, so never use that copy again.
        $request->session()->forget('dreamlayer.device');
    }
}
