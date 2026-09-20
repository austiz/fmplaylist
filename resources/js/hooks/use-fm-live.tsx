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
import { getCommuteLabel } from '@/lib/commute';
import { listenerId, subscribeLive } from '@/lib/live';
import type { ChatMsg } from '@/lib/live';
import type { NowPlayingData, PiStatus, Station } from '@/types/fm';

export type { ChatMsg };

interface FmLiveValue {
    /** `undefined` until the first live frame arrives; `null` means nothing is playing. */
    nowPlaying: NowPlayingData | null | undefined;
    piStatus: PiStatus | null;
    /** Bumps whenever the server signals the queue changed — subscribe in an effect to reload. */
    queueVersion: string | null;
    chatMessages: ChatMsg[];
    /** Title of the user's own request when it hits the air — drives the celebration. */
    onAirTitle: string | null;
    dismissOnAir: () => void;
    connected: boolean;
    /** Approximate concurrent-listener count, `null` until the first frame. */
    listenerCount: number | null;
    /** Broadcast label for the current hour ("Morning Drive"), refreshed on a shared
     *  timer so the hero and Driving Mode roll over together rather than on two clocks. */
    commuteLabel: string;
    /** The station every listener request has to be tagged with, `null` on the default. */
    stationSlug: string | null;
}

const FmLiveContext = createContext<FmLiveValue | null>(null);

const MY_REQUEST_KEY = 'fm.my_request';

/**
 * Owns the single `/api/live` poll for the whole listener session. Everything that
 * moves -- now-playing, pi-status, queue version, chat, "your song is on air" --
 * arrives on it, so a page holds one request in flight rather than one per component,
 * and the chat seed comes down on the first frame instead of a second request.
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
    const [commuteLabel, setCommuteLabel] = useState(getCommuteLabel);
    const onAirTimer = useRef<ReturnType<typeof setTimeout>>(undefined);

    // Roll the commute label over as the phase changes — shared so the hero and Driving
    // Mode update together instead of drifting on independent timers.
    useEffect(() => {
        const id = setInterval(
            () => setCommuteLabel(getCommuteLabel()),
            60_000,
        );

        return () => clearInterval(id);
    }, []);

    // Did the listener's own request just hit the air? Their request is the only thing
    // kept client-side, so this is the one place the celebration can be triggered from.
    const announceIfMine = useCallback((data: NowPlayingData | null) => {
        try {
            const stored = localStorage.getItem(MY_REQUEST_KEY);

            if (!stored || !data?.song?.id) {
                return;
            }

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
        } catch {
            /* private mode may block localStorage */
        }
    }, []);

    // The highest chat id held, read by the poll so the server sends only what is new.
    // A ref, not state: the poll must not be torn down and rebuilt on every message.
    const lastChatId = useRef(0);

    useEffect(() => {
        lastChatId.current = 0;

        return subscribeLive({
            stationSlug,
            clientId: listenerId(),
            since: () => lastChatId.current || null,
            onConnectedChange: setConnected,
            onFrame: (frame) => {
                setListenerCount(frame.listeners);

                // Absent, not null: an unchanged cursor sends no payload at all, and
                // `null` here is the real "nothing is playing".
                if (frame.now_playing !== undefined) {
                    setNowPlaying(frame.now_playing);
                    announceIfMine(frame.now_playing);
                }

                if (frame.pi_status !== undefined) {
                    setPiStatus(frame.pi_status ?? null);
                }

                if (frame.queue_version !== undefined) {
                    setQueueVersion(frame.queue_version);
                }

                if (frame.chat?.length) {
                    lastChatId.current = Math.max(
                        lastChatId.current,
                        ...frame.chat.map((m) => m.id),
                    );
                    setChatMessages((prev) =>
                        mergeChat(prev, frame.chat ?? []),
                    );
                }
            },
        });
        // `announceIfMine` is stable for the life of the provider.
        // eslint-disable-next-line react-hooks/exhaustive-deps
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
            commuteLabel,
            stationSlug,
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
            commuteLabel,
            stationSlug,
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
