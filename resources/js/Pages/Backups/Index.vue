<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Archive,
    Boxes,
    CalendarClock,
    Clock,
    Database,
    History,
    Pause,
    Pencil,
    Play,
    Plus,
    RefreshCw,
    RotateCcw,
    Trash2,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { formatBytes } from '@/lib/host';
import { formatDateTime, timeAgo } from '@/lib/format';
import { pushToast } from '@/lib/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    backups: { type: Object, required: true },
});

const page = usePage();
const canOperate = computed(() => page.props.permissions?.operate ?? false);
const jobs = computed(() => props.backups.jobs ?? []);
const runs = computed(() => props.backups.runs ?? []);
const inventory = computed(() => props.backups.inventory ?? {});
const counts = computed(() => props.backups.counts ?? {});
const datasets = computed(() => inventory.value.datasets ?? []);
const instances = computed(() => inventory.value.instances ?? []);
const zfsSnapshots = computed(() => inventory.value.zfs_snapshots ?? []);
const incusSnapshots = computed(() => inventory.value.incus_snapshots ?? []);

const snapshotKind = ref('zfs');
const snapshotSearch = ref('');
const snapshotOpen = ref(false);
const jobOpen = ref(false);
const editingJob = ref(null);
const pending = ref(null);
const working = ref(false);

const weekdays = [
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
    { value: 7, label: 'Sunday' },
];

const jobForm = useForm({
    name: '',
    kind: 'zfs',
    target: '',
    frequency: 'daily',
    hour: 3,
    minute: 0,
    weekday: 1,
    recursive: true,
    retention: 7,
    enabled: true,
});

const snapshotForm = useForm({
    kind: 'zfs',
    target: '',
    recursive: true,
});

const targetsForJob = computed(() => (jobForm.kind === 'zfs' ? datasets.value : instances.value));
const targetsForSnapshot = computed(() => (snapshotForm.kind === 'zfs' ? datasets.value : instances.value));

const visibleSnapshots = computed(() => {
    const source = snapshotKind.value === 'zfs' ? zfsSnapshots.value : incusSnapshots.value;
    const query = snapshotSearch.value.trim().toLowerCase();

    if (!query) return source;

    return source.filter((snapshot) => [snapshot.target, snapshot.name]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(query)));
});

const lastSuccess = computed(() => runs.value.find((run) => run.status === 'success') ?? null);

const pendingTitle = computed(() => {
    if (!pending.value) return '';

    if (pending.value.type === 'job') return `Delete ${pending.value.job.name}?`;
    if (pending.value.type === 'restore') return `Restore ${pending.value.snapshot.name}?`;

    return `Delete ${pending.value.snapshot.name}?`;
});

const pendingDescription = computed(() => {
    if (!pending.value) return '';

    if (pending.value.type === 'job') {
        return `${pending.value.job.name} will stop running on schedule. Snapshots it already created are kept, and the run history remains in the audit trail.`;
    }

    if (pending.value.type === 'restore') {
        if (pending.value.snapshot.kind === 'zfs') {
            return `${pending.value.snapshot.target} will be rolled back to ${pending.value.snapshot.name}. Data written after that snapshot is lost, and any ZFS snapshots newer than ${pending.value.snapshot.name} are destroyed.`;
        }

        return `${pending.value.snapshot.target} will be restored to ${pending.value.snapshot.name}. Anything written after that snapshot is lost. Incus instances may need to be stopped first.`;
    }

    return `${pending.value.snapshot.name} on ${pending.value.snapshot.target} will be destroyed. This cannot be undone.`;
});

const pendingLabel = computed(() => {
    if (!pending.value) return 'Confirm';
    if (pending.value.type === 'restore') return 'Restore';

    return 'Delete';
});

function scheduleLabel(job) {
    const time = `${String(job.hour).padStart(2, '0')}:${String(job.minute).padStart(2, '0')}`;

    if (job.frequency === 'hourly') return `Hourly at :${String(job.minute).padStart(2, '0')}`;
    if (job.frequency === 'weekly') return `Weekly on ${weekdays.find((day) => day.value === job.weekday)?.label ?? 'Monday'} at ${time}`;
    if (job.frequency === 'monthly') return `Monthly on the 1st at ${time}`;

    return `Daily at ${time}`;
}

