<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The live state of a station, and the cursor a listener polls it with.
 *
 * This replaced an SSE stream. The stream gave no latency advantage -- its own server
 * loop slept two seconds between checks -- while pinning a PHP worker per browser tab
 * for 55 seconds and issuing ~110 cache reads per connection. The Pi API shares that
 * worker pool, so enough listeners could starve the transmitter's own requests.
 *
 * A cursor is a hash of everything a listener watches. The client sends back the one it
 * last saw; when it still matches, the poll returns a few bytes and no payload. So the
 * cost of a quiet station is one cache read per listener per interval, and nothing is
 * held open.
 *
 * The cache keys live here rather than being spelled out at five call sites, which is
 * how `chat_version` and `listener_count` came to be the two that were never scoped to
 * a station.
 */
final class LiveState
{
    /** How often a listener polls. The client reads this from the payload. */
    public const POLL_SECONDS = 3;

    /** A listener who has not polled within this is assumed gone. */
    private const PRESENCE_SECONDS = 15;

    /** Bounds the presence map against forged client ids. */
    private const PRESENCE_LIMIT = 2000;

    private const TTL = 3600;

    public static function nowPlayingChanged(int $stationId, mixed $payload): void
    {
        Cache::put("live.now_playing.{$stationId}", $payload, self::TTL);
    }

    /** Shorter-lived than the rest: a device that stops heartbeating should go quiet by itself. */
    public static function piStatusChanged(int $stationId, mixed $payload): void
    {
        Cache::put("live.pi_status.{$stationId}", $payload, 180);
    }

    public static function queueChanged(int $stationId): void
    {
        Cache::put("live.queue_version.{$stationId}", (string) microtime(true), self::TTL);
    }

    public static function chatChanged(int $stationId): void
    {
        Cache::put("live.chat_version.{$stationId}", (string) microtime(true), self::TTL);
    }

    /**
     * Everything a listener watches, in one place, read once.
     *
     * The cursor is derived from this same array rather than from separate version
     * keys, so there is nothing to keep in step and no extra reads to pay for.
     *
     * @return array{now_playing: mixed, pi_status: mixed, queue_version: string, chat_version: string}
     */
    public static function read(int $stationId): array
    {
        return [
            'now_playing' => Cache::get("live.now_playing.{$stationId}"),
            'pi_status' => Cache::get("live.pi_status.{$stationId}"),
            'queue_version' => (string) Cache::get("live.queue_version.{$stationId}", '0'),
            'chat_version' => (string) Cache::get("live.chat_version.{$stationId}", '0'),
        ];
    }

    /** @param  array<string, mixed>  $state */
    public static function cursor(array $state): string
    {
        return md5(json_encode($state, JSON_THROW_ON_ERROR));
    }

    /**
     * Record that a listener is still here, and say how many are.
     *
     * One key per station holding `client id => last seen`, rewritten on each poll.
     * Two concurrent polls can lose one entry to the read-modify-write, and that
     * listener puts itself back three seconds later -- which is well inside what
     * "approximate listener count" promises.
     *
     * Callers with no client id (the admin status bar) are not listeners and are
     * counted as none.
     */
    public static function touchListener(int $stationId, ?string $clientId): int
    {
        $key = "live.listeners.{$stationId}";
        $cutoff = time() - self::PRESENCE_SECONDS;

        /** @var array<string, int> $seen */
        $seen = Cache::get($key, []);
        $seen = array_filter($seen, fn (int $at) => $at > $cutoff);

        if ($clientId !== null && $clientId !== '') {
            $seen[$clientId] = time();
        }

        if (count($seen) > self::PRESENCE_LIMIT) {
            arsort($seen);
            $seen = array_slice($seen, 0, self::PRESENCE_LIMIT, true);
        }

        Cache::put($key, $seen, self::PRESENCE_SECONDS * 2);

        return count($seen);
    }
}
