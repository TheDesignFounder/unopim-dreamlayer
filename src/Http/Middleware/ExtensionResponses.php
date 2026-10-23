<?php

namespace DreamLayer\UnoPIM\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ExtensionResponses
{
    public function handle(Request $request, Closure $next)
    {
        // The iframe must never become Laravel's post-login intended URL.
        if ($request->routeIs('dreamlayer.studio') && ! auth()->guard('admin')->check()) {
            return response('<!doctype html><script>parent.postMessage({dreamlayer:true,action:"expired"},location.origin)</script>', 401)
                ->header('Cache-Control', 'private, no-store')->header('X-Frame-Options', 'SAMEORIGIN');
        }
        return $next($request);
    }
}
