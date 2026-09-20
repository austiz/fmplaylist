<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStation;
use Database\Factories\WifiNetworkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WifiNetwork extends Model
{
    /** @use HasFactory<WifiNetworkFactory> */
    use BelongsToStation, HasFactory;

    protected $fillable = [
        'station_id', 'ssid', 'password', 'priority', 'active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'active' => 'boolean',
    ];

    /**
     * @param  Builder<WifiNetwork>  $query
     * @return Builder<WifiNetwork>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Ordered list handed to the Pi. Lowest priority number is tried first;
     * ties break on id so the ordering is stable across requests (an unstable
     * order would change the revision hash and cause pointless re-syncs).
     *
     * @return Collection<int, WifiNetwork>
     */
    public static function ordered(int $stationId): Collection
    {
        return self::query()
            ->active()
            ->forStation($stationId)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * The payload the daemon persists and hands to NetworkManager.
     *
     * @return array<int, array{ssid: string, password: string, priority: int}>
     */
    public static function profilesFor(int $stationId): array
    {
        return static::ordered($stationId)
            ->map(fn (self $n): array => [
                'ssid' => $n->ssid,
                'password' => (string) $n->password,
                'priority' => $n->priority,
            ])
            ->values()
            ->all();
    }

    /**
     * Short content hash of the ordered list. The Pi compares this against its
     * stored copy and only re-runs nmcli when it differs — reconfiguring the
     * interface every 30s would be wasted CPU on a Pi Zero mid-broadcast.
     */
    public static function revisionFor(int $stationId): string
    {
        $profiles = static::profilesFor($stationId);

        if ($profiles === []) {
            return '';
        }

        return substr(hash('sha256', (string) json_encode($profiles)), 0, 16);
    }
}
