<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\QueueItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

class QueueAdminController extends Controller
{
    public function destroy(QueueItem $queueItem): RedirectResponse
    {
        if ($queueItem->status !== 'pending') {
            return back()->with('error', 'Only pending requests can be removed.');
        }

        $queueItem->delete();
        Cache::put('sse.queue_version', (string) microtime(true), 3600);

        return back()->with('success', 'Request removed from queue.');
    }
}
