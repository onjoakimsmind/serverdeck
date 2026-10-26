<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Boxes,
    Container,
    Cpu,
    Database,
    HardDrive,
    Layers,
    Pause,
    Play,
    Plus,
    RefreshCw,
    RotateCw,
    Search,
    Square,
    SquareTerminal,
    TerminalSquare,
    Trash2,
    X,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import ConsoleTerminal from '@/Components/ConsoleTerminal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { api } from '@/lib/http';
import { formatBytes, shortContainer } from '@/lib/host';
import { pushToast } from '@/lib/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    docker: { type: Object, required: true },
});

const page = usePage();
const canOperate = computed(() => page.props.permissions?.operate ?? false);

const filter = ref('all');
const search = ref('');
const pending = ref(null);
const working = ref(false);
const logContainer = ref(null);
const logText = ref('');
const logLoading = ref(false);
const logTail = ref(250);
const consoleTarget = ref(null);
const deviceContainer = ref(null);
const deviceInventory = ref(null);
const deviceInventoryLoading = ref(false);
const deviceInventoryError = ref('');
const deviceDraft = ref({ devices: [], gpus: false });
const devicePick = ref('');
const devicePath = ref('');
const devicePermissions = ref('rwm');
const deviceSaving = ref(false);

const containers = computed(() => props.docker.containers ?? []);
const info = computed(() => props.docker.info ?? {});

const tabs = computed(() => [
    { value: 'all', label: 'All', count: props.docker.counts?.all ?? 0 },
    { value: 'running', label: 'Running', count: props.docker.counts?.running ?? 0 },
    { value: 'stopped', label: 'Stopped', count: props.docker.counts?.stopped ?? 0 },
    { value: 'paused', label: 'Paused', count: props.docker.counts?.paused ?? 0 },
    { value: 'unhealthy', label: 'Unhealthy', count: props.docker.counts?.unhealthy ?? 0 },
]);

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return containers.value.filter((container) => {
        const stateMatches =
            filter.value === 'all'
            || (filter.value === 'running' && container.state === 'running')
            || (filter.value === 'stopped' && ['exited', 'created', 'dead'].includes(container.state))
            || (filter.value === 'paused' && container.state === 'paused')
            || (filter.value === 'unhealthy' && container.health === 'unhealthy');

        if (!stateMatches) return false;
        if (!term) return true;

        return `${container.name} ${container.image} ${container.ports} ${shortContainer(container.name)}`
            .toLowerCase()
            .includes(term);
    });
});

const healthTone = (container) => {
    if (container.health === 'unhealthy') return 'rose';
    if (container.state === 'running') return 'accent';
    if (container.state === 'paused') return 'amber';
    return 'muted';
};

const stateLabel = (container) => (container.health ? `${container.state} · ${container.health}` : container.state);

function actionLabel(action) {
    return {
        start: 'Start',
        stop: 'Stop',
        restart: 'Restart',
        pause: 'Pause',
        unpause: 'Resume',
        remove: 'Remove',
    }[action] ?? action;
}

function actionsFor(container) {
    if (!canOperate.value) return [];

    if (container.state === 'running') {
        return container.health === 'paused'
            ? ['unpause', 'restart', 'stop']
            : ['restart', 'pause', 'stop'];
    }

    if (container.state === 'paused') return ['unpause', 'restart', 'stop'];
    if (['exited', 'created'].includes(container.state)) return ['start', 'remove'];
    if (container.state === 'dead') return ['remove'];

    return ['start', 'remove'];
}

const actionIcons = {
    start: Play,
    stop: Square,
    restart: RotateCw,
    pause: Pause,
    unpause: Play,
    remove: Trash2,
};

const actionTone = {
    start: 'hover:border-accent/40 hover:text-accent',
    stop: 'hover:border-amber/40 hover:text-amber',
    restart: 'hover:border-cyan/40 hover:text-cyan',
    pause: 'hover:border-amber/40 hover:text-amber',
    unpause: 'hover:border-accent/40 hover:text-accent',
    remove: 'hover:border-rose/40 hover:text-rose',
};

