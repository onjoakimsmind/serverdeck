<script setup>
import { ref } from 'vue';
import { MoreHorizontal } from '@lucide/vue';
import { useClickOutside } from '@/lib/polling';

defineProps({
    align: { type: String, default: 'right' },
});

const open = ref(false);
const root = ref(null);

useClickOutside(root, () => (open.value = false));

function toggle() {
    open.value = !open.value;
}

function close() {
    open.value = false;
}
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="grid h-8 w-8 place-items-center rounded-lg border border-transparent text-dim transition hover:border-line hover:bg-panel-2 hover:text-bright"
            aria-label="Open menu"
            :aria-expanded="open"
            @click.stop="toggle"
        >
            <slot name="trigger">
                <MoreHorizontal class="h-4 w-4" />
            </slot>
        </button>

        <Transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="transform scale-95 opacity-0"
            enter-to-class="transform scale-100 opacity-100"
            leave-active-class="transition duration-75 ease-in"
            leave-from-class="transform scale-100 opacity-100"
            leave-to-class="transform scale-95 opacity-0"
        >
            <div
                v-if="open"
                class="absolute z-40 mt-2 w-44 overflow-hidden rounded-xl border border-line-bright/80 bg-[#0c151f] p-1.5 shadow-2xl shadow-black/50"
                :class="align === 'right' ? 'right-0' : 'left-0'"
                @keydown.esc="close"
            >
                <slot name="items" :close="close" />
            </div>
        </Transition>
    </div>
</template>
