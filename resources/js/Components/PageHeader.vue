<script setup>
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
});
</script>

<template>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <div v-if="$slots.eyebrow" class="mb-2">
                <slot name="eyebrow" />
            </div>
            <h1 class="text-2xl font-semibold tracking-tight text-bright sm:text-[27px]">
                {{ title }}
            </h1>
            <p v-if="subtitle" class="mt-1.5 max-w-2xl text-sm leading-6 text-muted">
                {{ subtitle }}
            </p>
        </div>

        <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
