<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    Cpu,
    Gauge,
    HardDrive,
    MemoryStick,
    Network,
    RefreshCw,
    Search,
    Server,
    ShieldAlert,
    Timer,
    Zap,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import EmptyState from '@/Components/EmptyState.vue';
import MetricBar from '@/Components/MetricBar.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { api } from '@/lib/http';
import { formatBytes, formatCount, formatRate, percentTone } from '@/lib/host';
import { formatDateTime, formatDuration, formatUptime } from '@/lib/format';
import { usePolling } from '@/lib/polling';
import { pushToast } from '@/lib/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    metrics: { type: Object, required: true },
    processes: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const page = usePage();
const canOperate = computed(() => page.props.permissions?.operate ?? false);
const snapshot = ref(props.metrics);
const refreshing = ref(false);
const search = ref(props.filters.search ?? '');
const sort = ref(props.filters.sort ?? 'cpu');
const selected = ref(null);
const signal = ref('TERM');
const signalling = ref(false);
const liveError = ref(null);
let debounce = null;

const toneText = { accent: 'text-accent', cyan: 'text-cyan', amber: 'text-amber', rose: 'text-rose', muted: 'text-muted' };
const toneBg = { accent: 'bg-accent', cyan: 'bg-cyan', amber: 'bg-amber', rose: 'bg-rose', muted: 'bg-dim' };
const stateTone = (state) => (state?.startsWith('Z') ? 'rose' : state?.startsWith('R') ? 'accent' : 'muted');

const memorySegments = computed(() => {
    const memory = snapshot.value.memory;
    const total = Math.max(1, memory.total);

    return [
        { label: 'Used', value: memory.used, class: 'bg-cyan' },
        { label: 'Cached', value: memory.cached, class: 'bg-accent/60' },
        { label: 'Buffers', value: memory.buffers, class: 'bg-accent/30' },
        { label: 'Available', value: memory.available, class: 'bg-white/[0.08]' },
    ].map((segment) => ({ ...segment, percent: Math.min(100, (segment.value / total) * 100) }));
});

const rootDisk = computed(() => snapshot.value.disks.root);

const gpuDevices = computed(() => snapshot.value.gpu.devices ?? []);

const gpuStatus = computed(() => {
    if (snapshot.value.gpu.available) return { label: 'Driver active', tone: 'accent' };
    if (gpuDevices.value.length) return { label: 'Hardware detected · driver unavailable', tone: 'amber' };

    return { label: 'No GPU detected', tone: 'muted' };
});

async function pollMetrics() {
    try {
        const data = await api.get('/api/metrics');
        snapshot.value = data;
        liveError.value = null;
    } catch (error) {
        liveError.value = error.message ?? 'Live metrics unavailable.';
    }
}

usePolling(pollMetrics, 5000);

watch(
    () => props.metrics,
    (value) => {
        snapshot.value = value;
    },
);

function applyFilters() {
    router.get('/system', {
        ...(search.value.trim() ? { search: search.value.trim() } : {}),
        ...(sort.value !== 'cpu' ? { sort: sort.value } : {}),
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['processes', 'filters'],
    });
}

watch(search, () => {
    window.clearTimeout(debounce);
    debounce = window.setTimeout(applyFilters, 300);
});

watch(sort, applyFilters);

function refresh() {
    if (refreshing.value) return;

    refreshing.value = true;
    router.reload({
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (refreshing.value = false),
    });
}

function openSignal(process) {
    selected.value = process;
    signal.value = 'TERM';
}

function sendSignal() {
    if (!selected.value) return;

    signalling.value = true;
    router.post(`/system/processes/${selected.value.pid}/signal`, { signal: signal.value }, {
        preserveScroll: true,
        onSuccess: () => {
            pushToast(`SIG${signal.value} sent to PID ${selected.value.pid}.`, 'success', 'Signal sent');
            selected.value = null;
        },
        onError: (errors) => pushToast(errors.signal ?? 'The signal could not be delivered.', 'error'),
        onFinish: () => (signalling.value = false),
    });
}

onBeforeUnmount(() => window.clearTimeout(debounce));
</script>

