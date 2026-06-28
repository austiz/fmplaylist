<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PiToken extends Model
{
    protected $fillable = [
        'token_hash',
        'label',
        'last_seen_at',
        'pi_status',
        'pi_mode',
        'pi_ip',
        'pi_skip_next',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'pi_skip_next' => 'boolean',
    ];

    /** @return array{token: self, raw: string} */
    public static function generate(string $label = 'Raspberry Pi'): array
    {
        $raw = Str::random(48);
        $hash = hash('sha256', $raw);

        $token = static::create([
            'token_hash' => $hash,
            'label' => $label,
        ]);

        return ['token' => $token, 'raw' => $raw];
    }

    public static function findByRaw(string $raw): ?self
    {
        return static::where('token_hash', hash('sha256', $raw))->first();
    }
}
