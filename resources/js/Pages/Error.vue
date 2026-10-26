<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Ban,
    Clock,
    LockKeyhole,
    RefreshCw,
    SearchX,
    ServerCrash,
    ShieldAlert,
    Wrench,
} from '@lucide/vue';
import AppLogo from '@/Components/AppLogo.vue';

const props = defineProps({
    status: { type: Number, default: 500 },
});

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth));

const errors = {
    401: {
        title: 'Authentication required',
        description: 'Your session has ended. Sign in again to return to the control plane.',
        icon: LockKeyhole,
        tone: 'text-amber',
        ring: 'border-amber/20 bg-amber/5',
    },
    403: {
        title: 'Access denied',
        description: 'Your role does not allow this action. Ask an administrator if you believe this is a mistake.',
        icon: ShieldAlert,
        tone: 'text-rose',
        ring: 'border-rose/20 bg-rose/5',
    },
    404: {
        title: 'Page not found',
        description: 'This route does not exist, or the resource was removed. Check the address and try again.',
        icon: SearchX,
        tone: 'text-cyan',
        ring: 'border-cyan/20 bg-cyan/5',
    },
    405: {
        title: 'Method not allowed',
        description: 'This address cannot be reached that way. Return to the control plane and try again.',
        icon: Ban,
        tone: 'text-amber',
        ring: 'border-amber/20 bg-amber/5',
    },
    419: {
        title: 'Session expired',
        description: 'Your security token expired. Refresh the page and retry the action.',
        icon: Clock,
        tone: 'text-amber',
        ring: 'border-amber/20 bg-amber/5',
    },
    429: {
        title: 'Too many requests',
        description: 'Slow down for a moment. The control plane is rate limiting this action.',
        icon: Clock,
        tone: 'text-amber',
        ring: 'border-amber/20 bg-amber/5',
    },
    500: {
        title: 'Something went sideways',
        description: 'An unexpected error interrupted the request. The engineering team has the details.',
        icon: ServerCrash,
        tone: 'text-rose',
        ring: 'border-rose/20 bg-rose/5',
    },
    503: {
        title: 'Control plane unavailable',
        description: 'ServerDeck is briefly offline for maintenance. Try again in a moment.',
        icon: Wrench,
        tone: 'text-cyan',
        ring: 'border-cyan/20 bg-cyan/5',
    },
};

const error = computed(() => errors[props.status] ?? errors[500]);

function reload() {
    window.location.reload();
}
</script>

<template>
    <Head :title="`${status} · ${error.title}`" />

    <div class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-10">
        <div class="absolute inset-0 grid-bg opacity-30" />
        <div class="absolute left-1/2 top-1/3 h-[420px] w-[420px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-accent/[0.04] blur-[120px]" />

        <div class="relative w-full max-w-lg">
            <div class="mb-8 flex justify-center">
                <AppLogo />
            </div>

            <div class="panel overflow-hidden">
                <div class="border-b border-line/70 bg-panel-2/20 px-6 py-8 text-center">
                    <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl border" :class="error.ring">
                        <component :is="error.icon" class="h-6 w-6" :class="error.tone" :stroke-width="1.7" />
                    </div>
                    <div class="mt-4 font-mono text-[11px] uppercase tracking-[0.2em] text-dim">Error {{ status }}</div>
                    <h1 class="mt-2 text-xl font-semibold tracking-tight text-bright">{{ error.title }}</h1>
                    <p class="mx-auto mt-2 max-w-sm text-[12px] leading-6 text-muted">{{ error.description }}</p>
                </div>

                <div class="flex flex-wrap items-center justify-center gap-2 px-6 py-5">
                    <button type="button" class="btn btn-sm" @click="reload">
                        <RefreshCw class="h-3.5 w-3.5" />
                        Try again
                    </button>
                    <Link v-if="isAuthenticated" href="/" class="btn btn-primary btn-sm">
                        <ArrowLeft class="h-3.5 w-3.5" />
                        Back to overview
                    </Link>
                    <Link v-else href="/login" class="btn btn-primary btn-sm">
                        <ArrowLeft class="h-3.5 w-3.5" />
                        Sign in
                    </Link>
                </div>
            </div>

            <p class="mt-6 text-center text-[11px] text-dim">
                If this keeps happening, contact your administrator with the error code above.
            </p>
        </div>
    </div>
</template>