function statusTone(status) {
    if (status === 'success') return 'border-accent/25 bg-accent/10 text-accent';
    if (status === 'failed') return 'border-rose/25 bg-rose/10 text-rose';
    if (status === 'running') return 'border-cyan/25 bg-cyan/10 text-cyan';

    return 'border-line bg-panel-2 text-dim';
}

function formatDuration(duration) {
    if (duration === null || duration === undefined) return '—';
    if (duration < 1000) return `${duration} ms`;

    return `${(duration / 1000).toFixed(1)} s`;
}

function openJob(job = null) {
    editingJob.value = job;
    jobForm.reset();
    jobForm.clearErrors();

    if (job) {
        jobForm.name = job.name;
        jobForm.kind = job.kind;
        jobForm.target = job.target;
        jobForm.frequency = job.frequency;
        jobForm.hour = job.hour;
        jobForm.minute = job.minute;
        jobForm.weekday = job.weekday ?? 1;
        jobForm.recursive = Boolean(job.recursive);
        jobForm.retention = job.retention;
        jobForm.enabled = Boolean(job.enabled);
    } else {
        jobForm.kind = datasets.value.length ? 'zfs' : 'incus';
        jobForm.target = (jobForm.kind === 'zfs' ? datasets.value : instances.value)[0]?.name ?? '';
        jobForm.name = jobForm.kind === 'zfs' ? 'ZFS snapshot schedule' : 'Instance snapshot schedule';
        jobForm.frequency = 'daily';
        jobForm.hour = 3;
        jobForm.minute = 0;
        jobForm.weekday = 1;
        jobForm.recursive = true;
        jobForm.retention = 7;
        jobForm.enabled = true;
    }

    jobOpen.value = true;
}

function setJobKind(kind) {
    if (jobForm.kind === kind) return;

    jobForm.kind = kind;
    jobForm.target = (kind === 'zfs' ? datasets.value : instances.value)[0]?.name ?? '';
    jobForm.clearErrors('target');
}

function submitJob() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            jobOpen.value = false;
            pushToast(editingJob.value ? 'Backup job updated.' : 'Backup job created.', 'success');
        },
    };

    if (editingJob.value) {
        jobForm.put(`/backups/jobs/${editingJob.value.id}`, options);
    } else {
        jobForm.post('/backups/jobs', options);
    }
}

function openSnapshot() {
    snapshotForm.reset();
    snapshotForm.clearErrors();
    snapshotForm.kind = datasets.value.length ? 'zfs' : 'incus';
    snapshotForm.target = (snapshotForm.kind === 'zfs' ? datasets.value : instances.value)[0]?.name ?? '';
    snapshotForm.recursive = true;
    snapshotOpen.value = true;
}

function setSnapshotKind(kind) {
    if (snapshotForm.kind === kind) return;

    snapshotForm.kind = kind;
    snapshotForm.target = (kind === 'zfs' ? datasets.value : instances.value)[0]?.name ?? '';
}

function submitSnapshot() {
    snapshotForm.post('/backups/snapshots', {
        preserveScroll: true,
        onSuccess: () => {
            snapshotOpen.value = false;
            pushToast('Snapshot created.', 'success');
        },
    });
}

function runJob(job) {
    working.value = true;
    router.post(`/backups/jobs/${job.id}/run`, {}, {
        preserveScroll: true,
        onSuccess: () => pushToast(`${job.name} completed.`, 'success'),
        onError: (errors) => pushToast(Object.values(errors)[0] ?? 'The backup job failed.', 'error'),
        onFinish: () => (working.value = false),
    });
}

function toggleJob(job) {
    router.post(`/backups/jobs/${job.id}/toggle`, { enabled: !job.enabled }, {
        preserveScroll: true,
        onSuccess: () => pushToast(`${job.name} ${job.enabled ? 'paused' : 'enabled'}.`, 'success'),
    });
}

function requestJobDeletion(job) {
    pending.value = { type: 'job', job };
}

function requestSnapshotDeletion(snapshot) {
    pending.value = { type: 'delete', snapshot };
}

