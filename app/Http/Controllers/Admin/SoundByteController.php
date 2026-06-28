<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SoundByte;
use App\Support\AudioDuration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SoundByteController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/sound-bytes', [
            'soundBytes' => SoundByte::orderBy('category')->orderBy('title')->get(),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:wav,mp3,ogg', 'max:20480'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:jingle,shoutout,drop,id'],
        ]);

        $file = $data['file'];
        $filename = time().'_'.str_replace(' ', '_', $data['title']).'.'.$file->extension();
        $path = $file->storeAs('soundbytes', $filename, 'public');

        SoundByte::create([
            'title'             => $data['title'],
            'filename'          => $filename,
            'category'          => $data['category'],
            'storage_path'      => $path,
            'file_size'         => $file->getSize(),
            'duration_seconds'  => AudioDuration::extract(Storage::disk('public')->path($path)),
            'active'            => true,
            'needs_pi_download' => true,
        ]);

        return back()->with('success', 'Sound byte uploaded. Pi will download it on next heartbeat.');
    }

    public function update(SoundByte $soundByte, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title'    => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:jingle,shoutout,drop,id'],
            'rds_ps'   => ['nullable', 'string', 'max:8'],
        ]);

        $soundByte->update($data);

        return back()->with('success', 'Sound byte updated.');
    }

    public function toggleActive(SoundByte $soundByte): RedirectResponse
    {
        $soundByte->update(['active' => ! $soundByte->active]);

        return back()->with('success', $soundByte->active ? 'Sound byte enabled.' : 'Sound byte disabled.');
    }

    public function destroy(SoundByte $soundByte): RedirectResponse
    {
        // If Pi never downloaded it, delete immediately — no Pi-side cleanup needed
        if ($soundByte->needs_pi_download) {
            if ($soundByte->storage_path) {
                Storage::disk('public')->delete($soundByte->storage_path);
            }
            $soundByte->delete();

            return back()->with('success', 'Sound byte deleted.');
        }

        // Pi has the file; remove web copy and flag for Pi cleanup
        if ($soundByte->storage_path) {
            Storage::disk('public')->delete($soundByte->storage_path);
        }
        $soundByte->update([
            'active' => false,
            'pi_delete_requested' => true,
        ]);

        return back()->with('success', 'Sound byte marked for deletion. Pi will remove it on next heartbeat.');
    }
}
