<?php

namespace App\Http\Middleware;

use App\Models\PiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->header('X-Pi-Token');

        if (! $raw) {
            Log::warning('Pi API: missing token', ['ip' => $request->ip(), 'path' => $request->path()]);

            return response()->json(['error' => 'Missing token'], 401);
        }

        $token = PiToken::findByRaw($raw);

        if (! $token) {
            Log::warning('Pi API: invalid token', ['ip' => $request->ip(), 'path' => $request->path()]);

            return response()->json(['error' => 'Invalid token'], 401);
        }

        $token->update(['last_seen_at' => now()]);
        $request->attributes->set('pi_token', $token);

        return $next($request);
    }
}
