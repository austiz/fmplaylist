<?php

namespace App\Http\Controllers;

use App\Http\Resources\NowPlayingResource;
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

        $pendingItems = QueueItem::with('mediaAsset')->where('station_id', $stationId)->pending()->get();

        $queue = $pendingItems->map(fn (QueueItem $item) => [
            'id' => $item->id,
            'position' => $item->position,
            'requested_by_name' => $item->requested_by_name,
            'song' => [
                'title' => $item->mediaAsset->title ?? '(deleted)',
                'artist' => $item->mediaAsset->artist ?? '',
                'duration_seconds' => $item->mediaAsset?->duration_seconds,
            ],
        ]);

        $waitSeconds = $pendingItems->sum(fn (QueueItem $item) => $item->mediaAsset->duration_seconds ?? 0);

        $history = QueueItem::with('mediaAsset')
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
                    'title' => $item->mediaAsset->title ?? '(deleted)',
                    'artist' => $item->mediaAsset->artist ?? '',
                ],
            ]);

        return Inertia::render('queue', [
            'history' => $history,
            'station' => ['id' => $station->id, 'name' => $station->name, 'slug' => $station->slug],
            'nowPlaying' => $nowPlaying ? (new NowPlayingResource($nowPlaying))->resolve() : null,
            'queue' => $queue,
            'waitMinutes' => $waitSeconds > 0 ? (int) ceil($waitSeconds / 60) : null,
        ]);
    }
}
