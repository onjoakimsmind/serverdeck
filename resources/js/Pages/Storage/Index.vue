<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Database,
    HardDrive,
    Layers,
    Plus,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    Trash2,
    Waves,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import EmptyState from '@/Components/EmptyState.vue';
import MetricBar from '@/Components/MetricBar.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { formatBytes, formatCount, percentTone } from '@/lib/host';

defineOptions({ layout: AppLayout });

const props = defineProps({
    zfs: { type: Object, required: true },
    disks: { type: Object, required: true },
    block: { type: Object, required: true },
});

const page = usePage();
const canOperate = computed(() => page.props.permissions?.operate ?? false);

const toneText = { accent: 'text-accent', cyan: 'text-cyan', amber: 'text-amber', rose: 'text-rose', muted: 'text-muted' };

const modal = ref(null);
const target = ref(null);
const processing = ref(false);
const confirmation = ref('');

const form = reactive({
    pool: '',
    name: '',
    snapshot: '',
    recursive: false,
    devices: [],
});

const pools = computed(() => props.zfs.pools ?? []);
const datasets = computed(() => props.zfs.datasets ?? []);
const availableDevices = computed(() => props.block.available ?? []);
const rootDisk = computed(() => props.disks.root);
const poolCapacity = computed(() => (pools.value.length ? Math.max(...pools.value.map((pool) => Number(pool.capacity_percent ?? 0))) : 0));

function openModal(kind, item = null) {
    target.value = item;
    modal.value = kind;
    confirmation.value = '';
    form.recursive = false;
    form.devices = [];

    if (kind === 'create-dataset') {
        form.name = `${item?.name ?? pools.value[0]?.name ?? 'storage'}/`;
    }

    if (kind === 'snapshot') {
        form.name = item?.name ?? '';
        form.snapshot = `snapshot-${new Date().toISOString().slice(0, 10)}`;
    }

    if (kind === 'destroy') {
        form.name = item?.name ?? '';
    }

    if (kind === 'create-pool') {
        form.pool = '';
    }
}

function closeModal(force = false) {
    if (processing.value && !force) return;
    modal.value = null;
    target.value = null;
}

function post(payload) {
    processing.value = true;
    router.post('/storage/zfs', payload, {
        preserveScroll: true,
        onSuccess: () => closeModal(true),
        onFinish: () => (processing.value = false),
    });
}

function scrub() {
    if (!target.value) return;
    post({ action: 'scrub', pool: target.value.name });
}

function createDataset() {
    post({ action: 'create_dataset', name: form.name });
}

function snapshot() {
    post({ action: 'snapshot', name: form.name, snapshot: form.snapshot });
}

function destroyDataset() {
    post({ action: 'destroy_dataset', name: form.name, recursive: form.recursive });
}

function createPool() {
    if (confirmation.value !== form.pool) return;
    post({ action: 'create_pool', pool: form.pool, devices: form.devices });
}
</script>

