<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class TokenController extends Controller
{
    public function index(): Response
    {
        $tokens = PiToken::orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'label' => $t->label,
                'last_seen_at' => $t->last_seen_at?->diffForHumans(),
                'created_at' => $t->created_at->toDateString(),
            ]);

        return Inertia::render('admin/tokens', [
            'tokens' => $tokens,
            'newToken' => session('new_token'),
            'appUrl' => rtrim(config('app.url'), '/'),
        ]);
    }

    public function regenerate(): RedirectResponse
    {
        $raw = DB::transaction(function () {
            PiToken::query()->delete();
            ['raw' => $raw] = PiToken::generate('Raspberry Pi');

            return $raw;
        });

        Log::info('Pi token regenerated', ['ip' => request()->ip(), 'user' => auth()->id()]);

        return redirect()->route('admin.tokens')->with('new_token', $raw);
    }
}
