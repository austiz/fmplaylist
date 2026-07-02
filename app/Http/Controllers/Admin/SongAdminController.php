<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Song;
use App\Support\AudioDuration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SongAdminController extends Controller
{
    public function index(): Response
    {
        $songs = Song::orderBy('title')
            ->paginate(100)
            ->through(fn ($song) => [
                'id' => $song->id,
                'title' => $song->title,
                'artist' => $song->artist,
                'filename' => $song->filename,
                'duration_formatted' => $song->duration_formatted,
                'file_size' => $song->file_size,
                'available' => $song->available,
                'needs_pi_download' => $song->needs_pi_download,
                'pi_delete_requested' => $song->pi_delete_requested,
                'web_uploaded' => (bool) $song->storage_path,
                'created_at' => $song->created_at->toDateString(),
            ]);

        return Inertia::render('admin/songs', compact('songs'));
    }

    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:wav,mp3', 'max:51200'],
            'title' => ['required', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $data['file'];
        $slug = Str::slug($data['title']);
        $filename = $slug.'_'.time().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('songs', $filename, 'public');

        Song::create([
            'title'             => $data['title'],
            'artist'            => $data['artist'] ?? '',
            'filename'          => $filename,
            'file_size'         => $file->getSize(),
            'duration_seconds'  => AudioDuration::extract(Storage::disk('public')->path($path)),
            'storage_path'      => $path,
            'available'         => true,
            'needs_pi_download' => true,
        ]);

        return back()->with('success', 'Song uploaded. Pi will download it on next heartbeat (within 30 seconds).');
    }

    public function update(Song $song, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
        ]);

        $song->update([
            'title' => $data['title'],
            'artist' => $data['artist'] ?? '',
        ]);

        return back()->with('success', 'Song updated.');
    }

    public function toggleAvailable(Song $song): RedirectResponse
    {
        $song->update(['available' => ! $song->available]);
        $state = $song->available ? 'visible' : 'hidden';

        return back()->with('success', "Song is now {$state} in the public library.");
    }

    public function destroy(Song $song): RedirectResponse
    {
        // If uploaded via web and not yet on Pi, we can delete immediately
        if ($song->storage_path && $song->needs_pi_download) {
            Storage::disk('public')->delete($song->storage_path);
            $song->delete();

            return back()->with('success', 'Song deleted (was not yet downloaded by Pi).');
        }

        // Otherwise flag for Pi deletion; Pi will remove the file and confirm back
        if ($song->storage_path) {
            Storage::disk('public')->delete($song->storage_path);
        }

        $song->update([
            'available' => false,
            'pi_delete_requested' => true,
        ]);

        return back()->with('success', 'Song marked for deletion. Pi will remove it on next heartbeat.');
    }
}