function requestAction(action, container) {
    if (['stop', 'remove'].includes(action)) {
        pending.value = { action, container };
        return;
    }

    runAction(action, container);
}

function runAction(action, container) {
    working.value = true;

    router.post('/docker/action', { action, container: container.full_id || container.name }, {
        preserveScroll: true,
        onSuccess: () => {
            pushToast(`${actionLabel(action)} completed for ${shortContainer(container.name)}.`, 'success');
            pending.value = null;
        },
        onError: (errors) => pushToast(Object.values(errors)[0] ?? 'The Docker action failed.', 'error'),
        onFinish: () => (working.value = false),
    });
}

function confirmPending() {
    if (!pending.value) return;

    runAction(pending.value.action, pending.value.container);
}

const dockerDeviceChoices = computed(() => {
    const inventory = deviceInventory.value;

    if (!inventory) return [];

    const choices = [
        ...(inventory.unix ?? []).map((device) => ({
            value: device.path,
            label: device.path,
            description: `${device.kind} · group ${device.group} · ${device.mode}`,
            warnings: device.warnings ?? [],
            used_by: device.used_by ?? [],
        })),
        ...(inventory.block ?? [])
            .filter((device) => device.ready !== false)
            .map((device) => ({
                value: device.path,
                label: device.path,
                description: `${formatBytes(device.size)} · ${device.model || 'block device'}`,
                warnings: device.warnings ?? [],
                used_by: device.used_by ?? [],
            })),
    ];

    return [...new Map(choices.map((choice) => [choice.value, choice])).values()];
});

const selectedDockerDevice = computed(() => dockerDeviceChoices.value.find((choice) => choice.value === devicePick.value) ?? null);
const deviceEditable = computed(() => canOperate.value
    && !deviceContainer.value?.swarm
    && ['exited', 'created'].includes(deviceContainer.value?.state));
const deviceHasChanges = computed(() => {
    const container = deviceContainer.value;

    if (!container) return false;

    const current = (container.devices ?? []).map((device) => `${device.host}:${device.container}:${device.permissions}`).sort();
    const next = deviceDraft.value.devices.map((device) => `${device.host}:${device.container}:${device.permissions}`).sort();

    return Boolean(container.gpus) !== Boolean(deviceDraft.value.gpus)
        || JSON.stringify(current) !== JSON.stringify(next);
});

async function openDevices(container) {
    deviceContainer.value = container;
    deviceDraft.value = {
        devices: (container.devices ?? []).map((device) => ({ ...device })),
        gpus: Boolean(container.gpus),
    };
    devicePick.value = '';
    devicePath.value = '';
    devicePermissions.value = 'rwm';
    deviceInventoryError.value = '';
    deviceSaving.value = false;
    await loadDockerDeviceInventory();
}

async function loadDockerDeviceInventory(force = false) {
    if (deviceInventoryLoading.value || (deviceInventory.value && !force)) {
        if (!devicePick.value) {
            devicePick.value = dockerDeviceChoices.value[0]?.value ?? '';
        }

        return;
    }

    deviceInventoryLoading.value = true;
    deviceInventoryError.value = '';

    try {
        deviceInventory.value = await api.get(`/api/devices${force ? '?refresh=1' : ''}`);
        devicePick.value = dockerDeviceChoices.value[0]?.value ?? '';
    } catch (error) {
        deviceInventoryError.value = error.message ?? 'The host device inventory could not be loaded.';
    } finally {
        deviceInventoryLoading.value = false;
    }
}

function addDockerDevice() {
    if (!devicePick.value) return;

    const containerPath = devicePath.value.trim() || devicePick.value;

    if (deviceDraft.value.devices.some((device) => device.container === containerPath)) {
        deviceInventoryError.value = `${containerPath} is already attached.`;
        return;
    }

    deviceDraft.value.devices.push({
        host: devicePick.value,
        container: containerPath,
        permissions: devicePermissions.value,
    });
    deviceInventoryError.value = '';
    devicePath.value = '';
}

