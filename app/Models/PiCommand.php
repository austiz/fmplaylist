<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
    /** Actions the daemon knows how to run. Anything else is rejected up front. */
    public const COMMANDS = [
        'update',
        'rollback',
        'rebuild',
        'restart_daemon',
        'reboot',
        'fm_stop',
        'fm_start',
        'fetch_logs',
    ];

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

    /** @return BelongsTo<PiToken, PiCommand> */
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
