<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commercial;
use App\Models\Song;
use App\Models\SoundByte;
use App\Services\DeviceSyncService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SoundsController extends Controller
{
    public function index(Request $request, DeviceSyncService $sync): Response
    {
        $search = $request->string('search')->trim()->toString();

        $deviceCount = $sync->activeDeviceCount();

        $songs = Song::query()
            ->when($search, fn ($q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('artist', 'like', "%{$search}%")
                ->orWhere('filename', 'like', "%{$search}%"))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        $songHolders = $sync->holderCounts('song', $songs->getCollection()->pluck('id')->all());

        $songs->through(fn ($s) => [
            'id' => $s->id,
            'title' => $s->title,
            'artist' => $s->artist,
            'filename' => $s->filename,
            'duration_formatted' => $s->duration_formatted,
            'file_size' => $s->file_size,
            'available' => $s->available,
            'has_file' => (bool) $s->storage_path,
            'devices_have' => $songHolders[$s->id] ?? 0,
            'pi_delete_requested' => $s->pi_delete_requested,
            'created_at' => $s->created_at->toDateString(),
        ]);

        $commercialRows = Commercial::orderBy('rotation_order')->orderByDesc('created_at')->get();
        $commercialHolders = $sync->holderCounts('commercial', $commercialRows->pluck('id')->all());

        $commercials = $commercialRows->map(fn ($c) => [
            'id' => $c->id,
            'title' => $c->title,
            'filename' => $c->filename,
            'duration_formatted' => $c->duration_formatted,
            'file_size' => $c->file_size,
            'active' => $c->active,
            'rotation_order' => $c->rotation_order,
            'play_count' => $c->play_count,
            'has_file' => (bool) $c->storage_path,
            'devices_have' => $commercialHolders[$c->id] ?? 0,
            'pi_delete_requested' => $c->pi_delete_requested,
            'created_at' => $c->created_at->toDateString(),
        ]);

        $soundByteRows = SoundByte::orderByDesc('created_at')->get();
        $soundByteHolders = $sync->holderCounts('sound_byte', $soundByteRows->pluck('id')->all());

        $soundBytes = $soundByteRows->map(fn ($sb) => [
            'id' => $sb->id,
            'title' => $sb->title,
            'category' => $sb->category,
            'rds_ps' => $sb->rds_ps,
            'filename' => $sb->filename,
            'duration_formatted' => $sb->duration_formatted,
            'file_size' => $sb->file_size,
            'active' => $sb->active,
            'has_file' => (bool) $sb->storage_path,
            'devices_have' => $soundByteHolders[$sb->id] ?? 0,
            'pi_delete_requested' => $sb->pi_delete_requested,
            'created_at' => $sb->created_at->toDateString(),
        ]);

        return Inertia::render('admin/sounds', [
            'songs' => $songs,
            'commercials' => $commercials,
            'soundBytes' => $soundBytes,
            'deviceCount' => $deviceCount,
            'search' => $search,
        ]);
    }
}
