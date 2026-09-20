<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Support\AudioDuration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Read an upload's runtime off disk, out of the request that uploaded it.
 *
 * ffprobe on a 40 MB WAV is not fast, and it used to run inside the upload request,
 * which is the whole reason `media:backfill-durations` had to exist. The row is
 * created with a null duration and this fills it in; the UI shows a blank runtime
 * until it does, and the backfill command remains the net for the times ffprobe is
 * not installed at all.
 *
 * The asset is passed by id, not by model: an upload can be deleted again before the
 * worker reaches it, and a `ModelNotFoundException` from a serialized model would
 * retry until it gave up. A missing row here is just nothing to do.
 */
class ExtractAudioDuration implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $mediaAssetId) {}

    public function handle(): void
    {
        $asset = MediaAsset::allStations()->find($this->mediaAssetId);

        if (! $asset?->storage_path) {
            return;
        }

        $seconds = AudioDuration::extract(Storage::disk('public')->path($asset->storage_path));

        if ($seconds !== null) {
            $asset->update(['duration_seconds' => $seconds]);
        }
    }
}