function requestSnapshotRestore(snapshot) {
    pending.value = { type: 'restore', snapshot };
}

function confirmPending() {
    if (!pending.value) return;

    working.value = true;

    if (pending.value.type === 'job') {
        router.delete(`/backups/jobs/${pending.value.job.id}`, {
            preserveScroll: true,
            onSuccess: () => pushToast('Backup job deleted.', 'success'),
            onFinish: () => {
                working.value = false;
                pending.value = null;
            },
        });

        return;
    }

    const endpoint = pending.value.type === 'restore' ? '/backups/snapshots/restore' : '/backups/snapshots/delete';
    const snapshot = pending.value.snapshot;

    router.post(endpoint, {
        kind: snapshot.kind,
        target: snapshot.target,
        snapshot: snapshot.name,
    }, {
        preserveScroll: true,
        onSuccess: () => pushToast(pending.value.type === 'restore' ? 'Snapshot restored.' : 'Snapshot deleted.', 'success'),
        onError: (errors) => pushToast(Object.values(errors)[0] ?? 'The snapshot action failed.', 'error'),
        onFinish: () => {
            working.value = false;
            pending.value = null;
        },
    });
}
</script>

<template>
    <Head title="Backups" />

    <div class="space-y-5">
        <PageHeader
            title="Backups & snapshots"
            subtitle="Scheduled and on-demand ZFS and Incus snapshots with retention, restore and run history."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot :status="inventory.available ? 'online' : 'offline'" pulse />
                    {{ counts.jobs ?? 0 }} jobs
                    <span class="text-dim">·</span>
                    {{ counts.snapshots ?? 0 }} snapshots
                    <span v-if="counts.failing" class="text-dim">·</span>
                    <span v-if="counts.failing" class="text-rose">{{ counts.failing }} failing</span>
                </span>
            </template>
            <template #actions>
                <button v-if="canOperate" type="button" class="btn btn-primary btn-sm" @click="openJob()">
                    <Plus class="h-3.5 w-3.5" /> New schedule
                </button>
                <button v-if="canOperate" type="button" class="btn btn-sm" @click="openSnapshot()">
                    <Archive class="h-3.5 w-3.5" /> Snapshot now
                </button>
                <button type="button" class="btn btn-sm" @click="router.reload({ preserveScroll: true, preserveState: true })">
                    <RefreshCw class="h-3.5 w-3.5" /> Refresh
                </button>
            </template>
        </PageHeader>

        <div v-if="!inventory.available" class="flex items-start gap-2.5 rounded-xl border border-rose/25 bg-rose/10 px-4 py-3 text-[11px] leading-5 text-rose">
            <Database class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ inventory.error }}</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Schedules" :value="counts.jobs ?? 0" :subtitle="`${counts.enabled ?? 0} enabled`" :icon="CalendarClock" tone="cyan" detail="Automated snapshot jobs" />
            <StatCard label="Snapshots" :value="counts.snapshots ?? 0" :subtitle="`${inventory.zfs_snapshot_count ?? 0} ZFS · ${inventory.incus_snapshot_count ?? 0} Incus`" :icon="Archive" tone="accent" detail="Visible in the inventory below" />
            <StatCard label="Last success" :value="lastSuccess ? timeAgo(lastSuccess.finished_at ?? lastSuccess.started_at) : 'Never'" :subtitle="lastSuccess ? `${lastSuccess.action} on ${lastSuccess.target}` : 'No successful runs yet'" :icon="History" tone="cyan" detail="Most recent completed action" />
            <StatCard label="Failing schedules" :value="counts.failing ?? 0" :subtitle="counts.failing ? 'Review the run history' : 'All schedules healthy'" :icon="Clock" :tone="counts.failing ? 'rose' : 'accent'" detail="Last run failed" />
        </div>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Snapshot schedules</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Retention keeps the newest snapshots created by each schedule and prunes the rest.</p>
                </div>
                <span class="badge border-line bg-panel-2 text-dim">{{ jobs.length }} total</span>
            </div>

            <div v-if="jobs.length" class="overflow-x-auto">
                <table class="w-full min-w-[880px] text-left text-[11px]">
                    <thead class="border-y border-line/70 text-[10px] uppercase tracking-wider text-dim">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Schedule</th>
                            <th class="px-3 py-2.5 font-medium">Target</th>
                            <th class="px-3 py-2.5 font-medium">Cadence</th>
                            <th class="px-3 py-2.5 font-medium">Retention</th>
                            <th class="px-3 py-2.5 font-medium">Last run</th>
                            <th class="px-3 py-2.5 font-medium">Next run</th>
                            <th class="px-5 py-2.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <tr v-for="job in jobs" :key="job.id" class="transition hover:bg-white/[0.02]">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="grid h-7 w-7 place-items-center rounded-md border" :class="job.kind === 'zfs' ? 'border-cyan/20 bg-cyan/10 text-cyan' : 'border-accent/20 bg-accent/10 text-accent'">
                                        <Database v-if="job.kind === 'zfs'" class="h-3.5 w-3.5" />
                                        <Boxes v-else class="h-3.5 w-3.5" />
                                    </span>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="truncate font-medium text-bright">{{ job.name }}</span>
                                            <span class="badge" :class="job.enabled ? 'border-accent/25 bg-accent/10 text-accent' : 'border-line bg-panel-2 text-dim'">
                                                {{ job.enabled ? 'enabled' : 'paused' }}
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-[11px] text-dim">{{ job.kind === 'zfs' ? 'ZFS dataset' : 'Incus instance' }}<span v-if="job.recursive"> · recursive</span></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3 font-mono text-muted">{{ job.target }}</td>
                            <td class="px-3 py-3 text-muted">{{ scheduleLabel(job) }}</td>
                            <td class="px-3 py-3 text-muted">{{ job.retention }} kept</td>
                            <td class="px-3 py-3">
                                <div v-if="job.last_run_at" class="space-y-1">
                                    <span class="badge" :class="statusTone(job.last_status)">{{ job.last_status ?? 'unknown' }}</span>
                                    <p class="text-[11px] text-dim" :title="formatDateTime(job.last_run_at)">{{ timeAgo(job.last_run_at) }}</p>
                                </div>
                                <span v-else class="text-dim">Never</span>
                            </td>
                            <td class="px-3 py-3 text-muted">{{ job.enabled && job.next_run_at ? formatDateTime(job.next_run_at) : '—' }}</td>
                            <td class="px-5 py-3">
                                <div v-if="canOperate" class="flex items-center justify-end gap-1">
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-cyan/40 hover:text-cyan" title="Run now" :disabled="working" @click="runJob(job)">
                                        <Play class="h-3 w-3" />
                                    </button>
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-amber/40 hover:text-amber" :title="job.enabled ? 'Pause schedule' : 'Enable schedule'" @click="toggleJob(job)">
                                        <component :is="job.enabled ? Pause : Play" class="h-3 w-3" />
                                    </button>
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-accent/40 hover:text-accent" title="Edit schedule" @click="openJob(job)">
                                        <Pencil class="h-3 w-3" />
                                    </button>
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-rose/40 hover:text-rose" title="Delete schedule" @click="requestJobDeletion(job)">
                                        <Trash2 class="h-3 w-3" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="p-5">
                <EmptyState :icon="CalendarClock" title="No snapshot schedules" :description="canOperate ? 'Create a schedule to snapshot ZFS datasets or Incus instances automatically.' : 'No backup schedules have been configured yet.'">
                    <button v-if="canOperate" type="button" class="btn btn-primary btn-sm" @click="openJob()">
                        <Plus class="h-3 w-3" /> Create schedule
                    </button>
                </EmptyState>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Snapshot inventory</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Restore or destroy snapshots that exist on this host right now.</p>
                    </div>
                    <div class="inline-flex rounded-lg border border-line bg-panel-2/50 p-0.5">
                        <button type="button" class="rounded-md px-2.5 py-1 text-[11px] font-medium transition" :class="snapshotKind === 'zfs' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'" @click="snapshotKind = 'zfs'">
                            ZFS · {{ inventory.zfs_snapshot_count ?? 0 }}
                        </button>
                        <button type="button" class="rounded-md px-2.5 py-1 text-[11px] font-medium transition" :class="snapshotKind === 'incus' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'" @click="snapshotKind = 'incus'">
                            Incus · {{ inventory.incus_snapshot_count ?? 0 }}
                        </button>
                    </div>
                </div>

                <div class="border-b border-line/60 px-5 py-3">
                    <input v-model="snapshotSearch" class="input" placeholder="Filter by target or snapshot name…" autocomplete="off" spellcheck="false" />
                </div>

                <div v-if="visibleSnapshots.length" class="max-h-[420px] overflow-y-auto">
                    <div v-for="snapshot in visibleSnapshots" :key="`${snapshot.kind}:${snapshot.full}`" class="flex items-start justify-between gap-3 border-b border-line/40 px-5 py-3 last:border-b-0">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate font-mono text-[11px] font-medium text-bright">{{ snapshot.name }}</span>
                                <span v-if="snapshot.name.startsWith('serverdeck-')" class="badge border-cyan/25 bg-cyan/10 text-cyan">managed</span>
                                <span v-else class="badge border-line bg-panel-2 text-dim">external</span>
                            </div>
                            <p class="mt-1 truncate font-mono text-[11px] text-dim">{{ snapshot.target }}</p>
                            <p class="mt-0.5 text-[11px] text-dim">
                                {{ snapshot.created_at ? (typeof snapshot.created_at === 'number' ? formatDateTime(snapshot.created_at * 1000) : formatDateTime(snapshot.created_at)) : 'unknown date' }}
                                <template v-if="snapshot.used"> · {{ formatBytes(snapshot.used) }}</template>
                                <template v-if="snapshot.stateful"> · stateful</template>
                            </p>
                        </div>
                        <div v-if="canOperate" class="flex shrink-0 items-center gap-1">
                            <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-amber/40 hover:text-amber" title="Restore snapshot" @click="requestSnapshotRestore(snapshot)">
                                <RotateCcw class="h-3 w-3" />
                            </button>
                            <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-rose/40 hover:text-rose" title="Delete snapshot" @click="requestSnapshotDeletion(snapshot)">
                                <Trash2 class="h-3 w-3" />
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else class="p-5">
                    <EmptyState :icon="Archive" :title="snapshotKind === 'zfs' ? 'No ZFS snapshots' : 'No Incus snapshots'" :description="snapshotSearch ? 'No snapshots match that filter.' : 'Snapshots created manually or by a schedule will appear here.'" />
                </div>

                <div class="border-t border-line/60 bg-panel-2/20 px-5 py-3 text-[11px] leading-5 text-dim">
                    ZFS inventory lists snapshots for top-level datasets; Docker layer and Incus storage internals are hidden. Restoring a ZFS snapshot rolls the dataset back, discards newer data and destroys snapshots that are newer than the restore target.
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Run history</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Scheduled, manual, restore and delete actions recorded by the panel.</p>
                    </div>
                    <span class="badge border-line bg-panel-2 text-dim">{{ runs.length }} recent</span>
                </div>

                <div v-if="runs.length" class="max-h-[520px] overflow-y-auto">
                    <div v-for="run in runs" :key="run.id" class="border-b border-line/40 px-5 py-3 last:border-b-0">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="badge" :class="statusTone(run.status)">{{ run.status }}</span>
                                <span class="text-[11px] font-medium text-bright">{{ run.action }}</span>
                                <span class="font-mono text-[11px] text-muted">{{ run.target }}</span>
                            </div>
                            <span class="text-[11px] text-dim">{{ formatDateTime(run.started_at) }}</span>
                        </div>
                        <p v-if="run.snapshot" class="mt-1 truncate font-mono text-[11px] text-dim">{{ run.snapshot }}</p>
                        <p class="mt-1 text-[11px] leading-5" :class="run.status === 'failed' ? 'text-rose' : 'text-muted'">
                            {{ run.message || 'No details were recorded.' }}
                        </p>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-dim">
                            <span v-if="run.job">Schedule {{ run.job.name }}</span>
                            <span v-if="run.job" class="text-line-bright">·</span>
                            <span>{{ run.user?.name ?? 'system' }}</span>
                            <span class="text-line-bright">·</span>
                            <span>{{ formatDuration(run.duration_ms) }}</span>
                        </div>
                    </div>
                </div>

                <div v-else class="p-5">
                    <EmptyState :icon="History" title="No snapshot activity yet" description="Run a schedule or create a manual snapshot to start the history." />
                </div>
            </section>
        </div>

        <Modal :show="jobOpen" :title="editingJob ? `Edit ${editingJob.name}` : 'New snapshot schedule'" description="Snapshots are created by the host scheduler and pruned to the retention count." max-width="max-w-xl" @close="jobOpen = false">
            <form class="space-y-4" @submit.prevent="submitJob">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="job-name" class="label">Name</label>
                        <input id="job-name" v-model="jobForm.name" class="input" maxlength="100" placeholder="Nightly storage snapshot" autocomplete="off" />
                        <p v-if="jobForm.errors.name" class="mt-1 text-[11px] text-rose">{{ jobForm.errors.name }}</p>
                    </div>
                    <div>
                        <div class="label">Snapshot type</div>
                        <div class="inline-flex w-full rounded-lg border border-line bg-panel-2/50 p-0.5">
                            <button type="button" class="flex-1 rounded-md px-2.5 py-1.5 text-[11px] font-medium transition" :class="jobForm.kind === 'zfs' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'" @click="setJobKind('zfs')">
                                ZFS dataset
                            </button>
                            <button type="button" class="flex-1 rounded-md px-2.5 py-1.5 text-[11px] font-medium transition" :class="jobForm.kind === 'incus' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'" @click="setJobKind('incus')">
                                Incus instance
                            </button>
                        </div>
                    </div>
                </div>

                <div>
                    <label for="job-target" class="label">Target</label>
                    <select id="job-target" v-model="jobForm.target" class="input">
                        <option value="" disabled>{{ jobForm.kind === 'zfs' ? 'Choose a dataset' : 'Choose an instance' }}</option>
                        <option v-for="target in targetsForJob" :key="target.name" :value="target.name">
                            {{ target.name }}{{ target.mountpoint ? ` · ${target.mountpoint}` : '' }}{{ target.status ? ` · ${target.status}` : '' }}
                        </option>
                    </select>
                    <p v-if="jobForm.errors.target" class="mt-1 text-[11px] text-rose">{{ jobForm.errors.target }}</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="job-frequency" class="label">Frequency</label>
                        <select id="job-frequency" v-model="jobForm.frequency" class="input">
                            <option value="hourly">Hourly</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    <div v-if="jobForm.frequency !== 'hourly'">
                        <label for="job-hour" class="label">Hour (UTC)</label>
                        <input id="job-hour" v-model.number="jobForm.hour" class="input" type="number" min="0" max="23" />
                    </div>
                    <div>
                        <label for="job-minute" class="label">Minute</label>
                        <input id="job-minute" v-model.number="jobForm.minute" class="input" type="number" min="0" max="59" />
                    </div>
                    <div v-if="jobForm.frequency === 'weekly'">
                        <label for="job-weekday" class="label">Day</label>
                        <select id="job-weekday" v-model.number="jobForm.weekday" class="input">
                            <option v-for="day in weekdays" :key="day.value" :value="day.value">{{ day.label }}</option>
                        </select>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="job-retention" class="label">Retention</label>
                        <input id="job-retention" v-model.number="jobForm.retention" class="input" type="number" min="1" max="500" />
                        <p class="mt-1 text-[11px] leading-4 text-dim">Number of snapshots to keep. Older ones are pruned after each run.</p>
                        <p v-if="jobForm.errors.retention" class="mt-1 text-[11px] text-rose">{{ jobForm.errors.retention }}</p>
                    </div>
                    <div class="space-y-2">
                        <label v-if="jobForm.kind === 'zfs'" class="flex items-start gap-2.5 rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2.5">
                            <input v-model="jobForm.recursive" type="checkbox" class="mt-0.5 h-3.5 w-3.5 rounded border-line bg-panel-2" />
                            <span>
                                <span class="block text-[11px] font-medium text-bright">Recursive</span>
                                <span class="mt-0.5 block text-[11px] leading-4 text-dim">Include all child datasets in the snapshot.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2.5 rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2.5">
                            <input v-model="jobForm.enabled" type="checkbox" class="mt-0.5 h-3.5 w-3.5 rounded border-line bg-panel-2" />
                            <span>
                                <span class="block text-[11px] font-medium text-bright">Enabled</span>
                                <span class="mt-0.5 block text-[11px] leading-4 text-dim">Start running on the next matching slot.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="rounded-lg border border-line/60 bg-panel-2/20 px-3 py-2.5 text-[11px] leading-5 text-dim">
                    Times use the host clock in UTC. A missed slot runs as soon as the scheduler next wakes up.
                </div>
            </form>

            <template #footer>
                <button type="button" class="btn btn-sm" :disabled="jobForm.processing" @click="jobOpen = false">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" :disabled="jobForm.processing" @click="submitJob">
                    <span v-if="jobForm.processing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                    {{ editingJob ? 'Save schedule' : 'Create schedule' }}
                </button>
            </template>
        </Modal>

        <Modal :show="snapshotOpen" title="Create a snapshot now" description="Take an immediate snapshot without changing any schedule." max-width="max-w-lg" @close="snapshotOpen = false">
            <form class="space-y-4" @submit.prevent="submitSnapshot">
                <div>
                    <div class="label">Snapshot type</div>
                    <div class="inline-flex w-full rounded-lg border border-line bg-panel-2/50 p-0.5">
                        <button type="button" class="flex-1 rounded-md px-2.5 py-1.5 text-[11px] font-medium transition" :class="snapshotForm.kind === 'zfs' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'" @click="setSnapshotKind('zfs')">
                            ZFS dataset
                        </button>
                        <button type="button" class="flex-1 rounded-md px-2.5 py-1.5 text-[11px] font-medium transition" :class="snapshotForm.kind === 'incus' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'" @click="setSnapshotKind('incus')">
                            Incus instance
                        </button>
                    </div>
                </div>

                <div>
                    <label for="snapshot-target" class="label">Target</label>
                    <select id="snapshot-target" v-model="snapshotForm.target" class="input">
                        <option value="" disabled>{{ snapshotForm.kind === 'zfs' ? 'Choose a dataset' : 'Choose an instance' }}</option>
                        <option v-for="target in targetsForSnapshot" :key="target.name" :value="target.name">
                            {{ target.name }}{{ target.mountpoint ? ` · ${target.mountpoint}` : '' }}{{ target.status ? ` · ${target.status}` : '' }}
                        </option>
                    </select>
                    <p v-if="snapshotForm.errors.target" class="mt-1 text-[11px] text-rose">{{ snapshotForm.errors.target }}</p>
                </div>

                <label v-if="snapshotForm.kind === 'zfs'" class="flex items-start gap-2.5 rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2.5">
                    <input v-model="snapshotForm.recursive" type="checkbox" class="mt-0.5 h-3.5 w-3.5 rounded border-line bg-panel-2" />
                    <span>
                        <span class="block text-[11px] font-medium text-bright">Recursive</span>
                        <span class="mt-0.5 block text-[11px] leading-4 text-dim">Snapshot every child dataset below the chosen target.</span>
                    </span>
                </label>

                <div class="rounded-lg border border-line/60 bg-panel-2/20 px-3 py-2.5 text-[11px] leading-5 text-dim">
                    Snapshot names are generated automatically and start with <span class="font-mono text-muted">serverdeck-manual-</span> so they are easy to identify.
                </div>
            </form>

            <template #footer>
                <button type="button" class="btn btn-sm" :disabled="snapshotForm.processing" @click="snapshotOpen = false">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" :disabled="snapshotForm.processing || !snapshotForm.target" @click="submitSnapshot">
                    <span v-if="snapshotForm.processing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                    Create snapshot
                </button>
            </template>
        </Modal>

        <ConfirmDialog
            :show="Boolean(pending)"
            :title="pendingTitle"
            :description="pendingDescription"
            :confirm-label="pendingLabel"
            :danger="pending?.type !== 'restore'"
            :loading="working"
            @close="pending = null"
            @confirm="confirmPending"
        />
    </div>
</template>
