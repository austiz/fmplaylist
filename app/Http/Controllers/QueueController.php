<?php

namespace App\Http\Controllers;

use App\Models\NowPlaying;
use App\Models\QueueItem;
use App\Support\PublicStation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QueueController extends Controller
{
    public function index(Request $request): Response
    {
        $station = PublicStation::resolve($request);
        $stationId = $station->id;
        $nowPlaying = NowPlaying::forStation($stationId);

        $pendingItems = QueueItem::with('song')->where('station_id', $stationId)->pending()->get();

        $queue = $pendingItems->map(fn (QueueItem $item) => [
            'id' => $item->id,
            'position' => $item->position,
            'requested_by_name' => $item->requested_by_name,
            'song' => [
                'title' => $item->song?->title ?? '(deleted)',
                'artist' => $item->song?->artist ?? '',
                'duration_seconds' => $item->song?->duration_seconds,
            ],
        ]);

        $waitSeconds = $pendingItems->sum(fn (QueueItem $item) => $item->song?->duration_seconds ?? 0);

        $history = QueueItem::with('song')
            ->where('station_id', $stationId)
            ->where('status', 'played')
            ->orderByDesc('played_at')
            ->take(30)
            ->get()
            ->map(fn (QueueItem $item) => [
                'id' => $item->id,
                'played_at' => $item->played_at?->diffForHumans(),
                'requested_by_name' => $item->requested_by_name,
                'song' => [
                    'title' => $item->song?->title ?? '(deleted)',
                    'artist' => $item->song?->artist ?? '',
                ],
            ]);

        return Inertia::render('queue', [
            'history' => $history,
            'station' => ['id' => $station->id, 'name' => $station->name, 'slug' => $station->slug],
            'nowPlaying' => $nowPlaying ? [
                'type' => $nowPlaying->type,
                'song' => match ($nowPlaying->type) {
                    'commercial' => ['title' => 'Commercial Break', 'artist' => null, 'duration_seconds' => null],
                    'sound_byte' => ['title' => 'Radio Drop',       'artist' => null, 'duration_seconds' => null],
                    'station_id' => ['title' => 'Station ID',       'artist' => null, 'duration_seconds' => null],
                    default => $nowPlaying->song
                        ? [
                            'id' => $nowPlaying->song->id,
                            'title' => $nowPlaying->song->title,
                            'artist' => $nowPlaying->song->artist,
                            'duration_seconds' => $nowPlaying->song->duration_seconds,
                        ]
                        : null,
                },
                'started_at' => $nowPlaying->started_at?->toIso8601String(),
            ] : null,
            'queue' => $queue,
            'waitMinutes' => $waitSeconds > 0 ? (int) ceil($waitSeconds / 60) : null,
        ]);
    }
}
