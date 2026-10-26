<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Archive,
    Bell,
    Boxes,
    ChevronDown,
    ClipboardList,
    Container,
    Cpu,
    Disc3,
    HardDrive,
    HeartPulse,
    LayoutDashboard,
    LogOut,
    Menu,
    Network,
    PackageCheck,
    Rocket,
    ScrollText,
    Search,
    Settings,
    ShieldCheck,
    TerminalSquare,
    Users,
    Wrench,
    X,
} from '@lucide/vue';
import AppLogo from '@/Components/AppLogo.vue';
import CommandPalette from '@/Components/CommandPalette.vue';
import StatusDot from '@/Components/StatusDot.vue';
import ToastHost from '@/Components/ToastHost.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { useClickOutside } from '@/lib/polling';
import { formatClock, formatUptime, timeAgo } from '@/lib/format';
import { isMacPlatform } from '@/lib/platform';

const page = usePage();

const user = computed(() => page.props.auth);
const host = computed(() => page.props.host ?? {});
const permissions = computed(() => page.props.permissions ?? {});
const navigation = computed(() => page.props.navigation ?? { health: { active: [], counts: {} }, counts: {} });
const counts = computed(() => navigation.value.counts ?? {});

const sidebarOpen = ref(false);
const showSearch = ref(false);
const showNotifications = ref(false);
const showUserMenu = ref(false);
const notificationsRoot = ref(null);
const userMenuRoot = ref(null);

const now = ref(new Date());
const searchModifier = computed(() => (isMacPlatform() ? '⌘' : 'Ctrl'));
let clock = null;

onMounted(() => {
    clock = window.setInterval(() => (now.value = new Date()), 1000);
    window.addEventListener('keydown', handleShortcut);
});

onBeforeUnmount(() => {
    window.clearInterval(clock);
    window.removeEventListener('keydown', handleShortcut);
});

useClickOutside(notificationsRoot, () => (showNotifications.value = false));
useClickOutside(userMenuRoot, () => (showUserMenu.value = false));

function handleShortcut(event) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        showSearch.value = true;
    }

    if (event.key === 'Escape') {
        showSearch.value = false;
        showNotifications.value = false;
        showUserMenu.value = false;
        sidebarOpen.value = false;
    }
}

watch(
    () => page.url,
    () => {
        sidebarOpen.value = false;
        showNotifications.value = false;
        showUserMenu.value = false;
    },
);

