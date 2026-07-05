<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PiToken;
use App\Models\Station;
use App\Services\DeviceSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class TokenController extends Controller
{
    public function __construct(private DeviceSyncService $deviceSyncService) {}

    public function index(): Response
    {
        $tokens = PiToken::with('station')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (PiToken $t) {
                $progress = $this->deviceSyncService->downloadProgress($t);

                return [
                    'id' => $t->id,
                    'label' => $t->label,
                    'station' => $t->station ? ['id' => $t->station->id, 'name' => $t->station->name] : null,
                    'online' => $t->last_seen_at && $t->last_seen_at->diffInSeconds(now()) < 120,
                    'last_seen_at' => $t->last_seen_at?->diffForHumans(),
                    'disk_free_bytes' => $t->disk_free_bytes,
                    'disk_total_bytes' => $t->disk_total_bytes,
                    'downloads_done' => $progress['done'],
                    'downloads_total' => $progress['total'],
                    'created_at' => $t->created_at->toDateString(),
                ];
            });

        return Inertia::render('admin/tokens', [
            'tokens' => $tokens,
            'stations' => Station::orderBy('name')->get(['id', 'name']),
            'newToken' => session('new_token'),
            'appUrl' => rtrim(config('app.url'), '/'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'station_id' => ['required', 'integer', 'exists:stations,id'],
        ]);

        ['raw' => $raw] = PiToken::generate($data['label'], (int) $data['station_id']);

        Log::info('Pi device created', ['ip' => request()->ip(), 'user' => auth()->id(), 'station_id' => $data['station_id']]);

        return redirect()->route('admin.tokens')->with('new_token', $raw);
    }

    public function update(Request $request, PiToken $token): RedirectResponse
    {
        $data = $request->validate([
            'station_id' => ['required', 'integer', 'exists:stations,id'],
        ]);

        $token->update(['station_id' => $data['station_id']]);

        return back()->with('success', "\"{$token->label}\" reassigned.");
    }

    public function regenerate(PiToken $token): RedirectResponse
    {
        $raw = $token->regenerateSecret();

        Log::info('Pi token regenerated', ['ip' => request()->ip(), 'user' => auth()->id(), 'token_id' => $token->id]);

        return redirect()->route('admin.tokens')->with('new_token', $raw);
    }

    public function destroy(PiToken $token): RedirectResponse
    {
        $token->delete();

        return back()->with('success', 'Device revoked.');
    }
}
