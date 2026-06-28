<?php

namespace App\Http\Controllers;

use App\Models\NowPlaying;
use App\Models\QueueItem;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(): Response
    {
        $nowPlaying = NowPlaying::with('song')->find(1);

        $queue = QueueItem::with('song')
            ->pending()
            ->take(5)
            ->get()
            ->map(fn (QueueItem $item) => [
                'id' => $item->id,
                'position' => $item->position,
                'requested_by_name' => $item->requested_by_name,
                'song' => [
                    'title' => $item->song->title,
                    'artist' => $item->song->artist,
                ],
            ]);

        return Inertia::render('home', [
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
                'started_at' => $nowPlaying->started_at?->toIso8601String(),
            ] : null,
            'queue' => $queue,
            'queueCount' => QueueItem::pending()->count(),
        ]);
    }
}
