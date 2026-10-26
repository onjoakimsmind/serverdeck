<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    ArrowUpRight,
    Boxes,
    CheckCircle2,
    Container,
    Cpu,
    Database,
    ExternalLink,
    Gauge,
    HardDrive,
    HeartPulse,
    MemoryStick,
    Network,
    RefreshCw,
    Server,
    ShieldCheck,
    TerminalSquare,
    Wrench,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import AreaChart from '@/Components/AreaChart.vue';
import GaugeRing from '@/Components/GaugeRing.vue';
import MetricBar from '@/Components/MetricBar.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusDot from '@/Components/StatusDot.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { api } from '@/lib/http';
import { formatBytes, formatCount, formatRate, healthText, healthTone, percentTone, shortContainer } from '@/lib/host';
import { formatDateTime, formatTime, formatUptime, timeAgo } from '@/lib/format';
import { usePolling } from '@/lib/polling';

defineOptions({ layout: AppLayout });

const props = defineProps({
    metrics: { type: Object, required: true },
    health: { type: Object, required: true },
    stack: { type: Object, required: true },
    docker: { type: Object, required: true },
    incus: { type: Object, required: true },
    zfs: { type: Object, required: true },
    systemd: { type: Object, required: true },
    stats: { type: Object, required: true },
    activity: { type: Array, default: () => [] },
});

const page = usePage();
const permissions = computed(() => page.props.permissions ?? {});
const snapshot = ref(props.metrics);
const history = ref([
    {
        at: props.metrics.sampled_at ?? new Date().toISOString(),
        cpu: props.metrics.cpu.usage,
        memory: props.metrics.memory.percent,
        disk: Number(props.stats.disk ?? props.metrics.disks?.root?.percent ?? 0),
    },
]);
const chartRange = ref('live');
const historical = ref({ points: [], summary: null, label: 'Last 24 hours', bucket_minutes: 5 });
const historyLoading = ref(false);
const historyError = ref(null);
const refreshing = ref(false);
const lastUpdated = ref(new Date(props.metrics.sampled_at ?? Date.now()));
const liveError = ref(null);

const chartRanges = [
    { key: 'live', label: 'Live' },
    { key: '24h', label: '24h' },
    { key: '7d', label: '7d' },
];

const chartPoints = computed(() => (chartRange.value === 'live' ? history.value : historical.value.points ?? []));

const chartSeries = computed(() => [
    { key: 'cpu', label: 'CPU', values: chartPoints.value.map((point) => point.cpu), color: '#2ee6a8' },
    { key: 'memory', label: 'Memory', values: chartPoints.value.map((point) => point.memory), color: '#38bdf8' },
    { key: 'disk', label: 'Root disk', values: chartPoints.value.map((point) => point.disk), color: '#f59e0b' },
]);

const chartLabels = computed(() => chartPoints.value.map((point) => point.at));
const chartLabelMode = computed(() => (chartRange.value === '7d' ? 'date' : 'time'));
const historySummary = computed(() => historical.value.summary ?? {
    cpu: { avg: 0, max: 0 },
    memory: { avg: 0, max: 0 },
    disk: { avg: 0, max: 0 },
});
const chartSubtitle = computed(() => {
    if (chartRange.value === 'live') {
        return 'CPU, memory and root disk sampled every five seconds';
    }

    return `${historical.value.label ?? 'Historical range'} · averaged into ${historical.value.bucket_minutes ?? 5}-minute buckets`;
});

const healthToneClass = computed(() => healthTone(props.health.summary.overall));
const activeChecks = computed(() => props.health.summary.active ?? []);
const failedUnits = computed(() => props.systemd.failed ?? []);
const containers = computed(() => (props.docker.containers ?? []).slice(0, 6));
const instances = computed(() => (props.incus.instances ?? []).slice(0, 4));
const primaryPool = computed(() => props.zfs.pools?.[0] ?? null);
const topDatasets = computed(() => (props.zfs.datasets ?? []).filter((dataset) => dataset.type === 'filesystem').slice(0, 6));
const integrations = computed(() => props.stack.integrations ?? []);
const toneText = { accent: 'text-accent', cyan: 'text-cyan', amber: 'text-amber', rose: 'text-rose', muted: 'text-muted' };

