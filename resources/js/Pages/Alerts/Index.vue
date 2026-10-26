<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Bell,
    Clock,
    Inbox,
    Mail,
    Pencil,
    Plus,
    Power,
    RefreshCw,
    Send,
    ShieldAlert,
    Trash2,
    TriangleAlert,
    Webhook,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { formatDateTime, timeAgo } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    alerts: { type: Object, required: true },
});

const page = usePage();
const canManage = computed(() => page.props.permissions?.manageUsers ?? false);

const channels = computed(() => props.alerts.channels ?? []);
const openAlerts = computed(() => props.alerts.alerts ?? []);
const events = computed(() => props.alerts.events ?? []);
const counts = computed(() => props.alerts.counts ?? {});
const mail = computed(() => props.alerts.mail ?? {});

const channelOpen = ref(false);
const editing = ref(null);
const testing = ref(null);
const evaluating = ref(false);
const deleting = ref(null);
const deleteWorking = ref(false);

const form = useForm({
    name: '',
    type: 'email',
    destination: '',
    enabled: true,
    notify_warning: true,
    notify_critical: true,
    notify_recovery: true,
});

const statusTone = {
    ok: 'border-accent/25 bg-accent/10 text-accent',
    info: 'border-cyan/25 bg-cyan/10 text-cyan',
    warning: 'border-amber/25 bg-amber/10 text-amber',
    critical: 'border-rose/25 bg-rose/10 text-rose',
    recovery: 'border-accent/25 bg-accent/10 text-accent',
    test: 'border-cyan/25 bg-cyan/10 text-cyan',
};

const statusLabel = {
    ok: 'Healthy',
    info: 'Info',
    warning: 'Warning',
    critical: 'Critical',
    recovery: 'Recovered',
    test: 'Test',
};

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.enabled = true;
    form.notify_warning = true;
    form.notify_critical = true;
    form.notify_recovery = true;
    form.type = 'email';
    channelOpen.value = true;
}

function openEdit(channel) {
    editing.value = channel;
    form.name = channel.name;
    form.type = channel.type;
    form.destination = channel.destination ?? '';
    form.enabled = channel.enabled;
    form.notify_warning = channel.notify_warning;
    form.notify_critical = channel.notify_critical;
    form.notify_recovery = channel.notify_recovery;
    form.clearErrors();
    channelOpen.value = true;
}

function submitChannel() {
    if (editing.value) {
        form.put(`/alerts/channels/${editing.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                channelOpen.value = false;
                editing.value = null;
                form.reset();
            },
        });

        return;
    }

    form.post('/alerts/channels', {
        preserveScroll: true,
        onSuccess: () => {
            channelOpen.value = false;
            form.reset();
        },
    });
}

function toggleChannel(channel) {
    router.post(`/alerts/channels/${channel.id}/toggle`, {}, { preserveScroll: true });
}

function testChannel(channel) {
    testing.value = channel.id;

    router.post(`/alerts/channels/${channel.id}/test`, {}, {
        preserveScroll: true,
        onFinish: () => (testing.value = null),
    });
}

function requestDelete(channel) {
    deleting.value = channel;
}

function confirmDelete() {
    if (!deleting.value) return;

    deleteWorking.value = true;

    router.delete(`/alerts/channels/${deleting.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteWorking.value = false;
            deleting.value = null;
        },
    });
}

function evaluateNow() {
    evaluating.value = true;

    router.post('/alerts/run', {}, {
        preserveScroll: true,
        onFinish: () => (evaluating.value = false),
    });
}

function displayStatus(alert) {
    if (alert.resolved_at && !['warning', 'critical'].includes(alert.status)) {
        return 'recovery';
    }

    return alert.status;
}

function eventFlags(channel) {
    return [
        channel.notify_warning ? 'Warning' : null,
        channel.notify_critical ? 'Critical' : null,
        channel.notify_recovery ? 'Recovery' : null,
    ].filter(Boolean);
}

function eventLabel(event) {
    return {
        alert: 'Alert',
        recovery: 'Recovery',
        test: 'Test',
    }[event] ?? event;
}

function eventTone(entry) {
    if (!entry.delivered) return statusTone.critical;

    return statusTone[entry.status] ?? statusTone.info;
}
</script>

