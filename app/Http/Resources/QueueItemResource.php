<?php

namespace App\Http\Resources;

use App\Models\QueueItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the request queue, however it is being listed.
 *
 * Four controllers each carried their own mapper for this, and they had drifted:
 * the two admin pages reported `status` and `created_at` where the two listener
 * pages reported `position`, only the listener queue carried `duration_seconds`,
 * and a deleted song rendered as `(deleted)` in some lists and not others. They
 * are one shape now, so a page can show any column without a controller change.
 *
 * @property QueueItem $resource
 */
class QueueItemResource extends JsonResource
{
    /**
     * @param  bool  $relativeTimes  true renders `played_at` as "3 minutes ago" for the
     *                               listener pages; the admin lists want the timestamp.
     */
    public function __construct(QueueItem $resource, private bool $relativeTimes = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $song = $item->mediaAsset;

        return [
            'id' => $item->id,
            'position' => $item->position,
            'status' => $item->status,
            'requested_by_name' => $item->requested_by_name,
            'created_at' => $item->created_at?->toDateTimeString(),
            'played_at' => $this->relativeTimes
                ? $item->played_at?->diffForHumans()
                : $item->played_at?->toDateTimeString(),
            'song' => [
                'title' => $song->title ?? '(deleted)',
                'artist' => $song->artist ?? '',
                'duration_seconds' => $song?->duration_seconds,
            ],
        ];
    }
}
