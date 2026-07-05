<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Station;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseController extends Controller
{
    public function stream(): StreamedResponse
    {
        // Public SSE isn't station-aware yet (out of scope for the admin multi-station
        // rollout) — always streams the default station's now-playing/pi-status/queue events.
        $stationId = Station::defaultId();

        return response()->stream(function () use ($stationId) {
            set_time_limit(0);

            $lastNpHash   = '';
            $lastPiHash   = '';
            $lastQueueVer = '';
            $lastChatVer  = '';

            $np      = Cache::get("sse.now_playing.{$stationId}");
            $pi      = Cache::get("sse.pi_status.{$stationId}");
            $qv      = Cache::get("sse.queue_version.{$stationId}", '0');
            $chatVer = Cache::get('sse.chat_version', '0');

            // Send initial state immediately so the client is current on connect
            echo "event: now-playing\ndata: " . json_encode($np, JSON_THROW_ON_ERROR) . "\n\n";
            if ($pi) {
                echo "event: pi-status\ndata: " . json_encode($pi, JSON_THROW_ON_ERROR) . "\n\n";
            }
            @ob_flush(); flush();

            $lastNpHash   = md5(json_encode($np, JSON_THROW_ON_ERROR));
            $lastPiHash   = md5(json_encode($pi, JSON_THROW_ON_ERROR));
            $lastQueueVer = $qv;
            $lastChatVer  = $chatVer;

            // Reconnect after 55s so nginx / reverse proxies don't timeout
            $deadline = time() + 55;

            while (time() < $deadline && ! connection_aborted()) {
                $np      = Cache::get("sse.now_playing.{$stationId}");
                $pi      = Cache::get("sse.pi_status.{$stationId}");
                $qv      = Cache::get("sse.queue_version.{$stationId}", '0');
                $chatVer = Cache::get('sse.chat_version', '0');

                $npHash = md5(json_encode($np, JSON_THROW_ON_ERROR));
                $piHash = md5(json_encode($pi, JSON_THROW_ON_ERROR));

                if ($npHash !== $lastNpHash) {
                    echo "event: now-playing\ndata: " . json_encode($np, JSON_THROW_ON_ERROR) . "\n\n";
                    $lastNpHash = $npHash;
                }

                if ($piHash !== $lastPiHash) {
                    echo "event: pi-status\ndata: " . json_encode($pi, JSON_THROW_ON_ERROR) . "\n\n";
                    $lastPiHash = $piHash;
                }

                if ($qv !== $lastQueueVer) {
                    echo "event: queue-changed\ndata: {\"v\":\"$qv\"}\n\n";
                    $lastQueueVer = $qv;
                }

                if ($chatVer !== $lastChatVer) {
                    $msg = ChatMessage::latest()->first(['id', 'name', 'message', 'created_at']);
                    if ($msg) {
                        echo "event: chat-message\ndata: " . json_encode($msg, JSON_THROW_ON_ERROR) . "\n\n";
                    }
                    $lastChatVer = $chatVer;
                }

                // Keepalive comment (prevents nginx 60s idle timeout)
                echo ": ping\n\n";
                @ob_flush(); flush();

                sleep(2);
            }

            // Hint client to reconnect quickly
            echo "retry: 500\n\n";
            @ob_flush(); flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }
}
