<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Clock,
    Cpu,
    Fingerprint,
    HardDrive,
    KeyRound,
    MonitorCog,
    Rocket,
    Save,
    Share2,
    ShieldCheck,
    TerminalSquare,
    UserRound,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusDot from '@/Components/StatusDot.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { formatUptime, timeAgo } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    user: { type: Object, required: true },
    host: { type: Object, required: true },
    integrations: { type: Object, default: null },
});

const page = usePage();
const permissions = computed(() => page.props.permissions ?? {});
const saved = ref(false);
const dokploySaved = ref(false);
const hubSaved = ref(false);
const hubReporting = ref(false);

const form = useForm({
    name: props.user.name ?? '',
    title: props.user.title ?? '',
});

const dokployForm = useForm({
    dokploy_url: props.integrations?.dokploy?.url ?? 'http://127.0.0.1:3000',
    dokploy_token: '',
    clear_token: false,
});

const hubForm = useForm({
    hub_url: props.integrations?.hub?.url ?? '',
    hub_token: '',
    clear_token: false,
});

const roleLabel = computed(() => ({
    admin: 'Administrator',
    operator: 'Operator',
    viewer: 'Viewer',
}[props.user.role] ?? props.user.role));

const roleDescription = computed(() => ({
    admin: 'Full access to this host, including the terminal, access management and the audit trail.',
    operator: 'Can start, stop and restart workloads, services, containers and ZFS resources.',
    viewer: 'Read-only visibility into host telemetry, workloads, logs and health.',
}[props.user.role] ?? ''));

const facts = computed(() => [
    { icon: MonitorCog, label: 'Operating system', value: `${props.host.os} · kernel ${props.host.kernel}` },
    { icon: Cpu, label: 'Processor', value: `${props.host.cpu_model} · ${props.host.cpu_threads} threads` },
    { icon: HardDrive, label: 'Hostname', value: props.host.hostname },
    { icon: Clock, label: 'Uptime', value: formatUptime(props.host.uptime_seconds) },
]);

function save() {
    saved.value = false;

    form.put('/settings/profile', {
        preserveScroll: true,
        onSuccess: () => {
            saved.value = true;
            window.setTimeout(() => (saved.value = false), 3500);
        },
    });
}

function saveDokploy() {
    dokploySaved.value = false;

    dokployForm.put('/settings/dokploy', {
        preserveScroll: true,
        onSuccess: () => {
            dokploySaved.value = true;
            dokployForm.reset('dokploy_token', 'clear_token');
            window.setTimeout(() => (dokploySaved.value = false), 3500);
        },
    });
}

function saveHub() {
    hubSaved.value = false;

    hubForm.put('/settings/hub', {
        preserveScroll: true,
        onSuccess: () => {
            hubSaved.value = true;
            hubForm.reset('hub_token', 'clear_token');
            window.setTimeout(() => (hubSaved.value = false), 3500);
        },
    });
}

function reportHubNow() {
    hubReporting.value = true;

    router.post('/settings/hub/report', {}, {
        preserveScroll: true,
        onFinish: () => {
            hubReporting.value = false;
        },
    });
}
</script>