const sections = computed(() => [
    {
        label: 'This host',
        items: [
            { label: 'Overview', href: '/', icon: LayoutDashboard, exact: true },
            { label: 'System & processes', href: '/system', icon: Cpu },
            { label: 'Storage & ZFS', href: '/storage', icon: HardDrive },
            {
                label: 'Disk health',
                href: '/disks',
                icon: Disc3,
                ...(counts.value.smart_attention
                    ? { badge: counts.value.smart_attention, badgeTone: (counts.value.smart_critical ?? 0) > 0 ? 'rose' : 'amber' }
                    : {}),
            },
            { label: 'Backups', href: '/backups', icon: Archive },
            {
                label: 'Updates',
                href: '/updates',
                icon: PackageCheck,
                ...(counts.value.reboot_required
                    ? { badge: counts.value.updates || 'reboot', badgeTone: 'amber' }
                    : (counts.value.updates
                        ? { badge: counts.value.updates, badgeTone: counts.value.security_updates ? 'amber' : 'cyan' }
                        : {})),
            },
            { label: 'Network', href: '/network', icon: Network },
            { label: 'Services', href: '/services', icon: Wrench, badge: counts.value.failed_services ?? 0, badgeTone: 'rose' },
            { label: 'Health checks', href: '/health', icon: HeartPulse, badge: counts.value.health ?? 0, badgeTone: (counts.value.critical_health ?? 0) > 0 ? 'rose' : 'amber' },
            {
                label: 'Alerts',
                href: '/alerts',
                icon: Bell,
                ...(counts.value.alerts
                    ? { badge: counts.value.alerts, badgeTone: (counts.value.critical_health ?? 0) > 0 ? 'rose' : 'amber' }
                    : {}),
            },
        ],
    },
    {
        label: 'Workloads',
        items: [
            { label: 'Docker', href: '/docker', icon: Container, badge: counts.value.docker_running ?? 0, badgeTotal: counts.value.docker_total ?? 0, badgeTone: 'cyan' },
            { label: 'LXC/VM', href: '/incus', icon: Boxes, badge: counts.value.incus_running ?? 0, badgeTotal: counts.value.incus_total ?? 0, badgeTone: 'cyan' },
            { label: 'Dokploy', href: '/dokploy', icon: Rocket },
        ],
    },
    {
        label: 'Observe',
        items: [
            { label: 'Journal', href: '/logs', icon: ScrollText },
            ...(permissions.value.useTerminal ? [{ label: 'Terminal', href: '/terminal', icon: TerminalSquare }] : []),
        ],
    },
    ...(permissions.value.manageUsers || permissions.value.viewAudit
        ? [
              {
                  label: 'Administration',
                  items: [
                      ...(permissions.value.manageUsers ? [{ label: 'Access', href: '/users', icon: Users }] : []),
                      ...(permissions.value.viewAudit ? [{ label: 'Audit log', href: '/audit', icon: ClipboardList }] : []),
                  ],
              },
          ]
        : []),
    {
        label: 'Account',
        items: [{ label: 'Settings', href: '/settings', icon: Settings }],
    },
]);

const path = computed(() => page.url.split('?')[0]);

function isActive(item) {
    if (item.exact) return path.value === item.href;

    return path.value === item.href || path.value.startsWith(`${item.href}/`);
}

const sectionTitles = {
    Dashboard: 'Overview',
    System: 'System & processes',
    Storage: 'Storage & ZFS',
    Disks: 'Disk health',
    Backups: 'Backups & snapshots',
    Updates: 'Updates & reboot',
    Network: 'Network',
    Docker: 'Docker',
    Incus: 'LXC/VM',
    Dokploy: 'Dokploy',
    Services: 'Services',
    Logs: 'Journal',
    Health: 'Health checks',
    Alerts: 'Alerts & notifications',
    Terminal: 'Terminal',
    Users: 'Access',
    Audit: 'Audit log',
    Settings: 'Settings',
};

const section = computed(() => sectionTitles[page.component.split('/')[0]] ?? 'Overview');

const healthState = computed(() => {
    const health = navigation.value.health ?? {};
    const overall = health.overall ?? 'ok';

    if (overall === 'critical') {
        return { overall, label: `${health.critical ?? 0} critical check${health.critical === 1 ? '' : 's'}`, dot: 'offline', text: 'text-rose', tone: 'border-rose/25' };
    }

    if (overall === 'warning') {
        return { overall, label: `${health.attention ?? 0} check${health.attention === 1 ? '' : 's'} need attention`, dot: 'degraded', text: 'text-amber', tone: 'border-amber/25' };
    }

    return { overall, label: 'Host healthy', dot: 'online', text: 'text-accent', tone: 'border-line' };
});

const healthChecks = computed(() => (navigation.value.health?.active ?? []).slice(0, 6));

