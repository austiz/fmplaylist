<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves files out of storage/app/public over HTTP.
 *
 * The usual `public/storage` symlink is unreliable on LiteSpeed shared hosting, and
 * the /files/ prefix keeps this clear of Laravel's built-in storage.local route.
 */
class PublicFileController extends Controller
{
    public function __invoke(Request $request, string $path): BinaryFileResponse
    {
        $root = realpath(storage_path('app/public'));
        abort_if($root === false, 404);

        // realpath() resolves `..`, symlinks and Windows/`\` separators in one step, so
        // containment is decided by where the path actually lands — not by whether the
        // request happened to spell the escape in a way a blacklist recognises.
        $file = realpath($root.DIRECTORY_SEPARATOR.$path);

        abort_if($file === false, 404);
        abort_unless(str_starts_with($file, $root.DIRECTORY_SEPARATOR), 403);
        abort_unless(is_file($file), 404);

        return response()->file($file);
    }
}
