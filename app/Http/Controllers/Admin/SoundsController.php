<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Http\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use App\Services\DeviceSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SoundsController extends Controller
{
    public function index(Request $request, DeviceSyncService $sync): Response
    {
        $search = $request->string('search')->trim()->toString();

        $songs = MediaAsset::query()
            ->ofType(MediaType::Song)
            ->when($search, fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('title', 'like', "%{$search}%")
                ->orWhere('artist', 'like', "%{$search}%")
                ->orWhere('filename', 'like', "%{$search}%")))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        $songHolders = $sync->holderCounts('song', $songs->getCollection()->pluck('id')->all());
        $songs->through(fn (MediaAsset $s) => (new MediaAssetResource($s, $songHolders))->resolve());

        $commercials = MediaAsset::query()
            ->ofType(MediaType::Commercial)
            ->orderBy('rotation_order')
            ->orderByDesc('created_at')
            ->get();

        $soundBytes = MediaAsset::query()
            ->ofType(MediaType::SoundByte)
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('admin/sounds', [
            'songs' => $songs,
            'commercials' => $this->rows($commercials, 'commercial', $sync),
            'soundBytes' => $this->rows($soundBytes, 'sound_byte', $sync),
            'deviceCount' => $sync->activeDeviceCount(),
            'search' => $search,
        ]);
    }

    /**
     * @param  Collection<int, MediaAsset>  $assets
     * @return array<int, array<string, mixed>>
     */
    private function rows(Collection $assets, string $type, DeviceSyncService $sync): array
    {
        $holders = $sync->holderCounts($type, $assets->pluck('id')->all());

        return $assets->map(fn (MediaAsset $a) => (new MediaAssetResource($a, $holders))->resolve())->all();
    }
}
