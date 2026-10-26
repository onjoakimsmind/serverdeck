<script setup>
import { ref } from 'vue';
import { Check, Copy } from '@lucide/vue';
import { pushToast } from '@/lib/toast';

const props = defineProps({
    value: { type: String, required: true },
    label: { type: String, default: 'Copied to clipboard' },
});

const copied = ref(false);

async function copy() {
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(props.value);
        } else {
            const textarea = document.createElement('textarea');
            textarea.value = props.value;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            textarea.remove();
        }

        copied.value = true;
        pushToast(props.label, 'success', 'Copied');
        window.setTimeout(() => (copied.value = false), 1600);
    } catch {
        pushToast('Your browser blocked clipboard access.', 'error');
    }
}
</script>

<template>
    <button
        type="button"
        class="grid h-6 w-6 place-items-center rounded-md text-dim transition hover:bg-white/5 hover:text-bright"
        :aria-label="copied ? 'Copied' : 'Copy to clipboard'"
        @click.stop="copy"
    >
        <Check v-if="copied" class="h-3.5 w-3.5 text-accent" />
        <Copy v-else class="h-3.5 w-3.5" />
    </button>
</template>
