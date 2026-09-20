<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RDS text as receivers display it.
 *
 * The lengths are the RDS spec's, not ours: 64 characters of RadioText and an
 * 8-character programme service name. Anything longer is silently cut by the
 * receiver, so it is rejected here instead.
 */
class UpdateRdsRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'rds_rt_mode' => ['required', 'in:auto,custom'],
            'rds_rt' => ['nullable', 'string', 'max:64'],
            'rds_ps' => ['nullable', 'string', 'max:8'],
        ];
    }
}
