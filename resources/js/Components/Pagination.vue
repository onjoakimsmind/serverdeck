<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';

const props = defineProps({
    paginator: { type: Object, required: true },
});

function cleanLabel(label) {
    const text = label.replaceAll('&laquo;', '').replaceAll('&raquo;', '').replaceAll('Previous', 'Prev').replaceAll('Next', 'Next').trim();

    return text || '…';
}

const links = computed(() => props.paginator.links ?? []);
</script>

<template>
    <div v-if="paginator.last_page > 1" class="flex flex-col gap-3 border-t border-line/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-xs text-dim">
            Showing <span class="text-muted">{{ paginator.from ?? 0 }}–{{ paginator.to ?? 0 }}</span>
            of <span class="text-muted">{{ paginator.total }}</span>
        </p>

        <div class="flex flex-wrap items-center gap-1">
            <template v-for="(link, index) in links" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="inline-flex h-8 min-w-8 items-center justify-center gap-1 rounded-lg border px-2 text-xs transition"
                    :class="link.active
                        ? 'border-accent/40 bg-accent font-semibold text-[#04140f]'
                        : 'border-line bg-panel-2 text-muted hover:border-line-bright hover:text-bright'"
                >
                    <ChevronLeft v-if="link.label.includes('Previous')" class="h-3.5 w-3.5" />
                    {{ cleanLabel(link.label) }}
                    <ChevronRight v-if="link.label.includes('Next')" class="h-3.5 w-3.5" />
                </Link>
                <span
                    v-else
                    class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-xs text-dim"
                >
                    {{ cleanLabel(link.label) }}
                </span>
            </template>
        </div>
    </div>
</template>
