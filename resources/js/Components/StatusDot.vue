<script setup>
import { computed } from 'vue';
import { metaFor } from '@/lib/status';

const props = defineProps({
    group: { type: String, default: 'server' },
    status: { type: String, default: 'online' },
    pulse: { type: Boolean, default: false },
});

const meta = computed(() => metaFor(props.group, props.status));
</script>

<template>
    <span class="relative inline-flex h-2 w-2 shrink-0" :title="meta.label">
        <span
            v-if="pulse"
            class="ping-soft absolute inline-flex h-full w-full rounded-full opacity-60"
            :class="meta.dot"
        />
        <span class="relative inline-flex h-2 w-2 rounded-full" :class="meta.dot" />
    </span>
</template>
