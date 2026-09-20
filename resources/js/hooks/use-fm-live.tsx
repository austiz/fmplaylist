import { usePage } from '@inertiajs/react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { PropsWithChildren } from 'react';
import { getCommutePalette } from '@/lib/commute';
import type { CommutePalette } from '@/lib/commute';
import type { NowPlayingData, PiStatus, Station } from '@/types/fm';

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
    /** Approximate concurrent-listener count from the shared SSE stream, `null` until the first frame. */
    listenerCount: number | null;
    /** Current commute-phase palette/copy set, refreshed on a shared timer so every
     *  consumer (visualizer, hero copy, Driving Mode) rolls over together. */
    palette: CommutePalette;
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
    const { props } = usePage<{ publicStation: Station | null }>();
    const stationSlug = props.publicStation?.slug ?? null;
    const [nowPlaying, setNowPlaying] = useState<
        NowPlayingData | null | undefined
    >(undefined);
    const [piStatus, setPiStatus] = useState<PiStatus | null>(null);
    const [queueVersion, setQueueVersion] = useState<string | null>(null);
    const [chatMessages, setChatMessages] = useState<ChatMsg[]>([]);
    const [onAirTitle, setOnAirTitle] = useState<string | null>(null);
    const [connected, setConnected] = useState(false);
    const [listenerCount, setListenerCount] = useState<number | null>(null);
    const [palette, setPalette] = useState<CommutePalette>(() =>
        getCommutePalette(),
    );
    const onAirTimer = useRef<ReturnType<typeof setTimeout>>(undefined);

    // Roll the commute palette/copy over as the phase changes — shared so every consumer
    // (visualizer, hero, Driving Mode) updates together instead of drifting independently.
    useEffect(() => {
        const id = setInterval(() => setPalette(getCommutePalette()), 60_000);

        return () => clearInterval(id);
    }, []);

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

        return () => {
            cancelled = true;
        };
    }, []);

    useEffect(() => {
        const eventsUrl = stationSlug
            ? `/api/events?station=${encodeURIComponent(stationSlug)}`
            : '/api/events';
        const es = new EventSource(eventsUrl);

        es.onopen = () => setConnected(true);
        es.onerror = () => setConnected(false);

        es.addEventListener('now-playing', (e) => {
            const data: NowPlayingData = JSON.parse(e.data);
            setNowPlaying(data);

            // Did the user's own request just hit the air?
            try {
                const stored = localStorage.getItem(MY_REQUEST_KEY);

                if (stored && data?.song?.id) {
                    const { songId, title } = JSON.parse(stored) as {
                        songId: number;
                        title: string;
                    };

                    if (data.song.id === songId) {
                        localStorage.removeItem(MY_REQUEST_KEY);
                        setOnAirTitle(title);
                        clearTimeout(onAirTimer.current);
                        onAirTimer.current = setTimeout(
                            () => setOnAirTitle(null),
                            9000,
                        );
                    }
                }
            } catch {
                /* private mode may block localStorage */
            }
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

        es.addEventListener('listener-count', (e) => {
            try {
                setListenerCount((JSON.parse(e.data) as { n: number }).n);
            } catch {
                /* ignore malformed frame */
            }
        });

        return () => {
            es.close();
            clearTimeout(onAirTimer.current);
        };
    }, [stationSlug]);

    const dismissOnAir = useCallback(() => {
        clearTimeout(onAirTimer.current);
        setOnAirTitle(null);
    }, []);

    // This provider wraps every listener page, so a fresh object here re-renders
    // all of them on any render of the provider — including ones where none of
    // the live values actually moved.
    const value: FmLiveValue = useMemo(
        () => ({
            nowPlaying,
            piStatus,
            queueVersion,
            chatMessages,
            onAirTitle,
            dismissOnAir,
            connected,
            listenerCount,
            palette,
        }),
        [
            nowPlaying,
            piStatus,
            queueVersion,
            chatMessages,
            onAirTitle,
            dismissOnAir,
            connected,
            listenerCount,
            palette,
        ],
    );

    return (
        <FmLiveContext.Provider value={value}>
            {children}
        </FmLiveContext.Provider>
    );
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

    for (const m of a) {
        byId.set(m.id, m);
    }

    for (const m of b) {
        byId.set(m.id, m);
    }

    return [...byId.values()].sort((x, y) => x.id - y.id);
}
