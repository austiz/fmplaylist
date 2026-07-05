<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HasActiveStation;
use App\Http\Controllers\Controller;
use App\Models\NowPlaying;
use App\Models\QueueItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use HasActiveStation;

    public function index(Request $request): Response
    {
        $station = $this->activeStation($request);

        $nowPlaying = NowPlaying::forStation($station->id);

        $pendingItems = QueueItem::with('song')->where('station_id', $station->id)->pending()->get();

        $queueRuntimeSeconds = $pendingItems->sum(fn (QueueItem $item) => $item->song?->duration_seconds ?? 0);

        $recentRequests = QueueItem::with('song')
            ->where('station_id', $station->id)
            ->orderByDesc('created_at')
            ->take(20)
            ->get()
            ->map(fn (QueueItem $item) => [
                'id' => $item->id,
                'status' => $item->status,
                'requested_by_name' => $item->requested_by_name,
                'created_at' => $item->created_at->toDateTimeString(),
                'played_at' => $item->played_at?->toDateTimeString(),
                'song' => ['title' => $item->song?->title ?? '(deleted)', 'artist' => $item->song?->artist ?? ''],
            ]);

        return Inertia::render('admin/dashboard', [
            'nowPlaying' => $nowPlaying && $nowPlaying->song ? [
                'type' => $nowPlaying->type,
                'song' => ['title' => $nowPlaying->song->title, 'artist' => $nowPlaying->song->artist],
                'started_at' => $nowPlaying->started_at?->toIso8601String(),
            ] : null,
            'queueDepth' => $pendingItems->count(),
            'queueRuntimeSeconds' => $queueRuntimeSeconds,
            'recentRequests' => $recentRequests,
            'stats' => [
                'requestsToday' => QueueItem::where('station_id', $station->id)->whereDate('created_at', today())->count(),
                'songsPlayedToday' => QueueItem::where('station_id', $station->id)->played()->whereDate('played_at', today())->count(),
            ],
        ]);
    }
}
