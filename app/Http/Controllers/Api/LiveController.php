<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Support\LiveState;
use App\Support\PublicStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One poll for everything a page watches: what is on air, whether the transmitter is
 * up, whether the queue moved, and what has been said in chat.
 *
 * Both the listener pages and the admin status bar poll this, so there is one live
 * transport rather than an SSE stream plus a separate 30-second pi-status poll.
 * See `LiveState` for why the stream went.
 */
class LiveController extends Controller
{
    /** Chat is seeded with this many messages on a client's first poll. */
    private const CHAT_SEED = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $stationId = PublicStation::resolve($request)->id;

        $state = LiveState::read($stationId);
        $cursor = LiveState::cursor($state);
        $listeners = LiveState::touchListener($stationId, $request->query('c'));

        $payload = [
            'v' => $cursor,
            'listeners' => $listeners,
            'poll_seconds' => LiveState::POLL_SECONDS,
        ];

        // Nothing has moved since the client's last look, so there is nothing to send
        // it. Kept as a 200 with a small body rather than a 304: no conditional request
        // header was used, and a 304 for a plain fetch() is handled inconsistently by
        // proxies for no saving worth the ambiguity.
        if ($request->query('v') === $cursor) {
            return response()->json($payload);
        }

        return response()->json([
            ...$payload,
            'now_playing' => $state['now_playing'],
            'pi_status' => $state['pi_status'],
            'queue_version' => $state['queue_version'],
            'chat' => $this->chat($stationId, $request->query('since')),
        ]);
    }

    /**
     * Messages the client has not seen.
     *
     * Without a cursor this is the seed -- newest first, then reversed, because taking
     * the oldest 50 pinned a growing chat to the first conversation ever.
     *
     * Scoped explicitly rather than through the model's global scope: this controller
     * has already resolved the station it is answering for, and a transport that served
     * a different station's chat than its own cursor came from would be a hard bug to see.
     *
     * @return array<int, ChatMessage>
     */
    private function chat(int $stationId, ?string $since): array
    {
        $messages = ChatMessage::forStation($stationId)
            ->when($since !== null, fn ($q) => $q->where('id', '>', (int) $since))
            ->latest('id')
            ->take(self::CHAT_SEED)
            ->get(['id', 'name', 'message', 'created_at']);

        return $messages->reverse()->values()->all();
    }
}
