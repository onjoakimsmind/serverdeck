<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <Link href="/" class="group flex items-center gap-3">
        <div
            class="relative grid h-9 w-9 shrink-0 place-items-center rounded-xl border border-line-bright/70 bg-panel-2 transition group-hover:border-accent/50 group-hover:bg-panel-3"
        >
            <svg viewBox="0 0 32 32" class="h-5 w-5" fill="none" aria-hidden="true">
                <path
                    d="M8 9.5l7 6.5-7 6.5"
                    stroke="#2ee6a8"
                    stroke-width="2.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
                <path d="M17.5 22.5H24" stroke="#38bdf8" stroke-width="2.6" stroke-linecap="round" />
            </svg>
            <span class="absolute -right-0.5 -top-0.5 h-2 w-2 rounded-full bg-accent shadow-[0_0_8px_rgba(46,230,168,0.8)]" />
        </div>

        <div v-if="!compact" class="min-w-0">
            <div class="truncate text-[15px] font-semibold leading-tight tracking-tight text-bright">
                Server<span class="text-accent">Deck</span>
            </div>
            <div class="mt-0.5 text-[11px] font-semibold uppercase tracking-[0.22em] text-dim">
                Infrastructure control
            </div>
        </div>
    </Link>
</template>
