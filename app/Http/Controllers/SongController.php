<?php

namespace App\Http\Controllers;

use App\Models\Song;
use App\Services\QueueService;
use App\Support\PublicStation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SongController extends Controller
{
    public function __construct(private QueueService $queueService) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        $station = PublicStation::resolve($request);

        $songs = Song::available()
            ->when($search, fn ($q) => $q->search($search))
            ->orderBy('title')
            ->paginate(24)
            ->withQueryString()
            ->through(fn ($song) => [
                'id' => $song->id,
                'title' => $song->title,
                'artist' => $song->artist,
                'duration_formatted' => $song->duration_formatted,
            ]);

        return Inertia::render('songs', [
            'songs' => $songs,
            'search' => $search,
            'station' => ['id' => $station->id, 'name' => $station->name, 'slug' => $station->slug],
        ]);
    }

    public function request(Request $request, Song $song): RedirectResponse
    {
        abort_unless($song->available, 404);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:50'],
        ]);

        $station = PublicStation::resolve($request);

        $this->queueService->addToQueue($station->id, $song->id, $data['name'] ?? null);

        return back()->with('success', "\"{$song->title}\" added to the queue!");
    }
}
