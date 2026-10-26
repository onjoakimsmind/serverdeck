<script setup>
import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, Info, X } from '@lucide/vue';
import { dismissToast, pushToast, toasts } from '@/lib/toast';

const page = usePage();

const icons = {
    success: CheckCircle2,
    error: AlertTriangle,
    info: Info,
};

const tones = {
    success: 'border-accent/25 text-accent',
    error: 'border-rose/30 text-rose',
    info: 'border-cyan/30 text-cyan',
};

watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) return;

        if (flash.success) pushToast(flash.success, 'success');
        if (flash.error) pushToast(flash.error, 'error');
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div class="pointer-events-none fixed bottom-4 right-4 z-[100] flex w-[calc(100vw-2rem)] max-w-sm flex-col gap-2.5">
        <TransitionGroup name="toast" tag="div" class="flex flex-col gap-2.5">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto toast-in overflow-hidden rounded-xl border border-line-bright/70 bg-[#0c151f]/95 shadow-2xl shadow-black/60 backdrop-blur"
            >
                <div class="flex gap-3 p-3.5">
                    <div class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-lg border bg-panel-2" :class="tones[toast.type]">
                        <component :is="icons[toast.type] ?? Info" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold text-bright">{{ toast.title }}</p>
                        <p class="mt-0.5 text-xs leading-5 text-muted">{{ toast.message }}</p>
                    </div>
                    <button
                        type="button"
                        class="grid h-6 w-6 shrink-0 place-items-center rounded-md text-dim transition hover:bg-white/5 hover:text-bright"
                        aria-label="Dismiss"
                        @click="dismissToast(toast.id)"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: opacity 180ms ease, transform 180ms ease;
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(8px) scale(0.98);
}

.toast-move {
    transition: transform 180ms ease;
}
</style>
