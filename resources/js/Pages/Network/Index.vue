<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ChevronRight,
    Container,
    Globe,
    Network as NetworkIcon,
    Plug,
    Plus,
    Radar,
    RefreshCw,
    Route,
    ShieldCheck,
    Trash2,
    Waypoints,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { api } from '@/lib/http';
import { formatCount, formatRate } from '@/lib/host';
import { pushToast } from '@/lib/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    network: { type: Object, required: true },
    routes: { type: Object, required: true },
    dns: { type: Object, required: true },
    listening: { type: Object, required: true },
    firewall: { type: Object, required: true },
    firewallRules: { type: Object, required: true },
    incusNetworks: { type: Array, required: true },
    dockerNetworks: { type: Array, required: true },
});

const page = usePage();
const canOperate = computed(() => page.props.permissions?.operate ?? false);

const refreshing = ref(false);

function refresh() {
    if (refreshing.value) return;

    refreshing.value = true;
    router.reload({
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (refreshing.value = false),
    });
}

const upInterfaces = computed(() => props.network.interfaces.filter((iface) => iface.state === 'up' && iface.name !== 'lo'));
const firewallRuleCount = computed(() => props.firewallRules.rules.length);

const expandedTables = reactive({});

function tableKey(table) {
    return `${table.family}-${table.name}`;
}

function isExpanded(key) {
    return Boolean(expandedTables[key]);
}

function toggleTable(key) {
    expandedTables[key] = !expandedTables[key];
}

function expandAllTables() {
    for (const table of props.firewall.tables ?? []) {
        expandedTables[tableKey(table)] = true;
    }
}

function collapseAllTables() {
    for (const key of Object.keys(expandedTables)) {
        expandedTables[key] = false;
    }
}

const ruleModalOpen = ref(false);
const ruleForm = useForm({
    family: 'ipv4',
    protocol: 'tcp',
    action: 'accept',
    port: '',
    source: '',
    comment: '',
});

function openRuleModal() {
    ruleForm.reset();
    ruleForm.clearErrors();
    ruleModalOpen.value = true;
}

function closeRuleModal(force = false) {
    if (ruleForm.processing && !force) return;
    ruleModalOpen.value = false;
}

function submitRule() {
    ruleForm.post('/network/firewall/rules', {
        preserveScroll: true,
        onSuccess: () => {
            pushToast('Firewall rule added to the INPUT chain.', 'success');
            closeRuleModal(true);
        },
    });
}

const pendingDelete = ref(null);
const deleting = ref(false);

function requestDelete(rule) {
    pendingDelete.value = rule;
}

function confirmDelete() {
    if (!pendingDelete.value) return;

    deleting.value = true;
    router.delete(`/network/firewall/rules/${pendingDelete.value.family}/${pendingDelete.value.line}`, {
        preserveScroll: true,
        onSuccess: () => {
            pushToast('Firewall rule removed.', 'success');
            pendingDelete.value = null;
        },
        onError: (errors) => pushToast(Object.values(errors)[0] ?? 'The firewall rule could not be removed.', 'error'),
        onFinish: () => (deleting.value = false),
    });
}

function actionTone(action) {
    if (action === 'ACCEPT') return 'border-accent/25 bg-accent/10 text-accent';
    if (action === 'DROP') return 'border-line bg-panel-2 text-dim';
    return 'border-rose/25 bg-rose/10 text-rose';
}

const ping = reactive({ target: '', count: 4, loading: false, result: null, error: null });
const dig = reactive({ target: '', record: 'A', loading: false, result: null, error: null });
const portCheck = reactive({ target: '', port: '', loading: false, result: null, error: null });

async function runPing() {
    if (!ping.target.trim() || ping.loading) return;

    ping.loading = true;
    ping.error = null;
    ping.result = null;

    try {
        ping.result = await api.post('/network/tools/ping', { target: ping.target.trim(), count: ping.count });
    } catch (error) {
        ping.error = error.message ?? 'The ping could not be run.';
    } finally {
        ping.loading = false;
    }
}

