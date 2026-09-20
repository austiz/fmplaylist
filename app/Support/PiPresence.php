<?php

namespace App\Support;

use App\Models\PiToken;
use Illuminate\Support\Collection;

/**
 * "Which devices are up, and what is this station doing right now?"
 *
 * Answered from three places (public status endpoint, admin broadcast page, admin
 * devices page) that each used to carry their own copy of the 120-second threshold
 * and their own spelling of the status precedence.
 */
class PiPresence
{
    /**
     * @param  Collection<int, PiToken>  $tokens
     * @return Collection<int, PiToken>
     */
    public static function online(Collection $tokens): Collection
    {
        return $tokens->filter(static fn (PiToken $t): bool => self::isOnline($t));
    }

    public static function isOnline(PiToken $token): bool
    {
        return $token->last_seen_at !== null
            && $token->last_seen_at->diffInSeconds(now()) < (int) config('fm.online_after_seconds');
    }

    /**
     * The station's status, in descending order of interest: a station with one
     * device live and three idle is live. Offline only when nothing is online.
     *
     * @param  Collection<int, PiToken>  $online
     */
    public static function status(Collection $online): string
    {
        foreach (['live', 'playing', 'idle'] as $candidate) {
            if ($online->contains(static fn (PiToken $t): bool => $t->pi_status === $candidate)) {
                return $candidate;
            }
        }

        return $online->isEmpty() ? 'offline' : 'idle';
    }
}
