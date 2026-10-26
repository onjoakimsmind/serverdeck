<script setup>
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Fingerprint,
    History,
    LogIn,
    ShieldCheck,
    SquareTerminal,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConsoleTerminal from '@/Components/ConsoleTerminal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { timeAgo } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    host: { type: Object, required: true },
    sessions: { type: Array, default: () => [] },
});

const page = usePage();
const user = computed(() => page.props.auth);

function newTarget() {
    return {
        target: 'host',
        kind: 'host',
        mode: 'shell',
        name: props.host.hostname,
        label: `Root shell · ${props.host.hostname}`,
    };
}

const consoleTarget = ref(newTarget());

function openConsole() {
    consoleTarget.value = newTarget();
}

function closeConsole() {
    consoleTarget.value = null;
}

function sessionLabel(entry) {
    return entry.action === 'console.open' ? 'opened' : 'closed';
}
</script>

<template>
    <Head title="Terminal" />

    <div class="space-y-5">
        <PageHeader
            title="Terminal"
            subtitle="A real interactive root shell on this host, through the same PTY-backed console used for Docker, LXC and VM workloads."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-rose/25 bg-rose/10 px-2.5 py-1 text-[11px] text-rose">
                    <ShieldCheck class="h-3 w-3" />
                    Root shell · {{ host.hostname }}
                    <span class="text-rose/50">·</span>
                    privileged access
                </span>
            </template>
            <template #actions>
                <button v-if="!consoleTarget" type="button" class="btn btn-primary btn-sm" @click="openConsole">
                    <SquareTerminal class="h-3.5 w-3.5" /> Open root shell
                </button>
            </template>
        </PageHeader>

        <div class="flex items-start gap-2.5 rounded-xl border border-rose/25 bg-rose/10 px-4 py-3 text-[11px] leading-5 text-rose">
            <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>
                This is a real shell running as <span class="font-mono">root</span>, with no sandbox: changes you make here affect
                the live host immediately. Keystrokes and output are never logged; only session open and close events are written
                to the audit trail. Sessions end after 30 minutes idle.
            </span>
        </div>

        <section v-if="!consoleTarget" class="panel p-10 text-center">
            <div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl border border-line bg-panel-2 text-dim">
                <SquareTerminal class="h-5 w-5" :stroke-width="1.6" />
            </div>
            <h3 class="mt-4 text-sm font-semibold text-bright">Session ended</h3>
            <p class="mx-auto mt-1.5 max-w-sm text-xs leading-5 text-muted">
                The root shell was closed. Open a new session to continue working on {{ host.hostname }}.
            </p>
            <button type="button" class="btn btn-primary btn-sm mx-auto mt-4" @click="openConsole">
                <LogIn class="h-3.5 w-3.5" /> Open root shell
            </button>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="panel p-4">
                <div class="flex items-center gap-2 text-[11px] font-medium text-bright">
                    <Fingerprint class="h-3.5 w-3.5 text-cyan" /> Session identity
                </div>
                <dl class="mt-3 space-y-2 text-[11px]">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-dim">Linux account</dt>
                        <dd class="font-mono text-muted">{{ user?.username }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-dim">Panel role</dt>
                        <dd class="text-muted">{{ user?.role_label }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-dim">Host</dt>
                        <dd class="font-mono text-muted">{{ host.hostname }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-dim">Shell</dt>
                        <dd class="font-mono text-muted">/bin/bash -l</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-dim">Working directory</dt>
                        <dd class="font-mono text-muted">/root</dd>
                    </div>
                </dl>
            </section>

            <section class="panel overflow-hidden">
                <div class="panel-header">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Recent sessions</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Audited open and close events</p>
                    </div>
                    <History class="h-4 w-4 text-dim" />
                </div>

                <div v-if="sessions.length" class="divide-y divide-line/50">
                    <div v-for="entry in sessions" :key="entry.id" class="flex items-center gap-2 px-4 py-2.5 text-[11px]">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="entry.action === 'console.open' ? 'bg-accent' : 'bg-dim'" />
                        <span class="min-w-0 flex-1 truncate text-muted">
                            {{ entry.user?.username ?? 'system' }} {{ sessionLabel(entry) }} a root shell
                        </span>
                        <span class="shrink-0 text-dim">{{ timeAgo(entry.created_at) }}</span>
                    </div>
                </div>
                <p v-else class="px-4 py-6 text-center text-[11px] text-dim">No sessions have been opened yet.</p>
            </section>
        </div>
    </div>

    <ConsoleTerminal
        v-if="consoleTarget"
        :target="consoleTarget.target"
        :kind="consoleTarget.kind"
        :mode="consoleTarget.mode"
        :name="consoleTarget.name"
        :label="consoleTarget.label"
        @close="closeConsole"
    />
</template>
