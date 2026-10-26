<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Terminal as Xterm } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';
import {
    Eraser,
    Maximize2,
    Minimize2,
    RotateCw,
    SquareTerminal,
    X,
} from '@lucide/vue';
import { api } from '@/lib/http';

const props = defineProps({
    target: { type: String, required: true },
    kind: { type: String, required: true },
    mode: { type: String, default: 'shell' },
    name: { type: String, required: true },
    label: { type: String, default: null },
});

const emit = defineEmits(['close']);

const terminalEl = ref(null);
const status = ref('connecting');
const error = ref(null);
const fullscreen = ref(false);
const session = ref(null);

let term = null;
let fitAddon = null;
let pollTimer = null;
let inputTimer = null;
let resizeTimer = null;
let inputBuffer = '';
let writeChain = Promise.resolve();
let polling = false;
let generation = 0;
let disposed = false;
let pollOffset = 0;

const title = computed(() => props.label || props.name);
const isSerial = computed(() => props.mode === 'serial');
const kindLabel = computed(() => {
    if (props.target === 'host') return 'Host · root shell';
    if (props.target === 'docker') return 'Docker container';
    if (props.kind === 'virtual-machine') return isSerial.value ? 'Incus VM · serial' : 'Incus VM · shell';
    return 'Incus container · shell';
});
const subtitle = computed(() => {
    if (props.target === 'host') return 'Interactive root shell on this host, through a real PTY';
    if (props.target === 'docker') return 'Interactive shell through docker exec';
    if (props.kind === 'virtual-machine') {
        return isSerial.value
            ? 'Serial console attached through the Incus daemon'
            : 'Interactive shell through the Incus VM agent';
    }
    return 'Interactive shell through incus exec';
});
const statusLabel = computed(() => ({
    connecting: 'connecting',
    open: 'connected',
    closed: 'ended',
    error: 'error',
}[status.value] ?? status.value));
const statusClass = computed(() => {
    if (status.value === 'open') return 'border-accent/25 bg-accent/10 text-accent';
    if (status.value === 'connecting') return 'border-amber/25 bg-amber/10 text-amber';
    return 'border-rose/25 bg-rose/10 text-rose';
});

onMounted(async () => {
    await nextTick();
    initTerminal();
    connect();
    window.addEventListener('resize', onWindowResize);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', onWindowResize);
    stopTimers();

    if (session.value) {
        sendClose(session.value);
        session.value = null;
    }

    disposed = true;
    generation += 1;

    if (term) {
        term.dispose();
        term = null;
    }
});

function initTerminal() {
    term = new Xterm({
        cursorBlink: true,
        cursorStyle: 'bar',
        fontFamily: "'JetBrains Mono Variable', ui-monospace, SFMono-Regular, Menlo, monospace",
        fontSize: 12,
        lineHeight: 1.3,
        scrollback: 5000,
        allowTransparency: true,
        theme: {
            background: '#04080c',
            foreground: '#c9d6e2',
            cursor: '#2ee6a8',
            cursorAccent: '#04080c',
            selectionBackground: '#1c3b4a',
            black: '#0b141e',
            red: '#fb7185',
            green: '#2ee6a8',
            yellow: '#f5b544',
            blue: '#38bdf8',
            magenta: '#c084fc',
            cyan: '#22d3ee',
            white: '#e8eff8',
            brightBlack: '#5b6f84',
            brightRed: '#fda4af',
            brightGreen: '#6ee7b7',
            brightYellow: '#fcd34d',
            brightBlue: '#7dd3fc',
            brightMagenta: '#d8b4fe',
            brightCyan: '#67e8f9',
            brightWhite: '#ffffff',
        },
    });

    fitAddon = new FitAddon();
    term.loadAddon(fitAddon);
    term.open(terminalEl.value);

    fit();
    term.onData(queueInput);
    term.onResize(({ cols, rows }) => {
        if (!session.value) return;

        api.post(`/consoles/${session.value}/resize`, { cols, rows }).catch(() => {});
    });

    term.focus();
}

function fit() {
    try {
        fitAddon?.fit();
    } catch {
        // The viewport can be measured before layout settles; the next resize retries.
    }
}

