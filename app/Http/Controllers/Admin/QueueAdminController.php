<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HasActiveStation;
use App\Http\Controllers\Controller;
use App\Models\QueueItem;
use App\Support\LiveState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QueueAdminController extends Controller
{
    use HasActiveStation;

    public function destroy(Request $request, QueueItem $queueItem): RedirectResponse
    {
        $station = $this->activeStation($request);

        // No ownership check: the binding resolved under `StationScope`, so an id from
        // another station never became a model in the first place.
        if ($queueItem->status !== 'pending') {
            return back()->with('error', 'Only pending requests can be removed.');
        }

        $queueItem->delete();
        LiveState::queueChanged($station->id);

        return back()->with('success', 'Request removed from queue.');
    }
}
