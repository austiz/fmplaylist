import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';

/** A read-only value shown as code, with the button that puts it on the clipboard. */
export function CopyField({
    heading,
    text,
    variant,
}: {
    heading: string;
    text: string;
    variant?: 'outline';
}) {
    const [copiedText, copy] = useClipboard();

    return (
        <div>
            <p className="mb-1.5 font-display text-xs font-semibold tracking-wide text-red-400/80 uppercase">
                {heading}
            </p>
            <div className="flex gap-2">
                <code className="flex-1 border border-border bg-background px-3 py-2 font-mono text-xs break-all text-foreground">
                    {text}
                </code>
                <Button
                    size="sm"
                    variant={variant}
                    onClick={() => copy(text)}
                    className="shrink-0"
                >
                    {copiedText === text ? 'Copied!' : 'Copy'}
                </Button>
            </div>
        </div>
    );
}
