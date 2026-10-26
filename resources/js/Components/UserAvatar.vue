<script setup>
import { computed } from 'vue';
import { initials } from '@/lib/format';

const props = defineProps({
    name: { type: String, default: '' },
    size: { type: String, default: 'md' },
});

const palettes = [
    ['#2ee6a8', '#0e7490'],
    ['#38bdf8', '#1d4ed8'],
    ['#f5b544', '#b45309'],
    ['#fb7185', '#9f1239'],
    ['#a78bfa', '#4c1d95'],
    ['#34d399', '#065f46'],
];

const palette = computed(() => {
    const sum = [...(props.name || '')].reduce((total, char) => total + char.charCodeAt(0), 0);
    return palettes[sum % palettes.length];
});

const sizes = {
    xs: 'h-6 w-6 text-[11px]',
    sm: 'h-7 w-7 text-[11px]',
    md: 'h-8 w-8 text-[11px]',
    lg: 'h-11 w-11 text-sm',
    xl: 'h-14 w-14 text-lg',
};

const style = computed(() => ({
    background: `linear-gradient(135deg, ${palette.value[0]}, ${palette.value[1]})`,
}));
</script>

<template>
    <span
        class="inline-grid shrink-0 place-items-center rounded-full font-semibold tracking-wide text-[#04140f] ring-1 ring-white/10"
        :class="sizes[size] ?? sizes.md"
        :style="style"
        :title="name"
    >
        {{ initials(name) }}
    </span>
</template>
