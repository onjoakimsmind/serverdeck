<script setup>
import { computed, useId } from 'vue';

const props = defineProps({
    data: { type: Array, default: () => [] },
    height: { type: Number, default: 36 },
    color: { type: String, default: '#2ee6a8' },
    strokeWidth: { type: Number, default: 1.8 },
});

const uid = `spark-${useId().replace(/[^a-zA-Z0-9]/g, '')}`;
const width = 120;

const normalized = computed(() => {
    if (!props.data.length) return [0, 0];

    const max = Math.max(...props.data);
    const min = Math.min(...props.data);
    const spread = max - min || 1;

    return props.data.map((value) => 1 - (value - min) / spread);
});

const points = computed(() =>
    normalized.value.map((value, index) => ({
        x: (index / Math.max(1, normalized.value.length - 1)) * width,
        y: 3 + value * (props.height - 6),
    })),
);

const linePath = computed(() =>
    points.value.map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x.toFixed(2)},${point.y.toFixed(2)}`).join(' '),
);

const areaPath = computed(() => {
    if (!points.value.length) return '';

    const first = points.value[0];
    const last = points.value[points.value.length - 1];

    return `${linePath.value} L${last.x},${props.height} L${first.x},${props.height} Z`;
});
</script>

<template>
    <svg
        :viewBox="`0 0 ${width} ${height}`"
        :style="{ height: `${height}px` }"
        class="w-full overflow-visible"
        preserveAspectRatio="none"
        aria-hidden="true"
    >
        <defs>
            <linearGradient :id="uid" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" :stop-color="color" stop-opacity="0.28" />
                <stop offset="100%" :stop-color="color" stop-opacity="0" />
            </linearGradient>
        </defs>
        <path :d="areaPath" :fill="`url(#${uid})`" />
        <path
            :d="linePath"
            fill="none"
            :stroke="color"
            :stroke-width="strokeWidth"
            stroke-linecap="round"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
</template>