const uptime = computed(() => formatUptime(snapshot.value.host.uptime_seconds));
const gpuLabel = computed(() => {
    if (snapshot.value.gpu.available) {
        return snapshot.value.gpu.devices.map((device) => device.name).filter(Boolean).join(', ');
    }

    return snapshot.value.gpu.error ?? 'Driver unavailable';
});

async function pollMetrics() {
    try {
        const data = await api.get('/api/metrics');
        snapshot.value = data;
        lastUpdated.value = new Date(data.sampled_at ?? Date.now());
        liveError.value = null;

        history.value.push({
            at: data.sampled_at ?? new Date().toISOString(),
            cpu: data.cpu.usage,
            memory: data.memory.percent,
            disk: Number(data.disks?.root?.percent ?? 0),
        });

        if (history.value.length > 60) {
            history.value = history.value.slice(-60);
        }
    } catch (error) {
        liveError.value = error.message ?? 'Live telemetry unavailable.';
    }
}

async function loadHistory(range) {
    historyLoading.value = true;
    historyError.value = null;

    try {
        const data = await api.get(`/api/metrics/history?range=${range}`);

        if (chartRange.value === range) {
            historical.value = data;
        }
    } catch (error) {
        if (chartRange.value === range) {
            historyError.value = error.message ?? 'Historical metrics unavailable.';
        }
    } finally {
        if (chartRange.value === range) {
            historyLoading.value = false;
        }
    }
}

function setChartRange(range) {
    if (chartRange.value === range) return;

    chartRange.value = range;

    if (range === 'live') {
        historyLoading.value = false;
        historyError.value = null;

        return;
    }

    loadHistory(range);
}

function refresh() {
    if (refreshing.value) return;

    refreshing.value = true;
    router.reload({
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (refreshing.value = false),
    });
}

function integrationLink(item) {
    if (item.key === 'dokploy' && item.status === 'connected' && typeof window !== 'undefined') {
        return `http://${window.location.hostname}:3000`;
    }

    return item.href;
}

function isExternal(item) {
    return item.key === 'dokploy' && item.status === 'connected';
}

onMounted(pollMetrics);
usePolling(pollMetrics, 5000);

watch(
    () => props.metrics,
    (value) => {
        snapshot.value = value;
        lastUpdated.value = new Date(value.sampled_at ?? Date.now());
    },
);
</script>

