<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    CircleSlash,
    Power,
    PowerOff,
    RefreshCw,
    RotateCw,
    Search,
    ShieldAlert,
    Square,
    Wrench,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { pushToast } from '@/lib/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    systemd: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const page = usePage();
const canOperate = computed(() => page.props.permissions?.operate ?? false);

const search = ref(props.filters.search ?? '');
const pending = ref(null);
const working = ref(false);
const busyUnit = ref(null);
let debounce = null;

const tabs = computed(() => [
    { value: 'all', label: 'All units', count: props.systemd.counts?.all ?? 0 },
    { value: 'running', label: 'Active', count: props.systemd.counts?.running ?? 0 },
    { value: 'failed', label: 'Failed', count: props.systemd.counts?.failed ?? 0 },
    { value: 'inactive', label: 'Inactive', count: props.systemd.counts?.inactive ?? 0 },
]);

const currentFilter = computed(() => props.filters.filter ?? 'all');
const units = computed(() => props.systemd.units ?? []);

function apply(overrides = {}) {
    const query = {
        filter: (overrides.filter ?? currentFilter.value) !== 'all' ? (overrides.filter ?? currentFilter.value) : undefined,
        search: (overrides.search ?? search.value).trim() || undefined,
    };

    router.get('/services', Object.fromEntries(Object.entries(query).filter(([, value]) => value)), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['systemd', 'filters'],
    });
}

function setFilter(value) {
    apply({ filter: value });
}

watch(search, () => {
    window.clearTimeout(debounce);
    debounce = window.setTimeout(() => apply(), 300);
});

onBeforeUnmount(() => window.clearTimeout(debounce));

function actionLabel(action) {
    return {
        start: 'Start',
        stop: 'Stop',
        restart: 'Restart',
        reload: 'Reload',
        enable: 'Enable at boot',
        disable: 'Disable at boot',
    }[action] ?? action;
}

function actionsFor(unit) {
    if (!canOperate.value) return [];

    if (unit.active === 'active') return ['restart', 'reload', 'stop'];
    if (unit.active === 'failed') return ['restart', 'start'];

    return ['start', 'enable'];
}

const actionIcons = {
    start: Power,
    stop: Square,
    restart: RotateCw,
    reload: RefreshCw,
    enable: CheckCircle2,
    disable: PowerOff,
};

const actionTone = {
    start: 'hover:border-accent/40 hover:text-accent',
    stop: 'hover:border-rose/40 hover:text-rose',
    restart: 'hover:border-cyan/40 hover:text-cyan',
    reload: 'hover:border-cyan/40 hover:text-cyan',
    enable: 'hover:border-accent/40 hover:text-accent',
    disable: 'hover:border-amber/40 hover:text-amber',
};

function requestAction(action, unit) {
    if (['stop', 'disable'].includes(action)) {
        pending.value = { action, unit };
        return;
    }

    runAction(action, unit);
}

function runAction(action, unit) {
    working.value = true;
    busyUnit.value = unit.unit;

    router.post('/services/action', { action, unit: unit.unit }, {
        preserveScroll: true,
        onSuccess: () => {
            pushToast(`systemctl ${action} completed for ${unit.unit}.`, 'success');
            pending.value = null;
        },
        onError: (errors) => pushToast(Object.values(errors)[0] ?? 'The service action failed.', 'error'),
        onFinish: () => {
            working.value = false;
            busyUnit.value = null;
        },
    });
}

function confirmPending() {
    if (!pending.value) return;

    runAction(pending.value.action, pending.value.unit);
}

const activeTone = (unit) => {
    if (unit.active === 'active') return 'border-accent/25 bg-accent/10 text-accent';
    if (unit.active === 'failed') return 'border-rose/25 bg-rose/10 text-rose';
    if (unit.active === 'activating' || unit.active === 'deactivating') return 'border-amber/25 bg-amber/10 text-amber';

    return 'border-line bg-panel-2 text-dim';
};

const activeIcon = (unit) => {
    if (unit.active === 'active') return CheckCircle2;
    if (unit.active === 'failed') return AlertTriangle;

    return CircleSlash;
};
</script>

