<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The device's inventory of what it actually has on disk.
 *
 * `present` rather than `required`: an empty library is a legitimate report from a
 * freshly imaged Pi, and it is the one that most needs to be acted on.
 */
class SyncLibraryRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'songs' => ['present', 'array'],
            'songs.*.filename' => ['required', 'string'],
            'songs.*.file_size' => ['nullable', 'integer'],
        ];
    }
}
