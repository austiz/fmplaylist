import { useEffect, useRef, useState } from 'react';
import { useFmLive } from '@/hooks/use-fm-live';

export function ChatPanel() {
    const { chatMessages } = useFmLive();
    const [name, setName] = useState(() => {
        try {
            return localStorage.getItem('fm.chat_name') ?? '';
        } catch {
            return '';
        }
    });
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState('');
    const listRef = useRef<HTMLDivElement>(null);

    // Auto-scroll on new messages
    useEffect(() => {
        const el = listRef.current;

        if (el) {
            el.scrollTop = el.scrollHeight;
        }
    }, [chatMessages]);

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!text.trim() || !name.trim()) {
            return;
        }

        setSending(true);
        setError('');

        try {
            localStorage.setItem('fm.chat_name', name);
        } catch {
            /* ignore */
        }

        try {
            const res = await fetch('/api/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    name: name.trim(),
                    message: text.trim(),
                }),
            });

            if (res.status === 429) {
                setError('Slow down — too many messages.');
            } else if (!res.ok) {
                setError('Failed to send.');
            } else {
                setText('');
            }
        } catch {
            setError('Network error.');
        } finally {
            setSending(false);
        }
    };

    return (
        <div id="chat" className="border border-border bg-card">
            <div className="border-b border-border px-4 py-2.5">
                <p className="font-display text-xs font-bold tracking-[0.2em] text-muted-foreground uppercase">
                    Listener Chat
                </p>
            </div>

            <div
                ref={listRef}
                className="max-h-56 scrollbar-none space-y-2 overflow-y-auto px-4 py-3"
                style={{ scrollbarWidth: 'none' }}
            >
                {chatMessages.length === 0 ? (
                    <p className="py-4 text-center text-xs text-muted-foreground/50">
                        No messages yet — say hi!
                    </p>
                ) : (
                    chatMessages.map((msg) => (
                        <div key={msg.id} className="flex gap-2 text-sm">
                            <span className="shrink-0 font-bold text-red-500">
                                {msg.name}
                            </span>
                            <span className="min-w-0 break-words text-foreground/80">
                                {msg.message}
                            </span>
                        </div>
                    ))
                )}
            </div>

            <form
                onSubmit={submit}
                className="space-y-2 border-t border-border p-3"
            >
                {error && <p className="text-xs text-red-400">{error}</p>}
                <div className="flex gap-2">
                    <input
                        type="text"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="Name"
                        maxLength={30}
                        className="w-24 shrink-0 border border-border bg-background px-2 py-1.5 text-sm text-foreground placeholder:text-muted-foreground/50 focus:ring-1 focus:ring-red-500 focus:outline-none"
                    />
                    <input
                        type="text"
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        placeholder="Say something..."
                        maxLength={200}
                        className="flex-1 border border-border bg-background px-2 py-1.5 text-sm text-foreground placeholder:text-muted-foreground/50 focus:ring-1 focus:ring-red-500 focus:outline-none"
                    />
                    <button
                        type="submit"
                        disabled={sending || !text.trim() || !name.trim()}
                        className="shrink-0 border border-red-500/40 bg-red-500/10 px-3 py-1.5 text-xs font-bold tracking-wider text-red-500 uppercase transition-colors hover:bg-red-500 hover:text-foreground disabled:opacity-40"
                    >
                        Send
                    </button>
                </div>
            </form>
        </div>
    );
}
