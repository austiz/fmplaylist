<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HasActiveStation;
use App\Http\Controllers\Controller;
use App\Http\Resources\NowPlayingResource;
use App\Http\Resources\QueueItemResource;
use App\Models\NowPlaying;
use App\Models\QueueItem;
use App\Services\QueueService;
use App\Support\LiveState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Managing the queue, which until now was an "✕" on a dashboard card.
 *
 * Everything that changes an order goes through `QueueService`, which takes the
 * station lock: the Pi claims the head of the queue on its own poll, so an
 * operator dragging a row and a transmitter asking for its next song are two
 * writers on the same positions.
 */
class QueueAdminController extends Controller
{
    use HasActiveStation;

    public function __construct(private QueueService $queueService) {}

    /**
     * The live queue: what is on air, and everything waiting behind it.
     *
     * Not paginated, and deliberately so -- the page's whole job is reordering,
     * and an order you can only see 50 rows of at a time is not one you can
     * rearrange. `played` and `skipped` rows are History's job.
     */
    public function index(Request $request): Response
    {
        $station = $this->activeStation($request);

        $items = QueueItem::with('mediaAsset')
            ->where('station_id', $station->id)
            ->whereIn('status', ['pending', 'playing'])
            // Whatever is on air sits at the top regardless of its position, which
            // is how the operator reads the list: this is playing, these are next.
            ->orderByRaw("CASE WHEN status = 'playing' THEN 0 ELSE 1 END")
            ->orderBy('position')
            ->get()
            ->map(fn (QueueItem $item) => (new QueueItemResource($item))->resolve())
            ->all();

        $nowPlaying = ($np = NowPlaying::forStation($station->id))
            ? (new NowPlayingResource($np))->resolve()
            : null;

        return Inertia::render('admin/queue', compact('items', 'nowPlaying'));
    }

    /**
     * Persist a dragged order.
     *
     * The client sends the whole list rather than "item 7 moved to slot 2": the
     * same payload shape the saved-networks panel uses, and it means a reorder
     * that raced another one resolves to a complete order instead of applying a
     * move to a list that no longer looks like the one it was computed against.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $this->queueService->reorder($station->id, $data['ids']);

        return back()->with('success', 'Queue order updated.');
    }

    public function playNext(Request $request, QueueItem $queueItem): RedirectResponse
    {
        $station = $this->activeStation($request);

        if ($queueItem->status !== 'pending') {
            return back()->with('error', 'Only a pending request can be moved.');
        }

        $this->queueService->moveToFront($station->id, $queueItem->id);

        return back()->with('success', "“{$queueItem->mediaAsset?->title}” plays next.");
    }

    public function destroy(Request $request, QueueItem $queueItem): RedirectResponse
    {
        $station = $this->activeStation($request);

        // No ownership check: the binding resolved under `StationScope`, so an id from
        // another station never became a model in the first place.
        if ($queueItem->status !== 'pending') {
            return back()->with('error', 'Only pending requests can be removed.');
        }

        $queueItem->delete();
        LiveState::queueChanged($station->id);

        return back()->with('success', 'Request removed from queue.');
    }

    /**
     * Remove a selection in one go.
     *
     * Route-model binding gives one row per request, so clearing thirty autofilled
     * picks was thirty round trips and thirty queue-version bumps -- which every
     * listener's poll saw as thirty separate changes.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        // Scoped by station here as well as by id: this one does not go through
        // route-model binding, so `StationScope` is the only thing standing between
        // a posted id and another station's queue.
        $removed = QueueItem::where('station_id', $station->id)
            ->where('status', 'pending')
            ->whereIn('id', $data['ids'])
            ->delete();

        if ($removed === 0) {
            return back()->with('error', 'Nothing to remove — those requests have already played.');
        }

        $this->queueService->bumpQueueVersion($station->id);

        return back()->with('success', $removed === 1
            ? 'Request removed from queue.'
            : "{$removed} requests removed from queue.");
    }
}
