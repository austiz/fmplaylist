<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A device confirming it finished a download or a delete.
 *
 * Both endpoints take the same payload and had the same validation block written
 * out twice; the legacy `song_id` spelling lived in two places along with it.
 * Deployed daemons still send `song_id` with no `type`, so both forms are read
 * here and the callers only ever see the resolved pair.
 */
class ConfirmTransferRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'in:song,commercial,sound_byte'],
            'item_id' => ['nullable', 'integer'],
            'song_id' => ['nullable', 'integer'], // legacy field
        ];
    }

    /** Old daemons name no type because songs were all there was. */
    public function mediaType(): string
    {
        return $this->validated('type') ?? 'song';
    }

    public function itemId(): ?int
    {
        $data = $this->validated();
        $id = $data['item_id'] ?? $data['song_id'] ?? null;

        return $id === null ? null : (int) $id;
    }
}
