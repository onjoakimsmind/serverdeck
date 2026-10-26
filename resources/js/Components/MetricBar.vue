<script setup>
import { computed } from 'vue';
import { percentTone } from '@/lib/format';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: Number, default: 0 },
    detail: { type: String, default: null },
    compact: { type: Boolean, default: false },
    tone: { type: String, default: null },
});

const resolvedTone = computed(() => props.tone ?? percentTone(props.value));

const fills = {
    accent: 'bg-accent',
    amber: 'bg-amber',
    rose: 'bg-rose',
    cyan: 'bg-cyan',
    muted: 'bg-dim',
};

const texts = {
    accent: 'text-accent',
    amber: 'text-amber',
    rose: 'text-rose',
    cyan: 'text-cyan',
    muted: 'text-muted',
};

const width = computed(() => `${Math.max(0, Math.min(100, props.value))}%`);
</script>

<template>
    <div>
        <div class="flex items-baseline justify-between gap-2">
            <span class="text-[11px] font-medium uppercase tracking-[0.1em] text-dim">{{ label }}</span>
            <span class="font-mono text-[11px] tabular-nums" :class="texts[resolvedTone]">
                {{ Number(value).toFixed(compact ? 0 : 1) }}%
            </span>
        </div>
        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-white/[0.055]">
            <div
                class="h-full rounded-full transition-[width] duration-700 ease-out"
                :class="fills[resolvedTone]"
                :style="{ width }"
            />
        </div>
        <div v-if="detail" class="mt-1 text-[11px] text-dim">{{ detail }}</div>
    </div>
</template>
