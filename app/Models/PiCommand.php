<?php

namespace App\Models;

use Database\Factories\PiCommandFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One remote action queued for one Pi.
 *
 * Unlike the station-scoped Setting flags this replaces, a command is addressed
 * to a specific device and is only cleared when that device acknowledges it —
 * so the admin finally sees whether an action actually happened.
 */
class PiCommand extends Model
{
    /** @use HasFactory<PiCommandFactory> */
    use HasFactory;

    /** Actions an admin can aim at one device from the Devices page. */
    public const DISPATCHABLE = [
        'update',
        'rollback',
        'rebuild',
        'restart_daemon',
        'reboot',
        'fm_stop',
        'fm_start',
        'fetch_logs',
    ];

    /**
     * Everything the daemon knows how to run.
     *
     * `emergency` is not dispatchable: it carries the announcement filename as its
     * payload and is raised for a whole station from the Broadcast page, so there is
     * no sense in aiming it at a single Pi.
     */
    public const COMMANDS = [...self::DISPATCHABLE, 'emergency'];

    /** Actions that interrupt the broadcast — the UI confirms before sending these. */
    public const DISRUPTIVE = [
        'update', 'rollback', 'rebuild', 'restart_daemon', 'reboot', 'fm_stop',
    ];

    protected $fillable = [
        'pi_token_id', 'command', 'payload', 'status', 'result',
        'sent_at', 'completed_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Queue $command for every device on a station, skipping any that already have
     * one in flight.
     *
     * Station-wide actions used to be `Setting` flags that the config response
     * cleared, so whichever Pi heartbeated first consumed the flag and the second Pi
     * on the station never saw the emergency or the update. One row per device means
     * each one gets it, and each one acknowledges it.
     *
     * @return int the number of devices the command reached
     */
    public static function broadcastTo(int $stationId, string $command, ?string $payload = null): int
    {
        $tokenIds = PiToken::where('station_id', $stationId)->pluck('id');

        $alreadyQueued = static::query()
            ->whereIn('pi_token_id', $tokenIds)
            ->where('command', $command)
            ->pending()
            ->pluck('pi_token_id');

        $targets = $tokenIds->diff($alreadyQueued);

        foreach ($targets as $tokenId) {
            static::create([
                'pi_token_id' => $tokenId,
                'command' => $command,
                'payload' => $payload,
                'status' => 'queued',
            ]);
        }

        return $targets->count();
    }

    /** @return BelongsTo<PiToken, $this> */
    public function piToken(): BelongsTo
    {
        return $this->belongsTo(PiToken::class);
    }

    /**
     * @param  Builder<PiCommand>  $query
     * @return Builder<PiCommand>
     */
    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', 'queued');
    }

    /**
     * @param  Builder<PiCommand>  $query
     * @return Builder<PiCommand>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['queued', 'sent']);
    }

    public function markSent(): void
    {
        $this->update(['status' => 'sent', 'sent_at' => now()]);
    }

    public function markAcked(?string $result = null): void
    {
        $this->update([
            'status' => 'acked',
            'result' => $result,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(?string $result = null): void
    {
        $this->update([
            'status' => 'failed',
            'result' => $result,
            'completed_at' => now(),
        ]);
    }

    /**
     * Commands that were sent but never acknowledged. A reboot or a failed
     * update takes the daemon down mid-flight, so without this they would sit
     * in "sent" forever and the UI would show a spinner that never resolves.
     */
    public static function expireStale(int $minutes = 20): void
    {
        static::query()
            ->where('status', 'sent')
            ->where('sent_at', '<', now()->subMinutes($minutes))
            ->update([
                'status' => 'failed',
                'result' => 'No acknowledgement from device',
                'completed_at' => now(),
            ]);
    }
}
