<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Ban,
    ChevronDown,
    Disc3,
    HardDrive,
    Play,
    RefreshCw,
    Thermometer,
    TriangleAlert,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { formatBytes, formatCount } from '@/lib/host';
import { formatDateTime, timeAgo } from '@/lib/format';
import { pushToast } from '@/lib/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    smart: { type: Object, required: true },
});

const page = usePage();
const canOperate = computed(() => page.props.permissions?.operate ?? false);

const devices = computed(() => props.smart.devices ?? []);
const counts = computed(() => props.smart.counts ?? { total: 0, ok: 0, warning: 0, critical: 0, info: 0 });
const attentionDevices = computed(() => devices.value.filter((device) => ['warning', 'critical'].includes(device.status)));
const spinning = computed(() => devices.value.filter((device) => device.rotational).length);
const solidState = computed(() => devices.value.length - spinning.value);
const hottest = computed(() => devices.value
    .filter((device) => device.temperature !== null && device.temperature !== undefined)
    .sort((a, b) => b.temperature - a.temperature)[0] ?? null);
const zfsMembers = computed(() => devices.value.filter((device) => device.zfs_pools?.length).length);

const expanded = ref([]);
const scanWorking = ref(false);
const testWorking = ref({});

const statusClasses = {
    ok: 'border-accent/25 bg-accent/10 text-accent',
    warning: 'border-amber/25 bg-amber/10 text-amber',
    critical: 'border-rose/25 bg-rose/10 text-rose',
    info: 'border-cyan/25 bg-cyan/10 text-cyan',
};

const statusLabels = {
    ok: 'Healthy',
    warning: 'Warning',
    critical: 'Critical',
    info: 'No SMART',
};

function toggleExpanded(name) {
    expanded.value = expanded.value.includes(name)
        ? expanded.value.filter((value) => value !== name)
        : [...expanded.value, name];
}

function roleLabel(device) {
    if (device.root_disk) return 'Root disk';
    if (device.zfs_pools?.length) return `ZFS · ${device.zfs_pools.join(', ')}`;
    return 'Unassigned';
}

function roleClass(device) {
    if (device.root_disk) return 'border-cyan/25 bg-cyan/10 text-cyan';
    if (device.zfs_pools?.length) return 'border-accent/25 bg-accent/10 text-accent';
    return 'border-line bg-panel-2 text-dim';
}

function tempClass(device) {
    if (device.temperature === null || device.temperature === undefined) return 'text-dim';
    const warning = device.rotational ? 50 : 65;
    const critical = device.rotational ? 55 : 75;
    if (device.temperature >= critical) return 'text-rose';
    if (device.temperature >= warning) return 'text-amber';
    return 'text-muted';
}

function powerOnYears(hours) {
    if (hours === null || hours === undefined) return null;
    const years = hours / 8760;

    return years >= 1 ? `${years.toFixed(1)} yr` : `${Math.max(1, Math.round(hours / 24))} d`;
}

function wearLabel(device) {
    if (device.percentage_used !== null && device.percentage_used !== undefined) {
        return `${device.percentage_used}% endurance used`;
    }

    if (device.wear_leveling_value !== null && device.wear_leveling_value !== undefined) {
        return `${device.wear_leveling_value}% indicator`;
    }

    if (device.wear_leveling !== null && device.wear_leveling !== undefined) {
        return `Raw ${formatCount(device.wear_leveling)}`;
    }

    return '—';
}

function selfTestLabel(device) {
    const test = device.self_test;

    if (!test) return 'Never run';

    if (test.in_progress) {
        return `Running · ${test.remaining_percent ?? '?'}% left`;
    }

    return test.status;
}

function selfTestClass(device) {
    const test = device.self_test;

    if (!test) return 'border-line bg-panel-2 text-dim';
    if (test.failed) return 'border-rose/25 bg-rose/10 text-rose';
    if (test.in_progress) return 'border-cyan/25 bg-cyan/10 text-cyan';

    return 'border-accent/25 bg-accent/10 text-accent';
}

function detailItems(device) {
    return [
        { label: 'Model', value: device.model || 'Unknown' },
        { label: 'Serial', value: device.serial || '—' },
        { label: 'Firmware', value: device.firmware || '—' },
        { label: 'Protocol', value: [device.protocol, device.transport].filter(Boolean).join(' · ') || '—' },
        { label: 'Rotation', value: device.rotational ? 'Spinning disk' : 'Solid state' },
        { label: 'SMART', value: device.smart_supported ? (device.smart_enabled ? 'Enabled' : 'Disabled') : 'Not supported' },
        { label: 'Reallocated', value: device.reallocated_sectors ?? '—' },
        { label: 'Pending', value: device.pending_sectors ?? '—' },
        { label: 'Uncorrectable', value: device.uncorrectable_sectors ?? '—' },
        { label: 'CRC errors', value: device.crc_errors ?? '—' },
        { label: 'Wear', value: wearLabel(device) },
        { label: 'Error log', value: device.error_log_count ?? '—' },
        { label: 'ZFS pools', value: device.zfs_pools?.join(', ') || '—' },
        { label: 'Mount points', value: device.mounts?.join(', ') || '—' },
    ];
}