<template>
    <Head title="System" />

    <div class="space-y-5">
        <PageHeader
            title="System & processes"
            :subtitle="`${snapshot.host.cpu_model} · ${snapshot.host.cpu_threads} threads across ${snapshot.host.sockets} socket${snapshot.host.sockets === 1 ? '' : 's'}`"
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot status="online" pulse />
                    {{ snapshot.host.hostname }} · {{ snapshot.host.os }}
                    <span class="text-dim">·</span>
                    kernel {{ snapshot.host.kernel }}
                    <span class="text-dim">·</span>
                    up {{ formatUptime(snapshot.host.uptime_seconds) }}
                </span>
            </template>
            <template #actions>
                <button type="button" class="btn btn-sm" :disabled="refreshing" @click="refresh">
                    <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': refreshing }" />
                    Refresh
                </button>
            </template>
        </PageHeader>

        <div v-if="liveError" class="flex items-center gap-2 rounded-xl border border-amber/25 bg-amber/10 px-4 py-2.5 text-[11px] text-amber">
            <AlertTriangle class="h-3.5 w-3.5" /> {{ liveError }}
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="CPU" :value="`${Number(snapshot.cpu.usage).toFixed(1)}%`" :subtitle="`Load ${snapshot.cpu.load_1} / ${snapshot.cpu.load_5} / ${snapshot.cpu.load_15}`" :icon="Cpu" :tone="percentTone(snapshot.cpu.usage)" :detail="`${snapshot.host.cpu_cores} physical cores · ${snapshot.host.virtualization ?? 'virtualisation n/a'}`" />
            <StatCard label="Memory" :value="`${Number(snapshot.memory.percent).toFixed(1)}%`" :subtitle="`${formatBytes(snapshot.memory.used)} of ${formatBytes(snapshot.memory.total)}`" :icon="MemoryStick" :tone="percentTone(snapshot.memory.percent)" :detail="`${formatBytes(snapshot.memory.cached)} cached · ${formatBytes(snapshot.memory.swap_used)} swap used`" />
            <StatCard label="Processes" :value="formatCount(snapshot.processes)" subtitle="Visible in /proc right now" :icon="Activity" tone="cyan" :detail="`${processes.total} matched by ps · ${processes.filtered} shown`" />
            <StatCard label="Host uptime" :value="formatUptime(snapshot.host.uptime_seconds)" :subtitle="`Booted ${formatDateTime(snapshot.host.booted_at)}`" :icon="Timer" tone="accent" :detail="`${snapshot.temperature ? `${snapshot.temperature.label} ${snapshot.temperature.celsius}°C` : 'No thermal zone exposed'}`" />
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <section class="panel xl:col-span-2">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">CPU cores</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Per-thread utilisation from /proc/stat</p>
                    </div>
                    <span class="badge border-line bg-panel-2 text-dim">{{ snapshot.cpu.per_core.length }} threads</span>
                </div>
                <div class="grid grid-cols-4 gap-2 p-5 sm:grid-cols-8">
                    <div v-for="core in snapshot.cpu.per_core" :key="core.core" class="rounded-lg border border-line/70 bg-panel-2/40 px-2 py-2">
                        <div class="flex items-center justify-between text-[11px] text-dim">
                            <span>CPU{{ core.core }}</span>
                            <span class="font-mono" :class="toneText[percentTone(core.usage)]">{{ Number(core.usage).toFixed(0) }}%</span>
                        </div>
                        <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-white/[0.05]">
                            <div class="h-full rounded-full" :class="toneBg[percentTone(core.usage)]" :style="{ width: `${core.usage}%` }" />
                        </div>
                    </div>
                </div>
            </section>

            <section class="panel" id="memory">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Memory</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Physical RAM and swap</p>
                    </div>
                    <MemoryStick class="h-4 w-4 text-dim" />
                </div>
                <div class="space-y-4 p-5">
                    <div>
                        <div class="mb-2 flex items-baseline justify-between text-[11px]">
                            <span class="text-dim">RAM · {{ formatBytes(snapshot.memory.total) }}</span>
                            <span class="font-mono text-bright">{{ Number(snapshot.memory.percent).toFixed(1) }}%</span>
                        </div>
                        <div class="flex h-2.5 overflow-hidden rounded-full bg-white/[0.05]">
                            <div v-for="segment in memorySegments" :key="segment.label" class="h-full" :class="segment.class" :style="{ width: `${segment.percent}%` }" :title="`${segment.label}: ${formatBytes(segment.value)}`" />
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-[11px]">
                            <div class="flex justify-between rounded-lg border border-line/70 bg-panel-2/30 px-2.5 py-1.5"><span class="text-dim">Used</span><span class="font-mono text-muted">{{ formatBytes(snapshot.memory.used) }}</span></div>
                            <div class="flex justify-between rounded-lg border border-line/70 bg-panel-2/30 px-2.5 py-1.5"><span class="text-dim">Available</span><span class="font-mono text-muted">{{ formatBytes(snapshot.memory.available) }}</span></div>
                            <div class="flex justify-between rounded-lg border border-line/70 bg-panel-2/30 px-2.5 py-1.5"><span class="text-dim">Cached</span><span class="font-mono text-muted">{{ formatBytes(snapshot.memory.cached) }}</span></div>
                            <div class="flex justify-between rounded-lg border border-line/70 bg-panel-2/30 px-2.5 py-1.5"><span class="text-dim">Shared</span><span class="font-mono text-muted">{{ formatBytes(snapshot.memory.shared) }}</span></div>
                        </div>
                    </div>
                    <div class="border-t border-line/70 pt-4">
                        <MetricBar label="Swap" :value="snapshot.memory.swap_percent" :detail="`${formatBytes(snapshot.memory.swap_used)} of ${formatBytes(snapshot.memory.swap_total)}`" />
                    </div>
                    <div class="border-t border-line/70 pt-4">
                        <div class="flex items-center justify-between text-[11px]"><span class="text-dim">Root filesystem</span><span class="font-mono" :class="toneText[percentTone(rootDisk?.percent ?? 0)]">{{ rootDisk?.percent ?? 0 }}%</span></div>
                        <div class="mt-1 text-[11px] text-dim">{{ rootDisk?.mount }} · {{ formatBytes(rootDisk?.used) }} / {{ formatBytes(rootDisk?.size) }}</div>
                    </div>
                </div>
            </section>
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <section class="panel xl:col-span-2">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Network interfaces</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Live throughput from /proc/net/dev</p>
                    </div>
                    <Network class="h-4 w-4 text-dim" />
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[600px]">
                        <thead>
                            <tr class="border-b border-line/70">
                                <th class="table-head">Interface</th>
                                <th class="table-head">State</th>
                                <th class="table-head">Receive</th>
                                <th class="table-head">Transmit</th>
                                <th class="table-head text-right">Total in / out</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/50">
                            <tr v-for="iface in snapshot.network.interfaces" :key="iface.name" class="hover-row">
                                <td class="table-cell">
                                    <div class="flex items-center gap-2">
                                        <StatusDot :status="iface.state === 'up' ? 'online' : 'maintenance'" />
                                        <span class="font-mono text-[11px] text-bright">{{ iface.name }}</span>
                                    </div>
                                    <div class="mt-0.5 font-mono text-[11px] text-dim">{{ iface.mac || 'no MAC' }} · MTU {{ iface.mtu }}</div>
                                </td>
                                <td class="table-cell text-[11px] uppercase tracking-wider text-muted">{{ iface.state }}</td>
                                <td class="table-cell font-mono text-[11px] text-accent">{{ formatRate(iface.rx_rate) }}</td>
                                <td class="table-cell font-mono text-[11px] text-cyan">{{ formatRate(iface.tx_rate) }}</td>
                                <td class="table-cell text-right font-mono text-[11px] text-dim">{{ formatBytes(iface.rx_bytes) }} / {{ formatBytes(iface.tx_bytes) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel" id="gpu">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">GPU</h2>
                        <p class="mt-0.5 text-[11px] text-dim">NVIDIA driver and devices</p>
                    </div>
                    <Zap class="h-4 w-4 text-dim" />
                </div>
                <div class="space-y-3 p-5">
                    <div class="flex items-center gap-2 text-[11px]">
                        <StatusDot :status="snapshot.gpu.available ? 'online' : 'degraded'" />
                        <span :class="toneText[gpuStatus.tone]">{{ gpuStatus.label }}</span>
                    </div>

                    <div v-for="device in gpuDevices" :key="device.uuid || device.name" class="rounded-xl border border-line/70 bg-panel-2/30 p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-[11px] font-medium text-bright">{{ device.name }}</p>
                                <p class="mt-0.5 font-mono text-[11px] text-dim">{{ device.uuid || 'PCI device' }}</p>
                            </div>
                            <span v-if="device.driver" class="badge border-accent/20 bg-accent/10 text-accent">{{ device.driver }}</span>
                        </div>
                        <div v-if="device.memory_total" class="mt-3">
                            <MetricBar label="VRAM" :value="device.memory_total ? (device.memory_used / device.memory_total) * 100 : 0" :detail="`${formatBytes(device.memory_used)} of ${formatBytes(device.memory_total)}`" compact />
                        </div>
                        <div v-if="device.utilization !== null" class="mt-2 grid grid-cols-3 gap-2 text-[11px]">
                            <div class="rounded border border-line/60 px-2 py-1"><span class="block text-dim">GPU</span><span class="font-mono text-muted">{{ device.utilization }}%</span></div>
                            <div class="rounded border border-line/60 px-2 py-1"><span class="block text-dim">Temp</span><span class="font-mono text-muted">{{ device.temperature }}°C</span></div>
                            <div class="rounded border border-line/60 px-2 py-1"><span class="block text-dim">Power</span><span class="font-mono text-muted">{{ device.power }}W</span></div>
                        </div>
                    </div>

                    <p v-if="snapshot.gpu.error" class="rounded-lg border border-amber/20 bg-amber/5 px-3 py-2 text-[11px] leading-5 text-amber">
                        {{ snapshot.gpu.error }}
                    </p>
                    <p v-if="!snapshot.gpu.available" class="text-[11px] leading-5 text-dim">
                        The NVIDIA kernel driver is not exposing the installed cards. The panel will show utilisation as soon as <span class="font-mono text-muted">nvidia-smi</span> can query them.
                    </p>
                </div>
            </section>
        </div>

        <section class="panel overflow-hidden">
            <div class="panel-header flex-wrap gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Processes</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Real process table from ps · sorted by {{ sort === 'memory' ? 'memory' : sort === 'time' ? 'elapsed time' : sort === 'pid' ? 'PID' : 'CPU' }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <Search class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-dim" />
                        <input v-model="search" type="search" class="input h-8 w-48 py-1 pl-8 text-xs sm:w-64" placeholder="Filter process or user…" aria-label="Filter processes" />
                    </div>
                    <select v-model="sort" class="input h-8 w-auto py-1 text-xs" aria-label="Sort processes">
                        <option value="cpu">CPU desc</option>
                        <option value="memory">Memory desc</option>
                        <option value="time">Elapsed desc</option>
                        <option value="pid">PID asc</option>
                    </select>
                </div>
            </div>

            <div v-if="processes.available && processes.processes.length" class="overflow-x-auto">
                <table class="w-full min-w-[860px]">
                    <thead>
                        <tr class="border-b border-line/70">
                            <th class="table-head w-20">PID</th>
                            <th class="table-head">User</th>
                            <th class="table-head w-20">CPU</th>
                            <th class="table-head w-20">MEM</th>
                            <th class="table-head">Command</th>
                            <th class="table-head w-24">State</th>
                            <th class="table-head w-28">Elapsed</th>
                            <th class="table-head w-20 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <tr v-for="process in processes.processes" :key="process.pid" class="hover-row">
                            <td class="table-cell font-mono text-[11px] text-dim">{{ process.pid }}</td>
                            <td class="table-cell font-mono text-[11px] text-muted">{{ process.user }}</td>
                            <td class="table-cell font-mono text-[11px]" :class="toneText[percentTone(process.cpu)]">{{ Number(process.cpu).toFixed(1) }}%</td>
                            <td class="table-cell font-mono text-[11px] text-muted">{{ Number(process.memory).toFixed(1) }}%</td>
                            <td class="table-cell">
                                <div class="max-w-[420px] truncate font-mono text-[11px] text-bright" :title="process.args">{{ process.args || process.command }}</div>
                                <div class="text-[11px] text-dim">{{ process.command }}</div>
                            </td>
                            <td class="table-cell"><span class="badge border-line bg-panel-2 font-mono text-[11px]" :class="toneText[stateTone(process.state)]">{{ process.state }}</span></td>
                            <td class="table-cell font-mono text-[11px] text-dim">{{ formatDuration(process.elapsed) }}</td>
                            <td class="table-cell text-right">
                                <button v-if="canOperate" type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-rose/40 hover:text-rose" title="Send signal" @click="openSignal(process)">
                                    <ShieldAlert class="h-3 w-3" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else :icon="Activity" title="No processes matched" description="Try a different filter or refresh the process table." />
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line/70 px-5 py-3 text-[11px] text-dim">
                <span>{{ processes.filtered }} shown · {{ processes.total }} total from ps</span>
                <span>Signals execute as root through the audited host helper.</span>
            </div>
        </section>

        <div class="flex items-start gap-2.5 rounded-xl border border-line/60 bg-panel/40 px-4 py-3 text-[11px] leading-5 text-dim">
            <Server class="mt-0.5 h-3.5 w-3.5 shrink-0 text-cyan" />
            <span>
                CPU, memory, network, disk and uptime are read directly from the Linux host. Process signalling and service
                control run through a root-owned helper with a fixed allow-list and every action is written to the audit log.
            </span>
        </div>
    </div>

    <ConfirmDialog
        :show="Boolean(selected)"
        title="Send a signal to this process?"
        :description="selected ? `PID ${selected.pid} (${selected.user}) · ${selected.args || selected.command}` : ''"
        :confirm-label="`Send SIG${signal}`"
        :loading="signalling"
        @close="selected = null"
        @confirm="sendSignal"
    >
        <div class="mt-4">
            <label for="signal" class="label">Signal</label>
            <select id="signal" v-model="signal" class="input h-9">
                <option value="TERM">SIGTERM · graceful shutdown</option>
                <option value="KILL">SIGKILL · force kill</option>
                <option value="HUP">SIGHUP · reload / hang up</option>
                <option value="INT">SIGINT · interrupt</option>
            </select>
            <p class="mt-2 text-[11px] leading-5 text-dim">Signalling the wrong PID can interrupt services or your session.</p>
        </div>
    </ConfirmDialog>
</template>
