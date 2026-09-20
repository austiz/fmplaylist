import { Link, usePage } from '@inertiajs/react';
import { ChevronsUpDown, LogOut } from 'lucide-react';

import { isNavItemActive, navGroups } from '@/components/admin/nav-items';
import { PiStatusBlock } from '@/components/admin/pi-status-block';
import { StationMenuContent } from '@/components/station-menu-content';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
} from '@/components/ui/sidebar';
import type { Station } from '@/types/fm';

export function AdminSidebar() {
    const { url, props } = usePage<{
        activeStation: Station | null;
        stations: Station[] | null;
    }>();
    const { activeStation, stations } = props;

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader className="gap-2">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            size="lg"
                            tooltip="Back to the listener site"
                        >
                            <Link href="/">
                                <span className="flex aspect-square size-8 shrink-0 items-center justify-center rounded-lg bg-primary font-display text-xs font-bold text-primary-foreground">
                                    FM
                                </span>
                                <span className="font-display text-sm font-bold tracking-tight">
                                    PLAYLIST
                                </span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>

                {activeStation && stations && stations.length > 0 && (
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <SidebarMenuButton
                                        tooltip={activeStation.name}
                                        className="bg-surface-2 data-[state=open]:bg-surface-3"
                                    >
                                        {/* Stands in for the station name once the
                                            rail collapses to icons. */}
                                        <span className="flex size-4 shrink-0 items-center justify-center font-display text-[10px] font-bold text-muted-foreground">
                                            {activeStation.name
                                                .slice(0, 2)
                                                .toUpperCase()}
                                        </span>
                                        <span className="truncate text-xs font-medium">
                                            {activeStation.name}
                                        </span>
                                        <ChevronsUpDown className="ml-auto size-3.5 opacity-50" />
                                    </SidebarMenuButton>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="start"
                                    side="right"
                                    className="w-56"
                                >
                                    <StationMenuContent
                                        stations={stations}
                                        activeStation={activeStation}
                                    />
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </SidebarMenuItem>
                    </SidebarMenu>
                )}
            </SidebarHeader>

            <SidebarContent>
                {navGroups.map((group) => (
                    <SidebarGroup key={group.label}>
                        <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                        <SidebarGroupContent>
                            <SidebarMenu>
                                {group.items.map((item) => (
                                    <SidebarMenuItem key={item.href}>
                                        <SidebarMenuButton
                                            asChild
                                            isActive={isNavItemActive(
                                                item,
                                                url,
                                            )}
                                            tooltip={item.label}
                                        >
                                            <Link href={item.href} prefetch>
                                                <item.icon />
                                                <span>{item.label}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                ))}
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                ))}
            </SidebarContent>

            <SidebarFooter>
                <PiStatusBlock />
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton asChild tooltip="Log out">
                            <Link href="/logout" method="post" as="button">
                                <LogOut />
                                <span>Log out</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarFooter>

            <SidebarRail />
        </Sidebar>
    );
}
