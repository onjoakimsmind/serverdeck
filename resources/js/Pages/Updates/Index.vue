<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Clock,
    Download,
    PackageCheck,
    PackageOpen,
    Power,
    RefreshCw,
    Server,
    ShieldAlert,
    TriangleAlert,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { formatDateTime, timeAgo } from '@/lib/format';
import { pushToast } from '@/lib/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    updates: { type: Object, required: true },
});

const page = usePage();
const canOperate = computed(() => page.props.permissions?.operate ?? false);

const packages = computed(() => props.updates.packages ?? []);
const heldBack = computed(() => props.updates.held_back ?? []);
const counts = computed(() => props.updates.counts ?? { total: 0, planned: 0, security: 0, new_packages: 0, held_back: 0 });
const rebootRequired = computed(() => Boolean(props.updates.reboot_required));
const canUpgrade = computed(() => canOperate.value && props.updates.available && counts.value.planned > 0);

const confirmOpen = ref(false);
const upgrading = ref(false);
const refreshing = ref(false);

const confirmDescription = computed(() => {
    const count = counts.value.planned ?? 0;

    return `${count} package update${count === 1 ? '' : 's'} will be installed. APT may install new dependency packages, but no packages are removed. Running services can restart while the upgrade is applied.`;
});

function refreshLists() {
    refreshing.value = true;

    router.post('/updates/refresh', {}, {
        preserveScroll: true,
        onFinish: () => (refreshing.value = false),
    });
}

function confirmUpgrade() {
    upgrading.value = true;

    router.post('/updates/upgrade', {}, {
        preserveScroll: true,
        onError: (errors) => pushToast(Object.values(errors)[0] ?? 'The upgrade could not be started.', 'error'),
        onFinish: () => {
            upgrading.value = false;
            confirmOpen.value = false;
        },
    });
}

function epoch(value) {
    if (!value) return null;

    return value * 1000;
}

function packageRepository(entry) {
    return entry.repository || (entry.repositories ?? []).join(', ') || '—';
}
</script>

