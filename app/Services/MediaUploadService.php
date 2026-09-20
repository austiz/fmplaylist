<?php

namespace App\Services;

use App\Enums\MediaType;
use App\Models\MediaAsset;
use App\Support\AudioDuration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Store an upload and delete it again, for every media type.
 *
 * This was three copies of the same twenty lines, one per controller, and they had
 * already drifted: only the song path used `getClientOriginalExtension()`, and only
 * the song delete path checked `storage_path` before flagging for Pi cleanup.
 */
class MediaUploadService
{
    public function __construct(private DeviceSyncService $sync) {}

    /**
     * @param  array<string, mixed>  $attributes  type-specific fields (artist, category, …)
     */
    public function store(MediaType $type, UploadedFile $file, string $title, array $attributes = []): MediaAsset
    {
        $filename = Str::slug($title).'_'.time().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs($type->storageDirectory(), $filename, 'public');

        if ($path === false) {
            throw new RuntimeException("Failed to store {$type->value} upload as {$filename}.");
        }

        return MediaAsset::create([
            'type' => $type,
            'title' => $title,
            'filename' => $filename,
            'storage_path' => $path,
            'file_size' => $file->getSize(),
            'duration_seconds' => AudioDuration::extract(Storage::disk('public')->path($path)),
            'active' => true,
            'needs_pi_download' => true,
            ...$attributes,
        ]);
    }

    /**
     * Retire an asset, hard-deleting only when no device still holds the file.
     *
     * `needs_pi_download` cannot answer "is it on a Pi?" — it is global and defaults
     * to false — so `device_downloads` decides. When a device does hold it, the row
     * stays behind carrying `pi_delete_requested` until that device confirms the
     * delete, and only the web copy goes now.
     *
     * @return bool whether the row was deleted outright
     */
    public function delete(MediaAsset $asset): bool
    {
        if ($asset->storage_path) {
            Storage::disk('public')->delete($asset->storage_path);
        }

        if (! $this->sync->isHeldByAnyDevice($asset->type->value, $asset->id)) {
            $asset->delete();

            return true;
        }

        $asset->update([
            'active' => false,
            'pi_delete_requested' => true,
        ]);

        return false;
    }
}
