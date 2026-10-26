<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: { type: Number, default: 0 },
    size: { type: Number, default: 132 },
    thickness: { type: Number, default: 9 },
    label: { type: String, default: null },
    sublabel: { type: String, default: null },
    tone: { type: String, default: null },
});

const radius = 50 - props.thickness / 2;
const circumference = 2 * Math.PI * radius;

const resolvedTone = computed(() => {
    if (props.tone) return props.tone;
    if (props.value >= 80) return 'accent';
    if (props.value >= 60) return 'amber';
    return 'rose';
});

const strokes = {
    accent: 'stroke-accent',
    amber: 'stroke-amber',
    rose: 'stroke-rose',
    cyan: 'stroke-cyan',
};

const texts = {
    accent: 'text-accent',
    amber: 'text-amber',
    rose: 'text-rose',
    cyan: 'text-cyan',
};

const dashOffset = computed(() => circumference * (1 - Math.min(100, Math.max(0, props.value)) / 100));
</script>

<template>
    <div class="relative inline-grid place-items-center" :style="{ width: `${size}px`, height: `${size}px` }">
        <svg viewBox="0 0 100 100" class="-rotate-90" :width="size" :height="size" aria-hidden="true">
            <circle cx="50" cy="50" :r="radius" fill="none" stroke="currentColor" stroke-width="6" class="text-white/[0.06]" />
            <circle
                cx="50"
                cy="50"
                :r="radius"
                fill="none"
                :stroke-width="thickness"
                stroke-linecap="round"
                :stroke-dasharray="circumference"
                :stroke-dashoffset="dashOffset"
                class="transition-[stroke-dashoffset] duration-700 ease-out"
                :class="strokes[resolvedTone]"
            />
        </svg>
        <div class="absolute inset-0 grid place-items-center text-center">
            <div>
                <div class="font-semibold tabular-nums leading-none" :class="texts[resolvedTone]" :style="{ fontSize: `${size * 0.2}px` }">
                    {{ Math.round(value) }}<span class="text-[0.55em] font-medium">%</span>
                </div>
                <div v-if="label" class="mt-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-dim">{{ label }}</div>
                <div v-if="sublabel" class="mt-0.5 text-[11px] text-muted">{{ sublabel }}</div>
            </div>
        </div>
    </div>
</template>