<template>
    <Head title="Alerts" />

    <div class="space-y-5">
        <PageHeader
            title="Alerts & notifications"
            subtitle="Route host health transitions to email or webhook channels. Only state changes are delivered, not every check."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot :status="counts.critical ? 'offline' : (counts.open ? 'degraded' : 'online')" pulse />
                    {{ counts.enabled ?? 0 }} channel{{ counts.enabled === 1 ? '' : 's' }} active
                    <span class="text-dim">·</span>
                    {{ counts.open ?? 0 }} open
                    <span class="text-dim">·</span>
                    {{ alerts.last_run_at ? `checked ${timeAgo(alerts.last_run_at)}` : 'not evaluated yet' }}
                </span>
            </template>
            <template #actions>
                <button v-if="canManage" type="button" class="btn btn-sm" :disabled="evaluating" @click="evaluateNow">
                    <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': evaluating }" />
                    {{ evaluating ? 'Evaluating…' : 'Evaluate now' }}
                </button>
                <button v-if="canManage" type="button" class="btn btn-primary btn-sm" @click="openCreate">
                    <Plus class="h-3.5 w-3.5" /> Add channel
                </button>
            </template>
        </PageHeader>

        <div v-if="mail.log_only" class="flex items-start gap-2.5 rounded-xl border border-amber/25 bg-amber/10 px-4 py-3 text-[11px] leading-5 text-amber">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
            <span>
                <span class="font-mono">MAIL_MAILER</span> is set to <span class="font-mono">log</span>, so email alerts are written to the Laravel log instead of being delivered.
                Configure SMTP in <span class="font-mono">.env</span> to send real email. Webhook channels are unaffected.
            </span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Channels" :value="counts.channels ?? 0" :subtitle="`${counts.enabled ?? 0} enabled · ${(counts.channels ?? 0) - (counts.enabled ?? 0)} paused`" :icon="Bell" tone="cyan" detail="Email and webhook destinations" />
            <StatCard label="Open alerts" :value="counts.open ?? 0" :subtitle="counts.critical ? `${counts.critical} critical` : 'No critical checks'" :icon="ShieldAlert" :tone="counts.critical ? 'rose' : (counts.open ? 'amber' : 'accent')" detail="Current host health attention" />
            <StatCard label="Deliveries · 24h" :value="counts.deliveries_24h ?? 0" :subtitle="`${counts.failures_24h ?? 0} failed`" :icon="Send" :tone="counts.failures_24h ? 'rose' : 'accent'" detail="Successful channel deliveries" />
            <StatCard label="Last evaluation" :value="alerts.last_run_at ? timeAgo(alerts.last_run_at) : 'Never'" subtitle="Scheduler runs every minute" :icon="Clock" tone="cyan" :detail="alerts.last_run_at ? formatDateTime(alerts.last_run_at) : 'Run the evaluator to establish state'" />
        </div>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Open alerts</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Current warnings and critical checks, with the last time each transition was delivered.</p>
                </div>
                <span class="badge border-line bg-panel-2 text-dim">{{ openAlerts.length }}</span>
            </div>

            <div v-if="openAlerts.length" class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-[11px]">
                    <thead class="border-y border-line/70 text-[10px] uppercase tracking-wider text-dim">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Check</th>
                            <th class="px-3 py-2.5 font-medium">Status</th>
                            <th class="px-3 py-2.5 font-medium">Detail</th>
                            <th class="px-3 py-2.5 font-medium">First seen</th>
                            <th class="px-3 py-2.5 font-medium">Last notified</th>
                            <th class="px-5 py-2.5 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <tr v-for="alert in openAlerts" :key="alert.check_key" class="transition hover:bg-white/[0.02]">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2.5">
                                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-md border" :class="statusTone[displayStatus(alert)] ?? statusTone.info">
                                        <ShieldAlert class="h-3.5 w-3.5" />
                                    </span>
                                    <span class="font-medium text-bright">{{ alert.check_title }}</span>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <span class="badge" :class="statusTone[displayStatus(alert)] ?? statusTone.info">{{ statusLabel[displayStatus(alert)] ?? displayStatus(alert) }}</span>
                            </td>
                            <td class="px-3 py-3">
                                <p class="max-w-[360px] truncate text-dim" :title="alert.detail">{{ alert.detail ?? '—' }}</p>
                            </td>
                            <td class="px-3 py-3 text-muted">{{ alert.first_seen_at ? timeAgo(alert.first_seen_at) : '—' }}</td>
                            <td class="px-3 py-3">
                                <span v-if="alert.last_notified_at" class="text-muted">{{ timeAgo(alert.last_notified_at) }}</span>
                                <span v-else class="text-dim">Not delivered</span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <a v-if="alert.href" :href="alert.href" class="inline-flex items-center gap-1 text-[11px] font-medium text-accent hover:text-accent/80">
                                    Inspect <ArrowUpRight class="h-3 w-3" />
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="p-5">
                <EmptyState :icon="Inbox" title="No alerts" description="Every host health check is currently within its thresholds. Alert state is recorded automatically when a check changes." />
            </div>
        </section>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Notification channels</h2>
                    <p class="mt-0.5 text-[11px] text-dim">Destinations are encrypted at rest. Each channel chooses which severity transitions it receives.</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="badge border-line bg-panel-2 text-dim">{{ channels.length }} total</span>
                    <button v-if="canManage" type="button" class="btn btn-sm" @click="openCreate">
                        <Plus class="h-3 w-3" /> Add
                    </button>
                </div>
            </div>

            <div v-if="channels.length" class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-left text-[11px]">
                    <thead class="border-y border-line/70 text-[10px] uppercase tracking-wider text-dim">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Channel</th>
                            <th class="px-3 py-2.5 font-medium">Destination</th>
                            <th class="px-3 py-2.5 font-medium">Delivers</th>
                            <th class="px-3 py-2.5 font-medium">State</th>
                            <th class="px-3 py-2.5 font-medium">Last delivery</th>
                            <th class="px-5 py-2.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <tr v-for="channel in channels" :key="channel.id" class="transition hover:bg-white/[0.02]">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2.5">
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border" :class="channel.type === 'email' ? 'border-cyan/20 bg-cyan/10 text-cyan' : 'border-accent/20 bg-accent/10 text-accent'">
                                        <Mail v-if="channel.type === 'email'" class="h-4 w-4" />
                                        <Webhook v-else class="h-4 w-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-bright">{{ channel.name }}</p>
                                        <p class="mt-0.5 text-[11px] text-dim">{{ channel.type_label }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <span class="font-mono text-muted">{{ channel.destination_hint }}</span>
                                <p v-if="channel.last_error" class="mt-1 max-w-[220px] truncate text-[10px] text-rose" :title="channel.last_error">{{ channel.last_error }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="flag in eventFlags(channel)" :key="`${channel.id}-${flag}`" class="badge border-line bg-panel-2 text-dim">{{ flag }}</span>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <span class="badge" :class="channel.enabled ? 'border-accent/25 bg-accent/10 text-accent' : 'border-line bg-panel-2 text-dim'">
                                    {{ channel.enabled ? 'enabled' : 'paused' }}
                                </span>
                            </td>
                            <td class="px-3 py-3">
                                <span v-if="channel.last_sent_at" class="text-muted" :title="formatDateTime(channel.last_sent_at)">{{ timeAgo(channel.last_sent_at) }}</span>
                                <span v-else class="text-dim">Never</span>
                            </td>
                            <td class="px-5 py-3">
                                <div v-if="canManage" class="flex items-center justify-end gap-1">
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-cyan/40 hover:text-cyan" title="Send a test notification" :disabled="testing === channel.id" @click="testChannel(channel)">
                                        <span v-if="testing === channel.id" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                        <Send v-else class="h-3 w-3" />
                                    </button>
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-amber/40 hover:text-amber" :title="channel.enabled ? 'Pause channel' : 'Enable channel'" @click="toggleChannel(channel)">
                                        <Power class="h-3 w-3" />
                                    </button>
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-accent/40 hover:text-accent" title="Edit channel" @click="openEdit(channel)">
                                        <Pencil class="h-3 w-3" />
                                    </button>
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-rose/40 hover:text-rose" title="Delete channel" @click="requestDelete(channel)">
                                        <Trash2 class="h-3 w-3" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="p-5">
                <EmptyState :icon="Bell" title="No alert channels" :description="canManage ? 'Add an email or webhook channel to start receiving host health transitions.' : 'No alert channels have been configured yet.'">
                    <button v-if="canManage" type="button" class="btn btn-primary btn-sm" @click="openCreate">
                        <Plus class="h-3 w-3" /> Add channel
                    </button>
                </EmptyState>
            </div>
        </section>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Delivery history</h2>
                    <p class="mt-0.5 text-[11px] text-dim">The most recent notifications, including failures. History is pruned after 90 days.</p>
                </div>
                <span class="badge border-line bg-panel-2 text-dim">{{ events.length }} recent</span>
            </div>

            <div v-if="events.length" class="max-h-[420px] overflow-y-auto">
                <table class="w-full min-w-[900px] text-left text-[11px]">
                    <thead class="sticky top-0 border-y border-line/70 bg-[#0b141e] text-[10px] uppercase tracking-wider text-dim">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Time</th>
                            <th class="px-3 py-2.5 font-medium">Channel</th>
                            <th class="px-3 py-2.5 font-medium">Event</th>
                            <th class="px-3 py-2.5 font-medium">Check</th>
                            <th class="px-3 py-2.5 font-medium">Message</th>
                            <th class="px-5 py-2.5 text-right font-medium">Result</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <tr v-for="entry in events" :key="entry.id" class="transition hover:bg-white/[0.02]">
                            <td class="whitespace-nowrap px-5 py-3 text-muted" :title="formatDateTime(entry.created_at)">{{ timeAgo(entry.created_at) }}</td>
                            <td class="px-3 py-3 text-muted">{{ entry.channel_name }}</td>
                            <td class="px-3 py-3">
                                <span class="badge" :class="eventTone(entry)">{{ eventLabel(entry.event) }}</span>
                            </td>
                            <td class="px-3 py-3 text-bright">{{ entry.check_title }}</td>
                            <td class="px-3 py-3">
                                <p class="max-w-[320px] truncate text-dim" :title="entry.error ?? entry.message">{{ entry.error ?? entry.message }}</p>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <span class="badge" :class="entry.delivered ? 'border-accent/25 bg-accent/10 text-accent' : 'border-rose/25 bg-rose/10 text-rose'">
                                    {{ entry.delivered ? 'delivered' : 'failed' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="p-5">
                <EmptyState :icon="Send" title="No deliveries yet" description="Send a test notification from a channel to verify delivery, or wait for the next host health transition." />
            </div>
        </section>

        <div class="rounded-xl border border-line/60 bg-panel-2/20 px-4 py-3 text-[11px] leading-5 text-dim">
            The evaluator runs every minute through the ServerDeck scheduler. A notification is sent when a check enters a new severity, when it escalates from warning to critical, or when it recovers.
            Repeated evaluations at the same severity are deduplicated. Email uses the configured Laravel mailer; webhooks receive a JSON POST with a <span class="font-mono text-muted">X-ServerDeck-Event</span> header.
        </div>

        <Modal :show="channelOpen" :title="editing ? `Edit ${editing.name}` : 'New alert channel'" :description="editing ? 'Update where and when this channel delivers host alerts.' : 'Choose an email or webhook destination and the severity transitions it should receive.'" max-width="max-w-xl" @close="channelOpen = false">
            <form class="space-y-4" @submit.prevent="submitChannel">
                <div>
                    <label for="channel-name" class="label">Name</label>
                    <input id="channel-name" v-model="form.name" class="input" maxlength="80" placeholder="Operations email" autocomplete="off" />
                    <p v-if="form.errors.name" class="mt-1 text-[11px] text-rose">{{ form.errors.name }}</p>
                </div>

                <div>
                    <div class="label">Channel type</div>
                    <div class="inline-flex w-full rounded-lg border border-line bg-panel-2/50 p-0.5">
                        <button type="button" class="flex flex-1 items-center justify-center gap-1.5 rounded-md px-2.5 py-1.5 text-[11px] font-medium transition" :class="form.type === 'email' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'" @click="form.type = 'email'">
                            <Mail class="h-3 w-3" /> Email
                        </button>
                        <button type="button" class="flex flex-1 items-center justify-center gap-1.5 rounded-md px-2.5 py-1.5 text-[11px] font-medium transition" :class="form.type === 'webhook' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'" @click="form.type = 'webhook'">
                            <Webhook class="h-3 w-3" /> Webhook
                        </button>
                    </div>
                </div>

                <div v-if="form.type === 'email'">
                    <label for="channel-destination" class="label">Email recipients</label>
                    <textarea id="channel-destination" v-model="form.destination" class="input min-h-[74px] resize-y" maxlength="2000" placeholder="ops@example.com, oncall@example.com" />
                    <p class="mt-1 text-[11px] leading-4 text-dim">Comma-separated. Delivered through the <span class="font-mono text-muted">{{ mail.driver }}</span> mailer.</p>
                    <p v-if="form.errors.destination" class="mt-1 text-[11px] text-rose">{{ form.errors.destination }}</p>
                </div>

                <div v-else>
                    <label for="channel-destination" class="label">Webhook URL</label>
                    <input id="channel-destination" v-model="form.destination" class="input font-mono" maxlength="2000" placeholder="https://hooks.example.com/serverdeck" autocomplete="off" spellcheck="false" />
                    <p class="mt-1 text-[11px] leading-4 text-dim">Receives a JSON POST with the alert payload. Use HTTPS for anything outside this host.</p>
                    <p v-if="form.errors.destination" class="mt-1 text-[11px] text-rose">{{ form.errors.destination }}</p>
                </div>

                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="flex items-start gap-2.5 rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2.5">
                        <input v-model="form.notify_warning" type="checkbox" class="mt-0.5 h-3.5 w-3.5 rounded border-line bg-panel-2" />
                        <span>
                            <span class="block text-[11px] font-medium text-bright">Warnings</span>
                            <span class="mt-0.5 block text-[11px] leading-4 text-dim">Deliver warning transitions.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2.5 rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2.5">
                        <input v-model="form.notify_critical" type="checkbox" class="mt-0.5 h-3.5 w-3.5 rounded border-line bg-panel-2" />
                        <span>
                            <span class="block text-[11px] font-medium text-bright">Critical</span>
                            <span class="mt-0.5 block text-[11px] leading-4 text-dim">Deliver critical transitions.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2.5 rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2.5">
                        <input v-model="form.notify_recovery" type="checkbox" class="mt-0.5 h-3.5 w-3.5 rounded border-line bg-panel-2" />
                        <span>
                            <span class="block text-[11px] font-medium text-bright">Recovery</span>
                            <span class="mt-0.5 block text-[11px] leading-4 text-dim">Deliver when a check returns to normal.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2.5 rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2.5">
                        <input v-model="form.enabled" type="checkbox" class="mt-0.5 h-3.5 w-3.5 rounded border-line bg-panel-2" />
                        <span>
                            <span class="block text-[11px] font-medium text-bright">Enabled</span>
                            <span class="mt-0.5 block text-[11px] leading-4 text-dim">Include this channel in evaluations.</span>
                        </span>
                    </label>
                </div>

                <p v-if="form.errors.notify_warning" class="text-[11px] text-rose">{{ form.errors.notify_warning }}</p>

                <div class="rounded-lg border border-line/60 bg-panel-2/20 px-3 py-2.5 text-[11px] leading-5 text-dim">
                    Destinations are encrypted in the database. Changing a destination takes effect on the next evaluation.
                </div>
            </form>

            <template #footer>
                <button type="button" class="btn btn-sm" :disabled="form.processing" @click="channelOpen = false">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" :disabled="form.processing" @click="submitChannel">
                    <span v-if="form.processing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                    {{ editing ? 'Save channel' : 'Create channel' }}
                </button>
            </template>
        </Modal>

        <ConfirmDialog
            :show="Boolean(deleting)"
            title="Delete alert channel?"
            :description="deleting ? `${deleting.name} will stop receiving alerts. Delivery history is kept.` : ''"
            confirm-label="Delete channel"
            :loading="deleteWorking"
            @close="deleting = null"
            @confirm="confirmDelete"
        />
    </div>
</template>
