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

class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('home', $this->cockpitProps($request));
    }

    /**
     * Full-screen Driving Mode — same live data as the home cockpit, rendered chrome-free.
     */
    public function drive(Request $request): Response
    {
        return Inertia::render('drive', $this->cockpitProps($request));
    }

    /**
     * Shared now-playing + queue preview props for the home cockpit and Driving Mode.
     *
     * @return array<string, mixed>
     */
    private function cockpitProps(Request $request): array
    {
        $station = PublicStation::resolve($request);
        $stationId = $station->id;
        $nowPlaying = NowPlaying::forStation($stationId);

        $queue = QueueItem::with('mediaAsset')
            ->where('station_id', $stationId)
            ->pending()
            ->take(5)
            ->get()
            ->map(fn (QueueItem $item) => (new QueueItemResource($item, true))->resolve());

        return [
            'nowPlaying' => $this->serializeNowPlaying($nowPlaying),
            'queue' => $queue,
            'queueCount' => QueueItem::where('station_id', $stationId)->pending()->count(),
            'station' => ['id' => $station->id, 'name' => $station->name, 'slug' => $station->slug],
        ];
    }

    /** @return array<string, mixed>|null */
    private function serializeNowPlaying(?NowPlaying $nowPlaying): ?array
    {
        return $nowPlaying ? (new NowPlayingResource($nowPlaying))->resolve() : null;
    }
}
