<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseController extends Controller
{
    public function stream(): StreamedResponse
    {
        return response()->stream(function () {
            set_time_limit(0);

            $lastNpHash    = '';
            $lastPiHash    = '';
            $lastQueueVer  = '';

            $np = Cache::get('sse.now_playing');
            $pi = Cache::get('sse.pi_status');
            $qv = Cache::get('sse.queue_version', '0');

            // Send initial state immediately so the client is current on connect
            echo "event: now-playing\ndata: " . json_encode($np) . "\n\n";
            if ($pi) {
                echo "event: pi-status\ndata: " . json_encode($pi) . "\n\n";
            }
            @ob_flush(); flush();

            $lastNpHash   = md5(json_encode($np));
            $lastPiHash   = md5(json_encode($pi));
            $lastQueueVer = $qv;

            // Reconnect after 55s so nginx / reverse proxies don't timeout
            $deadline = time() + 55;

            while (time() < $deadline && ! connection_aborted()) {
                $np = Cache::get('sse.now_playing');
                $pi = Cache::get('sse.pi_status');
                $qv = Cache::get('sse.queue_version', '0');

                $npHash = md5(json_encode($np));
                $piHash = md5(json_encode($pi));

                if ($npHash !== $lastNpHash) {
                    echo "event: now-playing\ndata: " . json_encode($np) . "\n\n";
                    $lastNpHash = $npHash;
                }

                if ($piHash !== $lastPiHash) {
                    echo "event: pi-status\ndata: " . json_encode($pi) . "\n\n";
                    $lastPiHash = $piHash;
                }

                if ($qv !== $lastQueueVer) {
                    echo "event: queue-changed\ndata: {\"v\":\"$qv\"}\n\n";
                    $lastQueueVer = $qv;
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