<template>
    <Head title="Overview" />

    <div class="space-y-5">
        <PageHeader
            :title="`${snapshot.host.hostname} at a glance`"
            subtitle="Live telemetry and control for the Linux host running ServerDeck. No simulated fleet, no remote agents."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot :status="health.summary.overall === 'critical' ? 'offline' : health.summary.overall === 'warning' ? 'degraded' : 'online'" pulse />
                    {{ snapshot.host.os }}
                    <span class="text-dim">·</span>
                    kernel {{ snapshot.host.kernel }}
                    <span class="text-dim">·</span>
                    up {{ uptime }}
                    <span class="text-dim">·</span>
                    updated {{ formatTime(lastUpdated) }}
                </span>
            </template>

            <template #actions>
                <button type="button" class="btn btn-sm" :disabled="refreshing" title="Refresh host data" @click="refresh">
                    <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': refreshing }" />
                    Refresh
                </button>
                <Link v-if="permissions.useTerminal" href="/terminal" class="btn btn-primary btn-sm">
                    <TerminalSquare class="h-3.5 w-3.5" />
                    Open terminal
                </Link>
            </template>
        </PageHeader>

        <div
            v-if="liveError"
            class="flex items-center gap-2 rounded-xl border border-amber/25 bg-amber/10 px-4 py-2.5 text-[11px] text-amber"
        >
            <AlertTriangle class="h-3.5 w-3.5" />
            {{ liveError }}
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                label="CPU utilisation"
                :value="`${Number(snapshot.cpu.usage).toFixed(1)}%`"
                :subtitle="`Load ${snapshot.cpu.load_1} · ${snapshot.host.cpu_threads} threads`"
                :icon="Cpu"
                :tone="percentTone(snapshot.cpu.usage)"
                :spark="history.map((point) => point.cpu)"
                spark-color="#2ee6a8"
                :detail="`${snapshot.host.cpu_model} · ${snapshot.host.cpu_cores} cores`"
            />
            <StatCard
                label="Memory"
                :value="`${Number(snapshot.memory.percent).toFixed(1)}%`"
                :subtitle="`${formatBytes(snapshot.memory.used)} of ${formatBytes(snapshot.memory.total)}`"
                :icon="MemoryStick"
                :tone="percentTone(snapshot.memory.percent)"
                :spark="history.map((point) => point.memory)"
                spark-color="#38bdf8"
                :detail="`${formatBytes(snapshot.memory.available)} available · swap ${formatBytes(snapshot.memory.swap_used)}`"
            />
            <StatCard
                label="Root filesystem"
                :value="`${Number(stats.disk).toFixed(0)}%`"
                :subtitle="`${formatBytes(snapshot.disks.root?.used)} of ${formatBytes(snapshot.disks.root?.size)}`"
                :icon="HardDrive"
                :tone="percentTone(stats.disk)"
                :detail="`${stats.pools} ZFS pool${stats.pools === 1 ? '' : 's'} · ${stats.datasets} datasets`"
            />
            <StatCard
                label="Workloads"
                :value="`${stats.containers_running} / ${stats.containers_total}`"
                :subtitle="`Docker running · ${stats.instances_running}/${stats.instances_total} Incus`"
                :icon="Container"
                tone="cyan"
                :detail="`${stats.failed_services} failed systemd service${stats.failed_services === 1 ? '' : 's'} · ${stats.processes} processes`"
            />
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <section class="panel xl:col-span-2">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Host pressure</h2>
                        <p class="mt-0.5 text-[11px] text-dim">{{ chartSubtitle }}</p>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <div class="inline-flex rounded-lg border border-line bg-panel-2/50 p-0.5">
                            <button
                                v-for="range in chartRanges"
                                :key="range.key"
                                type="button"
                                class="rounded-md px-2.5 py-1 text-[11px] font-medium transition"
                                :class="chartRange === range.key ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'"
                                :aria-pressed="chartRange === range.key"
                                @click="setChartRange(range.key)"
                            >
                                {{ range.label }}
                            </button>
                        </div>
                        <span class="badge border-line bg-panel-2 text-dim">
                            {{ chartPoints.length }} {{ chartRange === 'live' ? 'samples' : 'buckets' }}
                        </span>
                    </div>
                </div>

                <div class="px-5 pb-5 pt-4">
                    <div v-if="historyError && chartRange !== 'live'" class="mb-3 flex items-center gap-2 rounded-lg border border-amber/25 bg-amber/10 px-3 py-2 text-[11px] text-amber">
                        <AlertTriangle class="h-3.5 w-3.5 shrink-0" />
                        {{ historyError }}
                    </div>

                    <div v-if="historyLoading && chartRange !== 'live'" class="grid h-[248px] place-items-center rounded-lg border border-dashed border-line/70 text-[11px] text-dim">
                        Loading historical samples…
                    </div>
                    <div v-else-if="!chartPoints.length" class="grid h-[248px] place-items-center rounded-lg border border-dashed border-line/70">
                        <div class="text-center">
                            <Activity class="mx-auto h-5 w-5 text-dim" />
                            <p class="mt-2 text-[11px] text-muted">No samples in this window yet.</p>
                            <p class="mt-0.5 text-[11px] text-dim">The scheduler records one sample every minute.</p>
                        </div>
                    </div>
                    <AreaChart v-else :labels="chartLabels" :series="chartSeries" :height="248" :label-mode="chartLabelMode" />

                    <div v-if="chartRange === 'live'" class="mt-4 grid grid-cols-2 gap-4 border-t border-line/70 pt-4 sm:grid-cols-4">
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-dim">Network in</div>
                            <div class="mt-1 font-mono text-sm text-bright">{{ formatRate(snapshot.network.rx_rate) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-dim">Network out</div>
                            <div class="mt-1 font-mono text-sm text-bright">{{ formatRate(snapshot.network.tx_rate) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-dim">Load 1 / 5 / 15</div>
                            <div class="mt-1 font-mono text-sm text-bright">{{ snapshot.cpu.load_1 }} / {{ snapshot.cpu.load_5 }} / {{ snapshot.cpu.load_15 }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-dim">GPU</div>
                            <div class="mt-1 truncate font-mono text-[11px]" :class="snapshot.gpu.available ? 'text-accent' : 'text-amber'">{{ gpuLabel }}</div>
                        </div>
                    </div>

                    <div v-else class="mt-4 grid grid-cols-2 gap-4 border-t border-line/70 pt-4 sm:grid-cols-4">
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-dim">CPU average</div>
                            <div class="mt-1 font-mono text-sm text-bright">{{ Number(historySummary.cpu.avg).toFixed(1) }}%</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-dim">CPU peak</div>
                            <div class="mt-1 font-mono text-sm text-bright">{{ Number(historySummary.cpu.max).toFixed(1) }}%</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-dim">Memory average</div>
                            <div class="mt-1 font-mono text-sm text-bright">{{ Number(historySummary.memory.avg).toFixed(1) }}%</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-dim">Root disk average</div>
                            <div class="mt-1 font-mono text-sm text-bright">{{ Number(historySummary.disk.avg).toFixed(1) }}%</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Host health</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Live checks across hardware and services</p>
                    </div>
                    <HeartPulse class="h-4 w-4" :class="toneText[healthToneClass]" :stroke-width="1.7" />
                </div>

                <div class="p-5">
                    <div class="flex items-center gap-4">
                        <GaugeRing
                            :value="Math.round(((health.summary.ok + health.summary.info) / Math.max(1, health.summary.total)) * 100)"
                            :size="112"
                            label="Checks"
                            :tone="healthToneClass"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-bright">{{ healthText(health.summary.overall) }}</p>
                            <p class="mt-1 text-[11px] leading-5 text-muted">
                                {{ health.summary.ok }} of {{ health.summary.total }} checks are clean.
                                {{ health.summary.warning }} warning{{ health.summary.warning === 1 ? '' : 's' }},
                                {{ health.summary.critical }} critical.
                            </p>
                            <Link href="/health" class="mt-2 inline-flex items-center gap-1 text-[11px] font-medium text-accent hover:text-accent/80">
                                All checks <ArrowRight class="h-3 w-3" />
                            </Link>
                        </div>
                    </div>

                    <div v-if="activeChecks.length" class="mt-4 space-y-1.5 border-t border-line/70 pt-4">
                        <Link
                            v-for="check in activeChecks"
                            :key="check.key"
                            :href="check.href"
                            class="flex items-start gap-2 rounded-lg px-2 py-1.5 text-[11px] leading-4 transition hover:bg-white/[0.03]"
                        >
                            <StatusDot group="severity" :status="check.status === 'critical' ? 'critical' : 'warning'" class="mt-1" />
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-muted">{{ check.title }}</span>
                                <span class="block truncate text-dim">{{ check.detail }}</span>
                            </span>
                        </Link>
                    </div>
                    <div v-else class="mt-4 flex items-center gap-2 border-t border-line/70 pt-4 text-[11px] text-accent">
                        <CheckCircle2 class="h-4 w-4" /> No warnings on this host.
                    </div>
                </div>
            </section>
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <section class="panel overflow-hidden xl:col-span-2">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Storage</h2>
                        <p class="mt-0.5 text-[11px] text-dim">ZFS pools, datasets and mounted filesystems</p>
                    </div>
                    <Link href="/storage" class="text-[11px] font-medium text-accent hover:text-accent/80">Manage</Link>
                </div>

                <div class="p-5">
                    <div v-if="primaryPool" class="rounded-xl border border-line bg-panel-2/40 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <div class="flex items-center gap-2">
                                    <Database class="h-4 w-4 text-cyan" />
                                    <span class="font-mono text-sm font-medium text-bright">{{ primaryPool.name }}</span>
                                    <span class="badge border-accent/25 bg-accent/10 text-accent">{{ primaryPool.health }}</span>
                                </div>
                                <p class="mt-1 text-[11px] text-dim">
                                    {{ formatBytes(primaryPool.alloc) }} allocated · {{ formatBytes(primaryPool.free) }} free · {{ primaryPool.fragmentation }}% fragmented
                                </p>
                            </div>
                            <span class="font-mono text-lg text-bright">{{ primaryPool.capacity }}%</span>
                        </div>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/[0.05]">
                            <div
                                class="h-full rounded-full transition-all duration-700"
                                :class="{
                                    'bg-accent': primaryPool.capacity_percent < 75,
                                    'bg-amber': primaryPool.capacity_percent >= 75 && primaryPool.capacity_percent < 90,
                                    'bg-rose': primaryPool.capacity_percent >= 90,
                                }"
                                :style="{ width: `${Math.min(100, primaryPool.capacity_percent)}%` }"
                            />
                        </div>
                    </div>

                    <div v-else class="rounded-xl border border-line bg-panel-2/40 p-4 text-[11px] text-dim">
                        {{ zfs.error ?? 'No ZFS pools reported.' }}
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <div class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-dim">Mounted filesystems</div>
                            <div class="space-y-2">
                                <div v-for="disk in snapshot.disks.filesystems.slice(0, 4)" :key="disk.mount" class="rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2">
                                    <div class="flex items-center justify-between gap-2 text-[11px]">
                                        <span class="truncate font-mono text-muted">{{ disk.mount }}</span>
                                        <span class="font-mono" :class="toneText[percentTone(disk.percent)]">{{ disk.percent }}%</span>
                                    </div>
                                    <div class="mt-1 text-[11px] text-dim">{{ disk.type }} · {{ formatBytes(disk.used) }} / {{ formatBytes(disk.size) }}</div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-dim">Datasets</div>
                            <div class="space-y-2">
                                <div v-for="dataset in topDatasets.slice(0, 4)" :key="dataset.name" class="flex items-center justify-between gap-2 rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2">
                                    <span class="truncate font-mono text-[11px] text-muted">{{ dataset.name }}</span>
                                    <span class="shrink-0 font-mono text-[11px] text-dim">{{ formatBytes(dataset.used) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="panel overflow-hidden">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Installed stack</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Detected services on this host</p>
                    </div>
                    <Link href="/health" class="text-[11px] font-medium text-accent hover:text-accent/80">Details</Link>
                </div>

                <div class="divide-y divide-line/50">
                    <a
                        v-for="item in integrations"
                        :key="item.key"
                        :href="integrationLink(item)"
                        :target="isExternal(item) ? '_blank' : undefined"
                        :rel="isExternal(item) ? 'noreferrer' : undefined"
                        class="flex items-center gap-3 px-4 py-2.5 transition hover:bg-white/[0.03]"
                    >
                        <StatusDot
                            :status="item.status === 'connected' ? 'online' : item.status === 'attention' ? 'degraded' : 'maintenance'"
                            :pulse="item.status === 'attention'"
                        />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] font-medium text-bright">{{ item.name }}</span>
                                <span class="rounded bg-white/[0.05] px-1 py-0.5 font-mono text-[10px] uppercase text-dim">{{ item.category }}</span>
                            </div>
                            <p class="mt-0.5 truncate text-[11px] text-dim">{{ item.detail }}</p>
                        </div>
                        <ExternalLink v-if="isExternal(item)" class="h-3 w-3 shrink-0 text-dim" />
                        <ArrowUpRight v-else class="h-3 w-3 shrink-0 text-dim" />
                    </a>
                </div>
            </section>
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <section class="panel overflow-hidden xl:col-span-2">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Containers</h2>
                        <p class="mt-0.5 text-[11px] text-dim">{{ docker.counts.running }} running Docker containers and {{ incus.counts.running }} Incus instances</p>
                    </div>
                    <Link href="/docker" class="text-[11px] font-medium text-accent hover:text-accent/80">All workloads</Link>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[680px]">
                        <thead>
                            <tr class="border-b border-line/70">
                                <th class="table-head">Container</th>
                                <th class="table-head">Runtime</th>
                                <th class="table-head">State</th>
                                <th class="table-head">CPU</th>
                                <th class="table-head">Memory</th>
                                <th class="table-head text-right">Uptime</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/50">
                            <tr v-for="container in containers" :key="container.full_id" class="hover-row">
                                <td class="table-cell">
                                    <div class="flex items-center gap-2">
                                        <Container class="h-3.5 w-3.5 shrink-0 text-cyan" />
                                        <div class="min-w-0">
                                            <div class="truncate text-[11px] font-medium text-bright">{{ shortContainer(container.name) }}</div>
                                            <div class="truncate font-mono text-[11px] text-dim">{{ container.image }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="table-cell text-[11px] text-muted">Docker</td>
                                <td class="table-cell"><span class="badge border-accent/25 bg-accent/10 text-accent">{{ container.health || container.state }}</span></td>
                                <td class="table-cell font-mono text-[11px] text-muted">{{ container.cpu ?? '—' }}</td>
                                <td class="table-cell font-mono text-[11px] text-muted">{{ container.memory ?? '—' }}</td>
                                <td class="table-cell text-right text-[11px] text-dim">{{ container.running_for || '—' }}</td>
                            </tr>
                            <tr v-for="instance in instances" :key="instance.name" class="hover-row">
                                <td class="table-cell">
                                    <div class="flex items-center gap-2">
                                        <Boxes class="h-3.5 w-3.5 shrink-0 text-accent" />
                                        <div class="min-w-0">
                                            <div class="truncate text-[11px] font-medium text-bright">{{ instance.name }}</div>
                                            <div class="truncate font-mono text-[11px] text-dim">{{ instance.image || instance.type }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="table-cell text-[11px] text-muted">Incus</td>
                                <td class="table-cell"><span class="badge border-accent/25 bg-accent/10 text-accent">{{ instance.status }}</span></td>
                                <td class="table-cell font-mono text-[11px] text-muted">{{ instance.memory_percent }}%</td>
                                <td class="table-cell font-mono text-[11px] text-muted">{{ formatBytes(instance.memory_used) }}</td>
                                <td class="table-cell text-right text-[11px] text-dim">{{ instance.ipv4?.[0] ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel overflow-hidden">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">systemd</h2>
                        <p class="mt-0.5 text-[11px] text-dim">{{ systemd.counts.running }} active · {{ systemd.counts.failed }} failed</p>
                    </div>
                    <Link href="/services" class="text-[11px] font-medium text-accent hover:text-accent/80">Services</Link>
                </div>

                <div v-if="failedUnits.length" class="divide-y divide-line/50">
                    <Link
                        v-for="unit in failedUnits"
                        :key="unit.unit"
                        href="/services?filter=failed"
                        class="flex items-start gap-2.5 border-l-2 border-l-rose px-4 py-3 transition hover:bg-white/[0.03]"
                    >
                        <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0 text-rose" />
                        <div class="min-w-0">
                            <p class="font-mono text-[11px] font-medium text-bright">{{ unit.unit }}</p>
                            <p class="mt-0.5 truncate text-[11px] text-dim">{{ unit.description }}</p>
                        </div>
                    </Link>
                </div>
                <div v-else class="flex items-center gap-2 px-4 py-6 text-[11px] text-accent">
                    <CheckCircle2 class="h-4 w-4" /> All systemd units are healthy.
                </div>

                <div class="border-t border-line/70 bg-panel-2/20 px-4 py-3">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-dim">GPU telemetry</span>
                        <span :class="snapshot.gpu.available ? 'text-accent' : 'text-amber'">{{ snapshot.gpu.available ? 'available' : 'unavailable' }}</span>
                    </div>
                    <div class="mt-1.5 flex items-center justify-between text-[11px]">
                        <span class="text-dim">Network interfaces</span>
                        <span class="font-mono text-muted">{{ snapshot.network.interfaces.length }}</span>
                    </div>
                    <div class="mt-1.5 flex items-center justify-between text-[11px]">
                        <span class="text-dim">Processes</span>
                        <span class="font-mono text-muted">{{ formatCount(snapshot.processes) }}</span>
                    </div>
                </div>
            </section>
        </div>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Recent privileged activity</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Every action through ServerDeck is recorded</p>
                </div>
                <Link v-if="permissions.viewAudit" href="/audit" class="text-[11px] font-medium text-accent hover:text-accent/80">Audit log</Link>
            </div>

            <div class="grid gap-0 divide-y divide-line/50 sm:grid-cols-2 sm:divide-x lg:grid-cols-4 lg:divide-y-0">
                <div v-for="entry in activity.slice(0, 8)" :key="entry.id" class="flex gap-3 px-4 py-3.5">
                    <UserAvatar :name="entry.user?.name ?? 'System'" size="xs" class="mt-0.5" />
                    <div class="min-w-0 flex-1">
                        <p class="line-clamp-2 text-[11px] leading-5 text-muted">{{ entry.description }}</p>
                        <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-dim">
                            <span>{{ entry.user?.username ?? entry.user?.name ?? 'System' }}</span>
                            <span>·</span>
                            <span :title="formatDateTime(entry.created_at)">{{ timeAgo(entry.created_at) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
