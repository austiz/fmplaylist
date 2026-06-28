<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commercial;
use App\Models\Song;
use App\Models\SoundByte;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SoundsController extends Controller
{
    public function index(Request $request): Response
    {
        $songs = Song::query()
            ->when($request->search, fn ($q) => $q
                ->where('title', 'like', "%{$request->search}%")
                ->orWhere('artist', 'like', "%{$request->search}%")
                ->orWhere('filename', 'like', "%{$request->search}%"))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->through(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'artist' => $s->artist,
                'filename' => $s->filename,
                'duration_formatted' => $s->duration_formatted,
                'file_size' => $s->file_size,
                'available' => $s->available,
                'web_uploaded' => (bool) $s->storage_path,
                'needs_pi_download' => $s->needs_pi_download,
                'pi_delete_requested' => $s->pi_delete_requested,
                'created_at' => $s->created_at->toDateString(),
            ]);

        $commercials = Commercial::orderBy('rotation_order')->orderByDesc('created_at')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'filename' => $c->filename,
                'duration_formatted' => $c->duration_formatted,
                'file_size' => $c->file_size,
                'active' => $c->active,
                'rotation_order' => $c->rotation_order,
                'play_count' => $c->play_count,
                'needs_pi_download' => $c->needs_pi_download,
                'pi_delete_requested' => $c->pi_delete_requested,
                'created_at' => $c->created_at->toDateString(),
            ]);

        $soundBytes = SoundByte::orderByDesc('created_at')
            ->get()
            ->map(fn ($sb) => [
                'id' => $sb->id,
                'title' => $sb->title,
                'category' => $sb->category,
                'rds_ps' => $sb->rds_ps,
                'filename' => $sb->filename,
                'duration_formatted' => $sb->duration_formatted,
                'file_size' => $sb->file_size,
                'active' => $sb->active,
                'needs_pi_download' => $sb->needs_pi_download,
                'pi_delete_requested' => $sb->pi_delete_requested,
                'created_at' => $sb->created_at->toDateString(),
            ]);

        return Inertia::render('admin/sounds', [
            'songs' => $songs,
            'commercials' => $commercials,
            'soundBytes' => $soundBytes,
        ]);
    }
}
