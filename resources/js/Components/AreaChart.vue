<script setup>
import { computed, ref, useId } from 'vue';

const props = defineProps({
    labels: { type: Array, default: () => [] },
    series: { type: Array, default: () => [] },
    height: { type: Number, default: 240 },
    suffix: { type: String, default: '%' },
    max: { type: Number, default: 100 },
    showLegend: { type: Boolean, default: true },
    labelMode: { type: String, default: 'time' },
});

const hovered = ref(null);
const chartWidth = 1000;
const padTop = 6;
const padBottom = 6;
const uid = useId().replace(/[^a-zA-Z0-9]/g, '');

const count = computed(() => props.labels.length);

const y = (value) =>
    padTop + (1 - Math.min(props.max, Math.max(0, value)) / props.max) * (props.height - padTop - padBottom);

const x = (index) => (index / Math.max(1, count.value - 1)) * chartWidth;

function linePath(values) {
    return values
        .map((value, index) => `${index === 0 ? 'M' : 'L'}${x(index).toFixed(2)},${y(value).toFixed(2)}`)
        .join(' ');
}

function areaPath(values) {
    if (!values.length) return '';

    return `${linePath(values)} L${chartWidth},${props.height} L0,${props.height} Z`;
}

const xLabels = computed(() => {
    if (!count.value) return [];

    const indexes = new Set([0, Math.round((count.value - 1) * 0.25), Math.round((count.value - 1) * 0.5), Math.round((count.value - 1) * 0.75), count.value - 1]);

    const format = props.labelMode === 'date'
        ? { day: 'numeric', month: 'short' }
        : { hour: '2-digit', minute: '2-digit', hour12: false };

    return [...indexes].filter((i) => props.labels[i]).map((index) => ({
        index,
        label: new Intl.DateTimeFormat('en-GB', format).format(new Date(props.labels[index])),
    }));
});

const yTicks = computed(() => {
    const ticks = [100, 75, 50, 25, 0];

    return ticks.map((tick) => ({ value: Math.round((tick / 100) * props.max), label: `${Math.round((tick / 100) * props.max)}${props.suffix}` }));
});

function pointerMove(event) {
    if (!count.value) return;

    const rect = event.currentTarget.getBoundingClientRect();
    const ratio = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
    hovered.value = Math.round(ratio * (count.value - 1));
}

const tooltipLeft = computed(() => {
    if (hovered.value === null || count.value <= 1) return '0%';

    const percent = (hovered.value / (count.value - 1)) * 100;
    return `${Math.min(92, Math.max(8, percent))}%`;
});
</script>

<template>
    <div>
        <div v-if="showLegend" class="mb-4 flex flex-wrap items-center gap-4">
            <div v-for="item in series" :key="item.key" class="flex items-center gap-2 text-[11px] text-muted">
                <span class="h-1.5 w-1.5 rounded-full" :style="{ background: item.color }" />
                {{ item.label }}
                <span v-if="item.values?.length" class="font-mono text-[11px] text-bright">
                    {{ Number(item.values[item.values.length - 1]).toFixed(1) }}{{ suffix }}
                </span>
            </div>
        </div>

        <div class="flex gap-3">
            <div class="flex w-8 shrink-0 flex-col justify-between py-0.5 text-right font-mono text-[11px] text-dim" :style="{ height: `${height}px` }">
                <span v-for="tick in yTicks" :key="tick.value">{{ tick.label }}</span>
            </div>

            <div class="min-w-0 flex-1">
                <div
                    class="relative w-full cursor-crosshair"
                    :style="{ height: `${height}px` }"
                    @pointermove="pointerMove"
                    @pointerleave="hovered = null"
                >
                    <svg
                        :viewBox="`0 0 ${chartWidth} ${height}`"
                        preserveAspectRatio="none"
                        class="absolute inset-0 h-full w-full overflow-visible"
                        aria-hidden="true"
                    >
                        <defs>
                            <linearGradient v-for="item in series" :id="`${uid}-${item.key}`" :key="item.key" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" :stop-color="item.color" stop-opacity="0.2" />
                                <stop offset="100%" :stop-color="item.color" stop-opacity="0" />
                            </linearGradient>
                        </defs>

                        <g>
                            <line
                                v-for="tick in [0, 25, 50, 75, 100]"
                                :key="tick"
                                x1="0"
                                :y1="(tick / 100) * height"
                                :x2="chartWidth"
                                :y2="(tick / 100) * height"
                                stroke="rgba(41, 65, 87, 0.38)"
                                stroke-width="1"
                                vector-effect="non-scaling-stroke"
                                stroke-dasharray="3 5"
                            />
                        </g>

                        <path
                            v-for="item in series"
                            :key="`area-${item.key}`"
                            :d="areaPath(item.values)"
                            :fill="`url(#${uid}-${item.key})`"
                        />
                        <path
                            v-for="item in series"
                            :key="`line-${item.key}`"
                            :d="linePath(item.values)"
                            fill="none"
                            :stroke="item.color"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            vector-effect="non-scaling-stroke"
                        />
                        <circle
                            v-for="item in count === 1 ? series : []"
                            :key="`point-${item.key}`"
                            :cx="x(0)"
                            :cy="y(item.values[0] ?? 0)"
                            r="3.5"
                            :fill="item.color"
                        />

                        <line
                            v-if="hovered !== null"
                            :x1="(hovered / Math.max(1, count - 1)) * chartWidth"
                            y1="0"
                            :x2="(hovered / Math.max(1, count - 1)) * chartWidth"
                            :y2="height"
                            stroke="rgba(131, 151, 172, 0.4)"
                            stroke-width="1"
                            vector-effect="non-scaling-stroke"
                            stroke-dasharray="3 3"
                        />
                    </svg>

                    <div
                        v-if="hovered !== null && labels[hovered]"
                        class="pointer-events-none absolute top-1 z-10 -translate-x-1/2 rounded-lg border border-line-bright/80 bg-[#0a121b]/95 px-2.5 py-2 shadow-xl backdrop-blur"
                        :style="{ left: tooltipLeft }"
                    >
                        <div class="mb-1 whitespace-nowrap text-[11px] font-medium uppercase tracking-wider text-dim">
                            {{ new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(labels[hovered])) }}
                        </div>
                        <div v-for="item in series" :key="`tip-${item.key}`" class="flex items-center justify-between gap-4 whitespace-nowrap text-[11px]">
                            <span class="flex items-center gap-1.5 text-muted">
                                <span class="h-1.5 w-1.5 rounded-full" :style="{ background: item.color }" />
                                {{ item.label }}
                            </span>
                            <span class="font-mono text-bright">{{ Number(item.values[hovered] ?? 0).toFixed(1) }}{{ suffix }}</span>
                        </div>
                    </div>
                </div>

                <div class="mt-2 flex justify-between font-mono text-[11px] text-dim">
                    <span v-for="tick in xLabels" :key="tick.index">{{ tick.label }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
