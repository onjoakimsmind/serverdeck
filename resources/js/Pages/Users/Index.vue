<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Fingerprint,
    KeyRound,
    Pencil,
    Search,
    ShieldCheck,
    UserRound,
    Users as UsersIcon,
    UserX,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusDot from '@/Components/StatusDot.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { formatDateTime, timeAgo } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, required: true },
    roles: { type: Array, default: () => [] },
    counts: { type: Object, required: true },
});

const page = usePage();
const currentUserId = computed(() => page.props.auth?.id);

const filters = reactive({
    search: props.filters.search ?? '',
    role: props.filters.role ?? '',
});

const selected = ref(null);
const editing = ref(false);
let debounce = null;

const form = useForm({
    name: '',
    title: '',
    role: 'viewer',
    is_active: true,
});

const tabs = computed(() => [
    { value: '', label: 'Everyone', count: props.counts.all ?? 0 },
    ...props.roles.map((role) => ({
        value: role.value,
        label: role.label,
        count: props.counts[role.value] ?? 0,
    })),
]);

const hasFilters = computed(() => Boolean(filters.search || filters.role));

const roleTone = {
    admin: 'border-accent/25 bg-accent/10 text-accent',
    operator: 'border-cyan/25 bg-cyan/10 text-cyan',
    viewer: 'border-line bg-panel-2 text-dim',
};

function params() {
    return Object.fromEntries(
        Object.entries({
            search: filters.search.trim() || undefined,
            role: filters.role || undefined,
        }).filter(([, value]) => value !== undefined && value !== ''),
    );
}

function apply() {
    router.get('/users', params(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['users', 'filters', 'counts'],
    });
}

watch(
    () => filters.search,
    () => {
        window.clearTimeout(debounce);
        debounce = window.setTimeout(apply, 300);
    },
);

watch(() => filters.role, apply);

onBeforeUnmount(() => window.clearTimeout(debounce));

function clearFilters() {
    filters.search = '';
    filters.role = '';
}

function openEdit(user) {
    selected.value = user;
    form.name = user.name ?? '';
    form.title = user.title ?? '';
    form.role = user.role;
    form.is_active = Boolean(user.is_active);
    form.clearErrors();
    editing.value = true;
}

function closeEdit() {
    if (form.processing) return;

    editing.value = false;
    selected.value = null;
    form.reset();
    form.clearErrors();
}

function save() {
    if (!selected.value) return;

    form.put(`/users/${selected.value.id}`, {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
    });
}

function roleDescription(value) {
    return props.roles.find((role) => role.value === value)?.description ?? '';
}
</script>

