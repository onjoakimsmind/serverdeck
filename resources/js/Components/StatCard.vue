<script setup>
import Sparkline from '@/Components/Sparkline.vue';

defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    subtitle: { type: String, default: null },
    icon: { type: [Object, Function], default: null },
    tone: { type: String, default: 'default' },
    spark: { type: Array, default: null },
    sparkColor: { type: String, default: '#2ee6a8' },
    detail: { type: String, default: null },
});

const iconTones = {
    default: 'border-line bg-panel-2 text-muted',
    accent: 'border-accent/20 bg-accent/10 text-accent',
    cyan: 'border-cyan/20 bg-cyan/10 text-cyan',
    amber: 'border-amber/20 bg-amber/10 text-amber',
    rose: 'border-rose/20 bg-rose/10 text-rose',
};
</script>

<template>
    <div class="panel group relative overflow-hidden p-5 transition-colors hover:border-line-bright">
        <div class="flex items-start justify-between gap-3">
            <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-dim">{{ label }}</span>
            <span v-if="icon" class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border" :class="iconTones[tone]">
                <component :is="icon" class="h-4 w-4" :stroke-width="1.8" />
            </span>
        </div>

        <div class="mt-3 flex items-end justify-between gap-3">
            <div class="min-w-0">
                <div class="text-[26px] font-semibold leading-none tracking-tight text-bright">{{ value }}</div>
                <div class="mt-2 text-xs text-muted">{{ subtitle }}</div>
            </div>
            <div v-if="spark?.length" class="w-24 shrink-0 opacity-80">
                <Sparkline :data="spark" :color="sparkColor" :height="34" />
            </div>
        </div>

        <div v-if="detail" class="mt-3 border-t border-line/60 pt-2.5 text-[11px] text-dim">{{ detail }}</div>
    </div>
</template>