const badgeTones = {
    muted: 'border-line bg-panel-2 text-dim',
    cyan: 'border-cyan/20 bg-cyan/10 text-cyan',
    amber: 'border-amber/20 bg-amber/10 text-amber',
    rose: 'border-rose/25 bg-rose/10 text-rose',
};

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-screen">
        <div v-if="sidebarOpen" class="fixed inset-0 z-40 bg-black/65 backdrop-blur-sm lg:hidden" @click="sidebarOpen = false" />

        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-line/80 bg-[#080e15]/95 backdrop-blur-xl transition-transform duration-200 ease-out lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-16 items-center justify-between border-b border-line/70 px-5">
                <AppLogo />
                <button
                    type="button"
                    class="grid h-8 w-8 place-items-center rounded-lg text-dim hover:bg-white/5 hover:text-bright lg:hidden"
                    aria-label="Close navigation"
                    @click="sidebarOpen = false"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-5">
                <div v-for="group in sections" :key="group.label" class="mb-5 last:mb-0">
                    <div class="mb-2 px-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-dim">
                        {{ group.label }}
                    </div>
                    <div class="space-y-0.5">
                        <Link
                            v-for="item in group.items"
                            :key="item.href"
                            :href="item.href"
                            class="group relative flex items-center gap-3 rounded-lg px-3 py-2 text-[13px] transition"
                            :class="isActive(item)
                                ? 'bg-white/[0.055] font-medium text-bright'
                                : 'text-muted hover:bg-white/[0.03] hover:text-bright'"
                        >
                            <span
                                v-if="isActive(item)"
                                class="absolute inset-y-2 left-0 w-0.5 rounded-full bg-accent shadow-[0_0_8px_rgba(46,230,168,0.7)]"
                            />
                            <component
                                :is="item.icon"
                                class="h-4 w-4 shrink-0 transition"
                                :class="isActive(item) ? 'text-accent' : 'text-dim group-hover:text-muted'"
                                :stroke-width="1.8"
                            />
                            <span class="flex-1">{{ item.label }}</span>
                            <span
                                v-if="item.badge || item.badgeTotal"
                                class="inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 font-mono text-[11px] leading-none"
                                :class="badgeTones[item.badgeTone]"
                            >
                                {{ item.badge }}<template v-if="item.badgeTotal">/{{ item.badgeTotal }}</template>
                            </span>
                        </Link>
                    </div>
                </div>
            </nav>

            <div class="space-y-3 border-t border-line/70 p-3">
                <Link href="/health" class="block rounded-xl border border-line bg-panel/60 p-3 transition hover:border-line-bright">
                    <div class="flex items-center justify-between text-[11px] font-semibold uppercase tracking-[0.14em] text-dim">
                        <span class="truncate">{{ host.hostname ?? 'This host' }}</span>
                        <span class="flex items-center gap-1.5" :class="healthState.text">
                            <StatusDot :status="healthState.dot" pulse />
                            {{ healthState.overall === 'ok' ? 'healthy' : healthState.overall }}
                        </span>
                    </div>
                    <div class="mt-2.5 truncate text-[11px] font-medium text-bright">{{ host.os ?? 'Linux' }}</div>
                    <div class="mt-1 flex items-center justify-between text-[11px] text-dim">
                        <span>{{ host.kernel ?? '—' }}</span>
                        <span>up {{ formatUptime(host.uptime_seconds) }}</span>
                    </div>
                </Link>

                <div class="flex items-center gap-2.5 rounded-xl border border-line bg-panel/40 p-2.5">
                    <UserAvatar :name="user?.name" size="sm" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-xs font-medium text-bright">{{ user?.name }}</div>
                        <div class="truncate text-[11px] uppercase tracking-wider text-dim">{{ user?.username }} · {{ user?.role_label }}</div>
                    </div>
                    <button
                        type="button"
                        class="grid h-7 w-7 place-items-center rounded-lg text-dim transition hover:bg-white/5 hover:text-rose"
                        title="Sign out"
                        aria-label="Sign out"
                        @click="logout"
                    >
                        <LogOut class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>
        </aside>

        <div class="lg:pl-72">
            <header class="sticky top-0 z-30 border-b border-line/70 bg-base/80 backdrop-blur-xl">
                <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
                    <button
                        type="button"
                        class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-line bg-panel/60 text-muted hover:text-bright lg:hidden"
                        aria-label="Open navigation"
                        @click="sidebarOpen = true"
                    >
                        <Menu class="h-4 w-4" />
                    </button>

                    <div class="hidden items-center gap-2 text-[11px] sm:flex">
                        <span class="text-dim">Host control</span>
                        <span class="text-line-bright">/</span>
                        <span class="font-medium text-muted">{{ section }}</span>
                    </div>

                    <button
                        type="button"
                        class="ml-auto flex h-9 items-center gap-2 rounded-lg border border-line bg-panel/60 px-3 text-xs text-dim transition hover:border-line-bright hover:text-muted sm:ml-4 sm:min-w-[240px]"
                        :aria-label="`Search this host (${searchModifier}+K)`"
                        :title="`Search this host (${searchModifier}+K)`"
                        @click="showSearch = true"
                    >
                        <Search class="h-3.5 w-3.5" />
                        <span class="hidden sm:inline">Search this host…</span>
                        <span class="ml-auto hidden items-center gap-1 sm:flex">
                            <span class="kbd">{{ searchModifier }}</span><span class="kbd">K</span>
                        </span>
                    </button>

                    <Link
                        href="/health"
                        class="hidden items-center gap-2 rounded-full border bg-panel/60 px-3 py-1.5 text-[11px] font-medium transition hover:border-line-bright md:flex"
                        :class="[healthState.tone, healthState.text]"
                    >
                        <StatusDot :status="healthState.dot" :pulse="healthState.dot !== 'online'" />
                        <span class="whitespace-nowrap">{{ healthState.label }}</span>
                    </Link>

                    <div class="hidden items-center gap-1 font-mono text-[11px] text-dim 2xl:flex">
                        <span class="h-1 w-1 rounded-full bg-line-bright" />
                        {{ formatClock(now) }} UTC
                    </div>

                    <div ref="notificationsRoot" class="relative ml-auto sm:ml-0">
                        <button
                            type="button"
                            class="relative grid h-9 w-9 place-items-center rounded-lg border border-line bg-panel/60 text-muted transition hover:border-line-bright hover:text-bright"
                            aria-label="Health warnings"
                            :aria-expanded="showNotifications"
                            @click="showNotifications = !showNotifications"
                        >
                            <Bell class="h-4 w-4" />
                            <span
                                v-if="(counts.health ?? 0) > 0"
                                class="absolute -right-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full border border-base bg-amber px-1 text-[10px] font-bold text-base"
                            >
                                {{ counts.health }}
                            </span>
                        </button>

                        <Transition
                            enter-active-class="transition duration-100 ease-out"
                            enter-from-class="translate-y-1 opacity-0"
                            enter-to-class="translate-y-0 opacity-100"
                            leave-active-class="transition duration-75 ease-in"
                            leave-from-class="opacity-100"
                            leave-to-class="opacity-0"
                        >
                            <div
                                v-if="showNotifications"
                                class="absolute right-0 mt-2 w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-line-bright/80 bg-[#0b141e] shadow-2xl shadow-black/70"
                            >
                                <div class="flex items-center justify-between border-b border-line/80 px-4 py-3">
                                    <div>
                                        <p class="text-xs font-semibold text-bright">Health attention</p>
                                        <p class="mt-0.5 text-[11px] text-dim">{{ counts.health ?? 0 }} unresolved check{{ counts.health === 1 ? '' : 's' }}</p>
                                    </div>
                                    <Link href="/health" class="text-[11px] font-medium text-accent hover:text-accent/80" @click="showNotifications = false">
                                        View all
                                    </Link>
                                </div>

                                <div v-if="healthChecks.length" class="max-h-80 overflow-y-auto">
                                    <Link
                                        v-for="check in healthChecks"
                                        :key="check.key"
                                        :href="check.href"
                                        class="flex gap-3 border-b border-line/50 px-4 py-3 transition last:border-0 hover:bg-white/[0.03]"
                                        @click="showNotifications = false"
                                    >
                                        <StatusDot group="severity" :status="check.status === 'critical' ? 'critical' : 'warning'" class="mt-1.5" />
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium leading-5 text-bright">{{ check.title }}</p>
                                            <p class="mt-0.5 line-clamp-2 text-[11px] text-dim">{{ check.detail }}</p>
                                        </div>
                                    </Link>
                                </div>

                                <div v-else class="px-4 py-8 text-center text-xs text-dim">
                                    No warnings. This host is healthy.
                                </div>
                            </div>
                        </Transition>
                    </div>

                    <div ref="userMenuRoot" class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-lg border border-transparent p-1 transition hover:border-line hover:bg-panel/60"
                            :aria-expanded="showUserMenu"
                            @click="showUserMenu = !showUserMenu"
                        >
                            <UserAvatar :name="user?.name" size="sm" />
                            <div class="hidden text-left xl:block">
                                <div class="max-w-[110px] truncate text-[11px] font-medium text-bright">{{ user?.name }}</div>
                                <div class="text-[11px] uppercase tracking-wider text-dim">{{ user?.role_label }}</div>
                            </div>
                            <ChevronDown class="hidden h-3 w-3 text-dim transition xl:block" :class="{ 'rotate-180': showUserMenu }" />
                        </button>

                        <Transition
                            enter-active-class="transition duration-100 ease-out"
                            enter-from-class="translate-y-1 opacity-0"
                            enter-to-class="translate-y-0 opacity-100"
                            leave-active-class="transition duration-75 ease-in"
                            leave-from-class="opacity-100"
                            leave-to-class="opacity-0"
                        >
                            <div
                                v-if="showUserMenu"
                                class="absolute right-0 mt-2 w-64 overflow-hidden rounded-xl border border-line-bright/80 bg-[#0b141e] shadow-2xl shadow-black/70"
                            >
                                <div class="flex items-center gap-3 border-b border-line/80 px-4 py-3">
                                    <UserAvatar :name="user?.name" size="md" />
                                    <div class="min-w-0">
                                        <p class="truncate text-xs font-semibold text-bright">{{ user?.name }}</p>
                                        <p class="truncate font-mono text-[11px] text-dim">{{ user?.username }}@{{ host.hostname }}</p>
                                    </div>
                                </div>
                                <div class="p-1.5">
                                    <Link
                                        href="/settings"
                                        class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-xs text-muted transition hover:bg-white/5 hover:text-bright"
                                        @click="showUserMenu = false"
                                    >
                                        <Settings class="h-3.5 w-3.5" /> Account settings
                                    </Link>
                                    <Link
                                        v-if="permissions.manageUsers"
                                        href="/users"
                                        class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-xs text-muted transition hover:bg-white/5 hover:text-bright"
                                        @click="showUserMenu = false"
                                    >
                                        <ShieldCheck class="h-3.5 w-3.5" /> Manage access
                                    </Link>
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-xs text-muted transition hover:bg-rose/10 hover:text-rose"
                                        @click="logout"
                                    >
                                        <LogOut class="h-3.5 w-3.5" /> Sign out
                                    </button>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </div>
            </header>

            <main class="mx-auto w-full max-w-[1560px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <slot />
            </main>

            <footer class="mx-auto flex w-full max-w-[1560px] flex-wrap items-center justify-between gap-2 px-4 pb-8 text-[11px] text-dim sm:px-6 lg:px-8">
                <span class="flex items-center gap-2">
                    <span class="h-1 w-1 rounded-full bg-accent/70" />
                    ServerDeck · {{ host.hostname }} · real host access
                </span>
                <span>Docker {{ counts.docker_running ?? 0 }}/{{ counts.docker_total ?? 0 }} · LXC/VM {{ counts.incus_running ?? 0 }}/{{ counts.incus_total ?? 0 }} · {{ counts.failed_services ?? 0 }} failed services</span>
            </footer>
        </div>

        <CommandPalette :show="showSearch" @close="showSearch = false" />
        <ToastHost />
    </div>
</template>