<template>
    <Head title="Updates & reboot" />

    <div class="space-y-5">
        <PageHeader
            title="Updates & reboot"
            subtitle="Pending APT updates, security patches, package list freshness and reboot requirements for this host."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot :status="!updates.available ? 'offline' : (rebootRequired ? 'degraded' : (counts.security ? 'degraded' : 'online'))" pulse />
                    APT
                    <span class="text-dim">·</span>
                    {{ counts.total ?? 0 }} updates
                    <span class="text-dim">·</span>
                    {{ counts.security ?? 0 }} security
                    <span class="text-dim">·</span>
                    {{ rebootRequired ? 'reboot required' : 'no reboot required' }}
                </span>
            </template>
            <template #actions>
                <button v-if="canOperate" type="button" class="btn btn-sm" :disabled="refreshing || upgrading" @click="refreshLists">
                    <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': refreshing }" />
                    {{ refreshing ? 'Refreshing…' : 'Refresh lists' }}
                </button>
                <button v-if="canOperate" type="button" class="btn btn-primary btn-sm" :disabled="!canUpgrade || upgrading" @click="confirmOpen = true">
                    <Download class="h-3.5 w-3.5" :class="{ 'animate-bounce': upgrading }" />
                    {{ upgrading ? 'Installing…' : `Install ${counts.planned ?? 0} update${counts.planned === 1 ? '' : 's'}` }}
                </button>
            </template>
        </PageHeader>

        <div v-if="!updates.available" class="flex items-start gap-2.5 rounded-xl border border-rose/25 bg-rose/10 px-4 py-3 text-[11px] leading-5 text-rose">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ updates.error }}</span>
        </div>

        <section v-if="rebootRequired" class="panel border-amber/25">
            <div class="flex flex-wrap items-start justify-between gap-3 p-5">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-amber/25 bg-amber/10 text-amber">
                        <Power class="h-4 w-4" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-bright">Reboot required</h2>
                        <p class="mt-1 text-[11px] leading-5 text-muted">
                            Kernel or system library updates are installed but not active until the host restarts.
                            ServerDeck never reboots the host automatically.
                        </p>
                        <div v-if="updates.reboot_packages?.length" class="mt-2 flex flex-wrap gap-1.5">
                            <span v-for="name in updates.reboot_packages" :key="name" class="badge border-amber/25 bg-amber/10 font-mono text-amber">{{ name }}</span>
                        </div>
                    </div>
                </div>
                <p class="font-mono text-[11px] text-dim">sudo reboot</p>
            </div>
        </section>

        <div v-else-if="updates.kernel_update_pending" class="flex items-start gap-2.5 rounded-xl border border-cyan/25 bg-cyan/10 px-4 py-3 text-[11px] leading-5 text-cyan">
            <Server class="mt-0.5 h-4 w-4 shrink-0" />
            <span>
                A newer kernel is installed ({{ updates.latest_kernel }}) but the host is running {{ updates.running_kernel }}. A reboot will activate it.
            </span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Available updates" :value="counts.total ?? 0" :subtitle="`${counts.planned ?? 0} ready to install${counts.held_back ? ` · ${counts.held_back} held back` : ''}`" :icon="PackageOpen" :tone="counts.total ? 'cyan' : 'accent'" detail="From the current APT package lists" />
            <StatCard label="Security updates" :value="counts.security ?? 0" :subtitle="counts.security ? 'Patch these first' : 'No security patches pending'" :icon="ShieldAlert" :tone="counts.security ? 'rose' : 'accent'" detail="Packages published in a security repository" />
            <StatCard label="Reboot required" :value="rebootRequired ? 'Yes' : 'No'" :subtitle="rebootRequired ? `${updates.reboot_packages?.length ?? 0} package${updates.reboot_packages?.length === 1 ? '' : 's'} waiting` : 'Running kernel matches the latest installed'" :icon="Power" :tone="rebootRequired ? 'amber' : 'accent'" detail="Host reboot is manual and audited" />
            <StatCard label="Package lists" :value="updates.lists_updated_at ? timeAgo(epoch(updates.lists_updated_at)) : 'Unknown'" :subtitle="updates.last_upgrade_at ? `Last upgrade ${timeAgo(epoch(updates.last_upgrade_at))}` : 'No upgrade history found'" :icon="Clock" tone="cyan" :detail="updates.simulated_at ? `Simulated ${timeAgo(epoch(updates.simulated_at))}` : 'APT status unavailable'" />
        </div>

        <div v-if="heldBack.length" class="rounded-xl border border-amber/25 bg-amber/10 px-4 py-3 text-[11px] leading-5 text-amber">
            {{ heldBack.length }} update{{ heldBack.length === 1 ? '' : 's' }} are held back by APT and will not be installed by the standard upgrade because they require package removals. Review them in a root shell before a full upgrade.
        </div>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Upgrade plan</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Packages APT will install with <span class="font-mono text-muted">--with-new-pkgs upgrade</span>. No packages are removed.</p>
                </div>
                <span class="badge border-line bg-panel-2 text-dim">{{ packages.length }} planned</span>
            </div>

            <div v-if="packages.length" class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-left text-[11px]">
                    <thead class="border-y border-line/70 text-[10px] uppercase tracking-wider text-dim">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Package</th>
                            <th class="px-3 py-2.5 font-medium">Current</th>
                            <th class="px-3 py-2.5 font-medium">New</th>
                            <th class="px-3 py-2.5 font-medium">Repository</th>
                            <th class="px-5 py-2.5 text-right font-medium">Flags</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <tr v-for="entry in packages" :key="entry.name" class="transition hover:bg-white/[0.02]">
                            <td class="px-5 py-3 font-mono font-medium text-bright">{{ entry.name }}</td>
                            <td class="px-3 py-3 font-mono text-dim">{{ entry.current_version ?? 'not installed' }}</td>
                            <td class="px-3 py-3 font-mono text-muted">{{ entry.candidate_version }}</td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <span v-for="repo in (entry.repositories?.length ? entry.repositories : [packageRepository(entry)])" :key="repo" class="badge border-line bg-panel-2 font-mono text-dim">{{ repo }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap items-center justify-end gap-1.5">
                                    <span v-if="entry.security" class="badge border-rose/25 bg-rose/10 text-rose">security</span>
                                    <span v-if="entry.is_new" class="badge border-cyan/25 bg-cyan/10 text-cyan">new dependency</span>
                                    <span v-if="!entry.security && !entry.is_new" class="badge border-line bg-panel-2 text-dim">update</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="p-5">
                <EmptyState :icon="PackageCheck" title="Everything is up to date" :description="updates.available ? 'APT has no pending upgrades from the current package lists. Refresh the lists to check for new releases.' : 'The upgrade plan is unavailable until APT status can be read.'">
                    <button v-if="canOperate" type="button" class="btn btn-sm" :disabled="refreshing" @click="refreshLists">
                        <RefreshCw class="h-3 w-3" :class="{ 'animate-spin': refreshing }" /> Refresh package lists
                    </button>
                </EmptyState>
            </div>
        </section>

        <section v-if="heldBack.length" class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Held back</h2>
                    <p class="mt-0.5 text-[11px] text-dim">These packages have a newer candidate but cannot be upgraded without removing packages.</p>
                </div>
                <span class="badge border-amber/25 bg-amber/10 text-amber">{{ heldBack.length }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-[11px]">
                    <thead class="border-y border-line/70 text-[10px] uppercase tracking-wider text-dim">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Package</th>
                            <th class="px-3 py-2.5 font-medium">Current</th>
                            <th class="px-3 py-2.5 font-medium">Candidate</th>
                            <th class="px-3 py-2.5 font-medium">Repository</th>
                            <th class="px-5 py-2.5 text-right font-medium">Flags</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <tr v-for="entry in heldBack" :key="`held-${entry.name}`">
                            <td class="px-5 py-3 font-mono text-bright">{{ entry.name }}</td>
                            <td class="px-3 py-3 font-mono text-dim">{{ entry.current_version }}</td>
                            <td class="px-3 py-3 font-mono text-muted">{{ entry.candidate_version }}</td>
                            <td class="px-3 py-3 font-mono text-dim">{{ packageRepository(entry) }}</td>
                            <td class="px-5 py-3 text-right">
                                <span v-if="entry.security" class="badge border-rose/25 bg-rose/10 text-rose">security</span>
                                <span v-else class="badge border-amber/25 bg-amber/10 text-amber">held back</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Host update state</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Kernel versions, unattended upgrades and package list freshness.</p>
                </div>
                <Server class="h-4 w-4 text-dim" />
            </div>
            <div class="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-xl border border-line/60 bg-panel-2/20 px-3.5 py-3">
                    <span class="text-[10px] uppercase tracking-wider text-dim">Unattended upgrades</span>
                    <div class="mt-1 flex items-center gap-2">
                        <StatusDot :status="updates.unattended_upgrades_active ? 'online' : 'maintenance'" />
                        <span class="text-[11px] text-muted">{{ updates.unattended_upgrades_active ? 'Service active' : 'Service inactive' }}</span>
                    </div>
                </div>
                <div class="rounded-xl border border-line/60 bg-panel-2/20 px-3.5 py-3">
                    <span class="text-[10px] uppercase tracking-wider text-dim">Running kernel</span>
                    <p class="mt-1 truncate font-mono text-[11px] text-muted">{{ updates.running_kernel ?? 'Unknown' }}</p>
                </div>
                <div class="rounded-xl border border-line/60 bg-panel-2/20 px-3.5 py-3">
                    <span class="text-[10px] uppercase tracking-wider text-dim">Latest installed kernel</span>
                    <p class="mt-1 truncate font-mono text-[11px]" :class="updates.kernel_update_pending ? 'text-amber' : 'text-muted'">{{ updates.latest_kernel ?? 'Unknown' }}</p>
                </div>
                <div class="rounded-xl border border-line/60 bg-panel-2/20 px-3.5 py-3">
                    <span class="text-[10px] uppercase tracking-wider text-dim">Package lists updated</span>
                    <p class="mt-1 text-[11px] text-muted">{{ updates.lists_updated_at ? formatDateTime(epoch(updates.lists_updated_at)) : 'Unknown' }}</p>
                </div>
                <div class="rounded-xl border border-line/60 bg-panel-2/20 px-3.5 py-3">
                    <span class="text-[10px] uppercase tracking-wider text-dim">Last upgrade</span>
                    <p class="mt-1 text-[11px] text-muted">{{ updates.last_upgrade_at ? formatDateTime(epoch(updates.last_upgrade_at)) : 'No history' }}</p>
                </div>
                <div class="rounded-xl border border-line/60 bg-panel-2/20 px-3.5 py-3">
                    <span class="text-[10px] uppercase tracking-wider text-dim">Plan simulated</span>
                    <p class="mt-1 text-[11px] text-muted">{{ updates.simulated_at ? formatDateTime(epoch(updates.simulated_at)) : 'Unavailable' }}</p>
                </div>
            </div>
            <div class="border-t border-line/60 bg-panel-2/20 px-5 py-3 text-[11px] leading-5 text-dim">
                Upgrades run <span class="font-mono text-muted">apt-get -y --with-new-pkgs upgrade</span> with non-interactive, keep-existing config handling. New dependency packages may be installed; no packages are removed and the host is never rebooted automatically.
            </div>
        </section>

        <ConfirmDialog
            :show="confirmOpen"
            title="Install package updates?"
            :description="confirmDescription"
            confirm-label="Install updates"
            :danger="true"
            :loading="upgrading"
            @close="confirmOpen = false"
            @confirm="confirmUpgrade"
        />
    </div>
</template>
