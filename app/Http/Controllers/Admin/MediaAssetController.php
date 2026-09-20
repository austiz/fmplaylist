<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Services\MediaUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Upload, edit, toggle and delete for all three media types.
 *
 * This replaces SongAdminController, CommercialController and SoundByteController,
 * whose four methods each were the same code three times over. The type comes from
 * the route (`->defaults('type', …)` in routes/web.php) so the URLs stay
 * `/admin/songs/…`, `/admin/commercials/…` and `/admin/sound-bytes/…`, and only
 * the genuinely type-specific bits — which fields exist, which extensions are
 * accepted — vary.
 */
class MediaAssetController extends Controller
{
    private const CATEGORIES = ['jingle', 'shoutout', 'drop', 'id'];

    public function __construct(private MediaUploadService $uploads) {}

    public function upload(Request $request): RedirectResponse
    {
        $type = $this->type($request);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:'.$type->allowedExtensions(), 'max:'.$type->maxUploadKb()],
            'title' => ['required', 'string', 'max:255'],
            ...$this->uploadRules($type),
        ]);

        $this->uploads->store(
            $type,
            $request->file('file'),
            $data['title'],
            $this->attributesFrom($type, $data),
        );

        return back()->with('success', "{$type->label()} uploaded. Pi will download it on next heartbeat.");
    }

    public function update(MediaAsset $mediaAsset, Request $request): RedirectResponse
    {
        $type = $this->typeFor($request, $mediaAsset);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            ...$this->updateRules($type),
        ]);

        $mediaAsset->update([
            'title' => $data['title'],
            ...$this->attributesFrom($type, $data),
        ]);

        return back()->with('success', "{$type->label()} updated.");
    }

    public function toggle(MediaAsset $mediaAsset, Request $request): RedirectResponse
    {
        $type = $this->typeFor($request, $mediaAsset);

        $mediaAsset->update(['active' => ! $mediaAsset->active]);

        return back()->with(
            'success',
            $mediaAsset->active ? "{$type->label()} enabled." : "{$type->label()} disabled.",
        );
    }

    public function destroy(MediaAsset $mediaAsset, Request $request): RedirectResponse
    {
        $type = $this->typeFor($request, $mediaAsset);

        $deleted = $this->uploads->delete($mediaAsset);

        return back()->with('success', $deleted
            ? "{$type->label()} deleted."
            : "{$type->label()} marked for deletion. Pi will remove it on next heartbeat.");
    }

    /**
     * Fields an upload accepts beyond `title` and the file itself.
     *
     * @return array<string, array<int, mixed>>
     */
    private function uploadRules(MediaType $type): array
    {
        return match ($type) {
            MediaType::Song => ['artist' => ['nullable', 'string', 'max:255']],
            // Rotation order is assigned later from the Sounds page, not at upload.
            MediaType::Commercial => [],
            MediaType::SoundByte => ['category' => ['required', Rule::in(self::CATEGORIES)]],
        };
    }

    /**
     * Fields the edit form submits beyond `title`.
     *
     * @return array<string, array<int, mixed>>
     */
    private function updateRules(MediaType $type): array
    {
        return match ($type) {
            MediaType::Song => ['artist' => ['nullable', 'string', 'max:255']],
            MediaType::Commercial => ['rotation_order' => ['required', 'integer', 'min:0']],
            MediaType::SoundByte => [
                'category' => ['required', Rule::in(self::CATEGORIES)],
                'rds_ps' => ['nullable', 'string', 'max:8'],
            ],
        };
    }

    /**
     * Narrows the validated payload to the columns this type actually has, so the
     * same `update()` can serve all three without writing a commercial's rotation
     * order onto a song.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributesFrom(MediaType $type, array $data): array
    {
        $attributes = match ($type) {
            // Nullable in the column, but the UI has always shown '' for "unknown".
            MediaType::Song => ['artist' => $data['artist'] ?? ''],
            MediaType::Commercial => ['rotation_order' => $data['rotation_order'] ?? null],
            MediaType::SoundByte => [
                'category' => $data['category'] ?? null,
                'rds_ps' => $data['rds_ps'] ?? null,
            ],
        };

        // rds_ps is genuinely nullable; the others are absent rather than blank.
        return array_filter(
            $attributes,
            fn (mixed $value, string $key) => $value !== null || $key === 'rds_ps',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Set by `->defaults('type', …)` on every route pointing here. */
    private function type(Request $request): MediaType
    {
        return MediaType::from($request->route('type'));
    }

    /**
     * All three types share one table, so `/admin/songs/{id}` would otherwise happily
     * edit a commercial that happens to carry that id.
     */
    private function typeFor(Request $request, MediaAsset $asset): MediaType
    {
        $type = $this->type($request);

        if ($asset->type !== $type) {
            throw new NotFoundHttpException;
        }

        return $type;
    }
}