function scanNow() {
    scanWorking.value = true;

    router.post('/disks/scan', {}, {
        preserveScroll: true,
        onFinish: () => (scanWorking.value = false),
    });
}

function startTest(device, mode) {
    testWorking.value = { ...testWorking.value, [device.path]: mode };

    router.post('/disks/test', { device: device.path, mode }, {
        preserveScroll: true,
        onError: (errors) => pushToast(Object.values(errors)[0] ?? 'The self-test could not be started.', 'error'),
        onFinish: () => {
            const next = { ...testWorking.value };
            delete next[device.path];
            testWorking.value = next;
        },
    });
}

function abortTest(device) {
    testWorking.value = { ...testWorking.value, [device.path]: 'abort' };

    router.post('/disks/test/abort', { device: device.path }, {
        preserveScroll: true,
        onError: (errors) => pushToast(Object.values(errors)[0] ?? 'The self-test could not be aborted.', 'error'),
        onFinish: () => {
            const next = { ...testWorking.value };
            delete next[device.path];
            testWorking.value = next;
        },
    });
}
</script>

<template>
    <Head title="Disk health" />

    <div class="space-y-5">
        <PageHeader
            title="Disk health"
            subtitle="SMART attributes, self-test results and ZFS membership for every physical drive on this host."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot :status="!smart.available ? 'offline' : counts.critical ? 'offline' : counts.warning ? 'degraded' : 'online'" pulse />
                    SMART
                    <span class="text-dim">·</span>
                    {{ counts.total ?? 0 }} disks
                    <span class="text-dim">·</span>
                    {{ counts.critical ?? 0 }} critical
                    <span class="text-dim">·</span>
                    {{ counts.warning ?? 0 }} warning
                </span>
            </template>
            <template #actions>
                <button type="button" class="btn btn-primary btn-sm" :disabled="scanWorking" @click="scanNow">
                    <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': scanWorking }" />
                    {{ scanWorking ? 'Scanning…' : 'Scan now' }}
                </button>
            </template>
        </PageHeader>

        <div v-if="!smart.available" class="flex items-start gap-2.5 rounded-xl border border-rose/25 bg-rose/10 px-4 py-3 text-[11px] leading-5 text-rose">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ smart.error }}</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Physical drives" :value="counts.total ?? 0" :subtitle="`${spinning} spinning · ${solidState} solid-state`" :icon="HardDrive" tone="cyan" :detail="`${zfsMembers} member${zfsMembers === 1 ? '' : 's'} of a ZFS pool`" />
            <StatCard label="Healthy" :value="counts.ok ?? 0" :subtitle="`of ${counts.total ?? 0} drives reporting telemetry`" :icon="Disc3" tone="accent" detail="SMART overall assessment passed" />
            <StatCard label="Needs attention" :value="attentionDevices.length" :subtitle="counts.critical ? `${counts.critical} critical · ${counts.warning} warning` : (counts.warning ? `${counts.warning} warning` : 'No failing attributes')" :icon="TriangleAlert" :tone="counts.critical ? 'rose' : (counts.warning ? 'amber' : 'accent')" detail="Pending, reallocated or uncorrectable sectors" />
            <StatCard label="Hottest drive" :value="hottest ? `${hottest.temperature}°C` : '—'" :subtitle="hottest ? `${hottest.name} · ${hottest.model}` : 'No temperature sensors'" :icon="Thermometer" :tone="hottest && hottest.temperature >= 50 ? 'amber' : 'cyan'" :detail="smart.scanned_at ? `Scanned ${timeAgo(smart.scanned_at * 1000)}` : 'SMART data unavailable'" />
        </div>

        <section v-if="attentionDevices.length" class="panel border-amber/20">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Drives that need attention</h2>
                    <p class="mt-0.5 text-[11px] text-dim">SMART attributes indicate wear or unstable sectors. Review and plan a replacement.</p>
                </div>
                <span class="badge border-amber/25 bg-amber/10 text-amber">{{ attentionDevices.length }}</span>
            </div>
            <div class="divide-y divide-line/40">
                <div v-for="device in attentionDevices" :key="`attention-${device.name}`" class="flex flex-wrap items-start justify-between gap-3 px-5 py-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg border" :class="device.status === 'critical' ? 'border-rose/25 bg-rose/10 text-rose' : 'border-amber/25 bg-amber/10 text-amber'">
                            <TriangleAlert class="h-4 w-4" />
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-[11px] font-medium text-bright">{{ device.name }}</span>
                                <span class="badge" :class="statusClasses[device.status]">{{ statusLabels[device.status] }}</span>
                                <span class="truncate text-[11px] text-dim">{{ device.model }}</span>
                            </div>
                            <p class="mt-1 text-[11px] leading-5" :class="device.status === 'critical' ? 'text-rose' : 'text-amber'">
                                {{ device.reasons.join(' · ') }}
                            </p>
                            <p class="mt-0.5 text-[11px] text-dim">
                                {{ roleLabel(device) }}
                                <span class="text-line-bright">·</span>
                                {{ formatBytes(device.size) }}
                                <span class="text-line-bright">·</span>
                                {{ formatCount(device.power_on_hours) }} power-on hours
                            </p>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm" @click="toggleExpanded(device.name)">
                        <ChevronDown class="h-3 w-3 transition" :class="{ 'rotate-180': expanded.includes(device.name) }" />
                        Details
                    </button>
                </div>
            </div>
        </section>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Drives</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Expand a drive to inspect attributes, self-test history and run a test.</p>
                </div>
                <span class="badge border-line bg-panel-2 text-dim">{{ devices.length }} total</span>
            </div>

            <div v-if="devices.length" class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-left text-[11px]">
                    <thead class="border-y border-line/70 text-[10px] uppercase tracking-wider text-dim">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Disk</th>
                            <th class="px-3 py-2.5 font-medium">Role</th>
                            <th class="px-3 py-2.5 font-medium">Capacity</th>
                            <th class="px-3 py-2.5 font-medium">Health</th>
                            <th class="px-3 py-2.5 font-medium">Temperature</th>
                            <th class="px-3 py-2.5 font-medium">Power-on</th>
                            <th class="px-3 py-2.5 font-medium">Last self-test</th>
                            <th class="px-5 py-2.5 text-right font-medium">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <template v-for="device in devices" :key="device.name">
                            <tr class="transition hover:bg-white/[0.02]" :class="expanded.includes(device.name) ? 'bg-white/[0.02]' : ''">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border" :class="device.rotational ? 'border-cyan/20 bg-cyan/10 text-cyan' : 'border-accent/20 bg-accent/10 text-accent'">
                                            <Disc3 class="h-4 w-4" />
                                        </span>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono font-medium text-bright">{{ device.name }}</span>
                                                <span class="text-dim">{{ device.path }}</span>
                                            </div>
                                            <p class="mt-0.5 truncate text-[11px] text-muted">{{ device.model || 'Unknown model' }}</p>
                                            <p class="truncate font-mono text-[10px] text-dim">{{ device.serial || 'No serial' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="badge" :class="roleClass(device)">{{ roleLabel(device) }}</span>
                                </td>
                                <td class="px-3 py-3 font-mono text-muted">{{ formatBytes(device.size) }}</td>
                                <td class="px-3 py-3">
                                    <span class="badge" :class="statusClasses[device.status] ?? statusClasses.info">{{ statusLabels[device.status] ?? device.status }}</span>
                                    <p v-if="device.reasons?.length" class="mt-1 max-w-[190px] truncate text-[10px] text-dim" :title="device.reasons.join(' · ')">
                                        {{ device.reasons[0] }}
                                    </p>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-1.5" :class="tempClass(device)">
                                        <Thermometer class="h-3 w-3" />
                                        <span class="font-mono">{{ device.temperature !== null && device.temperature !== undefined ? `${device.temperature}°C` : '—' }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="font-mono text-muted">{{ device.power_on_hours !== null && device.power_on_hours !== undefined ? formatCount(device.power_on_hours) + ' h' : '—' }}</span>
                                    <p v-if="powerOnYears(device.power_on_hours)" class="text-[10px] text-dim">{{ powerOnYears(device.power_on_hours) }}</p>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="badge" :class="selfTestClass(device)">{{ selfTestLabel(device) }}</span>
                                    <p v-if="device.self_test?.type" class="mt-1 text-[10px] text-dim">{{ device.self_test.type }}</p>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <button
                                            v-if="canOperate && device.self_test?.in_progress"
                                            type="button"
                                            class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-rose/40 hover:text-rose"
                                            title="Abort self-test"
                                            :disabled="Boolean(testWorking[device.path])"
                                            @click="abortTest(device)"
                                        >
                                            <Ban class="h-3 w-3" />
                                        </button>
                                        <button
                                            type="button"
                                            class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-accent/40 hover:text-accent"
                                            :title="expanded.includes(device.name) ? 'Hide details' : 'Show details'"
                                            @click="toggleExpanded(device.name)"
                                        >
                                            <ChevronDown class="h-3 w-3 transition" :class="{ 'rotate-180': expanded.includes(device.name) }" />
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="expanded.includes(device.name)" class="bg-panel-2/20">
                                <td colspan="8" class="px-5 py-4">
                                    <div class="grid gap-4 xl:grid-cols-[1.6fr_1fr]">
                                        <div class="space-y-3">
                                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                <div v-for="item in detailItems(device)" :key="`${device.name}-${item.label}`" class="rounded-lg border border-line/60 bg-panel/40 px-3 py-2">
                                                    <span class="block text-[10px] uppercase tracking-wider text-dim">{{ item.label }}</span>
                                                    <span class="mt-0.5 block truncate font-mono text-[11px] text-muted" :title="String(item.value)">{{ item.value }}</span>
                                                </div>
                                            </div>

                                            <div
                                                v-if="device.reasons?.length"
                                                class="rounded-lg border px-3 py-2.5"
                                                :class="device.status === 'critical' ? 'border-rose/25 bg-rose/10 text-rose' : (device.status === 'warning' ? 'border-amber/25 bg-amber/10 text-amber' : 'border-line bg-panel-2/30 text-dim')"
                                            >
                                                <p class="text-[11px] font-medium">SMART assessment</p>
                                                <ul class="mt-1 space-y-0.5 text-[11px] leading-5">
                                                    <li v-for="reason in device.reasons" :key="`${device.name}-${reason}`">· {{ reason }}</li>
                                                </ul>
                                            </div>

                                            <div v-if="device.messages?.length" class="rounded-lg border border-line/60 bg-panel/40 px-3 py-2.5 text-[11px] leading-5 text-dim">
                                                <p class="font-medium text-muted">smartctl messages</p>
                                                <p v-for="message in device.messages" :key="`${device.name}-${message}`" class="mt-1">{{ message }}</p>
                                            </div>
                                        </div>

                                        <div class="rounded-xl border border-line/60 bg-panel/40 p-4">
                                            <div class="flex items-center justify-between gap-2">
                                                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">Self-test</h3>
                                                <span class="badge" :class="selfTestClass(device)">{{ selfTestLabel(device) }}</span>
                                            </div>
                                            <dl class="mt-3 space-y-1.5 text-[11px]">
                                                <div class="flex items-center justify-between gap-3">
                                                    <dt class="text-dim">Last result</dt>
                                                    <dd class="truncate text-muted">{{ device.self_test?.status ?? 'Never run' }}</dd>
                                                </div>
                                                <div class="flex items-center justify-between gap-3">
                                                    <dt class="text-dim">Test type</dt>
                                                    <dd class="truncate text-muted">{{ device.self_test?.type ?? '—' }}</dd>
                                                </div>
                                                <div class="flex items-center justify-between gap-3">
                                                    <dt class="text-dim">Remaining</dt>
                                                    <dd class="text-muted">{{ device.self_test?.remaining_percent !== null && device.self_test?.remaining_percent !== undefined ? `${device.self_test.remaining_percent}%` : '—' }}</dd>
                                                </div>
                                            </dl>

                                            <div v-if="canOperate && device.smart_supported" class="mt-3 grid gap-2">
                                                <button type="button" class="btn btn-sm justify-center" :disabled="Boolean(testWorking[device.path]) || device.self_test?.in_progress" @click="startTest(device, 'short')">
                                                    <span v-if="testWorking[device.path] === 'short'" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                                    <Play v-else class="h-3 w-3" />
                                                    Short self-test
                                                </button>
                                                <button type="button" class="btn btn-sm justify-center" :disabled="Boolean(testWorking[device.path]) || device.self_test?.in_progress" @click="startTest(device, 'long')">
                                                    <span v-if="testWorking[device.path] === 'long'" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                                    <Play v-else class="h-3 w-3" />
                                                    Long self-test
                                                </button>
                                                <button v-if="device.self_test?.in_progress" type="button" class="btn btn-sm justify-center text-rose" :disabled="Boolean(testWorking[device.path])" @click="abortTest(device)">
                                                    <Ban class="h-3 w-3" /> Abort test
                                                </button>
                                            </div>

                                            <p class="mt-3 text-[11px] leading-4 text-dim">
                                                Self-tests read the whole drive. A long test can take hours and does not write data.
                                            </p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <EmptyState v-else :icon="Disc3" title="No physical drives found" description="No block devices were reported by lsblk on this host." />
        </section>

        <div class="rounded-xl border border-line/60 bg-panel-2/20 px-4 py-3 text-[11px] leading-5 text-dim">
            SMART data is cached for five minutes and refreshed on demand. Overall health can pass while individual attributes already show unstable sectors; pending and uncorrectable counts are treated as critical.
            <span v-if="smart.scanned_at" class="text-muted"> Last scan {{ formatDateTime(smart.scanned_at * 1000) }}.</span>
        </div>
    </div>
</template>
