<?php

namespace App\Http\Controllers;

use App\Http\Resources\NowPlayingResource;
use App\Http\Resources\QueueItemResource;
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

        $queue = $pendingItems->map(fn (QueueItem $item) => (new QueueItemResource($item, true))->resolve());

        $waitSeconds = $pendingItems->sum(fn (QueueItem $item) => $item->mediaAsset->duration_seconds ?? 0);

        $history = QueueItem::with('mediaAsset')
            ->where('station_id', $stationId)
            ->where('status', 'played')
            ->orderByDesc('played_at')
            ->take(30)
            ->get()
            ->map(fn (QueueItem $item) => (new QueueItemResource($item, true))->resolve());

        return Inertia::render('queue', [
            'history' => $history,
            'station' => ['id' => $station->id, 'name' => $station->name, 'slug' => $station->slug],
            'nowPlaying' => $nowPlaying ? (new NowPlayingResource($nowPlaying))->resolve() : null,
            'queue' => $queue,
            'waitMinutes' => $waitSeconds > 0 ? (int) ceil($waitSeconds / 60) : null,
        ]);
    }
}
