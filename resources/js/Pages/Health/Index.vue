<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowUpRight,
    CheckCircle2,
    CircleHelp,
    Gauge,
    HeartPulse,
    Info,
    RefreshCw,
    ShieldAlert,
    XCircle,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import GaugeRing from '@/Components/GaugeRing.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { formatDateTime, timeAgo } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    report: { type: Object, required: true },
    stack: { type: Object, required: true },
});

const summary = computed(() => props.report.summary ?? {});
const checks = computed(() => props.report.checks ?? []);
const integrations = computed(() => props.stack.integrations ?? []);

const healthyPercent = computed(() => Math.round((((summary.value.ok ?? 0) + (summary.value.info ?? 0)) / Math.max(1, summary.value.total ?? 0)) * 100));

const overall = computed(() => {
    const status = summary.value.overall ?? 'ok';

    return {
        ok: { label: 'Host healthy', text: 'text-accent', border: 'border-accent/25', bg: 'bg-accent/10', dot: 'online' },
        warning: { label: 'Attention needed', text: 'text-amber', border: 'border-amber/25', bg: 'bg-amber/10', dot: 'degraded' },
        critical: { label: 'Critical condition', text: 'text-rose', border: 'border-rose/25', bg: 'bg-rose/10', dot: 'offline' },
    }[status] ?? { label: status, text: 'text-muted', border: 'border-line', bg: 'bg-panel-2', dot: 'maintenance' };
});

const hardwareChecks = computed(() => checks.value.filter((check) => ['cpu', 'memory', 'disk', 'swap', 'temperature', 'gpu'].includes(check.key)));
const serviceChecks = computed(() => checks.value.filter((check) => !['cpu', 'memory', 'disk', 'swap', 'temperature', 'gpu'].includes(check.key)));

const statusMeta = {
    ok: { icon: CheckCircle2, text: 'text-accent', border: 'border-accent/20', bg: 'bg-accent/10', dot: 'online', label: 'Healthy' },
    info: { icon: Info, text: 'text-cyan', border: 'border-cyan/20', bg: 'bg-cyan/10', dot: 'online', label: 'Info' },
    warning: { icon: AlertTriangle, text: 'text-amber', border: 'border-amber/20', bg: 'bg-amber/10', dot: 'degraded', label: 'Warning' },
    critical: { icon: XCircle, text: 'text-rose', border: 'border-rose/25', bg: 'bg-rose/10', dot: 'offline', label: 'Critical' },
};

const integrationMeta = {
    connected: { dot: 'online', label: 'connected', tone: 'border-accent/25 bg-accent/10 text-accent' },
    attention: { dot: 'degraded', label: 'attention', tone: 'border-amber/25 bg-amber/10 text-amber' },
    unavailable: { dot: 'offline', label: 'unavailable', tone: 'border-rose/25 bg-rose/10 text-rose' },
    installed: { dot: 'maintenance', label: 'installed', tone: 'border-line bg-panel-2 text-dim' },
    'not-installed': { dot: 'maintenance', label: 'not installed', tone: 'border-line bg-panel-2 text-dim' },
};

const percentages = new Set(['cpu', 'memory', 'disk', 'swap', 'temperature']);

const resources = computed(() => summary.value.resources ?? {});

