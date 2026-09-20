<?php

namespace App\Http\Controllers;

use App\Support\PiSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PiSetupController extends Controller
{
    /** The host baked into the checked-in setup.sh, rewritten per-request below. */
    private const DEFAULT_BASE_URL = 'https://fmplaylist.com';

    /**
     * The payload the Pi installs, with per-file hashes so it can download only
     * what changed. Also the authority for "is this Pi up to date" — see PiSource.
     */
    public function manifest(): JsonResponse
    {
        $payload = [
            'hash' => PiSource::hash(),
            'files' => PiSource::manifest(),
        ];

        // Say so rather than serving a payload that is quietly incomplete. The
        // large assets are untracked, so a server that was deployed without them
        // otherwise looks healthy here and fails on the device, mid-install.
        $missing = PiSource::missing();

        if ($missing !== []) {
            $payload['missing'] = $missing;
        }

        return response()->json($payload);
    }

    public function file(string $filename): BinaryFileResponse
    {
        // Whitelist derived from the payload itself rather than a hand-kept list,
        // which had already gone stale (it was missing whisper.sh and the
        // generate_*.py the build uses).
        $path = PiSource::path($filename);

        abort_if($path === null || ! is_file($path), 404);

        return response()->download($path, $filename);
    }

    public function setup(Request $request): Response
    {
        // Single source of truth: public/pi/setup.sh. This used to be a ~200 line
        // heredoc copy, which silently drifted from the real installer — the copy
        // here lacked every apt-lock/dpkg-repair fix that shipped in the file.
        $path = public_path('pi/setup.sh');

        abort_unless(is_file($path), 404);

        $script = (string) file_get_contents($path);

        // Point the script back at the host it was fetched from, so a Pi
        // provisioned from a staging or per-domain install doesn't phone home to
        // production. Covers the BASE_URL assignment and the usage comment in one
        // pass; the FMPLAYLIST_REPO github.com URL is a different host, so it is
        // left alone.
        $base = $this->setupBaseUrl($request);

        if ($base !== self::DEFAULT_BASE_URL) {
            $script = str_replace(self::DEFAULT_BASE_URL, $base, $script);
        }

        return response($script, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function setupBaseUrl(Request $request): string
    {
        $configured = rtrim((string) config('app.url'), '/');

        if (str_starts_with($configured, 'http://') || str_starts_with($configured, 'https://')) {
            return $configured;
        }

        $host = $request->getHttpHost();

        if ($host !== '') {
            $isLocal = str_starts_with($host, 'localhost')
                || str_starts_with($host, '127.0.0.1')
                || str_starts_with($host, '[::1]');

            return ($isLocal ? $request->getScheme() : 'https')."://{$host}";
        }

        return (string) config('fm.base_url');
    }
}
