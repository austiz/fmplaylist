export interface Song {
    id: number;
    title: string;
    artist: string;
    duration_formatted?: string;
    filename?: string;
    file_size?: number;
    available?: boolean;
    created_at?: string;
}

export interface Commercial {
    id: number;
    title: string;
    filename?: string;
    duration_formatted?: string;
    file_size?: number | null;
    active?: boolean;
    rotation_order?: number;
    play_count?: number;
    /** Whether a web-side copy exists to serve to devices. */
    has_file?: boolean;
    /** Pi devices that have actually downloaded this — authoritative "on Pi" signal. */
    devices_have?: number;
    pi_delete_requested?: boolean;
    created_at?: string;
}

export interface SoundByte {
    id: number;
    title: string;
    category: 'jingle' | 'shoutout' | 'drop' | 'id';
    rds_ps?: string | null;
    filename?: string;
    duration_formatted?: string;
    file_size?: number | null;
    active?: boolean;
    /** Whether a web-side copy exists to serve to devices. */
    has_file?: boolean;
    /** Pi devices that have actually downloaded this — authoritative "on Pi" signal. */
    devices_have?: number;
    pi_delete_requested?: boolean;
    created_at?: string;
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
    type: 'song' | 'commercial' | 'sound_byte';
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
