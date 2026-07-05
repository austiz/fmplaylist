<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HasActiveStation;
use App\Http\Controllers\Controller;
use App\Models\QueueItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class QueueAdminController extends Controller
{
    use HasActiveStation;

    public function destroy(Request $request, QueueItem $queueItem): RedirectResponse
    {
        $station = $this->activeStation($request);

        abort_unless($queueItem->station_id === $station->id, 404);

        if ($queueItem->status !== 'pending') {
            return back()->with('error', 'Only pending requests can be removed.');
        }

        $queueItem->delete();
        Cache::put("sse.queue_version.{$station->id}", (string) microtime(true), 3600);

        return back()->with('success', 'Request removed from queue.');
    }
}
