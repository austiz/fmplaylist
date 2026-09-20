<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HasActiveStation;
use App\Http\Controllers\Admin\Concerns\HasChartStats;
use App\Http\Controllers\Controller;
use App\Http\Resources\NowPlayingResource;
use App\Http\Resources\QueueItemResource;
use App\Models\NowPlaying;
use App\Models\QueueItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use HasActiveStation;
    use HasChartStats;

    public function index(Request $request): Response
    {
        $station = $this->activeStation($request);

        $nowPlaying = NowPlaying::forStation($station->id);

        $pendingItems = QueueItem::with('mediaAsset')->where('station_id', $station->id)->pending()->get();

        $queueRuntimeSeconds = $pendingItems->sum(fn (QueueItem $item) => $item->mediaAsset->duration_seconds ?? 0);

        $recentRequests = QueueItem::with('mediaAsset')
            ->where('station_id', $station->id)
            ->orderByDesc('created_at')
            ->take(20)
            ->get()
            ->map(fn (QueueItem $item) => (new QueueItemResource($item))->resolve());

        return Inertia::render('admin/dashboard', [
            'nowPlaying' => $nowPlaying ? (new NowPlayingResource($nowPlaying))->resolve() : null,
            'queueDepth' => $pendingItems->count(),
            'queueRuntimeSeconds' => $queueRuntimeSeconds,
            'recentRequests' => $recentRequests,
            'stats' => [
                'requestsToday' => QueueItem::where('station_id', $station->id)->whereDate('created_at', today())->count(),
                'songsPlayedToday' => QueueItem::where('station_id', $station->id)->played()->whereDate('played_at', today())->count(),
            ],
            'requestsPerHour' => $this->requestsPerHourToday($station->id),
            'playsLast7Days' => $this->playsLast7Days($station->id),
        ]);
    }
}
