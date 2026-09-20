<?php

namespace Database\Factories;

use App\Models\DeviceDownload;
use App\Models\MediaAsset;
use App\Models\PiToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/** @extends Factory<DeviceDownload> */
class DeviceDownloadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pi_token_id' => PiToken::factory(),
            'media_type' => 'song',
            'media_id' => MediaAsset::factory(),
            'downloaded_at' => now(),
        ];
    }

    /**
     * media_id is a plain column rather than a constrained foreign key, so the
     * type and the id have to be set together or the row points at nothing.
     */
    public function forMedia(Model $media, string $mediaType): static
    {
        return $this->state(fn () => [
            'media_type' => $mediaType,
            'media_id' => $media->getKey(),
        ]);
    }

    /** Owed to the device but not yet confirmed downloaded. */
    public function pending(): static
    {
        return $this->state(fn () => ['downloaded_at' => null]);
    }
}
