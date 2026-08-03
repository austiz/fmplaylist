<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\QueueItem;
use Illuminate\Support\Facades\DB;

trait HasChartStats
{
    /** Requests received per hour today, 24 buckets (0-23), for a bar sparkline. */
    protected function requestsPerHourToday(int $stationId): array
    {
        $rows = QueueItem::where('station_id', $stationId)
            ->whereDate('created_at', today())
            ->select(DB::raw($this->hourExpression('created_at').' as hour'), DB::raw('COUNT(*) as total'))
            ->groupBy('hour')
            ->pluck('total', 'hour');

        return array_map(fn (int $hour) => (int) ($rows[$hour] ?? 0), range(0, 23));
    }

    /** Songs played per day over the last 7 days (oldest first), for a line/area sparkline. */
    protected function playsLast7Days(int $stationId): array
    {
        $start = today()->subDays(6);

        $rows = QueueItem::where('station_id', $stationId)
            ->played()
            ->whereDate('played_at', '>=', $start)
            ->select(DB::raw('DATE(played_at) as day'), DB::raw('COUNT(*) as total'))
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(0, 6))
            ->map(function (int $i) use ($start, $rows) {
                $date = $start->copy()->addDays($i);

                return [
                    'date' => $date->toDateString(),
                    'count' => (int) ($rows[$date->toDateString()] ?? 0),
                ];
            })
            ->all();
    }

    private function hourExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(strftime('%H', {$column}) AS INTEGER)",
            'pgsql' => "EXTRACT(HOUR FROM {$column})",
            'sqlsrv' => "DATEPART(hour, {$column})",
            default => "HOUR({$column})",
        };
    }
}
