<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CircleDot,
    Download,
    Pause,
    Play,
    RefreshCw,
    ScrollText,
    Search,
    TerminalSquare,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { api } from '@/lib/http';
import { formatDateTime, timeAgo } from '@/lib/format';
import { pushToast } from '@/lib/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    journal: { type: Object, required: true },
    filters: { type: Object, required: true },
    units: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
});

const filters = reactive({
    search: props.filters.search ?? '',
    unit: props.filters.unit ?? '',
    priority: props.filters.priority ?? '',
});

const live = ref(false);
const loading = ref(false);
const entries = ref(props.journal.entries ?? []);
const summary = ref(props.summary);
const lastUpdated = ref(new Date());
const liveError = ref(null);
let debounce = null;
let timer = null;

const hasFilters = computed(() => Boolean(filters.search || filters.unit || filters.priority));

const levelTone = {
    error: 'border-rose/25 bg-rose/10 text-rose',
    warning: 'border-amber/25 bg-amber/10 text-amber',
    info: 'border-cyan/25 bg-cyan/10 text-cyan',
    debug: 'border-line bg-panel-2 text-dim',
};

const levelDot = {
    error: 'offline',
    warning: 'degraded',
    info: 'online',
    debug: 'maintenance',
};

function queryParams(extra = {}) {
    return Object.fromEntries(
        Object.entries({
            search: filters.search.trim() || undefined,
            unit: filters.unit || undefined,
            priority: filters.priority || undefined,
            ...extra,
        }).filter(([, value]) => value !== undefined && value !== ''),
    );
}

function apply() {
    if (live.value) {
        loadTail();

        return;
    }

    router.get('/logs', queryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['journal', 'filters', 'summary'],
    });
}

async function loadTail() {
    if (loading.value) return;

    loading.value = true;

    try {
        const data = await api.get(`/logs/tail?${new URLSearchParams(queryParams())}`);
        entries.value = data.entries ?? [];
        summary.value = data.summary ?? summary.value;
        liveError.value = data.available ? null : (data.error ?? 'Journal is unavailable.');
        lastUpdated.value = new Date();
    } catch (error) {
        liveError.value = error.message ?? 'Could not refresh the journal.';
    } finally {
        loading.value = false;
    }
}

function toggleLive() {
    live.value = !live.value;

    if (live.value) {
        loadTail();
        timer = window.setInterval(loadTail, 5000);
        pushToast('Live journal tail enabled.', 'info', 'Journal');
    } else {
        window.clearInterval(timer);
        timer = null;
    }
}

function reload() {
    if (live.value) {
        loadTail();
    } else {
        router.reload({ preserveScroll: true, preserveState: true });
    }
}

function clearFilters() {
    filters.search = '';
    filters.unit = '';
    filters.priority = '';
}

