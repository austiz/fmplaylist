<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commercial;
use App\Support\AudioDuration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CommercialController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/commercials', [
            'commercials' => Commercial::orderBy('rotation_order')->orderBy('title')->get(),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:wav,mp3,ogg', 'max:51200'],
            'title' => ['required', 'string', 'max:255'],
        ]);

        $file = $data['file'];
        $filename = time().'_'.str_replace(' ', '_', $data['title']).'.'.$file->extension();
        $path = $file->storeAs('commercials', $filename, 'public');

        Commercial::create([
            'title'             => $data['title'],
            'filename'          => $filename,
            'storage_path'      => $path,
            'file_size'         => $file->getSize(),
            'duration_seconds'  => AudioDuration::extract(Storage::disk('public')->path($path)),
            'active'            => true,
            'needs_pi_download' => true,
        ]);

        return back()->with('success', 'Commercial uploaded. Pi will download it on next heartbeat.');
    }

    public function update(Commercial $commercial, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'rotation_order' => ['required', 'integer', 'min:0'],
        ]);

        $commercial->update($data);

        return back()->with('success', 'Commercial updated.');
    }

    public function toggleActive(Commercial $commercial): RedirectResponse
    {
        $commercial->update(['active' => ! $commercial->active]);

        return back()->with('success', $commercial->active ? 'Commercial enabled.' : 'Commercial disabled.');
    }

    public function destroy(Commercial $commercial): RedirectResponse
    {
        // If Pi never downloaded it, delete immediately — no Pi-side cleanup needed
        if ($commercial->needs_pi_download) {
            if ($commercial->storage_path) {
                Storage::disk('public')->delete($commercial->storage_path);
            }
            $commercial->delete();

            return back()->with('success', 'Commercial deleted.');
        }

        // Pi has the file; remove web copy and flag for Pi cleanup
        if ($commercial->storage_path) {
            Storage::disk('public')->delete($commercial->storage_path);
        }
        $commercial->update([
            'active' => false,
            'pi_delete_requested' => true,
        ]);

        return back()->with('success', 'Commercial marked for deletion. Pi will remove it on next heartbeat.');
    }
}
