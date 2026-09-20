<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Support\PublicStation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseController extends Controller
{
    public function stream(Request $request): StreamedResponse
    {
        $stationId = PublicStation::resolve($request)->id;

        return response()->stream(function () use ($stationId) {
            set_time_limit(0);
            ignore_user_abort(true);

            // Approximate concurrent-listener count. Each open tab reconnects every ~55s
            // (see $deadline below), so this is one cheap increment/decrement per tab per
            // cycle rather than a precise per-connection presence set — the cache store here
            // is `database` (no tag support), so a TTL'd presence set would mean a
            // read-modify-write every 2s per open tab instead of one atomic op per ~55s.
            Cache::increment("sse.listener_count.{$stationId}");
            $decremented = false;
            register_shutdown_function(function () use (&$decremented, $stationId) {
                if (! $decremented) {
                    $decremented = true;
                    Cache::decrement("sse.listener_count.{$stationId}");
                }
            });

            $lastNpHash = '';
            $lastPiHash = '';
            $lastQueueVer = '';
            $lastChatVer = '';
            $lastListenerCount = null;

            $np = Cache::get("sse.now_playing.{$stationId}");
            $pi = Cache::get("sse.pi_status.{$stationId}");
            $qv = Cache::get("sse.queue_version.{$stationId}", '0');
            $chatVer = Cache::get("sse.chat_version.{$stationId}", '0');
            $listenerCount = max(0, (int) Cache::get("sse.listener_count.{$stationId}", 1));

            // Send initial state immediately so the client is current on connect
            echo "event: now-playing\ndata: ".json_encode($np, JSON_THROW_ON_ERROR)."\n\n";
            if ($pi) {
                echo "event: pi-status\ndata: ".json_encode($pi, JSON_THROW_ON_ERROR)."\n\n";
            }
            echo "event: listener-count\ndata: {\"n\":$listenerCount}\n\n";
            @ob_flush();
            flush();

            $lastNpHash = md5(json_encode($np, JSON_THROW_ON_ERROR));
            $lastPiHash = md5(json_encode($pi, JSON_THROW_ON_ERROR));
            $lastQueueVer = $qv;
            $lastChatVer = $chatVer;
            $lastListenerCount = $listenerCount;

            // Reconnect after 55s so nginx / reverse proxies don't timeout
            $deadline = time() + 55;

            while (time() < $deadline && ! connection_aborted()) {
                $np = Cache::get("sse.now_playing.{$stationId}");
                $pi = Cache::get("sse.pi_status.{$stationId}");
                $qv = Cache::get("sse.queue_version.{$stationId}", '0');
                $chatVer = Cache::get("sse.chat_version.{$stationId}", '0');

                $npHash = md5(json_encode($np, JSON_THROW_ON_ERROR));
                $piHash = md5(json_encode($pi, JSON_THROW_ON_ERROR));

                if ($npHash !== $lastNpHash) {
                    echo "event: now-playing\ndata: ".json_encode($np, JSON_THROW_ON_ERROR)."\n\n";
                    $lastNpHash = $npHash;
                }

                if ($piHash !== $lastPiHash) {
                    echo "event: pi-status\ndata: ".json_encode($pi, JSON_THROW_ON_ERROR)."\n\n";
                    $lastPiHash = $piHash;
                }

                if ($qv !== $lastQueueVer) {
                    echo "event: queue-changed\ndata: {\"v\":\"$qv\"}\n\n";
                    $lastQueueVer = $qv;
                }

                if ($chatVer !== $lastChatVer) {
                    // Named, not ambient: this runs inside the streamed response, long
                    // after the request that resolved the station has finished.
                    $msg = ChatMessage::forStation($stationId)->latest()->first(['id', 'name', 'message', 'created_at']);
                    if ($msg) {
                        echo "event: chat-message\ndata: ".json_encode($msg, JSON_THROW_ON_ERROR)."\n\n";
                    }
                    $lastChatVer = $chatVer;
                }

                // Keepalive comment (prevents nginx 60s idle timeout)
                echo ": ping\n\n";
                @ob_flush();
                flush();

                sleep(2);
            }

            // Hint client to reconnect quickly
            echo "retry: 500\n\n";
            @ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}