<template>
    <Head title="Storage" />

    <div class="space-y-5">
        <PageHeader title="Storage & ZFS" subtitle="Pools, datasets, mounted filesystems and physical disks on this host.">
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <Database class="h-3 w-3 text-cyan" />
                    {{ pools.length }} pool{{ pools.length === 1 ? '' : 's' }} · {{ datasets.length }} datasets
                    <span class="text-dim">·</span>
                    {{ zfs.version ?? 'OpenZFS' }}
                </span>
            </template>
            <template #actions>
                <button v-if="canOperate" type="button" class="btn btn-sm" @click="openModal('create-dataset', pools[0] ?? null)">
                    <Plus class="h-3.5 w-3.5" /> Dataset
                </button>
                <button v-if="canOperate && availableDevices.length" type="button" class="btn btn-primary btn-sm" @click="openModal('create-pool')">
                    <Sparkles class="h-3.5 w-3.5" /> Create pool
                </button>
            </template>
        </PageHeader>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Pools" :value="pools.length" :subtitle="`${datasets.length} datasets`" :icon="Database" tone="cyan" :detail="zfs.counts.degraded ? `${zfs.counts.degraded} pool needs attention` : 'All pools ONLINE'" />
            <StatCard label="Pool capacity" :value="`${poolCapacity.toFixed(0)}%`" :subtitle="pools[0]?.name ?? 'No pools'" :icon="Waves" :tone="percentTone(poolCapacity)" :detail="pools[0] ? `${formatBytes(pools[0].alloc)} allocated · ${formatBytes(pools[0].free)} free` : zfs.error ?? '—'" />
            <StatCard label="Root filesystem" :value="`${rootDisk?.percent ?? 0}%`" :subtitle="`${formatBytes(rootDisk?.used)} of ${formatBytes(rootDisk?.size)}`" :icon="HardDrive" :tone="percentTone(rootDisk?.percent ?? 0)" :detail="`${rootDisk?.type ?? 'unknown'} on ${rootDisk?.mount ?? '/'}`" />
            <StatCard label="Block devices" :value="formatCount(block.devices.length)" :subtitle="`${availableDevices.length} unclaimed disk${availableDevices.length === 1 ? '' : 's'}`" :icon="Layers" tone="accent" :detail="block.error ?? 'From lsblk'" />
        </div>

        <div v-if="!zfs.available" class="flex items-start gap-2.5 rounded-xl border border-rose/25 bg-rose/10 px-4 py-3 text-[11px] leading-5 text-rose">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ zfs.error }}</span>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <section v-for="pool in pools" :key="pool.name" class="panel">
                <div class="panel-header">
                    <div class="flex items-center gap-2">
                        <Database class="h-4 w-4 text-cyan" />
                        <div>
                            <h2 class="font-mono text-sm font-semibold text-bright">{{ pool.name }}</h2>
                            <p class="mt-0.5 text-[11px] text-dim">{{ formatBytes(pool.size) }} raw · {{ pool.dedup }} dedup · {{ pool.fragmentation }}% fragmented</p>
                        </div>
                    </div>
                    <span class="badge" :class="pool.health === 'ONLINE' ? 'border-accent/25 bg-accent/10 text-accent' : 'border-rose/25 bg-rose/10 text-rose'">{{ pool.health }}</span>
                </div>
                <div class="space-y-4 p-5">
                    <MetricBar label="Capacity" :value="pool.capacity_percent" :detail="`${formatBytes(pool.alloc)} of ${formatBytes(pool.size)} allocated`" />
                    <div class="grid grid-cols-3 gap-2 text-[11px]">
                        <div class="rounded-lg border border-line/70 bg-panel-2/30 px-2.5 py-2">
                            <span class="block text-dim">Allocated</span>
                            <span class="mt-0.5 block font-mono text-muted">{{ formatBytes(pool.alloc) }}</span>
                        </div>
                        <div class="rounded-lg border border-line/70 bg-panel-2/30 px-2.5 py-2">
                            <span class="block text-dim">Free</span>
                            <span class="mt-0.5 block font-mono text-muted">{{ formatBytes(pool.free) }}</span>
                        </div>
                        <div class="rounded-lg border border-line/70 bg-panel-2/30 px-2.5 py-2">
                            <span class="block text-dim">Datasets</span>
                            <span class="mt-0.5 block font-mono text-muted">{{ datasets.filter((dataset) => dataset.name === pool.name || dataset.name.startsWith(`${pool.name}/`)).length }}</span>
                        </div>
                    </div>
                    <div v-if="canOperate" class="flex flex-wrap items-center gap-2 border-t border-line/60 pt-4">
                        <button type="button" class="btn btn-sm" @click="openModal('scrub', pool)">
                            <RefreshCw class="h-3 w-3" /> Scrub
                        </button>
                        <button type="button" class="btn btn-sm" @click="openModal('create-dataset', pool)">
                            <Plus class="h-3 w-3" /> Create dataset
                        </button>
                    </div>
                </div>
            </section>

            <section v-if="!pools.length" class="panel p-8 text-center text-xs text-dim">
                No ZFS pools are imported on this host.
            </section>
        </div>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Datasets</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Filesystems, volumes and their mount points</p>
                </div>
                <span class="badge border-line bg-panel-2 text-dim">{{ datasets.length }} total</span>
            </div>

            <div v-if="datasets.length" class="max-h-[520px] overflow-auto">
                <table class="w-full min-w-[840px]">
                    <thead class="sticky top-0 z-10 bg-panel">
                        <tr class="border-b border-line/70">
                            <th class="table-head">Dataset</th>
                            <th class="table-head">Type</th>
                            <th class="table-head">Used</th>
                            <th class="table-head">Available</th>
                            <th class="table-head">Referenced</th>
                            <th class="table-head">Mount point</th>
                            <th class="table-head">Compression</th>
                            <th class="table-head text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <tr v-for="dataset in datasets" :key="dataset.name" class="hover-row">
                            <td class="table-cell font-mono text-[11px] text-bright">{{ dataset.name }}</td>
                            <td class="table-cell text-[11px] text-muted">{{ dataset.type }}</td>
                            <td class="table-cell font-mono text-[11px] text-muted">{{ formatBytes(dataset.used) }}</td>
                            <td class="table-cell font-mono text-[11px] text-muted">{{ formatBytes(dataset.avail) }}</td>
                            <td class="table-cell font-mono text-[11px] text-dim">{{ formatBytes(dataset.refer) }}</td>
                            <td class="table-cell font-mono text-[11px] text-dim">{{ dataset.mountpoint ?? '—' }}</td>
                            <td class="table-cell text-[11px] text-dim">{{ dataset.compression }}</td>
                            <td class="table-cell">
                                <div v-if="canOperate" class="flex items-center justify-end gap-1">
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-cyan/40 hover:text-cyan" title="Create snapshot" @click="openModal('snapshot', dataset)">
                                        <Sparkles class="h-3 w-3" />
                                    </button>
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-rose/40 hover:text-rose" title="Destroy dataset" @click="openModal('destroy', dataset)">
                                        <Trash2 class="h-3 w-3" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else :icon="Database" title="No datasets" description="Create a dataset on one of the pools above." />
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="panel overflow-hidden">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Block devices</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Disks, partitions and their current use</p>
                    </div>
                    <HardDrive class="h-4 w-4 text-dim" />
                </div>
                <div class="max-h-[420px] overflow-auto">
                    <table class="w-full min-w-[560px]">
                        <thead class="sticky top-0 bg-panel">
                            <tr class="border-b border-line/70">
                                <th class="table-head">Device</th>
                                <th class="table-head">Size</th>
                                <th class="table-head">Type</th>
                                <th class="table-head">Filesystem</th>
                                <th class="table-head">Mounts</th>
                                <th class="table-head text-right">State</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/50">
                            <tr v-for="device in block.devices" :key="device.path" class="hover-row">
                                <td class="table-cell">
                                    <div class="font-mono text-[11px] text-bright">{{ device.path }}</div>
                                    <div class="text-[11px] text-dim">{{ device.model || device.name }}</div>
                                </td>
                                <td class="table-cell font-mono text-[11px] text-muted">{{ formatBytes(device.size) }}</td>
                                <td class="table-cell text-[11px] text-dim">{{ device.type }}</td>
                                <td class="table-cell font-mono text-[11px] text-dim">{{ device.fstype ?? '—' }}</td>
                                <td class="table-cell font-mono text-[11px] text-dim">{{ device.mounts?.length ? device.mounts.join(', ') : '—' }}</td>
                                <td class="table-cell text-right"><span class="badge" :class="device.used ? 'border-line bg-panel-2 text-dim' : 'border-accent/25 bg-accent/10 text-accent'">{{ device.used ? 'in use' : 'free' }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel overflow-hidden">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">zpool status</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Topology, scrub state and device errors</p>
                    </div>
                    <ShieldCheck class="h-4 w-4 text-dim" />
                </div>
                <pre class="max-h-[420px] overflow-auto p-5 font-mono text-[11px] leading-5 text-muted">{{ zfs.status || 'No pool status available.' }}</pre>
            </section>
        </div>
    </div>

    <ConfirmDialog
        :show="modal === 'scrub'"
        title="Start a scrub?"
        :description="target ? `ZFS will verify all data in ${target.name}. This is I/O intensive but safe to run online.` : ''"
        confirm-label="Start scrub"
        :danger="false"
        :loading="processing"
        @close="closeModal"
        @confirm="scrub"
    />

    <Modal :show="modal === 'create-dataset'" title="Create dataset" description="Creates a new ZFS filesystem under the selected pool." max-width="max-w-md" @close="closeModal">
        <form class="space-y-4" @submit.prevent="createDataset">
            <div>
                <label for="dataset-name" class="label">Dataset name</label>
                <input id="dataset-name" v-model="form.name" class="input font-mono" placeholder="storage/media" autocomplete="off" />
                <p class="mt-1.5 text-[11px] text-dim">Use the full dataset path, including the pool.</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line/60 pt-4">
                <button type="button" class="btn btn-sm" @click="closeModal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm" :disabled="processing || !form.name.includes('/')">
                    <span v-if="processing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                    Create dataset
                </button>
            </div>
        </form>
    </Modal>

    <Modal :show="modal === 'snapshot'" title="Create snapshot" :description="`Snapshot ${form.name}`" max-width="max-w-md" @close="closeModal">
        <form class="space-y-4" @submit.prevent="snapshot">
            <div>
                <label for="snapshot-name" class="label">Snapshot name</label>
                <input id="snapshot-name" v-model="form.snapshot" class="input font-mono" placeholder="before-upgrade" autocomplete="off" />
                <p class="mt-1.5 text-[11px] text-dim">The final name is <span class="font-mono text-muted">{{ form.name }}@{{ form.snapshot || '…' }}</span>.</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line/60 pt-4">
                <button type="button" class="btn btn-sm" @click="closeModal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm" :disabled="processing || !form.snapshot">Create snapshot</button>
            </div>
        </form>
    </Modal>

    <Modal :show="modal === 'destroy'" title="Destroy dataset" :description="`This permanently removes ${form.name}.`" max-width="max-w-md" @close="closeModal">
        <div class="space-y-4">
            <div class="flex items-start gap-2.5 rounded-lg border border-rose/25 bg-rose/10 px-3 py-2.5 text-[11px] leading-5 text-rose">
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>All files in this dataset are deleted immediately. There is no recycle bin.</span>
            </div>
            <label class="flex items-center gap-2 text-[11px] text-muted">
                <input v-model="form.recursive" type="checkbox" class="h-4 w-4 rounded border-line bg-panel-2" />
                Destroy child datasets recursively
            </label>
            <div class="flex justify-end gap-2 border-t border-line/60 pt-4">
                <button type="button" class="btn btn-sm" @click="closeModal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm" :disabled="processing" @click="destroyDataset">
                    <Trash2 class="h-3 w-3" /> Destroy dataset
                </button>
            </div>
        </div>
    </Modal>

    <Modal :show="modal === 'create-pool'" title="Create ZFS pool" description="Build a new pool from unclaimed block devices." max-width="max-w-xl" @close="closeModal">
        <form class="space-y-4" @submit.prevent="createPool">
            <div class="flex items-start gap-2.5 rounded-lg border border-amber/25 bg-amber/10 px-3 py-2.5 text-[11px] leading-5 text-amber">
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>Pool creation writes a new label to every selected device. Existing data on those disks will not be preserved.</span>
            </div>

            <div>
                <label for="pool-name" class="label">Pool name</label>
                <input id="pool-name" v-model="form.pool" class="input font-mono" placeholder="tank" autocomplete="off" />
            </div>

            <div>
                <div class="label">Devices</div>
                <div v-if="availableDevices.length" class="max-h-56 space-y-1.5 overflow-auto rounded-lg border border-line p-2">
                    <label v-for="device in availableDevices" :key="device.path" class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2 text-[11px] transition hover:bg-white/[0.03]">
                        <input v-model="form.devices" type="checkbox" :value="device.path" class="h-3.5 w-3.5 rounded border-line bg-panel-2" />
                        <span class="font-mono text-bright">{{ device.path }}</span>
                        <span class="text-dim">{{ formatBytes(device.size) }}</span>
                        <span class="truncate text-dim">{{ device.model }}</span>
                    </label>
                </div>
                <p v-else class="rounded-lg border border-line px-3 py-4 text-center text-[11px] text-dim">No unclaimed disks are available.</p>
            </div>

            <div>
                <label for="pool-confirm" class="label">Type the pool name to confirm</label>
                <input id="pool-confirm" v-model="confirmation" class="input font-mono" :placeholder="form.pool || 'tank'" autocomplete="off" />
            </div>

            <div class="flex justify-end gap-2 border-t border-line/60 pt-4">
                <button type="button" class="btn btn-sm" @click="closeModal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm" :disabled="processing || !form.pool || !form.devices.length || confirmation !== form.pool">
                    <Sparkles class="h-3 w-3" /> Create pool
                </button>
            </div>
        </form>
    </Modal>
</template>
