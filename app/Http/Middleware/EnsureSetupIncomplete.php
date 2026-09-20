<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the first-operator page reachable only while there is no operator.
 *
 * There is no public sign-up: an account here is not a listener account, it
 * drives a transmitter. But a fresh install has nobody who can log in to make
 * the first one, so this one door stays open until it is used once. It 404s
 * afterwards rather than 403ing, so a stranger who guesses the URL learns
 * nothing about whether the page ever existed.
 */
class EnsureSetupIncomplete
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(User::query()->exists(), 404);

        return $next($request);
    }
}
