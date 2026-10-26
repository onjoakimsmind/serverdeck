<script setup>
import { ServerOff } from '@lucide/vue';

defineProps({
    icon: { type: [Object, Function], default: () => ServerOff },
    title: { type: String, default: 'Nothing here yet' },
    description: { type: String, default: null },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
        <div class="grid h-12 w-12 place-items-center rounded-2xl border border-line bg-panel-2 text-dim">
            <component :is="icon" class="h-5 w-5" :stroke-width="1.6" />
        </div>
        <h3 class="mt-4 text-sm font-semibold text-bright">{{ title }}</h3>
        <p v-if="description" class="mt-1.5 max-w-sm text-xs leading-5 text-muted">{{ description }}</p>
        <div v-if="$slots.default" class="mt-4">
            <slot />
        </div>
    </div>
</template>
