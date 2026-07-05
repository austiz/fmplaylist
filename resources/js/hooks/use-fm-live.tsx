import { createContext, useContext, useEffect, useRef, useState } from 'react';
import type { PropsWithChildren } from 'react';
import type { NowPlayingData, PiStatus } from '@/types/fm';

export interface ChatMsg {
    id: number;
    name: string;
    message: string;
    created_at: string;
}

interface FmLiveValue {
    /** `undefined` until the first SSE frame arrives; `null` means nothing is playing. */
    nowPlaying: NowPlayingData | null | undefined;
    piStatus: PiStatus | null;
    /** Bumps whenever the server signals the queue changed — subscribe in an effect to reload. */
    queueVersion: string | null;
    chatMessages: ChatMsg[];
    /** Title of the user's own request when it hits the air — drives the celebration. */
    onAirTitle: string | null;
    dismissOnAir: () => void;
    connected: boolean;
}

const FmLiveContext = createContext<FmLiveValue | null>(null);

const MY_REQUEST_KEY = 'fm.my_request';

/**
 * Owns the single `/api/events` EventSource for the whole listener session and the
 * one-shot `GET /api/chat` seed. Everything live (now-playing, pi-status, queue version,
 * chat, "your song is on air") flows through here so we hold exactly one SSE connection
 * instead of one per component.
 */
export function FmLiveProvider({ children }: PropsWithChildren) {
    const [nowPlaying, setNowPlaying] = useState<NowPlayingData | null | undefined>(undefined);
    const [piStatus, setPiStatus] = useState<PiStatus | null>(null);
    const [queueVersion, setQueueVersion] = useState<string | null>(null);
    const [chatMessages, setChatMessages] = useState<ChatMsg[]>([]);
    const [onAirTitle, setOnAirTitle] = useState<string | null>(null);
    const [connected, setConnected] = useState(false);
    const onAirTimer = useRef<ReturnType<typeof setTimeout>>(undefined);

    // Seed chat history once for the session.
    useEffect(() => {
        let cancelled = false;
        fetch('/api/chat')
            .then((r) => r.json())
            .then((data: ChatMsg[]) => {
                if (!cancelled) {
                    setChatMessages((prev) => mergeChat(data, prev));
                }
            })
            .catch(() => {});
        return () => { cancelled = true; };
    }, []);

    useEffect(() => {
        const es = new EventSource('/api/events');

        es.onopen = () => setConnected(true);
        es.onerror = () => setConnected(false);

        es.addEventListener('now-playing', (e) => {
            const data: NowPlayingData = JSON.parse(e.data);
            setNowPlaying(data);

            // Did the user's own request just hit the air?
            try {
                const stored = localStorage.getItem(MY_REQUEST_KEY);
                if (stored && data?.song?.id) {
                    const { songId, title } = JSON.parse(stored) as { songId: number; title: string };
                    if (data.song.id === songId) {
                        localStorage.removeItem(MY_REQUEST_KEY);
                        setOnAirTitle(title);
                        clearTimeout(onAirTimer.current);
                        onAirTimer.current = setTimeout(() => setOnAirTitle(null), 9000);
                    }
                }
            } catch { /* private mode may block localStorage */ }
        });

        es.addEventListener('pi-status', (e) => {
            setPiStatus(JSON.parse(e.data));
        });

        es.addEventListener('queue-changed', (e) => {
            try {
                setQueueVersion((JSON.parse(e.data) as { v: string }).v);
            } catch {
                setQueueVersion(String(Date.now()));
            }
        });

        es.addEventListener('chat-message', (e) => {
            const msg: ChatMsg = JSON.parse(e.data);
            setChatMessages((prev) => mergeChat(prev, [msg]));
        });

        return () => {
            es.close();
            clearTimeout(onAirTimer.current);
        };
    }, []);

    const value: FmLiveValue = {
        nowPlaying,
        piStatus,
        queueVersion,
        chatMessages,
        onAirTitle,
        dismissOnAir: () => {
            clearTimeout(onAirTimer.current);
            setOnAirTitle(null);
        },
        connected,
    };

    return <FmLiveContext.Provider value={value}>{children}</FmLiveContext.Provider>;
}

export function useFmLive(): FmLiveValue {
    const ctx = useContext(FmLiveContext);
    if (!ctx) {
        throw new Error('useFmLive must be used within an <FmLiveProvider>');
    }
    return ctx;
}

/** Merge two chat lists, deduping by id and keeping ascending order. */
function mergeChat(a: ChatMsg[], b: ChatMsg[]): ChatMsg[] {
    const byId = new Map<number, ChatMsg>();
    for (const m of a) byId.set(m.id, m);
    for (const m of b) byId.set(m.id, m);
    return [...byId.values()].sort((x, y) => x.id - y.id);
}
