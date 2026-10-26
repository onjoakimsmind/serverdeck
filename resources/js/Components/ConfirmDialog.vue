<script setup>
import { AlertTriangle } from '@lucide/vue';
import Modal from '@/Components/Modal.vue';

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: 'Are you sure?' },
    description: { type: String, default: null },
    confirmLabel: { type: String, default: 'Confirm' },
    cancelLabel: { type: String, default: 'Cancel' },
    danger: { type: Boolean, default: true },
    loading: { type: Boolean, default: false },
});

defineEmits(['confirm', 'close']);
</script>

<template>
    <Modal :show="show" :aria-label="title" max-width="max-w-md" @close="$emit('close')">
        <div class="flex gap-4">
            <div class="grid h-10 w-10 shrink-0 place-items-center rounded-full border border-rose/20 bg-rose/10 text-rose">
                <AlertTriangle class="h-5 w-5" />
            </div>
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-bright">{{ title }}</h2>
                <p v-if="description" class="mt-1.5 text-sm leading-6 text-muted">{{ description }}</p>
                <slot />
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-sm" :disabled="loading" @click="$emit('close')">
                {{ cancelLabel }}
            </button>
            <button
                type="button"
                class="btn btn-sm"
                :class="danger ? 'btn-danger' : 'btn-primary'"
                :disabled="loading"
                @click="$emit('confirm')"
            >
                <span v-if="loading" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                {{ confirmLabel }}
            </button>
        </template>
    </Modal>
</template>