function removeDockerDevice(index) {
    deviceDraft.value.devices.splice(index, 1);
}

function closeDevices() {
    if (deviceSaving.value) return;

    deviceContainer.value = null;
    deviceInventoryError.value = '';
}

async function saveDockerDevices() {
    if (!deviceContainer.value || !deviceEditable.value) return;

    deviceSaving.value = true;
    deviceInventoryError.value = '';

    try {
        const result = await api.post('/docker/devices', {
            container: deviceContainer.value.full_id || deviceContainer.value.name,
            devices: deviceDraft.value.devices.map((device) => ({
                host: device.host,
                container: device.container,
                permissions: device.permissions,
            })),
            gpus: deviceDraft.value.gpus,
        });

        pushToast(result.message || 'Docker devices updated.', 'success');
        deviceContainer.value = null;
        router.reload({ preserveScroll: true, preserveState: true });
    } catch (error) {
        const errors = error?.payload?.errors;

        deviceInventoryError.value = errors && typeof errors === 'object'
            ? (Object.values(errors)[0] ?? error.message)
            : (error.message ?? 'The Docker device configuration could not be saved.');
    } finally {
        deviceSaving.value = false;
    }
}

async function openLogs(container) {
    logContainer.value = container;
    logText.value = '';
    await loadLogs();
}

async function loadLogs() {
    if (!logContainer.value) return;

    logLoading.value = true;

    try {
        const data = await api.get(`/docker/logs?container=${encodeURIComponent(logContainer.value.full_id || logContainer.value.name)}&tail=${logTail.value}`);
        logText.value = data.output || 'No log output yet.';
    } catch (error) {
        logText.value = error.message ?? 'Could not read container logs.';
    } finally {
        logLoading.value = false;
    }
}

function closeLogs() {
    logContainer.value = null;
    logText.value = '';
}

function openConsole(container) {
    if (!canOperate.value || container.state !== 'running') return;

    consoleTarget.value = {
        target: 'docker',
        kind: 'container',
        mode: 'shell',
        name: container.full_id || container.name,
        label: shortContainer(container.name),
    };
}

function closeConsole() {
    consoleTarget.value = null;
}
</script>

