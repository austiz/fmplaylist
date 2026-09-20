<?php

namespace App\Http\Resources;

use App\Enums\MediaType;
use App\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The admin Sounds row for one media asset.
 *
 * Replaces the three hand-written array mappers SoundsController used to carry, which
 * had drifted apart in key names as well as content. The drift is preserved on purpose
 * for now — songs still report `available` where commercials and sound bytes report
 * `active`, because the React page reads those names. Unifying them is a frontend
 * change, not a backend one.
 *
 * @property MediaAsset $resource
 */
class MediaAssetResource extends JsonResource
{
    /**
     * @param  array<int, int>  $holderCounts  media id => number of devices holding it,
     *                                         as returned by DeviceSyncService::holderCounts()
     */
    public function __construct(MediaAsset $resource, private array $holderCounts = [])
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $asset = $this->resource;

        return [
            'id' => $asset->id,
            'title' => $asset->title,
            'filename' => $asset->filename,
            'duration_formatted' => $asset->duration_formatted,
            'file_size' => $asset->file_size,
            'has_file' => (bool) $asset->storage_path,
            'devices_have' => $this->holderCounts[$asset->id] ?? 0,
            'pi_delete_requested' => $asset->pi_delete_requested,
            'created_at' => $asset->created_at?->toDateString(),
            ...$this->typeFields($asset),
        ];
    }

    /** @return array<string, mixed> */
    private function typeFields(MediaAsset $asset): array
    {
        return match ($asset->type) {
            MediaType::Song => [
                'artist' => $asset->artist,
                'available' => $asset->active,
            ],
            MediaType::Commercial => [
                'active' => $asset->active,
                'rotation_order' => $asset->rotation_order,
                'play_count' => $asset->play_count,
            ],
            MediaType::SoundByte => [
                'active' => $asset->active,
                'category' => $asset->category,
                'rds_ps' => $asset->rds_ps,
            ],
        };
    }
}
