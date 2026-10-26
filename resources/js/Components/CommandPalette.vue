<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    Archive,
    ArrowDown,
    ArrowUp,
    Boxes,
    Bell,
    ClipboardList,
    Container,
    CornerDownLeft,
    Cpu,
    Disc3,
    HardDrive,
    HeartPulse,
    LayoutDashboard,
    Network,
    PackageCheck,
    ScrollText,
    Search,
    Settings,
    TerminalSquare,
    Users,
    Wrench,
    X,
} from '@lucide/vue';

const props = defineProps({
    show: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const page = usePage();
const query = ref('');
const selected = ref(0);
const input = ref(null);

const pages = computed(() => {
    const permissions = page.props.permissions ?? {};

    return [
        { label: 'Overview', meta: 'Host health and activity', href: '/', icon: LayoutDashboard },
        { label: 'System & processes', meta: 'CPU, memory, network, GPU, PIDs', href: '/system', icon: Cpu },
        { label: 'Storage & ZFS', meta: 'Pools, datasets, filesystems and disks', href: '/storage', icon: HardDrive },
        { label: 'Disk health', meta: 'SMART attributes, self-tests and ZFS membership', href: '/disks', icon: Disc3 },
        { label: 'Backups', meta: 'Scheduled ZFS and Incus snapshots, retention and restore', href: '/backups', icon: Archive },
        { label: 'Updates', meta: 'APT updates, security patches and reboot status', href: '/updates', icon: PackageCheck },
        { label: 'Network', meta: 'Interfaces, routes, DNS, firewall and diagnostics', href: '/network', icon: Network },
        { label: 'Docker', meta: 'Containers, state and logs', href: '/docker', icon: Container },
        { label: 'LXC/VM', meta: 'Instances and virtualisation', href: '/incus', icon: Boxes },
        { label: 'Services', meta: 'systemd units and controls', href: '/services', icon: Wrench },
        { label: 'Journal', meta: 'Search and tail journald', href: '/logs', icon: ScrollText },
        { label: 'Health checks', meta: 'Live host checks and attention items', href: '/health', icon: HeartPulse },
        { label: 'Alerts', meta: 'Notification channels, routing and delivery history', href: '/alerts', icon: Bell },
        ...(permissions.useTerminal
            ? [{ label: 'Terminal', meta: 'Root shell on this host', href: '/terminal', icon: TerminalSquare }]
            : []),
        ...(permissions.manageUsers
            ? [{ label: 'Access', meta: 'Linux accounts and panel roles', href: '/users', icon: Users }]
            : []),
        ...(permissions.viewAudit
            ? [{ label: 'Audit log', meta: 'Every privileged action', href: '/audit', icon: ClipboardList }]
            : []),
        { label: 'Settings', meta: 'Your profile and host identity', href: '/settings', icon: Settings },
    ];
});

const results = computed(() => {
    const term = query.value.trim().toLowerCase();

    if (!term) {
        return pages.value.slice(0, 10);
    }

    return pages.value
        .filter((item) => `${item.label} ${item.meta}`.toLowerCase().includes(term))
        .slice(0, 10);
});

function reset() {
    query.value = '';
    selected.value = 0;
}

function choose(item) {
    if (!item) return;

    emit('close');
    router.visit(item.href);
}

function onKeydown(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        selected.value = Math.min(results.value.length - 1, selected.value + 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        selected.value = Math.max(0, selected.value - 1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        choose(results.value[selected.value]);
    } else if (event.key === 'Escape') {
        emit('close');
    }
}

watch(
    () => props.show,
    async (show) => {
        if (show) {
            reset();
            await nextTick();
            input.value?.focus();
        }
    },
);

watch(query, () => {
    selected.value = 0;
});
</script>

<template>
    <Teleport to="body">
        <Transition name="palette">
            <div v-if="show" class="fixed inset-0 z-[80] flex items-start justify-center p-4 pt-[10vh] sm:pt-[14vh]">
                <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" @click="$emit('close')" />

                <div class="relative w-full max-w-xl overflow-hidden rounded-2xl border border-line-bright/80 bg-[#0b141e] shadow-2xl shadow-black/80">
                    <div class="flex items-center gap-3 border-b border-line/80 px-4">
                        <Search class="h-4 w-4 shrink-0 text-dim" />
                        <input
                            ref="input"
                            v-model="query"
                            type="text"
                            class="h-14 w-full bg-transparent text-sm text-bright placeholder:text-dim focus:outline-none"
                            placeholder="Search pages and host controls…"
                            autocomplete="off"
                            spellcheck="false"
                            @keydown="onKeydown"
                        />
                        <button
                            type="button"
                            class="grid h-6 w-6 shrink-0 place-items-center rounded text-dim hover:bg-white/5 hover:text-bright"
                            aria-label="Close search"
                            @click="$emit('close')"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>

                    <div class="max-h-[52vh] overflow-y-auto p-2">
                        <div v-if="results.length" class="space-y-0.5">
                            <button
                                v-for="(item, index) in results"
                                :key="item.href"
                                type="button"
                                class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition"
                                :class="index === selected ? 'bg-white/[0.06]' : 'hover:bg-white/[0.035]'"
                                @mouseenter="selected = index"
                                @click="choose(item)"
                            >
                                <span
                                    class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border"
                                    :class="index === selected ? 'border-accent/25 bg-accent/10 text-accent' : 'border-line bg-panel-2 text-dim'"
                                >
                                    <component :is="item.icon" class="h-4 w-4" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-bright">{{ item.label }}</span>
                                    <span class="mt-0.5 block truncate font-mono text-[11px] text-dim">{{ item.meta }}</span>
                                </span>
                                <span class="text-[11px] font-semibold uppercase tracking-wider text-dim">Page</span>
                            </button>
                        </div>

                        <div v-else class="px-3 py-12 text-center">
                            <p class="text-sm font-medium text-muted">No matches for “{{ query }}”</p>
                            <p class="mt-1 text-xs text-dim">Try “docker”, “zfs”, “logs” or “terminal”.</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t border-line/80 bg-panel/50 px-4 py-2.5 text-[11px] text-dim">
                        <div class="flex items-center gap-3">
                            <span class="flex items-center gap-1"><ArrowUp class="h-3 w-3" /><ArrowDown class="h-3 w-3" /> navigate</span>
                            <span class="flex items-center gap-1"><CornerDownLeft class="h-3 w-3" /> open</span>
                        </div>
                        <span class="flex items-center gap-1"><span class="kbd">esc</span> close</span>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.palette-enter-active,
.palette-leave-active {
    transition: opacity 140ms ease;
}

.palette-enter-active .relative,
.palette-leave-active .relative {
    transition: transform 140ms ease, opacity 140ms ease;
}

.palette-enter-from,
.palette-leave-to {
    opacity: 0;
}

.palette-enter-from .relative,
.palette-leave-to .relative {
    transform: translateY(-6px) scale(0.99);
    opacity: 0;
}
</style>
