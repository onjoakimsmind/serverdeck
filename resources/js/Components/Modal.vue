<script setup>
import { computed, onBeforeUnmount, watch } from 'vue';
import { X } from '@lucide/vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: null },
    ariaLabel: { type: String, default: null },
    description: { type: String, default: null },
    maxWidth: { type: String, default: 'max-w-lg' },
    closeable: { type: Boolean, default: true },
});

const emit = defineEmits(['close']);

const widths = {
    'max-w-sm': 'max-w-sm',
    'max-w-md': 'max-w-md',
    'max-w-lg': 'max-w-lg',
    'max-w-xl': 'max-w-xl',
    'max-w-2xl': 'max-w-2xl',
    'max-w-3xl': 'max-w-3xl',
};

const widthClass = computed(() => widths[props.maxWidth] ?? props.maxWidth);

function close() {
    if (props.closeable) emit('close');
}

function onKeydown(event) {
    if (event.key === 'Escape') close();
}

watch(
    () => props.show,
    (show) => {
        document.body.style.overflow = show ? 'hidden' : '';

        if (show) {
            window.addEventListener('keydown', onKeydown);
        } else {
            window.removeEventListener('keydown', onKeydown);
        }
    },
);

onBeforeUnmount(() => {
    document.body.style.overflow = '';
    window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="show" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 pt-[8vh] sm:p-6 sm:pt-[10vh]">
                <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" @click="close" />

                <div
                    class="relative w-full overflow-hidden rounded-2xl border border-line-bright/80 bg-[#0b141e] shadow-2xl shadow-black/70"
                    :class="widthClass"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="ariaLabel ?? title ?? undefined"
                >
                    <div v-if="title || $slots.header" class="flex items-start justify-between gap-4 border-b border-line/80 px-5 py-4">
                        <div class="min-w-0">
                            <slot name="header">
                                <h2 class="text-base font-semibold tracking-tight text-bright">{{ title }}</h2>
                                <p v-if="description" class="mt-1 text-xs leading-5 text-muted">{{ description }}</p>
                            </slot>
                        </div>
                        <button
                            v-if="closeable"
                            type="button"
                            class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-dim transition hover:bg-white/5 hover:text-bright"
                            aria-label="Close"
                            @click="close"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>

                    <div class="max-h-[70vh] overflow-y-auto px-5 py-5">
                        <slot />
                    </div>

                    <div v-if="$slots.footer" class="flex flex-wrap items-center justify-end gap-2 border-t border-line/80 bg-panel/60 px-5 py-3.5">
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity 160ms ease;
}

.modal-enter-active > div:last-child,
.modal-leave-active > div:last-child {
    transition: transform 160ms ease, opacity 160ms ease;
}

.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}

.modal-enter-from > div:last-child,
.modal-leave-to > div:last-child {
    transform: translateY(8px) scale(0.985);
    opacity: 0;
}
</style>
