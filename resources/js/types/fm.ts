/** How the server spells each kind of audio in the one media library. */
export type MediaKind = 'song' | 'commercial' | 'sound_byte';

/**
 * A track as the listener-facing pages receive it: enough to name it and request it.
 */
export interface Song {
    id: number;
    title: string;
    artist: string;
    duration_formatted?: string;
}

/**
 * A row on the admin Sounds page, exactly as `MediaAssetResource` serializes it.
 *
 * Songs, commercials and sound bytes share one table, one controller and one
 * resource, so they share one interface here too -- this used to be three
 * near-identical declarations plus a fourth copy inside `sounds.tsx`. The fields
 * below the shared block belong to a single kind, which is why they are optional.
 */
export interface MediaAsset {
    id: number;
    title: string;
    filename: string;
    duration_formatted?: string;
    file_size: number | null;
    active: boolean;
    /** Whether a web-side copy exists to serve to devices. */
    has_file: boolean;
    /** Devices that have actually downloaded this — the authoritative "on Pi" signal. */
    devices_have: number;
    pi_delete_requested: boolean;
    created_at: string;

    /** Songs only. */
    artist?: string;

    /** Commercials only. */
    rotation_order?: number;
    play_count?: number;

    /** Sound bytes only. */
    category?: 'jingle' | 'shoutout' | 'drop' | 'id';
    rds_ps?: string | null;
}

export interface QueueItem {
    id: number;
    position: number;
    requested_by_name: string | null;
    status?: string;
    created_at?: string;
    played_at?: string | null;
    song: Pick<Song, 'title' | 'artist'>;
}

export interface NowPlayingData {
    type: 'song' | 'commercial' | 'sound_byte' | 'station_id';
    song: {
        id?: number | null;
        title: string;
        artist: string | null;
        duration_seconds?: number | null;
    } | null;
    queue_item_id?: number | null;
    started_at: string | null;
}

export interface PiStatus {
    online: boolean;
    status: 'offline' | 'idle' | 'playing' | 'live';
    mode: 'normal' | 'phone_stream' | 'usb_input' | 'custom_stream';
    ip: string | null;
    update_available: boolean;
    last_seen?: string | null;
    device_count?: number;
}

export interface Station {
    id: number;
    name: string;
    slug: string;
    is_default?: boolean;
}

export interface PiDevice {
    id: number;
    label: string;
    station: Pick<Station, 'id' | 'name'> | null;
    online: boolean;
    last_seen_at: string | null;
    disk_free_bytes: number | null;
    disk_total_bytes: number | null;
    downloads_done: number;
    downloads_total: number;
    created_at: string;
    status: string | null;
    mode: string | null;
    ip: string | null;
    /** Null until a Pi running a build that reports it has checked in. */
    fm_running: boolean | null;
    queue_depth: number | null;
    last_error: string | null;
    daemon_hash: string | null;
    up_to_date: boolean;
    last_update_status: string | null;
    last_update_at: string | null;
    commands: PiCommand[];
}

export interface PiCommand {
    id: number;
    command: string;
    status: 'queued' | 'sent' | 'acked' | 'failed';
    result: string | null;
    at: string | null;
}

export interface PaginatedResponse<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}
