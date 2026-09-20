/**
 * Tags a URL with the station the listener is on.
 *
 * Every listener-facing endpoint resolves its station from `?station=`, and falling
 * back to the default is silent -- an untagged request reaches the wrong station's
 * chat or queue rather than failing. So the tagging lives in one place instead of
 * being spelled out at each call site, which is how `/api/chat` came to be missing it.
 */
export function withStation(
    url: string,
    slug: string | null | undefined,
): string {
    if (!slug) {
        return url;
    }

    const separator = url.includes('?') ? '&' : '?';

    return `${url}${separator}station=${encodeURIComponent(slug)}`;
}