async function connect() {
    const activeGeneration = ++generation;
    status.value = 'connecting';
    error.value = null;
    polling = false;
    inputBuffer = '';

    fit();

    if (!term) return;

    term.reset();
    term.write(`\x1b[38;5;244mConnecting to ${title.value}…\x1b[0m\r\n`);

    try {
        const data = await api.post('/consoles', {
            target: props.target,
            kind: props.kind,
            mode: props.mode,
            name: props.name,
            cols: Math.max(20, term.cols),
            rows: Math.max(5, term.rows),
        });

        if (activeGeneration !== generation || disposed) {
            sendClose(data.session);
            return;
        }

        session.value = data.session;
        status.value = 'open';
        term.write(`\x1b[38;5;42mConnected · ${data.title ?? title.value}\x1b[0m\r\n\r\n`);
        term.focus();
        poll();
    } catch (exception) {
        if (activeGeneration !== generation || disposed) return;

        status.value = 'error';
        error.value = exception.message ?? 'The console could not be opened.';
        term.write(`\x1b[31m${error.value}\x1b[0m\r\n`);
    }
}

async function poll() {
    const id = session.value;

    if (!id || disposed || polling) return;

    const activeGeneration = generation;
    polling = true;
    let nextDelay = 0;

    try {
        const data = await api.get(`/consoles/${id}?offset=${Math.max(0, pollOffset)}`);

        if (activeGeneration !== generation || disposed) return;

        if (data.data) {
            term.write(base64ToBytes(data.data));
            pollOffset = Number(data.offset ?? pollOffset);
        }

        if (data.closed) {
            status.value = 'closed';
            term.write(`\r\n\x1b[38;5;244m[console ended${data.exit_code !== null && data.exit_code !== undefined ? ` · exit ${data.exit_code}` : ''}]\x1b[0m\r\n`);
            return;
        }

        nextDelay = 120;
    } catch (exception) {
        if (activeGeneration !== generation || disposed) return;

        if (exception.status === 410) {
            status.value = 'closed';
            term.write('\r\n\x1b[38;5;244m[console session expired]\x1b[0m\r\n');
            return;
        }

        status.value = 'error';
        error.value = exception.message ?? 'Console connection lost.';
        term.write(`\r\n\x1b[31m${error.value}\x1b[0m\r\n`);
        nextDelay = 1500;
    } finally {
        polling = false;
    }

    if (nextDelay && activeGeneration === generation) {
        pollTimer = setTimeout(poll, nextDelay);
    }
}

function queueInput(data) {
    if (!session.value || status.value !== 'open' || !data) return;

    inputBuffer += data;

    if (inputBuffer.length >= 4096) {
        flushInput();
        return;
    }

    clearTimeout(inputTimer);
    inputTimer = setTimeout(flushInput, 15);
}

function flushInput() {
    clearTimeout(inputTimer);

    if (!inputBuffer || !session.value) return;

    const id = session.value;
    const payload = inputBuffer;
    inputBuffer = '';
    const bytes = new TextEncoder().encode(payload);

    for (let offset = 0; offset < bytes.length; offset += 131072) {
        const encoded = bytesToBase64(bytes.subarray(offset, offset + 131072));

        writeChain = writeChain
            .then(() => api.post(`/consoles/${id}/input`, { data: encoded }))
            .catch((exception) => {
                if (id !== session.value) return;

                if (exception.status === 410) {
                    status.value = 'closed';
                    term?.write('\r\n\x1b[38;5;244m[console session expired]\x1b[0m\r\n');
                    return;
                }

                status.value = 'error';
                error.value = exception.message ?? 'Console input failed.';
            });
    }
}

function clearScreen() {
    term?.clear();
    term?.focus();
}

async function reconnect() {
    stopTimers();

    if (session.value) {
        const previous = session.value;
        session.value = null;
        sendClose(previous);
    }

    pollOffset = 0;
    await nextTick();
    connect();
}

function requestClose() {
    const id = session.value;
    session.value = null;
    disposed = true;
    generation += 1;
    stopTimers();

    if (id) sendClose(id);

    if (term) {
        term.dispose();
        term = null;
    }

    emit('close');
}

function sendClose(id) {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    fetch(`/consoles/${id}`, {
        method: 'DELETE',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
    }).catch(() => {});
}

