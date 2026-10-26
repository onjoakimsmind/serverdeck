<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    ClipboardList,
    Fingerprint,
    Search,
    ShieldCheck,
    TerminalSquare,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { formatDateTime, timeAgo } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, required: true },
    actions: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
});

const filters = reactive({
    search: props.filters.search ?? '',
    action: props.filters.action ?? '',
    user: props.filters.user ?? '',
});

let debounce = null;

const hasFilters = computed(() => Boolean(filters.search || filters.action || filters.user));

function apply() {
    const params = Object.fromEntries(
        Object.entries({
            search: filters.search.trim() || undefined,
            action: filters.action || undefined,
            user: filters.user || undefined,
        }).filter(([, value]) => value !== undefined && value !== ''),
    );

    router.get('/audit', params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['logs', 'filters'],
    });
}

watch(
    () => filters.search,
    () => {
        window.clearTimeout(debounce);
        debounce = window.setTimeout(apply, 300);
    },
);

watch(() => [filters.action, filters.user], apply);

onBeforeUnmount(() => window.clearTimeout(debounce));

function clearFilters() {
    filters.search = '';
    filters.action = '';
    filters.user = '';
}

function actionTone(action) {
    if (action === 'auth.blocked' || action.endsWith('.remove') || action.endsWith('.destroy_dataset')) return 'border-rose/25 bg-rose/10 text-rose';
    if (action.startsWith('terminal.') || action === 'process.signal') return 'border-amber/25 bg-amber/10 text-amber';
    if (action.startsWith('auth.') || action.startsWith('user.') || action.startsWith('profile.') || action.startsWith('zfs.')) return 'border-cyan/25 bg-cyan/10 text-cyan';

    return 'border-line bg-panel-2 text-muted';
}

function metaText(meta) {
    return meta ? JSON.stringify(meta, null, 2) : null;
}
</script>

<template>
    <Head title="Audit log" />

    <div class="space-y-5">
        <PageHeader
            title="Audit log"
            subtitle="Every privileged action taken through ServerDeck, attributed to a Linux account."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <ShieldCheck class="h-3 w-3 text-accent" />
                    {{ logs.total ?? 0 }} recorded actions
                    <span class="text-dim">·</span>
                    immutable history
                </span>
            </template>
        </PageHeader>

        <section class="panel overflow-hidden">
            <div class="flex flex-wrap items-center gap-2 border-b border-line/70 px-4 py-3">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-dim" />
                    <input v-model="filters.search" type="search" class="input h-8 w-64 py-1 pl-8 text-xs" placeholder="Search description…" aria-label="Search audit log" />
                </div>

                <select v-model="filters.action" class="input h-8 w-auto max-w-[240px] py-1 text-xs" aria-label="Filter by action">
                    <option value="">All actions</option>
                    <option v-for="action in actions" :key="action" :value="action">{{ action }}</option>
                </select>

                <select v-model="filters.user" class="input h-8 w-auto max-w-[220px] py-1 text-xs" aria-label="Filter by user">
                    <option value="">All accounts</option>
                    <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}{{ user.username ? ` (${user.username})` : '' }}</option>
                </select>

                <button v-if="hasFilters" type="button" class="btn btn-sm" @click="clearFilters">Clear</button>

                <span class="ml-auto flex items-center gap-1.5 text-[11px] text-dim">
                    <Fingerprint class="h-3 w-3" /> {{ logs.from ?? 0 }}–{{ logs.to ?? 0 }} of {{ logs.total ?? 0 }}
                </span>
            </div>

            <div v-if="logs.data.length" class="divide-y divide-line/50">
                <div v-for="entry in logs.data" :key="entry.id" class="flex gap-3 px-4 py-3 transition hover:bg-white/[0.02]">
                    <UserAvatar :name="entry.user?.name ?? 'System'" size="sm" class="mt-0.5" />

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[11px] font-medium text-bright">{{ entry.user?.name ?? 'System' }}</span>
                            <span v-if="entry.user?.username" class="font-mono text-[11px] text-dim">{{ entry.user.username }}</span>
                            <span class="badge" :class="actionTone(entry.action)">{{ entry.action }}</span>
                            <span class="ml-auto text-[11px] text-dim" :title="formatDateTime(entry.created_at)">{{ timeAgo(entry.created_at) }}</span>
                        </div>

                        <p class="mt-1 break-words text-[11px] leading-5 text-muted">{{ entry.description }}</p>

                        <div class="mt-1 flex flex-wrap items-center gap-3 text-[11px] text-dim">
                            <span>{{ formatDateTime(entry.created_at) }}</span>
                            <span v-if="entry.ip_address" class="font-mono">{{ entry.ip_address }}</span>
                            <details v-if="entry.meta" class="group">
                                <summary class="cursor-pointer select-none text-cyan/80 hover:text-cyan">metadata</summary>
                                <pre class="mt-1 max-h-48 overflow-auto rounded-lg border border-line bg-[#04080c] p-2 font-mono text-[11px] leading-4 text-dim">{{ metaText(entry.meta) }}</pre>
                            </details>
                        </div>
                    </div>
                </div>
            </div>

            <EmptyState
                v-else
                :icon="ClipboardList"
                :title="hasFilters ? 'No matching audit entries' : 'No audit entries yet'"
                :description="hasFilters ? 'Try a different search, action or account.' : 'Actions taken through ServerDeck will appear here.'"
            >
                <button v-if="hasFilters" type="button" class="btn btn-sm" @click="clearFilters">Clear filters</button>
            </EmptyState>

            <Pagination v-if="logs.last_page > 1" :paginator="logs" />
        </section>

        <div class="flex items-start gap-2.5 rounded-xl border border-line/60 bg-panel/40 px-4 py-3 text-[11px] leading-5 text-dim">
            <TerminalSquare class="mt-0.5 h-3.5 w-3.5 shrink-0 text-cyan" />
            <span>
                Sign-ins, terminal commands, process signals, container and service actions, ZFS changes and access updates are recorded
                with the acting account, source IP and structured metadata. Entries are written by the application and never edited from the UI.
            </span>
        </div>
    </div>
</template>
