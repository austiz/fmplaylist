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
 * had drifted apart in key names as well as content — songs reported `available`
 * where the other two reported `active` for the same column.
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
            'active' => $asset->active,
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
            ],
            MediaType::Commercial => [
                'rotation_order' => $asset->rotation_order,
                'play_count' => $asset->play_count,
            ],
            MediaType::SoundByte => [
                'category' => $asset->category,
                'rds_ps' => $asset->rds_ps,
            ],
        };
    }
}
