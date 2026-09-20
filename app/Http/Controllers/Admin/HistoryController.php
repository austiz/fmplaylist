<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HasActiveStation;
use App\Http\Controllers\Admin\Concerns\HasChartStats;
use App\Http\Controllers\Controller;
use App\Models\QueueItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    use HasActiveStation;
    use HasChartStats;

    public function index(Request $request): Response
    {
        $station = $this->activeStation($request);

        $allowed = ['all', 'pending', 'playing', 'played', 'skipped'];
        $filter = in_array($request->query('filter'), $allowed, true)
            ? $request->query('filter')
            : 'all';

        $items = QueueItem::with('mediaAsset')
            ->where('station_id', $station->id)
            ->when($filter !== 'all', fn ($q) => $q->where('status', $filter))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn ($item) => [
                'id' => $item->id,
                'status' => $item->status,
                'requested_by_name' => $item->requested_by_name,
                'created_at' => $item->created_at->toDateTimeString(),
                'played_at' => $item->played_at?->toDateTimeString(),
                'song' => [
                    'title' => $item->mediaAsset->title ?? '(deleted)',
                    'artist' => $item->mediaAsset->artist ?? '',
                ],
            ]);

        $playsLast7Days = $this->playsLast7Days($station->id);

        return Inertia::render('admin/history', compact('items', 'filter', 'playsLast7Days'));
    }
}
