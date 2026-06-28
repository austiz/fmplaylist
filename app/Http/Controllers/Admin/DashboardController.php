<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NowPlaying;
use App\Models\QueueItem;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $nowPlaying = NowPlaying::with('song')->find(1);

        $pendingItems = QueueItem::with('song')->pending()->get();

        $queueRuntimeSeconds = $pendingItems->sum(fn ($item) => $item->song->duration_seconds ?? 0);

        $recentRequests = QueueItem::with('song')
            ->orderByDesc('created_at')
            ->take(20)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'status' => $item->status,
                'requested_by_name' => $item->requested_by_name,
                'created_at' => $item->created_at->toDateTimeString(),
                'played_at' => $item->played_at?->toDateTimeString(),
                'song' => ['title' => $item->song->title, 'artist' => $item->song->artist],
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
                'requestsToday' => QueueItem::whereDate('created_at', today())->count(),
                'songsPlayedToday' => QueueItem::played()->whereDate('played_at', today())->count(),
            ],
        ]);
    }
}