<template>
    <Head title="Settings" />

    <div class="space-y-5">
        <PageHeader
            title="Settings"
            subtitle="Your ServerDeck profile and the Linux identity behind it."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot status="online" />
                    {{ user.username }}@{{ host.hostname }}
                    <span class="text-dim">·</span>
                    {{ roleLabel }}
                </span>
            </template>
        </PageHeader>

        <div class="grid gap-4 xl:grid-cols-3">
            <div class="space-y-4 xl:col-span-2">
                <section class="panel overflow-hidden">
                    <div class="panel-header">
                        <div>
                            <h2 class="text-sm font-semibold text-bright">Profile</h2>
                            <p class="mt-0.5 text-[11px] text-dim">How your account appears inside ServerDeck</p>
                        </div>
                        <UserRound class="h-4 w-4 text-dim" />
                    </div>

                    <form class="space-y-4 p-5" @submit.prevent="save">
                        <div class="flex items-center gap-4 rounded-xl border border-line bg-panel-2/40 p-4">
                            <UserAvatar :name="user.name" size="lg" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium text-bright">{{ user.name }}</div>
                                <div class="mt-0.5 truncate font-mono text-[11px] text-dim">{{ user.username }} · uid {{ user.linux_uid ?? '—' }}</div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <span v-for="group in user.linux_groups ?? []" :key="group" class="rounded bg-white/[0.05] px-1.5 py-0.5 font-mono text-[10px] text-dim">{{ group }}</span>
                                </div>
                            </div>
                            <span class="badge border-accent/25 bg-accent/10 text-accent">{{ roleLabel }}</span>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="profile-name" class="label">Display name</label>
                                <input id="profile-name" v-model="form.name" class="input" maxlength="80" required />
                                <p v-if="form.errors.name" class="mt-1 text-[11px] text-rose">{{ form.errors.name }}</p>
                            </div>
                            <div>
                                <label for="profile-title" class="label">Title</label>
                                <input id="profile-title" v-model="form.title" class="input" maxlength="100" placeholder="Host administrator" />
                                <p v-if="form.errors.title" class="mt-1 text-[11px] text-rose">{{ form.errors.title }}</p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line/60 pt-4">
                            <p class="text-[11px] text-dim">Your Linux username and UID are managed by the host.</p>
                            <div class="flex items-center gap-2">
                                <span v-if="saved" class="inline-flex items-center gap-1 text-[11px] text-accent">
                                    <CheckCircle2 class="h-3 w-3" /> Saved
                                </span>
                                <button type="submit" class="btn btn-primary btn-sm" :disabled="form.processing">
                                    <span v-if="form.processing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                    <Save v-else class="h-3 w-3" />
                                    Save profile
                                </button>
                            </div>
                        </div>
                    </form>
                </section>

                <section class="panel overflow-hidden">
                    <div class="panel-header">
                        <div>
                            <h2 class="text-sm font-semibold text-bright">Authentication</h2>
                            <p class="mt-0.5 text-[11px] text-dim">PAM validates your host password on every sign-in</p>
                        </div>
                        <Fingerprint class="h-4 w-4 text-cyan" />
                    </div>

                    <div class="divide-y divide-line/50">
                        <div class="flex items-start gap-3 px-5 py-3.5">
                            <KeyRound class="mt-0.5 h-4 w-4 shrink-0 text-cyan" />
                            <div class="min-w-0 flex-1">
                                <div class="text-[11px] font-medium text-bright">Password changes happen on the host</div>
                                <p class="mt-0.5 text-[11px] leading-5 text-dim">
                                    ServerDeck does not store or change passwords. Run
                                    <span class="font-mono text-muted">passwd</span> in the terminal or over SSH, then sign in with the new password.
                                </p>
                            </div>
                            <Link v-if="permissions.useTerminal" href="/terminal" class="btn btn-sm shrink-0">
                                <TerminalSquare class="h-3 w-3" /> Terminal
                            </Link>
                        </div>

                        <div class="flex items-start gap-3 px-5 py-3.5">
                            <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-accent" />
                            <div class="min-w-0 flex-1">
                                <div class="text-[11px] font-medium text-bright">{{ roleLabel }}</div>
                                <p class="mt-0.5 text-[11px] leading-5 text-dim">{{ roleDescription }}</p>
                            </div>
                            <span class="badge border-line bg-panel-2 text-dim">{{ user.linux_groups?.includes('sudo') ? 'sudo member' : 'standard user' }}</span>
                        </div>

                        <div class="flex items-start gap-3 px-5 py-3.5">
                            <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0 text-accent" />
                            <div class="min-w-0 flex-1">
                                <div class="text-[11px] font-medium text-bright">Access is active</div>
                                <p class="mt-0.5 text-[11px] leading-5 text-dim">
                                    An administrator can disable this account or change its panel role from the Access page.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
                <section v-if="integrations" class="panel overflow-hidden">
                    <div class="panel-header">
                        <div>
                            <h2 class="text-sm font-semibold text-bright">Integrations</h2>
                            <p class="mt-0.5 text-[11px] text-dim">Services ServerDeck can manage through their APIs</p>
                        </div>
                        <KeyRound class="h-4 w-4 text-cyan" />
                    </div>

                    <div class="space-y-4 p-5">
                        <div class="flex items-start gap-3 rounded-xl border border-line bg-panel-2/40 p-4">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-cyan/20 bg-cyan/10 text-cyan">
                                <Rocket class="h-4 w-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[11px] font-medium text-bright">Dokploy</span>
                                    <span class="badge" :class="integrations.dokploy?.configured ? 'border-accent/25 bg-accent/10 text-accent' : 'border-line bg-panel-2 text-dim'">
                                        <StatusDot :status="integrations.dokploy?.configured ? 'online' : 'maintenance'" />
                                        {{ integrations.dokploy?.configured ? 'configured' : 'not configured' }}
                                    </span>
                                </div>
                                <p class="mt-0.5 text-[11px] leading-5 text-dim">
                                    Talks to the Dokploy REST API. The token is encrypted at rest and never returned to the browser.
                                </p>
                            </div>
                            <Link v-if="integrations.dokploy?.configured" href="/dokploy" class="btn btn-sm shrink-0">Open</Link>
                        </div>

                        <form class="space-y-4" @submit.prevent="saveDokploy">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="dokploy-url" class="label">Dokploy base URL</label>
                                    <input id="dokploy-url" v-model="dokployForm.dokploy_url" class="input font-mono" placeholder="http://127.0.0.1:3000" autocomplete="off" />
                                    <p class="mt-1 text-[11px] text-dim">Do not include the <span class="font-mono">/api</span> suffix.</p>
                                    <p v-if="dokployForm.errors.dokploy_url" class="mt-1 text-[11px] text-rose">{{ dokployForm.errors.dokploy_url }}</p>
                                </div>
                                <div>
                                    <label for="dokploy-token" class="label">API token</label>
                                    <input id="dokploy-token" v-model="dokployForm.dokploy_token" type="password" class="input font-mono" autocomplete="new-password" :placeholder="integrations.dokploy?.token_hint ?? 'Paste a Dokploy API key'" />
                                    <p class="mt-1 text-[11px] text-dim">
                                        Leave blank to keep the current token<template v-if="integrations.dokploy?.token_hint"> ({{ integrations.dokploy.token_hint }})</template>.
                                    </p>
                                    <p v-if="dokployForm.errors.dokploy_token" class="mt-1 text-[11px] text-rose">{{ dokployForm.errors.dokploy_token }}</p>
                                </div>
                            </div>

                            <label v-if="integrations.dokploy?.configured" class="flex cursor-pointer items-center gap-2 text-[11px] text-muted">
                                <input v-model="dokployForm.clear_token" type="checkbox" class="h-3.5 w-3.5 rounded border-line bg-panel-2" />
                                Remove the stored Dokploy token
                            </label>

                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line/60 pt-4">
                                <p class="text-[11px] text-dim">Generate a key in Dokploy under Settings → API Keys.</p>
                                <div class="flex items-center gap-2">
                                    <span v-if="dokploySaved" class="inline-flex items-center gap-1 text-[11px] text-accent">
                                        <CheckCircle2 class="h-3 w-3" /> Saved
                                    </span>
                                    <button type="submit" class="btn btn-primary btn-sm" :disabled="dokployForm.processing">
                                        <span v-if="dokployForm.processing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                        <Save v-else class="h-3 w-3" />
                                        Save integration
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </section>

                <section class="panel overflow-hidden">
                    <div class="panel-header">
                        <div>
                            <h2 class="text-sm font-semibold text-bright">Hub reporting</h2>
                            <p class="mt-0.5 text-[11px] text-dim">Opt-in, one-way status snapshots to a ServerDeck Hub</p>
                        </div>
                        <Share2 class="h-4 w-4 text-cyan" />
                    </div>

                    <div class="space-y-4 p-5">
                        <div class="flex items-start gap-3 rounded-xl border border-line bg-panel-2/40 p-4">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-cyan/20 bg-cyan/10 text-cyan">
                                <Share2 class="h-4 w-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[11px] font-medium text-bright">ServerDeck Hub</span>
                                    <span class="badge" :class="integrations.hub?.configured ? 'border-accent/25 bg-accent/10 text-accent' : 'border-line bg-panel-2 text-dim'">
                                        <StatusDot :status="integrations.hub?.configured ? 'online' : 'maintenance'" />
                                        {{ integrations.hub?.configured ? 'configured' : 'not configured' }}
                                    </span>
                                </div>
                                <p class="mt-0.5 text-[11px] leading-5 text-dim">
                                    Sends a small, fixed status snapshot (CPU/memory/disk, workload counts, health
                                    summary) to a Hub every few minutes. One-way only — the Hub can never act on
                                    this host.
                                </p>
                                <p v-if="integrations.hub?.configured" class="mt-1 text-[11px] text-dim">
                                    Last report:
                                    <span :class="integrations.hub?.last_status === 'ok' ? 'text-accent' : 'text-rose'">
                                        {{ integrations.hub?.last_reported_at ? timeAgo(integrations.hub.last_reported_at) : 'never' }}
                                        <template v-if="integrations.hub?.last_status && integrations.hub.last_status !== 'ok'">({{ integrations.hub.last_status }})</template>
                                    </span>
                                </p>
                            </div>
                            <button
                                v-if="integrations.hub?.configured"
                                type="button"
                                class="btn btn-sm shrink-0"
                                :disabled="hubReporting"
                                @click="reportHubNow"
                            >
                                <span v-if="hubReporting" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                Report now
                            </button>
                        </div>

                        <form class="space-y-4" @submit.prevent="saveHub">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="hub-url" class="label">Hub URL</label>
                                    <input id="hub-url" v-model="hubForm.hub_url" class="input font-mono" placeholder="https://hub.example.com" autocomplete="off" />
                                    <p class="mt-1 text-[11px] text-dim">Can be a Tailscale/Twingate-only address — it never needs to be public.</p>
                                    <p v-if="hubForm.errors.hub_url" class="mt-1 text-[11px] text-rose">{{ hubForm.errors.hub_url }}</p>
                                </div>
                                <div>
                                    <label for="hub-token" class="label">Enrollment token</label>
                                    <input id="hub-token" v-model="hubForm.hub_token" type="password" class="input font-mono" autocomplete="new-password" :placeholder="integrations.hub?.token_hint ?? 'Paste the token from the Hub'" />
                                    <p class="mt-1 text-[11px] text-dim">
                                        Leave blank to keep the current token<template v-if="integrations.hub?.token_hint"> ({{ integrations.hub.token_hint }})</template>.
                                    </p>
                                    <p v-if="hubForm.errors.hub_token" class="mt-1 text-[11px] text-rose">{{ hubForm.errors.hub_token }}</p>
                                </div>
                            </div>

                            <label v-if="integrations.hub?.configured" class="flex cursor-pointer items-center gap-2 text-[11px] text-muted">
                                <input v-model="hubForm.clear_token" type="checkbox" class="h-3.5 w-3.5 rounded border-line bg-panel-2" />
                                Remove the stored Hub token (stops reporting)
                            </label>

                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line/60 pt-4">
                                <p class="text-[11px] text-dim">Leave the URL blank to disable reporting entirely.</p>
                                <div class="flex items-center gap-2">
                                    <span v-if="hubSaved" class="inline-flex items-center gap-1 text-[11px] text-accent">
                                        <CheckCircle2 class="h-3 w-3" /> Saved
                                    </span>
                                    <button type="submit" class="btn btn-primary btn-sm" :disabled="hubForm.processing">
                                        <span v-if="hubForm.processing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                        <Save v-else class="h-3 w-3" />
                                        Save integration
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </section>
            </div>

            <div class="space-y-4">
                <section class="panel overflow-hidden">
                    <div class="panel-header">
                        <div>
                            <h2 class="text-sm font-semibold text-bright">Host identity</h2>
                            <p class="mt-0.5 text-[11px] text-dim">The machine behind this panel</p>
                        </div>
                        <HardDrive class="h-4 w-4 text-dim" />
                    </div>
                    <div class="divide-y divide-line/50">
                        <div v-for="fact in facts" :key="fact.label" class="flex items-center gap-3 px-4 py-3">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border border-line bg-panel-2 text-dim">
                                <component :is="fact.icon" class="h-3.5 w-3.5" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="text-[11px] font-semibold uppercase tracking-wider text-dim">{{ fact.label }}</div>
                                <div class="mt-0.5 truncate text-[11px] text-muted">{{ fact.value }}</div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="panel p-4">
                    <div class="flex items-center gap-2 text-[11px] font-medium text-bright">
                        <Fingerprint class="h-3.5 w-3.5 text-cyan" /> Linux identity
                    </div>
                    <dl class="mt-3 space-y-2 text-[11px]">
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-dim">Username</dt>
                            <dd class="font-mono text-muted">{{ user.username }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-dim">UID</dt>
                            <dd class="font-mono text-muted">{{ user.linux_uid ?? '—' }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-2">
                            <dt class="text-dim">Groups</dt>
                            <dd class="text-right font-mono text-[11px] text-muted">{{ (user.linux_groups ?? []).join(', ') || '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-dim">Member since</dt>
                            <dd class="text-muted">{{ user.created_at ? new Date(user.created_at).toLocaleDateString() : '—' }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="panel p-4">
                    <div class="flex items-center gap-2 text-[11px] font-medium text-bright">
                        <ShieldCheck class="h-3.5 w-3.5 text-accent" /> Security model
                    </div>
                    <p class="mt-2 text-[11px] leading-5 text-dim">
                        Authentication is delegated to PAM on {{ host.hostname }}. Privileged host actions pass through a
                        root-owned helper with a fixed allow-list, and every action is attributed to your Linux account in the audit log.
                    </p>
                </section>
            </div>
        </div>
    </div>
</template>