function download() {
    const text = entries.value
        .map((entry) => `${entry.timestamp ?? ''} [${entry.priority}] ${entry.unit ?? entry.source ?? 'host'}: ${entry.message}`)
        .join('\n');

    const blob = new Blob([text], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `journal-${new Date().toISOString().slice(0, 19).replaceAll(':', '-')}.log`;
    link.click();
    URL.revokeObjectURL(url);
}

watch(
    () => filters.search,
    () => {
        window.clearTimeout(debounce);
        debounce = window.setTimeout(apply, 350);
    },
);

watch(() => [filters.unit, filters.priority], apply);

watch(
    () => props.journal.entries,
    (value) => {
        if (!live.value) entries.value = value ?? [];
    },
);

watch(
    () => props.summary,
    (value) => {
        if (!live.value) summary.value = value;
    },
);

onBeforeUnmount(() => {
    window.clearTimeout(debounce);
    window.clearInterval(timer);
});
</script>

<template>
    <Head title="Journal" />

    <div class="space-y-5">
        <PageHeader
            title="Journal"
            subtitle="Read journald directly on this host. Filter by unit, priority or message content."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot :status="journal.available ? 'online' : 'offline'" :pulse="journal.available && live" />
                    journald
                    <span class="text-dim">·</span>
                    {{ entries.length }} entries
                    <span class="text-dim">·</span>
                    updated {{ timeAgo(lastUpdated) }}
                    <template v-if="live">
                        <span class="text-dim">·</span>
                        <span class="text-accent">live</span>
                    </template>
                </span>
            </template>
            <template #actions>
                <button type="button" class="btn btn-sm" :disabled="loading" @click="reload">
                    <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': loading }" /> Refresh
                </button>
                <button
                    type="button"
                    class="btn btn-sm"
                    :class="live ? 'border-rose/30 text-rose hover:border-rose/50' : 'btn-primary'"
                    @click="toggleLive"
                >
                    <Pause v-if="live" class="h-3.5 w-3.5" />
                    <Play v-else class="h-3.5 w-3.5" />
                    {{ live ? 'Pause tail' : 'Live tail' }}
                </button>
            </template>
        </PageHeader>

        <div v-if="!journal.available || liveError" class="flex items-start gap-2.5 rounded-xl border border-rose/25 bg-rose/10 px-4 py-3 text-[11px] leading-5 text-rose">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ journal.error || liveError }}</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="panel p-5">
                <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-dim">Entries in view</div>
                <div class="mt-2 text-[26px] font-semibold leading-none text-bright">{{ summary.total ?? 0 }}</div>
                <div class="mt-2 text-xs text-muted">last {{ entries.length }} matching records</div>
            </div>
            <div class="panel p-5">
                <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-dim">Errors</div>
                <div class="mt-2 text-[26px] font-semibold leading-none" :class="(summary.errors ?? 0) > 0 ? 'text-rose' : 'text-bright'">{{ summary.errors ?? 0 }}</div>
                <div class="mt-2 text-xs text-muted">priority err and above</div>
            </div>
            <div class="panel p-5">
                <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-dim">Warnings</div>
                <div class="mt-2 text-[26px] font-semibold leading-none" :class="(summary.warnings ?? 0) > 0 ? 'text-amber' : 'text-bright'">{{ summary.warnings ?? 0 }}</div>
                <div class="mt-2 text-xs text-muted">priority warning</div>
            </div>
            <div class="panel p-5">
                <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-dim">Sources</div>
                <div class="mt-2 text-[26px] font-semibold leading-none text-bright">{{ summary.sources ?? 0 }}</div>
                <div class="mt-2 text-xs text-muted">distinct journal identifiers</div>
            </div>
        </div>

        <section class="panel overflow-hidden">
            <div class="flex flex-wrap items-center gap-2 border-b border-line/70 px-4 py-3">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-dim" />
                    <input v-model="filters.search" type="search" class="input h-8 w-64 py-1 pl-8 text-xs" placeholder="Search messages…" aria-label="Search journal messages" />
                </div>

                <select v-model="filters.unit" class="input h-8 w-auto max-w-[240px] py-1 text-xs" aria-label="Filter by unit">
                    <option value="">All units</option>
                    <option v-for="unit in units" :key="unit" :value="unit">{{ unit }}</option>
                </select>

                <select v-model="filters.priority" class="input h-8 w-auto py-1 text-xs" aria-label="Filter by priority">
                    <option value="">Any priority</option>
                    <option v-for="priority in priorities" :key="priority" :value="priority">{{ priority }}</option>
                </select>

                <button v-if="hasFilters" type="button" class="btn btn-sm" @click="clearFilters">Clear</button>

                <div class="ml-auto flex items-center gap-2">
                    <span class="hidden font-mono text-[11px] text-dim sm:inline">{{ lastUpdated.toLocaleTimeString() }}</span>
                    <button type="button" class="btn btn-sm" :disabled="!entries.length" @click="download">
                        <Download class="h-3 w-3" /> Export
                    </button>
                </div>
            </div>

            <div v-if="entries.length" class="max-h-[640px] divide-y divide-line/40 overflow-y-auto">
                <div
                    v-for="(entry, index) in entries"
                    :key="`${entry.timestamp}-${index}`"
                    class="flex gap-3 px-4 py-2.5 transition hover:bg-white/[0.02]"
                >
                    <div class="hidden w-[138px] shrink-0 pt-0.5 font-mono text-[11px] leading-4 text-dim sm:block">
                        <div>{{ formatDateTime(entry.timestamp) }}</div>
                        <div class="text-[11px]">{{ entry.pid ? `pid ${entry.pid}` : '' }}</div>
                    </div>

                    <span class="mt-1 shrink-0">
                        <StatusDot :status="levelDot[entry.level] ?? 'maintenance'" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="badge" :class="levelTone[entry.level] ?? levelTone.debug">{{ entry.priority }}</span>
                            <span v-if="entry.unit" class="font-mono text-[11px] text-cyan">{{ entry.unit }}</span>
                            <span v-else-if="entry.source" class="font-mono text-[11px] text-muted">{{ entry.source }}</span>
                            <span v-if="entry.source && entry.unit" class="font-mono text-[11px] text-dim">{{ entry.source }}</span>
                            <span class="ml-auto font-mono text-[11px] text-dim sm:hidden">{{ formatDateTime(entry.timestamp) }}</span>
                        </div>
                        <p class="mt-1 whitespace-pre-wrap break-words font-mono text-[11px] leading-5" :class="entry.level === 'error' ? 'text-rose' : entry.level === 'warning' ? 'text-amber' : 'text-muted'">{{ entry.message }}</p>
                    </div>
                </div>
            </div>

            <EmptyState
                v-else
                :icon="ScrollText"
                title="No journal entries"
                :description="hasFilters ? 'Nothing matched these filters. Try widening the search.' : 'journald returned no records for this host.'"
            />

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line/70 px-5 py-3 text-[11px] text-dim">
                <span>Showing {{ entries.length }} entries · newest first</span>
                <span class="flex items-center gap-1.5"><CircleDot class="h-3 w-3" /> {{ live ? 'Refreshing every 5 seconds' : 'Manual refresh' }}</span>
            </div>
        </section>

        <div class="flex items-start gap-2.5 rounded-xl border border-line/60 bg-panel/40 px-4 py-3 text-[11px] leading-5 text-dim">
            <TerminalSquare class="mt-0.5 h-3.5 w-3.5 shrink-0 text-cyan" />
            <span>
                ServerDeck calls <span class="font-mono text-muted">journalctl --output=json</span> through the audited host helper.
                Search terms are treated literally. Live tail uses a short poll rather than a persistent stream.
            </span>
        </div>
    </div>
</template>
