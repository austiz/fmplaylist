<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DispatchPiCommandRequest;
use App\Models\PiCommand;
use App\Models\PiToken;
use App\Models\Station;
use App\Services\DeviceSyncService;
use App\Support\PiPresence;
use App\Support\PiSource;
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
        // Also scheduled (routes/console.php). Kept here so the devices page
        // self-heals on shared hosting where no cron may be configured.
        PiCommand::expireStale();

        $currentHash = PiSource::hash();

        $devices = PiToken::with('station')->orderByDesc('created_at')->get();
        $progress = $this->deviceSyncService->downloadProgressFor($devices->pluck('id')->all());

        $tokens = $devices
            ->map(function (PiToken $t) use ($currentHash, $progress) {
                return [
                    'id' => $t->id,
                    'label' => $t->label,
                    'station' => $t->station ? ['id' => $t->station->id, 'name' => $t->station->name] : null,
                    'online' => PiPresence::isOnline($t),
                    'last_seen_at' => $t->last_seen_at?->diffForHumans(),
                    'disk_free_bytes' => $t->disk_free_bytes,
                    'disk_total_bytes' => $t->disk_total_bytes,
                    'downloads_done' => $progress['done'][$t->id] ?? 0,
                    'downloads_total' => $progress['total'],
                    'created_at' => $t->created_at->toDateString(),
                    'status' => $t->pi_status,
                    'mode' => $t->pi_mode,
                    'ip' => $t->pi_ip,
                    // Whether the station is actually on the air, as opposed to
                    // merely having a live daemon process.
                    'fm_running' => $t->pi_fm_running,
                    'queue_depth' => $t->pi_queue_depth,
                    'last_error' => $t->pi_last_error,
                    'daemon_hash' => $t->pi_daemon_hash,
                    'up_to_date' => $t->pi_daemon_hash !== null && $t->pi_daemon_hash === $currentHash,
                    'last_update_status' => $t->pi_last_update_status,
                    'last_update_at' => $t->pi_last_update_at?->diffForHumans(),
                    'commands' => $this->recentCommands($t),
                ];
            });

        return Inertia::render('admin/tokens', [
            'tokens' => $tokens,
            'stations' => Station::orderBy('name')->get(['id', 'name']),
            'newToken' => session('new_token'),
            'appUrl' => rtrim(config('app.url'), '/'),
            'currentHash' => $currentHash,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function recentCommands(PiToken $token): array
    {
        return PiCommand::query()
            ->where('pi_token_id', $token->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (PiCommand $c): array => [
                'id' => $c->id,
                'command' => $c->command,
                'status' => $c->status,
                'result' => $c->result,
                'at' => $c->created_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Queue an action for one device.
     *
     * Per-device on purpose: the station-scoped Setting flags this replaces were
     * consumed by whichever Pi heartbeated first, so on a multi-Pi station the
     * others silently never got the command.
     */
    public function dispatchCommand(DispatchPiCommandRequest $request, PiToken $token): RedirectResponse
    {
        $data = $request->validated();

        // Re-queuing while one is still in flight just stacks duplicate reboots.
        $inFlight = PiCommand::query()
            ->where('pi_token_id', $token->id)
            ->where('command', $data['command'])
            ->pending()
            ->exists();

        if ($inFlight) {
            return back()->with('error', "\"{$data['command']}\" is already pending on {$token->label}.");
        }

        PiCommand::create([
            'pi_token_id' => $token->id,
            'command' => $data['command'],
            'payload' => $data['payload'] ?? null,
            'status' => 'queued',
        ]);

        Log::info('Pi command queued', [
            'user' => auth()->id(),
            'token_id' => $token->id,
            'command' => $data['command'],
        ]);

        return back()->with('success', "Queued \"{$data['command']}\" for {$token->label}. It runs within 30 seconds.");
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