<template>
    <Head title="Services" />

    <div class="space-y-5">
        <PageHeader
            title="Services"
            subtitle="systemd units on this host, with direct lifecycle and boot-persistence controls."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot :status="systemd.counts?.failed ? 'degraded' : 'online'" :pulse="Boolean(systemd.counts?.failed)" />
                    systemd
                    <span class="text-dim">·</span>
                    {{ systemd.counts?.running ?? 0 }} active
                    <span class="text-dim">·</span>
                    {{ systemd.counts?.failed ?? 0 }} failed
                    <span class="text-dim">·</span>
                    {{ systemd.counts?.all ?? 0 }} total units
                </span>
            </template>
            <template #actions>
                <button type="button" class="btn btn-sm" @click="router.reload({ preserveScroll: true, preserveState: true })">
                    <RefreshCw class="h-3.5 w-3.5" /> Refresh
                </button>
            </template>
        </PageHeader>

        <div v-if="!systemd.available" class="flex items-start gap-2.5 rounded-xl border border-rose/25 bg-rose/10 px-4 py-3 text-[11px] leading-5 text-rose">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ systemd.error }}</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Active units" :value="systemd.counts?.running ?? 0" :subtitle="`of ${systemd.counts?.all ?? 0} service units`" :icon="CheckCircle2" tone="accent" detail="Running in systemd right now" />
            <StatCard label="Failed units" :value="systemd.counts?.failed ?? 0" subtitle="Need investigation" :icon="ShieldAlert" :tone="(systemd.counts?.failed ?? 0) > 0 ? 'rose' : 'accent'" :detail="(systemd.counts?.failed ?? 0) > 0 ? 'Open failed units below' : 'No failed units'" />
            <StatCard label="Inactive units" :value="systemd.counts?.inactive ?? 0" subtitle="Installed but not running" :icon="CircleSlash" tone="muted" detail="Start on demand or enable at boot" />
            <StatCard label="View" :value="systemd.matched ?? units.length" subtitle="Units after filters" :icon="Wrench" tone="cyan" :detail="filters.search ? `Search: ${filters.search}` : 'All unit files'" />
        </div>

        <section class="panel overflow-hidden">
            <div class="panel-header flex-wrap gap-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[11px] transition"
                        :class="currentFilter === tab.value
                            ? 'border-accent/35 bg-accent/10 font-medium text-accent'
                            : 'border-line bg-panel-2/50 text-muted hover:border-line-bright hover:text-bright'"
                        @click="setFilter(tab.value)"
                    >
                        {{ tab.label }}
                        <span class="font-mono text-[11px] text-dim">{{ tab.count }}</span>
                    </button>
                </div>

                <div class="relative">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-dim" />
                    <input v-model="search" type="search" class="input h-8 w-64 py-1 pl-8 text-xs" placeholder="Search unit or description…" aria-label="Search services" />
                </div>
            </div>

            <div v-if="units.length" class="overflow-x-auto">
                <table class="w-full min-w-[900px]">
                    <thead>
                        <tr class="border-b border-line/70">
                            <th class="table-head">Unit</th>
                            <th class="table-head">State</th>
                            <th class="table-head">Sub-state</th>
                            <th class="table-head">Load</th>
                            <th class="table-head text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <tr v-for="unit in units" :key="unit.unit" class="hover-row" :class="{ 'border-l-2 border-l-rose': unit.active === 'failed' }">
                            <td class="table-cell">
                                <div class="flex items-center gap-2">
                                    <component :is="activeIcon(unit)" class="h-3.5 w-3.5 shrink-0" :class="unit.active === 'active' ? 'text-accent' : unit.active === 'failed' ? 'text-rose' : 'text-dim'" />
                                    <div class="min-w-0">
                                        <div class="font-mono text-[11px] font-medium text-bright">{{ unit.unit }}</div>
                                        <div class="mt-0.5 max-w-[440px] truncate text-[11px] text-dim" :title="unit.description">{{ unit.description || 'No description' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="table-cell">
                                <span class="badge" :class="activeTone(unit)">{{ unit.active }}</span>
                            </td>
                            <td class="table-cell font-mono text-[11px] text-muted">{{ unit.sub }}</td>
                            <td class="table-cell"><span class="font-mono text-[11px] text-dim">{{ unit.load }}</span></td>
                            <td class="table-cell">
                                <div class="flex items-center justify-end gap-1">
                                    <span v-if="busyUnit === unit.unit" class="mr-1 text-[11px] text-dim">working…</span>
                                    <button
                                        v-for="action in actionsFor(unit)"
                                        :key="action"
                                        type="button"
                                        class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition"
                                        :class="actionTone[action]"
                                        :title="`${actionLabel(action)} ${unit.unit}`"
                                        @click="requestAction(action, unit)"
                                    >
                                        <component :is="actionIcons[action]" class="h-3 w-3" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <EmptyState v-else :icon="Wrench" title="No units matched" :description="search ? `Nothing matches “${search}”.` : 'This filter has no units.'" />

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line/70 px-5 py-3 text-[11px] text-dim">
                <span>{{ units.length }} units shown · {{ systemd.counts?.all ?? 0 }} total</span>
                <span>Actions run through systemctl as root and are audited</span>
            </div>
        </section>

        <div class="flex items-start gap-2.5 rounded-xl border border-line/60 bg-panel/40 px-4 py-3 text-[11px] leading-5 text-dim">
            <Wrench class="mt-0.5 h-3.5 w-3.5 shrink-0 text-cyan" />
            <span>
                This list is read with <span class="font-mono text-muted">systemctl list-units --type=service</span>, including inactive
                units. Starting, stopping, reloading and enabling services requires operator access; viewers can inspect state only.
            </span>
        </div>

        <ConfirmDialog
            :show="Boolean(pending)"
            :title="pending ? `${actionLabel(pending.action)} service?` : ''"
            :description="pending ? (pending.action === 'stop' ? `${pending.unit.unit} will stop immediately and dependent services may fail.` : `${pending.unit.unit} will no longer start automatically at boot.`) : ''"
            :confirm-label="pending ? actionLabel(pending.action) : 'Confirm'"
            :danger="pending?.action === 'stop'"
            :loading="working"
            @close="pending = null"
            @confirm="confirmPending"
        />
    </div>
</template>
