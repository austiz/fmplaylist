<?php

namespace App\Http\Controllers;

use App\Models\NowPlaying;
use App\Models\QueueItem;
use Inertia\Inertia;
use Inertia\Response;

class QueueController extends Controller
{
    public function index(): Response
    {
        $nowPlaying = NowPlaying::with('song')->find(1);

        $pendingItems = QueueItem::with('song')->pending()->get();

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
            ->where('status', 'played')
            ->orderByDesc('played_at')
            ->take(30)
            ->get()
            ->map(fn (QueueItem $item) => [
                'id'                => $item->id,
                'played_at'         => $item->played_at?->diffForHumans(),
                'requested_by_name' => $item->requested_by_name,
                'song' => [
                    'title'  => $item->song?->title ?? '(deleted)',
                    'artist' => $item->song?->artist ?? '',
                ],
            ]);

        return Inertia::render('queue', [
            'history' => $history,
            'nowPlaying' => $nowPlaying ? [
                'type' => $nowPlaying->type,
                'song' => match ($nowPlaying->type) {
                    'commercial' => ['title' => 'Commercial Break', 'artist' => null],
                    'sound_byte' => ['title' => 'Radio Drop',       'artist' => null],
                    'station_id' => ['title' => 'Station ID',       'artist' => null],
                    default => $nowPlaying->song
                        ? ['title' => $nowPlaying->song->title, 'artist' => $nowPlaying->song->artist]
                        : null,
                },
                'started_at' => null,
            ] : null,
            'queue' => $queue,
            'waitMinutes' => $waitSeconds > 0 ? (int) ceil($waitSeconds / 60) : null,
        ]);
    }
}