<template>
    <Head title="Docker" />

    <div class="space-y-5">
        <PageHeader
            title="Docker"
            subtitle="Every container on this host, with live state and direct engine controls."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot :status="docker.available ? 'online' : 'offline'" pulse />
                    Docker {{ info.version ?? 'engine' }}
                    <span class="text-dim">·</span>
                    {{ info.driver ?? 'unknown' }} storage
                    <span class="text-dim">·</span>
                    {{ docker.counts?.running ?? 0 }}/{{ docker.counts?.all ?? 0 }} running
                </span>
            </template>
            <template #actions>
                <button type="button" class="btn btn-sm" @click="router.reload({ preserveScroll: true, preserveState: true })">
                    <RefreshCw class="h-3.5 w-3.5" /> Refresh
                </button>
            </template>
        </PageHeader>

        <div v-if="!docker.available" class="flex items-start gap-2.5 rounded-xl border border-rose/25 bg-rose/10 px-4 py-3 text-[11px] leading-5 text-rose">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ docker.error }}</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Containers" :value="docker.counts?.all ?? 0" :subtitle="`${docker.counts?.running ?? 0} running · ${docker.counts?.stopped ?? 0} stopped`" :icon="Container" tone="cyan" :detail="`Engine ${info.version ?? '—'} · ${info.cgroup ?? '—'} cgroups`" />
            <StatCard label="Unhealthy" :value="docker.counts?.unhealthy ?? 0" subtitle="Failed container health checks" :icon="AlertTriangle" :tone="(docker.counts?.unhealthy ?? 0) > 0 ? 'rose' : 'accent'" :detail="(docker.counts?.unhealthy ?? 0) > 0 ? 'Investigate container logs' : 'All health checks passing'" />
            <StatCard label="Images" :value="info.images ?? '—'" subtitle="Local image store" :icon="Layers" tone="accent" :detail="`${info.driver ?? '—'} driver · ${info.storage ?? '—'}`" />
            <StatCard label="Engine capacity" :value="`${info.cpus ?? '—'} CPUs`" :subtitle="formatBytes(info.memory)" :icon="Cpu" :detail="`Kernel ${info.kernel ?? '—'}`" />
        </div>

        <section class="panel overflow-hidden">
            <div class="panel-header flex-wrap gap-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[11px] transition"
                        :class="filter === tab.value
                            ? 'border-accent/35 bg-accent/10 font-medium text-accent'
                            : 'border-line bg-panel-2/50 text-muted hover:border-line-bright hover:text-bright'"
                        @click="filter = tab.value"
                    >
                        {{ tab.label }}
                        <span class="font-mono text-[11px] text-dim">{{ tab.count }}</span>
                    </button>
                </div>

                <div class="relative">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-dim" />
                    <input v-model="search" type="search" class="input h-8 w-56 py-1 pl-8 text-xs" placeholder="Search containers…" aria-label="Search containers" />
                </div>
            </div>

            <div v-if="filtered.length" class="overflow-x-auto">
                <table class="w-full min-w-[1060px]">
                    <thead>
                        <tr class="border-b border-line/70">
                            <th class="table-head">Container</th>
                            <th class="table-head">State</th>
                            <th class="table-head">CPU</th>
                            <th class="table-head">Memory</th>
                            <th class="table-head">Ports</th>
                            <th class="table-head">Devices</th>
                            <th class="table-head">Uptime</th>
                            <th class="table-head text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <tr v-for="container in filtered" :key="container.full_id" class="hover-row">
                            <td class="table-cell">
                                <div class="flex items-center gap-2.5">
                                    <Container class="h-4 w-4 shrink-0 text-cyan" :stroke-width="1.7" />
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <span class="truncate text-[11px] font-medium text-bright">{{ shortContainer(container.name) }}</span>
                                            <span class="rounded bg-white/[0.05] px-1 font-mono text-[10px] text-dim">{{ container.id }}</span>
                                        </div>
                                        <div class="mt-0.5 max-w-[320px] truncate font-mono text-[11px] text-dim" :title="container.image">{{ container.image }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="table-cell">
                                <span class="badge" :class="{
                                    'border-accent/25 bg-accent/10 text-accent': healthTone(container) === 'accent',
                                    'border-amber/25 bg-amber/10 text-amber': healthTone(container) === 'amber',
                                    'border-rose/25 bg-rose/10 text-rose': healthTone(container) === 'rose',
                                    'border-line bg-panel-2 text-dim': healthTone(container) === 'muted',
                                }">
                                    {{ stateLabel(container) }}
                                </span>
                                <div v-if="container.health === 'healthy'" class="mt-1 text-[11px] text-accent">health check passing</div>
                            </td>
                            <td class="table-cell font-mono text-[11px] text-muted">{{ container.cpu ?? '—' }}</td>
                            <td class="table-cell">
                                <div class="font-mono text-[11px] text-muted">{{ container.memory ?? '—' }}</div>
                                <div v-if="container.memory_percent" class="text-[11px] text-dim">{{ container.memory_percent }}</div>
                            </td>
                            <td class="table-cell">
                                <div class="max-w-[230px] truncate font-mono text-[11px] text-dim" :title="container.ports">{{ container.ports || '—' }}</div>
                            </td>
                            <td class="table-cell">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 font-mono text-[11px] transition"
                                    :class="(container.devices?.length || container.gpus)
                                        ? 'border-cyan/30 bg-cyan/10 text-cyan hover:border-cyan/50'
                                        : 'border-line bg-panel-2/50 text-dim hover:border-line-bright hover:text-muted'"
                                    :title="container.swarm
                                        ? 'Swarm-managed container — devices are read-only'
                                        : 'Manage host device pass-through'"
                                    @click="openDevices(container)"
                                >
                                    <HardDrive class="h-3 w-3" />
                                    <span>{{ (container.devices ?? []).length }}</span>
                                    <span v-if="container.gpus" class="rounded bg-amber/15 px-1 text-[10px] font-medium text-amber">GPU</span>
                                </button>
                            </td>
                            <td class="table-cell text-[11px] text-dim">{{ container.running_for || '—' }}</td>
                            <td class="table-cell">
                                <div class="flex items-center justify-end gap-1">
                                    <button
                                        v-if="canOperate && container.state === 'running'"
                                        type="button"
                                        class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-accent/40 hover:text-accent"
                                        :title="`Open console in ${shortContainer(container.name)}`"
                                        @click="openConsole(container)"
                                    >
                                        <SquareTerminal class="h-3 w-3" />
                                    </button>
                                    <button
                                        type="button"
                                        class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-cyan/40 hover:text-cyan"
                                        title="View logs"
                                        @click="openLogs(container)"
                                    >
                                        <TerminalSquare class="h-3 w-3" />
                                    </button>
                                    <button
                                        v-for="action in actionsFor(container)"
                                        :key="action"
                                        type="button"
                                        class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition"
                                        :class="actionTone[action]"
                                        :title="`${actionLabel(action)} ${shortContainer(container.name)}`"
                                        @click="requestAction(action, container)"
                                    >
                                        <component :is="actionIcons[action]" class="h-3 w-3" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <EmptyState v-else :icon="Container" title="No containers matched" :description="search ? 'Try a different search or filter.' : 'No Docker containers exist in this host state.'" />

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line/70 px-5 py-3 text-[11px] text-dim">
                <span>{{ filtered.length }} of {{ docker.counts?.all ?? 0 }} containers</span>
                <span>Docker actions execute as root through the audited host helper</span>
            </div>
        </section>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Engine</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Docker daemon identity and storage configuration</p>
                </div>
                <HardDrive class="h-4 w-4 text-dim" />
            </div>
            <div class="grid gap-0 divide-y divide-line/50 sm:grid-cols-2 sm:divide-x lg:grid-cols-4 lg:divide-y-0">
                <div v-for="item in [
                    { label: 'Server version', value: info.version ?? '—' },
                    { label: 'Storage driver', value: info.driver ?? '—' },
                    { label: 'Storage backend', value: info.storage ?? '—' },
                    { label: 'Cgroup driver', value: info.cgroup ?? '—' },
                    { label: 'Operating system', value: info.os ?? '—' },
                    { label: 'Kernel', value: info.kernel ?? '—' },
                    { label: 'Host CPUs', value: info.cpus ?? '—' },
                    { label: 'Host memory', value: formatBytes(info.memory) },
                ]" :key="item.label" class="px-5 py-3.5">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-dim">{{ item.label }}</div>
                    <div class="mt-1 truncate font-mono text-[11px] text-muted">{{ item.value }}</div>
                </div>
            </div>
        </section>

        <section v-if="(docker.containers ?? []).length" class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Recent container states</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Docker inspect labels and configured networks</p>
                </div>
                <Boxes class="h-4 w-4 text-dim" />
            </div>
            <div class="grid gap-0 divide-y divide-line/50 sm:grid-cols-2 sm:divide-x">
                <div v-for="container in containers" :key="`meta-${container.full_id}`" class="flex items-start gap-3 px-5 py-3">
                    <StatusDot :status="healthTone(container) === 'accent' ? 'online' : healthTone(container) === 'rose' ? 'offline' : 'degraded'" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-[11px] font-medium text-bright">{{ shortContainer(container.name) }}</div>
                        <div class="mt-0.5 truncate font-mono text-[11px] text-dim">{{ container.networks || 'default network' }}</div>
                        <div class="mt-0.5 truncate font-mono text-[11px] text-dim">{{ container.mounts || 'no bind mounts' }}</div>
                    </div>
                    <button type="button" class="shrink-0 text-[11px] font-medium text-accent hover:text-accent/80" @click="openLogs(container)">
                        logs
                    </button>
                    <button
                        v-if="canOperate && container.state === 'running'"
                        type="button"
                        class="shrink-0 text-[11px] font-medium text-cyan hover:text-cyan/80"
                        @click="openConsole(container)"
                    >
                        console
                    </button>
                </div>
            </div>
        </section>

        <div class="flex items-start gap-2.5 rounded-xl border border-line/60 bg-panel/40 px-4 py-3 text-[11px] leading-5 text-dim">
            <Database class="mt-0.5 h-3.5 w-3.5 shrink-0 text-cyan" />
            <span>
                This is the live Docker engine on {{ page.props.host?.hostname ?? 'this host' }}. ServerDeck speaks to it through a
                root-owned helper with an allow-listed command set; container removal is confirmed first and every action is audited.
            </span>
        </div>

        <ConfirmDialog
            :show="Boolean(pending)"
            :title="pending ? `${actionLabel(pending.action)} container?` : ''"
            :description="pending ? `${shortContainer(pending.container.name)} will be ${pending.action === 'remove' ? 'deleted permanently' : 'stopped immediately'}.` : ''"
            :confirm-label="pending ? actionLabel(pending.action) : 'Confirm'"
            :loading="working"
            @close="pending = null"
            @confirm="confirmPending"
        />

        <Modal :show="Boolean(logContainer)" :title="logContainer ? `Logs · ${shortContainer(logContainer.name)}` : 'Logs'" :description="logContainer?.image" max-width="max-w-3xl" @close="closeLogs">
            <div class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-1.5">
                        <button
                            v-for="tail in [100, 250, 500, 1000]"
                            :key="tail"
                            type="button"
                            class="rounded-md border px-2 py-1 font-mono text-[11px] transition"
                            :class="logTail === tail ? 'border-cyan/35 bg-cyan/10 text-cyan' : 'border-line bg-panel-2 text-dim hover:text-muted'"
                            @click="logTail = tail; loadLogs()"
                        >
                            {{ tail }}
                        </button>
                    </div>
                    <button type="button" class="btn btn-sm" :disabled="logLoading" @click="loadLogs">
                        <RefreshCw class="h-3 w-3" :class="{ 'animate-spin': logLoading }" /> Refresh
                    </button>
                </div>

                <div class="relative">
                    <pre class="max-h-[52vh] min-h-[220px] overflow-auto rounded-xl border border-line bg-[#04080c] p-4 font-mono text-[11px] leading-5 text-muted">{{ logLoading ? 'Reading container logs…' : (logText || 'No log output.') }}</pre>
                    <button
                        v-if="logContainer"
                        type="button"
                        class="absolute right-2 top-2 grid h-6 w-6 place-items-center rounded border border-line bg-panel-2 text-dim hover:text-bright"
                        title="Close logs"
                        @click="closeLogs"
                    >
                        <X class="h-3 w-3" />
                    </button>
                </div>
            </div>
        </Modal>

        <Modal
            :show="Boolean(deviceContainer)"
            :title="deviceContainer ? `Devices · ${shortContainer(deviceContainer.name)}` : 'Container devices'"
            description="Pass host device nodes into the container. Docker cannot change devices in place; saving recreates the container with the same configuration."
            max-width="max-w-2xl"
            :closeable="!deviceSaving"
            @close="closeDevices"
        >
            <div v-if="deviceContainer" class="space-y-3">
                <div v-if="deviceContainer.swarm" class="flex items-start gap-2 rounded-xl border border-rose/25 bg-rose/10 px-3.5 py-3 text-[11px] leading-5 text-rose">
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span>
                        This container is managed by Docker Swarm. Device changes must be made in the service definition; ServerDeck shows the current devices but cannot edit them.
                    </span>
                </div>

                <div v-else-if="!['exited', 'created'].includes(deviceContainer.state)" class="flex items-start gap-2 rounded-xl border border-amber/25 bg-amber/10 px-3.5 py-3 text-[11px] leading-5 text-amber">
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span>Stop this container before changing devices. ServerDeck recreates the container, so it must not be running.</span>
                </div>

                <div v-if="!canOperate" class="rounded-xl border border-line bg-panel-2/30 px-3.5 py-3 text-[11px] leading-5 text-dim">
                    Your role is read-only. Device changes require operate permission.
                </div>

                <div v-if="deviceInventory?.warnings?.length" class="rounded-xl border border-amber/25 bg-amber/10 px-3.5 py-3 text-[11px] leading-5 text-amber">
                    <div class="flex items-start gap-2">
                        <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        <div class="space-y-1">
                            <p v-for="warning in deviceInventory.warnings" :key="warning">{{ warning }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-line/70 bg-panel/50 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">Current devices</h3>
                            <p class="mt-0.5 text-[11px] text-dim">Device mappings captured from the Docker inspect response.</p>
                        </div>
                        <button type="button" class="btn btn-sm" :disabled="deviceInventoryLoading" @click="loadDockerDeviceInventory(true)">
                            <RefreshCw class="h-3 w-3" :class="{ 'animate-spin': deviceInventoryLoading }" /> Rescan host
                        </button>
                    </div>

                    <div class="mt-3 space-y-2">
                        <div v-for="(device, index) in deviceDraft.devices" :key="`${device.host}-${device.container}`" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate font-mono text-[11px] text-bright">
                                    {{ device.host }}
                                    <span class="text-dim">→</span>
                                    {{ device.container }}
                                </p>
                                <p class="mt-0.5 font-mono text-[11px] text-dim">permissions {{ device.permissions }}</p>
                            </div>
                            <button type="button" class="btn btn-sm btn-danger" :disabled="!deviceEditable || deviceSaving" @click="removeDockerDevice(index)">
                                <Trash2 class="h-3 w-3" /> Remove
                            </button>
                        </div>

                        <div v-if="deviceDraft.gpus" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber/25 bg-amber/10 px-3 py-2.5">
                            <div>
                                <p class="text-[11px] font-medium text-amber">All NVIDIA GPUs</p>
                                <p class="mt-0.5 text-[11px] text-amber/80">Docker device request · Capabilities [["gpu"]]</p>
                            </div>
                            <button type="button" class="btn btn-sm" :disabled="!deviceEditable || deviceSaving" @click="deviceDraft.gpus = false">
                                <X class="h-3 w-3" /> Disable
                            </button>
                        </div>

                        <p v-if="!deviceDraft.devices.length && !deviceDraft.gpus" class="rounded-lg border border-dashed border-line/70 px-3 py-4 text-center text-[11px] text-dim">
                            No host devices are attached to this container.
                        </p>
                    </div>
                </div>

                <div class="rounded-xl border border-line/70 bg-panel/50 p-4">
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">Attach a host device</h3>
                    <p class="mt-0.5 text-[11px] text-dim">Character and block device nodes available on this host. Docker passes the node through with the selected cgroup permissions.</p>

                    <div v-if="deviceInventoryError" class="mt-3 rounded-lg border border-rose/25 bg-rose/10 px-3 py-2.5 text-[11px] leading-5 text-rose">
                        {{ deviceInventoryError }}
                    </div>

                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="docker-device-choice" class="label">Host device</label>
                            <select id="docker-device-choice" v-model="devicePick" class="input" :disabled="!deviceEditable || deviceInventoryLoading">
                                <option value="" disabled>{{ dockerDeviceChoices.length ? 'Choose a device' : 'No host devices found' }}</option>
                                <option v-for="choice in dockerDeviceChoices" :key="choice.value" :value="choice.value">
                                    {{ choice.label }} — {{ choice.description }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label for="docker-device-path" class="label">Path inside container</label>
                            <input id="docker-device-path" v-model="devicePath" class="input font-mono" :placeholder="devicePick || '/dev/device'" autocomplete="off" spellcheck="false" :disabled="!deviceEditable" />
                        </div>
                        <div>
                            <label for="docker-device-permissions" class="label">Permissions</label>
                            <select id="docker-device-permissions" v-model="devicePermissions" class="input" :disabled="!deviceEditable">
                                <option value="rwm">rwm · read, write, mknod</option>
                                <option value="rw">rw · read and write</option>
                                <option value="r">r · read only</option>
                                <option value="m">m · mknod only</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="selectedDockerDevice" class="mt-3 rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2.5 text-[11px] leading-5 text-dim">
                        <p class="font-mono text-muted">{{ selectedDockerDevice.description }}</p>
                        <p v-if="selectedDockerDevice.used_by?.length" class="mt-1 text-amber">
                            Already used by
                            <span v-for="(use, index) in selectedDockerDevice.used_by" :key="`${use.runtime}-${use.owner}-${use.device}`">
                                {{ index ? ', ' : '' }}{{ use.runtime }} {{ use.owner }}{{ use.device ? ` (${use.device})` : '' }}
                            </span>
                        </p>
                    </div>

                    <div v-if="selectedDockerDevice?.warnings?.length" class="mt-3 rounded-lg border border-amber/25 bg-amber/10 px-3 py-2.5 text-[11px] leading-5 text-amber">
                        <p v-for="warning in selectedDockerDevice.warnings" :key="warning">{{ warning }}</p>
                    </div>

                    <div class="mt-3 flex justify-end">
                        <button type="button" class="btn btn-sm btn-primary" :disabled="!deviceEditable || !devicePick || deviceSaving" @click="addDockerDevice">
                            <Plus class="h-3 w-3" /> Add device
                        </button>
                    </div>
                </div>

                <div class="rounded-xl border border-line/70 bg-panel/50 p-4">
                    <label class="flex items-start gap-2.5">
                        <input v-model="deviceDraft.gpus" type="checkbox" class="mt-0.5 h-3.5 w-3.5 rounded border-line bg-panel-2" :disabled="!deviceEditable || deviceSaving" />
                        <span>
                            <span class="block text-[11px] font-medium text-bright">Request all NVIDIA GPUs</span>
                            <span class="mt-0.5 block text-[11px] leading-4 text-dim">
                                Adds a Docker device request with the <span class="font-mono">gpu</span> capability. The NVIDIA container runtime must be registered before the container can start with GPU access.
                            </span>
                        </span>
                    </label>

                    <div v-if="deviceDraft.gpus && deviceInventory?.docker && !deviceInventory.docker.nvidia_runtime" class="mt-2.5 rounded-lg border border-rose/25 bg-rose/10 px-3 py-2.5 text-[11px] leading-5 text-rose">
                        The NVIDIA container runtime is not installed or registered. The recreation will succeed, but the container will not start with GPU access until the runtime is available.
                    </div>
                </div>

                <p class="text-[11px] leading-5 text-dim">
                    Saving renames the current container, creates a replacement with the same image, command, environment, mounts, ports, labels, restart policy and networks, then removes the previous container. Devices are only applied to the replacement.
                </p>
            </div>

            <template #footer>
                <button type="button" class="btn btn-sm" :disabled="deviceSaving" @click="closeDevices">Cancel</button>
                <button
                    type="button"
                    class="btn btn-sm btn-primary"
                    :disabled="!deviceEditable || !deviceHasChanges || deviceSaving"
                    @click="saveDockerDevices"
                >
                    <span v-if="deviceSaving" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                    <RotateCw v-else class="h-3 w-3" />
                    {{ deviceSaving ? 'Recreating container…' : 'Save & recreate' }}
                </button>
            </template>
        </Modal>

        <ConsoleTerminal
            v-if="consoleTarget"
            :target="consoleTarget.target"
            :kind="consoleTarget.kind"
            :mode="consoleTarget.mode"
            :name="consoleTarget.name"
            :label="consoleTarget.label"
            @close="closeConsole"
        />
    </div>
</template>
