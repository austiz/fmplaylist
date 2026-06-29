<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\QueueItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $allowed = ['all', 'pending', 'playing', 'played', 'skipped'];
        $filter  = in_array($request->query('filter'), $allowed, true)
            ? $request->query('filter')
            : 'all';

        $items = QueueItem::with('song')
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
                    'title' => $item->song?->title ?? '(deleted)',
                    'artist' => $item->song?->artist ?? '',
                ],
            ]);

        return Inertia::render('admin/history', compact('items', 'filter'));
    }
}