function refresh() {
    router.reload({ preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head title="Health checks" />

    <div class="space-y-5">
        <PageHeader
            title="Health checks"
            subtitle="Live checks computed from this host's hardware, systemd, Docker, Incus and ZFS state."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot :status="overall.dot" :pulse="overall.dot !== 'online'" />
                    {{ overall.label }}
                    <span class="text-dim">·</span>
                    {{ summary.ok ?? 0 }}/{{ summary.total ?? 0 }} checks clean
                    <span class="text-dim">·</span>
                    generated {{ timeAgo(report.generated_at) }}
                </span>
            </template>
            <template #actions>
                <button type="button" class="btn btn-sm" @click="refresh">
                    <RefreshCw class="h-3.5 w-3.5" /> Re-run checks
                </button>
            </template>
        </PageHeader>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Overall" :value="overall.label" :subtitle="`${summary.total ?? 0} live checks`" :icon="HeartPulse" :tone="summary.overall === 'critical' ? 'rose' : summary.overall === 'warning' ? 'amber' : 'accent'" :detail="`Generated ${formatDateTime(report.generated_at)}`" />
            <StatCard label="Clean checks" :value="summary.ok ?? 0" :subtitle="`${summary.info ?? 0} informational`" :icon="CheckCircle2" tone="accent" :detail="`${healthyPercent}% of all checks healthy`" />
            <StatCard label="Warnings" :value="summary.warning ?? 0" subtitle="Degraded but operational" :icon="AlertTriangle" :tone="(summary.warning ?? 0) > 0 ? 'amber' : 'accent'" :detail="(summary.warning ?? 0) > 0 ? 'Review the checks below' : 'Nothing degraded'" />
            <StatCard label="Critical" :value="summary.critical ?? 0" subtitle="Require immediate action" :icon="ShieldAlert" :tone="(summary.critical ?? 0) > 0 ? 'rose' : 'accent'" :detail="(summary.critical ?? 0) > 0 ? 'Open the related page' : 'No critical conditions'" />
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <section class="panel p-5">
                <div class="flex items-center gap-5">
                    <GaugeRing :value="healthyPercent" :size="124" label="Healthy" :tone="summary.overall === 'critical' ? 'rose' : summary.overall === 'warning' ? 'amber' : 'accent'" />
                    <div class="min-w-0 flex-1">
                        <div class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-medium" :class="[overall.border, overall.bg, overall.text]">
                            <StatusDot :status="overall.dot" />
                            {{ overall.label }}
                        </div>
                        <p class="mt-3 text-[11px] leading-5 text-muted">
                            <template v-if="summary.attention">
                                {{ summary.attention }} check{{ summary.attention === 1 ? '' : 's' }} need attention across this host.
                            </template>
                            <template v-else>
                                Every configured check is passing. Health data is recomputed from live host state and cached for 20 seconds.
                            </template>
                        </p>
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-2 border-t border-line/60 pt-4 text-[11px]">
                    <div class="rounded-lg border border-line/70 bg-panel-2/30 px-2.5 py-2">
                        <span class="block text-dim">Docker</span>
                        <span class="mt-0.5 block font-mono text-muted">{{ resources.docker?.running ?? 0 }}/{{ resources.docker?.all ?? 0 }} running</span>
                    </div>
                    <div class="rounded-lg border border-line/70 bg-panel-2/30 px-2.5 py-2">
                        <span class="block text-dim">Incus</span>
                        <span class="mt-0.5 block font-mono text-muted">{{ resources.incus?.running ?? 0 }}/{{ resources.incus?.all ?? 0 }} running</span>
                    </div>
                    <div class="rounded-lg border border-line/70 bg-panel-2/30 px-2.5 py-2">
                        <span class="block text-dim">ZFS pools</span>
                        <span class="mt-0.5 block font-mono text-muted">{{ resources.zfs?.pools ?? 0 }} · {{ resources.zfs?.degraded ?? 0 }} degraded</span>
                    </div>
                    <div class="rounded-lg border border-line/70 bg-panel-2/30 px-2.5 py-2">
                        <span class="block text-dim">systemd</span>
                        <span class="mt-0.5 block font-mono text-muted">{{ resources.systemd?.failed ?? 0 }} failed / {{ resources.systemd?.all ?? 0 }}</span>
                    </div>
                </div>
            </section>

            <section class="panel overflow-hidden xl:col-span-2">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Attention queue</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Checks that are warning or critical right now</p>
                    </div>
                    <Gauge class="h-4 w-4 text-dim" />
                </div>

                <div v-if="(summary.active ?? []).length" class="divide-y divide-line/50">
                    <Link
                        v-for="check in summary.active"
                        :key="check.key"
                        :href="check.href"
                        class="flex items-start gap-3 border-l-2 px-4 py-3 transition hover:bg-white/[0.03]"
                        :class="check.status === 'critical' ? 'border-l-rose' : 'border-l-amber'"
                    >
                        <component :is="statusMeta[check.status]?.icon ?? CircleHelp" class="mt-0.5 h-4 w-4 shrink-0" :class="statusMeta[check.status]?.text ?? 'text-dim'" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-[11px] font-medium text-bright">{{ check.title }}</span>
                                <span class="badge" :class="statusMeta[check.status]?.border + ' ' + statusMeta[check.status]?.bg + ' ' + statusMeta[check.status]?.text">{{ statusMeta[check.status]?.label ?? check.status }}</span>
                            </div>
                            <p class="mt-0.5 text-[11px] leading-5 text-dim">{{ check.detail }}</p>
                        </div>
                        <ArrowUpRight class="mt-0.5 h-3.5 w-3.5 shrink-0 text-dim" />
                    </Link>
                </div>
                <div v-else class="flex items-center gap-2 px-4 py-8 text-[11px] text-accent">
                    <CheckCircle2 class="h-4 w-4" /> Nothing needs attention.
                </div>
            </section>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="panel overflow-hidden">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Hardware & resources</h2>
                        <p class="mt-0.5 text-[11px] text-dim">CPU, memory, storage, thermals and acceleration</p>
                    </div>
                    <Gauge class="h-4 w-4 text-dim" />
                </div>

                <div class="divide-y divide-line/50">
                    <div v-for="check in hardwareChecks" :key="check.key" class="px-4 py-3.5">
                        <div class="flex items-start gap-3">
                            <component :is="statusMeta[check.status]?.icon ?? CircleHelp" class="mt-0.5 h-4 w-4 shrink-0" :class="statusMeta[check.status]?.text ?? 'text-dim'" />
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-[11px] font-medium text-bright">{{ check.title }}</span>
                                    <span class="badge" :class="statusMeta[check.status]?.border + ' ' + statusMeta[check.status]?.bg + ' ' + statusMeta[check.status]?.text">{{ statusMeta[check.status]?.label ?? check.status }}</span>
                                </div>
                                <p class="mt-0.5 text-[11px] leading-5 text-dim">{{ check.detail }}</p>
                                <div v-if="percentages.has(check.key)" class="mt-2 h-1.5 overflow-hidden rounded-full bg-white/[0.05]">
                                    <div
                                        class="h-full rounded-full transition-all duration-500"
                                        :class="check.status === 'critical' ? 'bg-rose' : check.status === 'warning' ? 'bg-amber' : 'bg-accent'"
                                        :style="{ width: `${Math.min(100, Number(check.value))}%` }"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="panel overflow-hidden">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Services & platform</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Daemons, container runtimes and storage pools</p>
                    </div>
                    <HeartPulse class="h-4 w-4 text-dim" />
                </div>

                <div class="divide-y divide-line/50">
                    <div v-for="check in serviceChecks" :key="check.key" class="px-4 py-3.5">
                        <div class="flex items-start gap-3">
                            <component :is="statusMeta[check.status]?.icon ?? CircleHelp" class="mt-0.5 h-4 w-4 shrink-0" :class="statusMeta[check.status]?.text ?? 'text-dim'" />
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-[11px] font-medium text-bright">{{ check.title }}</span>
                                    <Link :href="check.href" class="text-[11px] font-medium text-accent hover:text-accent/80">Open</Link>
                                </div>
                                <p class="mt-0.5 text-[11px] leading-5 text-dim">{{ check.detail }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <section class="panel overflow-hidden">
            <div class="panel-header">
                <div>
                    <h2 class="text-sm font-semibold text-bright">Detected stack</h2>
                    <p class="mt-0.5 text-[11px] text-dim">{{ stack.installed }} integrations detected on this host</p>
                </div>
                <span class="badge border-line bg-panel-2 text-dim">{{ integrations.length }} total</span>
            </div>

            <div class="grid gap-0 divide-y divide-line/50 sm:grid-cols-2 sm:divide-x lg:grid-cols-3">
                <a
                    v-for="item in integrations"
                    :key="item.key"
                    :href="item.href"
                    class="flex items-start gap-3 px-4 py-3 transition hover:bg-white/[0.03]"
                >
                    <StatusDot :status="integrationMeta[item.status]?.dot ?? 'maintenance'" :pulse="item.status === 'attention'" class="mt-1" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[11px] font-medium text-bright">{{ item.name }}</span>
                            <span class="rounded bg-white/[0.05] px-1 py-0.5 font-mono text-[10px] uppercase text-dim">{{ item.category }}</span>
                            <span class="badge" :class="integrationMeta[item.status]?.tone ?? 'border-line bg-panel-2 text-dim'">{{ integrationMeta[item.status]?.label ?? item.status }}</span>
                        </div>
                        <p class="mt-0.5 text-[11px] leading-5 text-dim">{{ item.detail }}</p>
                    </div>
                    <ArrowUpRight class="mt-0.5 h-3 w-3 shrink-0 text-dim" />
                </a>
            </div>
        </section>
    </div>
</template>