async function runDig() {
    if (!dig.target.trim() || dig.loading) return;

    dig.loading = true;
    dig.error = null;
    dig.result = null;

    try {
        dig.result = await api.post('/network/tools/dig', { target: dig.target.trim(), record: dig.record });
    } catch (error) {
        dig.error = error.message ?? 'The DNS lookup could not be run.';
    } finally {
        dig.loading = false;
    }
}

async function runPortCheck() {
    if (!portCheck.target.trim() || !portCheck.port || portCheck.loading) return;

    portCheck.loading = true;
    portCheck.error = null;
    portCheck.result = null;

    try {
        portCheck.result = await api.post('/network/tools/portcheck', {
            target: portCheck.target.trim(),
            port: Number(portCheck.port),
        });
    } catch (error) {
        portCheck.error = error.message ?? 'The port check could not be run.';
    } finally {
        portCheck.loading = false;
    }
}
</script>

<template>
    <Head title="Network" />

    <div class="space-y-5">
        <PageHeader title="Network" subtitle="Interfaces, routes, DNS, firewall rules, workload networks and diagnostics for this host.">
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot status="online" pulse />
                    {{ upInterfaces.length }} interface{{ upInterfaces.length === 1 ? '' : 's' }} up
                    <span class="text-dim">·</span>
                    {{ listening.ports.length }} listening ports
                </span>
            </template>
            <template #actions>
                <button type="button" class="btn btn-sm" :disabled="refreshing" @click="refresh">
                    <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': refreshing }" />
                    Refresh
                </button>
            </template>
        </PageHeader>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Interfaces up" :value="upInterfaces.length" :subtitle="`of ${network.interfaces.length} total`" :icon="NetworkIcon" tone="cyan" detail="Excludes loopback" />
            <StatCard label="Throughput" :value="formatRate(network.rx_rate + network.tx_rate)" :subtitle="`${formatRate(network.rx_rate)} in · ${formatRate(network.tx_rate)} out`" :icon="Waypoints" tone="accent" detail="Live from /proc/net/dev" />
            <StatCard label="Listening ports" :value="listening.ports.length" subtitle="TCP and UDP sockets" :icon="Plug" tone="cyan" :detail="listening.error ?? 'From ss -tulpn'" />
            <StatCard label="Firewall" :value="firewallRuleCount" subtitle="INPUT chain rules" :icon="ShieldCheck" :tone="firewallRules.available ? 'accent' : 'rose'" :detail="firewallRules.available ? `Default policy ${firewallRules.policy.ipv4 ?? 'unknown'}` : (firewallRules.error ?? 'Could not be read')" />
        </div>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Interfaces</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Live throughput, addressing and link state</p>
                </div>
                <NetworkIcon class="h-4 w-4 text-dim" />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[700px]">
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
                        <tr v-for="iface in network.interfaces" :key="iface.name" class="hover-row">
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
                            <td class="table-cell text-right font-mono text-[11px] text-dim">{{ iface.rx_packets }} / {{ iface.tx_packets }} pkts</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-3">
            <section class="panel xl:col-span-2 overflow-hidden">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Routes</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Kernel routing table from ip route show</p>
                    </div>
                    <Route class="h-4 w-4 text-dim" />
                </div>
                <div class="space-y-4 p-5">
                    <div>
                        <div class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-dim">IPv4</div>
                        <div v-if="routes.ipv4.length" class="space-y-1.5">
                            <div v-for="(route, index) in routes.ipv4" :key="`v4-${index}`" class="flex flex-wrap items-center gap-2 rounded-lg border border-line/70 bg-panel-2/30 px-2.5 py-1.5 font-mono text-[11px]">
                                <span class="text-bright">{{ route.dst ?? 'default' }}</span>
                                <span v-if="route.gateway" class="text-dim">via <span class="text-muted">{{ route.gateway }}</span></span>
                                <span class="text-dim">dev <span class="text-muted">{{ route.dev }}</span></span>
                                <span v-if="route.prefsrc" class="text-dim">src <span class="text-muted">{{ route.prefsrc }}</span></span>
                                <span v-if="route.protocol" class="ml-auto text-dim">{{ route.protocol }}</span>
                            </div>
                        </div>
                        <p v-else class="text-[11px] text-dim">No IPv4 routes.</p>
                    </div>
                    <div class="border-t border-line/70 pt-4">
                        <div class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-dim">IPv6</div>
                        <div v-if="routes.ipv6.length" class="max-h-56 space-y-1.5 overflow-auto">
                            <div v-for="(route, index) in routes.ipv6" :key="`v6-${index}`" class="flex flex-wrap items-center gap-2 rounded-lg border border-line/70 bg-panel-2/30 px-2.5 py-1.5 font-mono text-[11px]">
                                <span class="truncate text-bright">{{ route.dst ?? 'default' }}</span>
                                <span v-if="route.gateway" class="text-dim">via <span class="text-muted">{{ route.gateway }}</span></span>
                                <span class="text-dim">dev <span class="text-muted">{{ route.dev }}</span></span>
                                <span v-if="route.protocol" class="ml-auto text-dim">{{ route.protocol }}</span>
                            </div>
                        </div>
                        <p v-else class="text-[11px] text-dim">No IPv6 routes.</p>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">DNS</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Resolver configuration</p>
                    </div>
                    <Globe class="h-4 w-4 text-dim" />
                </div>
                <div class="space-y-4 p-5">
                    <div>
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-dim">Nameservers</div>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span v-for="server in dns.nameservers" :key="server" class="rounded bg-white/[0.05] px-1.5 py-0.5 font-mono text-[11px] text-muted">{{ server }}</span>
                            <span v-if="!dns.nameservers.length" class="text-[11px] text-dim">None configured</span>
                        </div>
                    </div>
                    <div class="border-t border-line/70 pt-4">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-dim">Search domains</div>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span v-for="domain in dns.search" :key="domain" class="rounded bg-white/[0.05] px-1.5 py-0.5 font-mono text-[11px] text-muted">{{ domain }}</span>
                            <span v-if="!dns.search.length" class="text-[11px] text-dim">None configured</span>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Listening ports</h2>
                    <p class="mt-0.5 text-[11px] text-dim">TCP and UDP sockets accepting connections on this host</p>
                </div>
                <Plug class="h-4 w-4 text-dim" />
            </div>
            <div v-if="listening.ports.length" class="max-h-[420px] overflow-auto">
                <table class="w-full min-w-[640px]">
                    <thead class="sticky top-0 bg-panel">
                        <tr class="border-b border-line/70">
                            <th class="table-head">Protocol</th>
                            <th class="table-head">State</th>
                            <th class="table-head">Local address</th>
                            <th class="table-head text-right">Process</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <tr v-for="(port, index) in listening.ports" :key="`${port.local_address}-${index}`" class="hover-row">
                            <td class="table-cell text-[11px] uppercase tracking-wider text-muted">{{ port.protocol }}</td>
                            <td class="table-cell text-[11px] text-dim">{{ port.state }}</td>
                            <td class="table-cell font-mono text-[11px] text-bright">{{ port.local_address }}</td>
                            <td class="table-cell text-right font-mono text-[11px] text-dim">{{ port.processes.join(', ') || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else :icon="Plug" title="No listening sockets" :description="listening.error ?? 'Nothing is currently listening on this host.'" />
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="panel overflow-hidden">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Docker networks</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Bridges and overlays managed by the Docker daemon</p>
                    </div>
                    <Container class="h-4 w-4 text-dim" />
                </div>
                <div v-if="dockerNetworks.length" class="max-h-[420px] divide-y divide-line/50 overflow-auto">
                    <div v-for="network in dockerNetworks" :key="network.id" class="px-5 py-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-[11px] font-medium text-bright">{{ network.name }}</span>
                            <span class="badge border-line bg-panel-2 text-dim">{{ network.driver }} · {{ network.scope }}</span>
                        </div>
                        <div class="mt-1 font-mono text-[11px] text-dim">
                            <span v-for="subnet in network.subnets" :key="subnet.subnet">{{ subnet.subnet }} <span v-if="subnet.gateway">via {{ subnet.gateway }}</span></span>
                            <span v-if="!network.subnets.length">no IPAM subnet</span>
                        </div>
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            <span v-for="container in network.containers" :key="container.name" class="rounded bg-white/[0.05] px-1.5 py-0.5 font-mono text-[10px] text-dim">{{ container.name }}</span>
                            <span v-if="!network.containers.length" class="text-[11px] text-dim">No attached containers</span>
                        </div>
                    </div>
                </div>
                <EmptyState v-else :icon="Container" title="No Docker networks" description="Docker is unavailable or has no networks defined." />
            </section>

            <section class="panel overflow-hidden">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Incus networks</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Bridges used by LXC/VM instances</p>
                    </div>
                    <NetworkIcon class="h-4 w-4 text-dim" />
                </div>
                <div v-if="incusNetworks.length" class="max-h-[420px] divide-y divide-line/50 overflow-auto">
                    <div v-for="network in incusNetworks" :key="network.name" class="px-5 py-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-[11px] font-medium text-bright">{{ network.name }}</span>
                            <span class="badge" :class="network.managed ? 'border-accent/25 bg-accent/10 text-accent' : 'border-line bg-panel-2 text-dim'">{{ network.managed ? 'managed' : network.type }}</span>
                        </div>
                        <div class="mt-1 font-mono text-[11px] text-dim">
                            <span v-if="network.ipv4_address">{{ network.ipv4_address }}</span>
                            <span v-else>no managed IPv4 subnet</span>
                        </div>
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            <span v-for="name in network.instances" :key="name" class="rounded bg-white/[0.05] px-1.5 py-0.5 font-mono text-[10px] text-dim">{{ name }}</span>
                            <span v-if="!network.instances.length" class="text-[11px] text-dim">Not used by any instance</span>
                        </div>
                    </div>
                </div>
                <EmptyState v-else :icon="NetworkIcon" title="No Incus networks" description="Incus is unavailable or reports no networks." />
            </section>
        </div>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Firewall · INPUT chain</h2>
                    <p class="mt-0.5 text-[11px] text-dim">
                        Rules evaluated for inbound traffic to this host, managed through iptables/ip6tables
                        <span v-if="firewallRules.available"> · default policy {{ firewallRules.policy.ipv4 ?? 'unknown' }} (IPv4) / {{ firewallRules.policy.ipv6 ?? 'unknown' }} (IPv6)</span>
                    </p>
                </div>
                <button v-if="canOperate" type="button" class="btn btn-primary btn-sm" @click="openRuleModal">
                    <Plus class="h-3.5 w-3.5" /> Add rule
                </button>
            </div>

            <div v-if="!firewallRules.available" class="flex items-start gap-2.5 px-5 py-4 text-[11px] leading-5 text-rose">
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>{{ firewallRules.error }}</span>
            </div>

            <div v-else-if="firewallRules.rules.length" class="overflow-x-auto">
                <table class="w-full min-w-[820px]">
                    <thead>
                        <tr class="border-b border-line/70">
                            <th class="table-head w-14">#</th>
                            <th class="table-head">Action</th>
                            <th class="table-head">Family</th>
                            <th class="table-head">Protocol</th>
                            <th class="table-head">Port</th>
                            <th class="table-head">Source</th>
                            <th class="table-head">Comment</th>
                            <th class="table-head text-right">Hits</th>
                            <th class="table-head w-12 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <tr v-for="rule in firewallRules.rules" :key="`${rule.family}-${rule.line}`" class="hover-row">
                            <td class="table-cell font-mono text-[11px] text-dim">{{ rule.line }}</td>
                            <td class="table-cell"><span class="badge" :class="actionTone(rule.action)">{{ rule.action }}</span></td>
                            <td class="table-cell text-[11px] uppercase tracking-wider text-muted">{{ rule.family === 'ipv6' ? 'IPv6' : 'IPv4' }}</td>
                            <td class="table-cell font-mono text-[11px] text-muted">{{ rule.protocol }}</td>
                            <td class="table-cell font-mono text-[11px] text-bright">{{ rule.port ?? 'any' }}</td>
                            <td class="table-cell font-mono text-[11px] text-dim">{{ rule.source }}</td>
                            <td class="table-cell text-[11px] text-muted">{{ rule.comment || '—' }}</td>
                            <td class="table-cell text-right font-mono text-[11px] text-dim">{{ formatCount(rule.packets) }}</td>
                            <td class="table-cell text-right">
                                <button
                                    v-if="canOperate"
                                    type="button"
                                    class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-rose/40 hover:text-rose"
                                    title="Remove rule"
                                    @click="requestDelete(rule)"
                                >
                                    <Trash2 class="h-3 w-3" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else :icon="ShieldCheck" title="No INPUT rules" description="There are no explicit accept, drop or reject rules on this chain yet.">
                <button v-if="canOperate" type="button" class="btn btn-primary btn-sm" @click="openRuleModal">
                    <Plus class="h-3 w-3" /> Add rule
                </button>
            </EmptyState>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line/70 px-5 py-3 text-[11px] text-dim">
                <span>Rules are inserted at the top of the chain, so the newest rule is evaluated first.</span>
                <span>Docker and Incus manage their own chains separately and are not shown here.</span>
            </div>
        </section>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Full ruleset (read-only)</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Everything nftables/iptables is enforcing right now, grouped by table and chain, including what Docker and Incus manage for themselves</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="btn btn-sm" @click="expandAllTables">Expand all</button>
                    <button type="button" class="btn btn-sm" @click="collapseAllTables">Collapse all</button>
                </div>
            </div>
            <div v-if="!firewall.available" class="flex items-start gap-2.5 px-5 py-4 text-[11px] leading-5 text-rose">
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>{{ firewall.error }}</span>
            </div>
            <div v-else-if="firewall.tables?.length" class="divide-y divide-line/50">
                <div v-for="table in firewall.tables" :key="tableKey(table)">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-5 py-3 text-left transition hover:bg-white/[0.02]"
                        @click="toggleTable(tableKey(table))"
                    >
                        <span class="flex min-w-0 items-center gap-2">
                            <ChevronRight class="h-3.5 w-3.5 shrink-0 text-dim transition-transform" :class="{ 'rotate-90': isExpanded(tableKey(table)) }" />
                            <span class="font-mono text-[11px] font-semibold text-bright">{{ table.family }} {{ table.name }}</span>
                            <span class="badge border-line bg-panel-2 text-dim">{{ table.chains.length }} chain{{ table.chains.length === 1 ? '' : 's' }}</span>
                            <span v-if="table.notice" class="badge border-amber/25 bg-amber/10 text-amber">managed externally</span>
                        </span>
                    </button>

                    <div v-show="isExpanded(tableKey(table))" class="space-y-3 px-5 pb-4">
                        <p v-if="table.notice" class="flex items-start gap-2 text-[11px] leading-5 text-amber">
                            <AlertTriangle class="mt-0.5 h-3 w-3 shrink-0" />
                            {{ table.notice }}
                        </p>

                        <div v-for="chain in table.chains" :key="chain.name" class="rounded-lg border border-line/70 bg-panel-2/30 p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-mono text-[11px] font-semibold text-bright">chain {{ chain.name }}</span>
                                <span v-if="chain.header" class="font-mono text-[10px] text-dim">{{ chain.header }}</span>
                            </div>
                            <pre v-if="chain.rules.length" class="mt-2 overflow-x-auto font-mono text-[10px] leading-5 text-muted">{{ chain.rules.join('\n') }}</pre>
                            <p v-else class="mt-2 text-[10px] text-dim">No rules in this chain.</p>
                        </div>
                    </div>
                </div>
            </div>
            <EmptyState v-else :icon="ShieldCheck" title="No ruleset loaded" description="nftables and iptables both reported an empty ruleset." />
        </section>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Diagnostics</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Ping, DNS lookups and TCP port checks, run directly from this host</p>
                </div>
                <Radar class="h-4 w-4 text-dim" />
            </div>

            <div v-if="canOperate" class="grid gap-4 p-5 lg:grid-cols-3">
                <form class="space-y-2.5 rounded-xl border border-line/70 bg-panel-2/30 p-3.5" @submit.prevent="runPing">
                    <div class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-dim"><Radar class="h-3.5 w-3.5" /> Ping</div>
                    <input v-model="ping.target" type="text" class="input h-8 text-xs" placeholder="host or IP address" autocomplete="off" />
                    <div class="flex items-center gap-2">
                        <select v-model.number="ping.count" class="input h-8 w-24 text-xs">
                            <option :value="4">4 packets</option>
                            <option :value="1">1 packet</option>
                            <option :value="8">8 packets</option>
                        </select>
                        <button type="submit" class="btn btn-sm ml-auto" :disabled="ping.loading || !ping.target.trim()">
                            <span v-if="ping.loading" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                            Run
                        </button>
                    </div>
                    <p v-if="ping.error" class="text-[11px] text-rose">{{ ping.error }}</p>
                    <pre v-if="ping.result" class="max-h-48 overflow-auto rounded-lg border border-line bg-[#04080c] p-2.5 font-mono text-[10px] leading-4 text-muted">{{ ping.result.output }}</pre>
                </form>

                <form class="space-y-2.5 rounded-xl border border-line/70 bg-panel-2/30 p-3.5" @submit.prevent="runDig">
                    <div class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-dim"><Globe class="h-3.5 w-3.5" /> DNS lookup</div>
                    <input v-model="dig.target" type="text" class="input h-8 text-xs" placeholder="hostname" autocomplete="off" />
                    <div class="flex items-center gap-2">
                        <select v-model="dig.record" class="input h-8 w-24 text-xs">
                            <option v-for="type in ['A', 'AAAA', 'MX', 'TXT', 'NS', 'CNAME', 'SOA', 'PTR']" :key="type" :value="type">{{ type }}</option>
                        </select>
                        <button type="submit" class="btn btn-sm ml-auto" :disabled="dig.loading || !dig.target.trim()">
                            <span v-if="dig.loading" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                            Run
                        </button>
                    </div>
                    <p v-if="dig.error" class="text-[11px] text-rose">{{ dig.error }}</p>
                    <pre v-if="dig.result" class="max-h-48 overflow-auto rounded-lg border border-line bg-[#04080c] p-2.5 font-mono text-[10px] leading-4 text-muted">{{ dig.result.output }}</pre>
                </form>

                <form class="space-y-2.5 rounded-xl border border-line/70 bg-panel-2/30 p-3.5" @submit.prevent="runPortCheck">
                    <div class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-dim"><Plug class="h-3.5 w-3.5" /> Port check</div>
                    <div class="flex gap-2">
                        <input v-model="portCheck.target" type="text" class="input h-8 flex-1 text-xs" placeholder="host or IP address" autocomplete="off" />
                        <input v-model="portCheck.port" type="number" min="1" max="65535" class="input h-8 w-20 text-xs" placeholder="port" />
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="portCheck.result" class="text-[11px]" :class="portCheck.result.open ? 'text-accent' : 'text-rose'">
                            {{ portCheck.result.open ? 'Open' : 'Closed / unreachable' }}
                        </span>
                        <button type="submit" class="btn btn-sm ml-auto" :disabled="portCheck.loading || !portCheck.target.trim() || !portCheck.port">
                            <span v-if="portCheck.loading" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                            Run
                        </button>
                    </div>
                    <p v-if="portCheck.error" class="text-[11px] text-rose">{{ portCheck.error }}</p>
                </form>
            </div>
            <EmptyState v-else :icon="Radar" title="Operator access required" description="Viewers can see network state but cannot run diagnostic tools." />
        </section>

        <div class="flex items-start gap-2.5 rounded-xl border border-line/60 bg-panel/40 px-4 py-3 text-[11px] leading-5 text-dim">
            <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0 text-cyan" />
            <span>
                Interfaces, routes and DNS are read directly from the Linux host. The firewall ruleset and diagnostic tools run
                through the audited root helper; ping, DNS lookups and port checks are written to the audit log.
            </span>
        </div>
    </div>

    <Modal :show="ruleModalOpen" title="Add firewall rule" description="Inserted at the top of the INPUT chain for the selected address family." max-width="max-w-lg" @close="closeRuleModal">
        <form class="space-y-4" @submit.prevent="submitRule">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="rule-family" class="label">Address family</label>
                    <select id="rule-family" v-model="ruleForm.family" class="input">
                        <option value="ipv4">IPv4</option>
                        <option value="ipv6">IPv6</option>
                    </select>
                </div>
                <div>
                    <label for="rule-action" class="label">Action</label>
                    <select id="rule-action" v-model="ruleForm.action" class="input">
                        <option value="accept">Accept</option>
                        <option value="drop">Drop</option>
                        <option value="reject">Reject</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="rule-protocol" class="label">Protocol</label>
                    <select id="rule-protocol" v-model="ruleForm.protocol" class="input">
                        <option value="tcp">TCP</option>
                        <option value="udp">UDP</option>
                        <option value="icmp">ICMP</option>
                        <option value="all">All protocols</option>
                    </select>
                </div>
                <div>
                    <label for="rule-port" class="label">Port <span class="text-dim">(optional)</span></label>
                    <input
                        id="rule-port"
                        v-model="ruleForm.port"
                        class="input font-mono"
                        placeholder="8080 or 27000:27050"
                        autocomplete="off"
                        :disabled="!['tcp', 'udp'].includes(ruleForm.protocol)"
                    />
                </div>
            </div>
            <p v-if="ruleForm.errors.port" class="-mt-2 text-[11px] text-rose">{{ ruleForm.errors.port }}</p>

            <div>
                <label for="rule-source" class="label">Source <span class="text-dim">(optional)</span></label>
                <input id="rule-source" v-model="ruleForm.source" class="input font-mono" :placeholder="ruleForm.family === 'ipv6' ? '2001:db8::/32' : '203.0.113.0/24'" autocomplete="off" />
                <p class="mt-1.5 text-[11px] text-dim">Leave empty to match any source address.</p>
                <p v-if="ruleForm.errors.source" class="mt-1 text-[11px] text-rose">{{ ruleForm.errors.source }}</p>
            </div>

            <div>
                <label for="rule-comment" class="label">Comment <span class="text-dim">(optional)</span></label>
                <input id="rule-comment" v-model="ruleForm.comment" class="input" maxlength="200" placeholder="What this rule is for" autocomplete="off" />
                <p v-if="ruleForm.errors.comment" class="mt-1 text-[11px] text-rose">{{ ruleForm.errors.comment }}</p>
            </div>

            <div v-if="ruleForm.action !== 'accept'" class="flex items-start gap-2.5 rounded-lg border border-amber/25 bg-amber/10 px-3 py-2.5 text-[11px] leading-5 text-amber">
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>This rule is inserted first and takes effect immediately. A badly scoped drop or reject rule can block your own access to this host, including SSH.</span>
            </div>

            <div class="flex justify-end gap-2 border-t border-line/60 pt-4">
                <button type="button" class="btn btn-sm" :disabled="ruleForm.processing" @click="closeRuleModal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm" :disabled="ruleForm.processing">
                    <span v-if="ruleForm.processing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                    <Plus v-else class="h-3 w-3" />
                    Add rule
                </button>
            </div>
        </form>
    </Modal>

    <ConfirmDialog
        :show="Boolean(pendingDelete)"
        title="Remove this firewall rule?"
        :description="pendingDelete ? `${pendingDelete.action} ${pendingDelete.protocol} ${pendingDelete.port ?? ''} from ${pendingDelete.source} (${pendingDelete.family === 'ipv6' ? 'IPv6' : 'IPv4'}) will stop being enforced immediately.` : ''"
        confirm-label="Remove rule"
        danger
        :loading="deleting"
        @close="pendingDelete = null"
        @confirm="confirmDelete"
    />
</template>
