<?php

namespace App\Http\Resources;

use App\Models\NowPlaying;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one shape for "what is on air right now".
 *
 * This was serialized in six places in three shapes that genuinely disagreed:
 * the REST and SSE payloads for the same event carried different keys, the Pi's
 * public endpoint omitted `duration_seconds`, and the admin dashboard returned
 * null for commercials so they rendered as "nothing playing".
 *
 * Commercials, sound bytes and station IDs have no media row to name — they are
 * announced rather than titled — so they get a fixed display title here.
 *
 * @property NowPlaying $resource
 */
class NowPlayingResource extends JsonResource
{
    private const ANNOUNCEMENTS = [
        'commercial' => 'Commercial Break',
        'sound_byte' => 'Radio Drop',
        'station_id' => 'Station ID',
    ];

    public function __construct(NowPlaying $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource->type,
            'song' => $this->track(),
            'queue_item_id' => $this->resource->queue_item_id,
            'started_at' => $this->resource->started_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null null when the song row has since been deleted */
    private function track(): ?array
    {
        $announcement = self::ANNOUNCEMENTS[$this->resource->type] ?? null;

        if ($announcement !== null) {
            return ['id' => null, 'title' => $announcement, 'artist' => null, 'duration_seconds' => null];
        }

        $song = $this->resource->mediaAsset;

        return $song ? [
            'id' => $song->id,
            'title' => $song->title,
            'artist' => $song->artist,
            'duration_seconds' => $song->duration_seconds,
        ] : null;
    }
}
