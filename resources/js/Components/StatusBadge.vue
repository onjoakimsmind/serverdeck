<script setup>
import { computed } from 'vue';
import { metaFor } from '@/lib/status';
import StatusDot from '@/Components/StatusDot.vue';

const props = defineProps({
    group: { type: String, default: 'server' },
    status: { type: String, default: 'online' },
    dot: { type: Boolean, default: true },
    pulse: { type: Boolean, default: false },
    label: { type: String, default: null },
});

const meta = computed(() => metaFor(props.group, props.status));
</script>

<template>
    <span class="badge whitespace-nowrap" :class="meta.badge">
        <StatusDot v-if="dot" :group="group" :status="status" :pulse="pulse" />
        {{ label ?? meta.label }}
    </span>
</template>
