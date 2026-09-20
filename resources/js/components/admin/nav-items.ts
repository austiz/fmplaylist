import {
    Cpu,
    History,
    LayoutDashboard,
    Music,
    Radio,
    RadioTower,
    Settings,
    UserCog,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export type NavItem = {
    href: string;
    label: string;
    icon: LucideIcon;
    /**
     * Extra prefixes that should also light this item up. Account points at
     * `/settings/profile`, but `/settings/security` is the same destination as
     * far as the rail is concerned.
     */
    match?: string[];
};

export type NavGroup = {
    label: string;
    items: NavItem[];
};

/**
 * The admin nav, grouped by what an operator is doing rather than as one flat
 * list of seven. The groups are also what makes room for new surfaces: a queue
 * page belongs under Live, and a flat strip had nowhere to put it.
 *
 * `CommandPalette` builds its "Go to…" entries from `flatNav` below, so the
 * palette cannot drift out of sync with the rail.
 */
export const navGroups: NavGroup[] = [
    {
        label: 'Live',
        items: [
            { href: '/admin', label: 'Dashboard', icon: LayoutDashboard },
            { href: '/admin/broadcast', label: 'Broadcast', icon: RadioTower },
        ],
    },
    {
        label: 'Library',
        items: [{ href: '/admin/sounds', label: 'Sounds', icon: Music }],
    },
    {
        label: 'Devices',
        items: [
            { href: '/admin/tokens', label: 'Pi Devices', icon: Cpu },
            { href: '/admin/stations', label: 'Stations', icon: Radio },
        ],
    },
    {
        label: 'System',
        items: [
            { href: '/admin/settings', label: 'Settings', icon: Settings },
            {
                href: '/settings/profile',
                label: 'Account',
                icon: UserCog,
                match: ['/settings'],
            },
            { href: '/admin/history', label: 'History', icon: History },
        ],
    },
];

export const flatNav = navGroups.flatMap((group) => group.items);

/**
 * `/admin` is a prefix of every other admin route, so it only matches exactly;
 * everything else matches its subtree so that, say, `/admin/sounds?tab=songs`
 * still lights up Sounds.
 */
export function isNavItemActive(item: NavItem, url: string): boolean {
    if (item.href === '/admin') {
        return url === '/admin' || url.startsWith('/admin?');
    }

    return [item.href, ...(item.match ?? [])].some(
        (prefix) =>
            url === prefix ||
            url.startsWith(`${prefix}/`) ||
            url.startsWith(`${prefix}?`),
    );
}
