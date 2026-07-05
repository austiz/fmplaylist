<?php

namespace App\Http\Controllers;

use App\Models\NowPlaying;
use App\Models\QueueItem;
use App\Models\Station;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('home', $this->cockpitProps());
    }

    /**
     * Full-screen Driving Mode — same live data as the home cockpit, rendered chrome-free.
     */
    public function drive(): Response
    {
        return Inertia::render('drive', $this->cockpitProps());
    }

    /**
     * Shared now-playing + queue preview props for the home cockpit and Driving Mode.
     *
     * @return array<string, mixed>
     */
    private function cockpitProps(): array
    {
        $stationId = Station::defaultId();
        $nowPlaying = NowPlaying::forStation($stationId);

        $queue = QueueItem::with('song')
            ->where('station_id', $stationId)
            ->pending()
            ->take(5)
            ->get()
            ->map(fn (QueueItem $item) => [
                'id' => $item->id,
                'position' => $item->position,
                'requested_by_name' => $item->requested_by_name,
                'song' => [
                    'title' => $item->song?->title ?? '(deleted)',
                    'artist' => $item->song?->artist ?? '',
                ],
            ]);

        return [
            'nowPlaying' => $this->serializeNowPlaying($nowPlaying),
            'queue' => $queue,
            'queueCount' => QueueItem::where('station_id', $stationId)->pending()->count(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeNowPlaying(?NowPlaying $nowPlaying): ?array
    {
        if (! $nowPlaying) {
            return null;
        }

        return [
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
        ];
    }
}