<template>
    <Head title="Access" />

    <div class="space-y-5">
        <PageHeader
            title="Access"
            subtitle="Linux accounts that have signed in to ServerDeck, with their panel roles and activation state."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <Fingerprint class="h-3 w-3 text-cyan" />
                    {{ counts.active ?? 0 }} active accounts
                    <span class="text-dim">·</span>
                    {{ counts.admin ?? 0 }} admin{{ (counts.admin ?? 0) === 1 ? '' : 's' }}
                    <span class="text-dim">·</span>
                    PAM provisioned
                </span>
            </template>
            <template #actions>
                <button type="button" class="btn btn-sm" @click="router.reload({ preserveScroll: true, preserveState: true })">
                    <UsersIcon class="h-3.5 w-3.5" /> Refresh
                </button>
            </template>
        </PageHeader>

        <div class="flex items-start gap-2.5 rounded-xl border border-line/60 bg-panel/40 px-4 py-3 text-[11px] leading-5 text-dim">
            <KeyRound class="mt-0.5 h-3.5 w-3.5 shrink-0 text-cyan" />
            <span>
                Accounts are created automatically the first time a local Linux user signs in. Members of
                <span class="font-mono text-muted">sudo</span> or <span class="font-mono text-muted">wheel</span> start as administrators;
                everyone else starts read-only. Passwords are managed by the host, not here. Disable an account to revoke panel access
                without touching the Linux user.
            </span>
        </div>

        <section class="panel overflow-hidden">
            <div class="panel-header flex-wrap gap-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[11px] transition"
                        :class="filters.role === tab.value
                            ? 'border-accent/35 bg-accent/10 font-medium text-accent'
                            : 'border-line bg-panel-2/50 text-muted hover:border-line-bright hover:text-bright'"
                        @click="filters.role = tab.value"
                    >
                        {{ tab.label }}
                        <span class="font-mono text-[11px] text-dim">{{ tab.count }}</span>
                    </button>
                </div>

                <div class="relative">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-dim" />
                    <input v-model="filters.search" type="search" class="input h-8 w-60 py-1 pl-8 text-xs" placeholder="Search name or username…" aria-label="Search accounts" />
                </div>
            </div>

            <div v-if="users.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[860px]">
                    <thead>
                        <tr class="border-b border-line/70">
                            <th class="table-head">Account</th>
                            <th class="table-head">Role</th>
                            <th class="table-head">Linux identity</th>
                            <th class="table-head">Access</th>
                            <th class="table-head">Last seen</th>
                            <th class="table-head text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/50">
                        <tr v-for="user in users.data" :key="user.id" class="hover-row">
                            <td class="table-cell">
                                <div class="flex items-center gap-2.5">
                                    <UserAvatar :name="user.name" size="sm" />
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <span class="truncate text-[11px] font-medium text-bright">{{ user.name }}</span>
                                            <span v-if="user.id === currentUserId" class="rounded bg-accent/10 px-1 py-0.5 text-[10px] font-medium uppercase tracking-wider text-accent">you</span>
                                        </div>
                                        <div class="mt-0.5 font-mono text-[11px] text-dim">{{ user.username || 'no username' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="table-cell">
                                <span class="badge" :class="roleTone[user.role] ?? roleTone.viewer">{{ user.role }}</span>
                                <div class="mt-1 max-w-[150px] truncate text-[11px] text-dim">{{ user.title || 'No title' }}</div>
                            </td>
                            <td class="table-cell">
                                <div class="font-mono text-[11px] text-muted">uid {{ user.linux_uid ?? '—' }}</div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <span v-for="group in (user.linux_groups ?? []).slice(0, 3)" :key="group" class="rounded bg-white/[0.05] px-1 py-0.5 font-mono text-[10px] text-dim">{{ group }}</span>
                                    <span v-if="(user.linux_groups ?? []).length > 3" class="text-[11px] text-dim">+{{ user.linux_groups.length - 3 }}</span>
                                    <span v-if="!(user.linux_groups ?? []).length" class="text-[11px] text-dim">no supplementary groups</span>
                                </div>
                            </td>
                            <td class="table-cell">
                                <span class="inline-flex items-center gap-1.5 text-[11px]" :class="user.is_active ? 'text-accent' : 'text-rose'">
                                    <StatusDot :status="user.is_active ? 'online' : 'offline'" />
                                    {{ user.is_active ? 'enabled' : 'disabled' }}
                                </span>
                            </td>
                            <td class="table-cell">
                                <div class="text-[11px] text-muted">{{ user.last_seen_at ? timeAgo(user.last_seen_at) : 'never' }}</div>
                                <div v-if="user.last_seen_at" class="mt-0.5 text-[11px] text-dim">{{ formatDateTime(user.last_seen_at) }}</div>
                            </td>
                            <td class="table-cell text-right">
                                <button type="button" class="inline-flex items-center gap-1 rounded-md border border-line bg-panel-2 px-2 py-1.5 text-[11px] text-muted transition hover:border-line-bright hover:text-bright" @click="openEdit(user)">
                                    <Pencil class="h-3 w-3" /> Edit
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <EmptyState
                v-else
                :icon="filters.search || filters.role ? UsersIcon : UserX"
                :title="hasFilters ? 'No accounts matched' : 'No accounts yet'"
                :description="hasFilters ? 'Try a different search or role.' : 'Sign in with a local Linux account to create the first ServerDeck identity.'"
            >
                <button v-if="hasFilters" type="button" class="btn btn-sm" @click="clearFilters">Clear filters</button>
            </EmptyState>

            <Pagination v-if="users.last_page > 1" :paginator="users" />
            <div v-else class="flex flex-wrap items-center justify-between gap-2 border-t border-line/70 px-5 py-3 text-[11px] text-dim">
                <span>{{ users.total ?? 0 }} account{{ (users.total ?? 0) === 1 ? '' : 's' }} total</span>
                <span>Roles and activation are enforced on every request</span>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-3">
            <section v-for="role in roles" :key="role.value" class="panel p-4">
                <div class="flex items-center gap-2">
                    <ShieldCheck class="h-4 w-4" :class="role.value === 'admin' ? 'text-accent' : role.value === 'operator' ? 'text-cyan' : 'text-dim'" />
                    <span class="text-[11px] font-medium text-bright">{{ role.label }}</span>
                    <span class="ml-auto badge" :class="roleTone[role.value] ?? roleTone.viewer">{{ counts[role.value] ?? 0 }}</span>
                </div>
                <p class="mt-2 text-[11px] leading-5 text-dim">{{ role.description }}</p>
            </section>
        </div>

        <Modal :show="editing" :title="selected ? `Edit ${selected.name}` : 'Edit access'" :description="selected ? `Linux account ${selected.username}` : ''" max-width="max-w-lg" @close="closeEdit">
            <form v-if="selected" class="space-y-4" @submit.prevent="save">
                <div class="flex items-center gap-3 rounded-xl border border-line bg-panel-2/40 p-3">
                    <UserAvatar :name="selected.name" size="md" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-xs font-medium text-bright">{{ selected.name }}</div>
                        <div class="truncate font-mono text-[11px] text-dim">{{ selected.username }} · uid {{ selected.linux_uid ?? '—' }}</div>
                    </div>
                    <span class="badge" :class="roleTone[selected.role]">{{ selected.role }}</span>
                </div>

                <div>
                    <label for="user-name" class="label">Display name</label>
                    <input id="user-name" v-model="form.name" class="input" maxlength="80" required />
                    <p v-if="form.errors.name" class="mt-1 text-[11px] text-rose">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="user-title" class="label">Title</label>
                    <input id="user-title" v-model="form.title" class="input" maxlength="100" placeholder="Host administrator" />
                    <p v-if="form.errors.title" class="mt-1 text-[11px] text-rose">{{ form.errors.title }}</p>
                </div>

                <div>
                    <label for="user-role" class="label">Panel role</label>
                    <select id="user-role" v-model="form.role" class="input" :disabled="selected.id === currentUserId">
                        <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                    </select>
                    <p class="mt-1 text-[11px] leading-5 text-dim">{{ roleDescription(form.role) }}</p>
                    <p v-if="selected.id === currentUserId" class="mt-1 text-[11px] text-amber">You cannot change your own role.</p>
                    <p v-if="form.errors.role" class="mt-1 text-[11px] text-rose">{{ form.errors.role }}</p>
                </div>

                <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-line bg-panel-2/30 px-3 py-2.5">
                    <input v-model="form.is_active" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-line bg-panel-2" :disabled="selected.id === currentUserId" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-[11px] font-medium text-bright">ServerDeck access enabled</span>
                        <span class="mt-0.5 block text-[11px] leading-5 text-dim">Disabled accounts keep their record and Linux identity but are signed out immediately and blocked from returning.</span>
                    </span>
                </label>
                <p v-if="form.errors.is_active" class="text-[11px] text-rose">{{ form.errors.is_active }}</p>

                <div class="flex items-center gap-2 rounded-lg border border-line/60 bg-panel/40 px-3 py-2 text-[11px] leading-4 text-dim">
                    <UserRound class="h-3.5 w-3.5 shrink-0 text-cyan" />
                    Role changes apply on the next request. The Linux account itself is unchanged.
                </div>

                <div class="flex justify-end gap-2 border-t border-line/60 pt-4">
                    <button type="button" class="btn btn-sm" :disabled="form.processing" @click="closeEdit">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="form.processing">
                        <span v-if="form.processing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                        Save access
                    </button>
                </div>
            </form>
        </Modal>
    </div>
</template>