function stopTimers() {
    clearTimeout(pollTimer);
    clearTimeout(inputTimer);
    clearTimeout(resizeTimer);
    pollTimer = null;
    inputTimer = null;
    resizeTimer = null;
}

function onWindowResize() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(fit, 120);
}

async function toggleFullscreen() {
    fullscreen.value = !fullscreen.value;
    await nextTick();
    fit();
    term?.focus();
}

function base64ToBytes(value) {
    const binary = atob(value);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

function bytesToBase64(bytes) {
    let binary = '';
    const chunk = 0x2000;

    for (let index = 0; index < bytes.length; index += chunk) {
        binary += String.fromCharCode(...bytes.subarray(index, index + chunk));
    }

    return btoa(binary);
}
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/85 backdrop-blur-sm" :class="fullscreen ? '' : 'p-3 sm:p-6'">
            <div
                class="flex flex-col overflow-hidden bg-[#071018] shadow-2xl shadow-black/80"
                :class="fullscreen
                    ? 'h-screen w-screen'
                    : 'h-[min(780px,calc(100vh-1.5rem))] w-[min(1240px,calc(100vw-1.5rem))] rounded-2xl border border-line-bright/80 sm:h-[min(780px,calc(100vh-3rem))] sm:w-[min(1240px,calc(100vw-3rem))]'"
                role="dialog"
                aria-modal="true"
                :aria-label="`${title} console`"
            >
                <div class="flex flex-wrap items-center gap-3 border-b border-line/80 bg-panel/70 px-4 py-2.5">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border border-cyan/20 bg-cyan/10 text-cyan">
                        <SquareTerminal class="h-4 w-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="truncate font-mono text-xs font-semibold text-bright">{{ title }}</h2>
                            <span class="rounded border border-line bg-panel-2 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-dim">{{ kindLabel }}</span>
                        </div>
                        <p class="mt-0.5 truncate text-[11px] text-dim">{{ subtitle }}</p>
                    </div>

                    <span class="badge" :class="statusClass">
                        <span class="h-1.5 w-1.5 rounded-full" :class="{
                            'bg-accent': status === 'open',
                            'animate-pulse bg-amber': status === 'connecting',
                            'bg-rose': status === 'closed' || status === 'error',
                        }" />
                        {{ statusLabel }}
                    </span>

                    <div class="flex items-center gap-1">
                        <button
                            v-if="status === 'closed' || status === 'error'"
                            type="button"
                            class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-accent/40 hover:text-accent"
                            title="Reconnect"
                            @click="reconnect"
                        >
                            <RotateCw class="h-3.5 w-3.5" />
                        </button>
                        <button
                            type="button"
                            class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-cyan/40 hover:text-cyan"
                            title="Clear screen"
                            @click="clearScreen"
                        >
                            <Eraser class="h-3.5 w-3.5" />
                        </button>
                        <button
                            type="button"
                            class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-cyan/40 hover:text-cyan"
                            :title="fullscreen ? 'Exit fullscreen' : 'Fullscreen'"
                            @click="toggleFullscreen"
                        >
                            <Minimize2 v-if="fullscreen" class="h-3.5 w-3.5" />
                            <Maximize2 v-else class="h-3.5 w-3.5" />
                        </button>
                        <button
                            type="button"
                            class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-rose/40 hover:text-rose"
                            title="Close console"
                            @click="requestClose"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>

                <div class="relative min-h-0 flex-1 overflow-hidden bg-[#04080c] p-1.5 sm:p-2">
                    <div ref="terminalEl" class="h-full w-full" />
                </div>

                <div class="flex flex-wrap items-center gap-2 border-t border-line/80 bg-panel/60 px-4 py-1.5 text-[11px] text-dim">
                    <span class="font-mono">{{ target }} · {{ name }}{{ isSerial ? ' · serial' : '' }}</span>
                    <span class="text-line-bright">·</span>
                    <span v-if="error" class="truncate text-rose">{{ error }}</span>
                    <span v-else>Ctrl+C interrupts · Ctrl+D exits · paste with Ctrl+Shift+V</span>
                    <span class="ml-auto hidden sm:inline">Sessions end after 30 minutes idle</span>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
:deep(.xterm) {
    height: 100%;
    padding: 4px 5px;
}

:deep(.xterm-viewport) {
    scrollbar-width: thin;
    scrollbar-color: #294157 transparent;
}
</style>
