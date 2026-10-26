import { onBeforeUnmount, onMounted, unref } from 'vue';

export function useClickOutside(targetRef, callback) {
    const listener = (event) => {
        const target = unref(targetRef);

        if (target && !target.contains(event.target)) {
            callback(event);
        }
    };

    onMounted(() => document.addEventListener('pointerdown', listener));
    onBeforeUnmount(() => document.removeEventListener('pointerdown', listener));
}

export function usePolling(callback, interval = 5000, { immediate = false } = {}) {
    let timer = null;
    let stopped = false;

    const tick = async () => {
        if (stopped) return;

        if (document.visibilityState === 'visible') {
            await callback();
        }
    };

    const start = () => {
        stopped = false;
        stop();

        if (immediate) tick();

        timer = window.setInterval(tick, interval);
    };

    const stop = () => {
        if (timer) {
            window.clearInterval(timer);
            timer = null;
        }
    };

    onMounted(start);
    onBeforeUnmount(() => {
        stopped = true;
        stop();
    });

    return { start, stop };
}
