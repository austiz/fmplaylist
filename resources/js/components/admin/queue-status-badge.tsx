import { Badge } from '@/components/ui/badge';

/**
 * A queue item's status as a pill.
 *
 * The dashboard and History each carried the same four-branch ternary, and
 * they had already drifted -- one of them rendered `skipped` in the same grey
 * as `pending`, so a song the transmitter gave up on looked like one still
 * waiting its turn.
 *
 * `status` is a plain string on the wire, so an unrecognised value reads as
 * unremarkable rather than rendering nothing.
 */
const VARIANTS = {
    played: 'online',
    playing: 'playing',
    pending: 'neutral',
    skipped: 'warning',
} as const;

export function QueueStatusBadge({ status }: { status: string }) {
    return (
        <Badge
            variant={VARIANTS[status as keyof typeof VARIANTS] ?? 'neutral'}
            className="shrink-0"
        >
            {status}
        </Badge>
    );
}
