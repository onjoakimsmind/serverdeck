<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    Cpu,
    Eye,
    EyeOff,
    Fingerprint,
    HardDrive,
    KeyRound,
    LockKeyhole,
    MonitorCog,
    ShieldCheck,
    TerminalSquare,
    UserRound,
} from '@lucide/vue';
import AppLogo from '@/Components/AppLogo.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { formatUptime } from '@/lib/format';

const props = defineProps({
    host: { type: Object, required: true },
});

const form = useForm({
    username: '',
    password: '',
    remember: true,
});

const showPassword = ref(false);

const canSubmit = computed(() => form.username.length > 0 && form.password.length > 0);

const facts = computed(() => [
    { icon: Cpu, label: 'Processor', value: `${props.host.cpu_model} · ${props.host.cpu_threads} threads` },
    { icon: MonitorCog, label: 'Operating system', value: `${props.host.os} · kernel ${props.host.kernel}` },
    { icon: HardDrive, label: 'Hostname', value: props.host.hostname },
    { icon: TerminalSquare, label: 'Uptime', value: formatUptime(props.host.uptime_seconds) },
]);

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign in" />

    <div class="relative min-h-screen overflow-hidden">
        <div class="absolute inset-0 grid-bg opacity-40" />
        <div class="absolute -left-40 top-0 h-[520px] w-[520px] rounded-full bg-accent/[0.055] blur-[120px]" />
        <div class="absolute bottom-0 right-0 h-[420px] w-[420px] rounded-full bg-cyan/[0.045] blur-[110px]" />

        <div class="relative mx-auto flex min-h-screen w-full max-w-[1400px] flex-col lg:grid lg:grid-cols-[1.05fr_0.95fr]">
            <section class="hidden flex-col justify-between border-r border-line/60 p-10 lg:flex xl:p-14">
                <AppLogo />

                <div class="max-w-xl py-12">
                    <div class="mb-5 inline-flex items-center gap-2 rounded-full border border-line bg-panel/70 px-3 py-1 text-[11px] font-medium uppercase tracking-[0.14em] text-muted">
                        <StatusDot status="online" pulse />
                        This machine · {{ host.hostname }}
                    </div>

                    <h1 class="text-[42px] font-semibold leading-[1.06] tracking-[-0.03em] text-bright xl:text-[52px]">
                        The machine
                        <br />
                        <span class="text-muted">you are already on.</span>
                    </h1>
                    <p class="mt-5 max-w-md text-sm leading-7 text-muted">
                        ServerDeck is the control panel for this Linux host. Your existing local account
                        is the only credential it needs.
                    </p>

                    <div class="mt-9 overflow-hidden rounded-2xl border border-line bg-panel/70 shadow-2xl shadow-black/30 backdrop-blur">
                        <div class="flex items-center justify-between border-b border-line/70 px-4 py-3">
                            <span class="text-[11px] font-semibold uppercase tracking-[0.15em] text-dim">Host identity</span>
                            <span class="flex items-center gap-1.5 text-[11px] text-accent">
                                <span class="h-1 w-1 rounded-full bg-accent" />
                                PAM authentication
                            </span>
                        </div>

                        <div class="divide-y divide-line/50">
                            <div v-for="fact in facts" :key="fact.label" class="flex items-center gap-3 px-4 py-3">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border border-line bg-panel-2 text-dim">
                                    <component :is="fact.icon" class="h-3.5 w-3.5" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-[11px] font-semibold uppercase tracking-wider text-dim">{{ fact.label }}</div>
                                    <div class="mt-0.5 truncate text-[11px] text-muted">{{ fact.value }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between border-t border-line/70 bg-panel-2/40 px-4 py-2.5 text-[11px] text-dim">
                            <span class="flex items-center gap-1.5"><Fingerprint class="h-3 w-3 text-accent" /> Linux PAM · host accounts</span>
                            <span>No panel password stored</span>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-5 text-[11px] text-dim">
                    <span class="flex items-center gap-1.5"><Check class="h-3 w-3 text-accent" /> Local Linux credentials</span>
                    <span class="flex items-center gap-1.5"><Check class="h-3 w-3 text-accent" /> Role-based access</span>
                    <span class="flex items-center gap-1.5"><Check class="h-3 w-3 text-accent" /> Full audit trail</span>
                </div>
            </section>

            <section class="flex flex-1 items-center justify-center px-4 py-8 sm:px-8 lg:px-12">
                <div class="w-full max-w-[400px]">
                    <div class="mb-10 flex justify-center lg:hidden">
                        <AppLogo />
                    </div>

                    <div class="mb-8">
                        <div class="mb-3 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-dim">
                            <LockKeyhole class="h-3.5 w-3.5" />
                            Host sign-in
                        </div>
                        <h2 class="text-2xl font-semibold tracking-tight text-bright">Sign in to {{ host.hostname }}</h2>
                        <p class="mt-2 text-sm leading-6 text-muted">
                            Use the username and password of a Linux account on this machine.
                        </p>
                    </div>

                    <div
                        v-if="form.errors.username"
                        class="mb-5 rounded-lg border border-rose/25 bg-rose/10 px-3.5 py-3 text-xs leading-5 text-rose"
                    >
                        {{ form.errors.username }}
                    </div>

                    <form class="space-y-4" @submit.prevent="submit">
                        <div>
                            <label for="username" class="label">Linux username</label>
                            <div class="relative">
                                <UserRound class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-dim" />
                                <input
                                    id="username"
                                    v-model="form.username"
                                    type="text"
                                    name="username"
                                    autocomplete="username"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    placeholder="joakim"
                                    class="input h-11 pl-10 font-mono"
                                    :class="{ 'border-rose/50': form.errors.username }"
                                    required
                                />
                            </div>
                        </div>

                        <div>
                            <div class="flex items-center justify-between">
                                <label for="password" class="label">Host password</label>
                                <span class="text-[11px] text-dim">checked by PAM</span>
                            </div>
                            <div class="relative">
                                <KeyRound class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-dim" />
                                <input
                                    id="password"
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    name="password"
                                    autocomplete="current-password"
                                    placeholder="••••••••"
                                    class="input h-11 pl-10 pr-11"
                                    required
                                />
                                <button
                                    type="button"
                                    class="absolute inset-y-0 right-0 grid w-11 place-items-center text-dim transition hover:text-bright"
                                    :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                    @click="showPassword = !showPassword"
                                >
                                    <EyeOff v-if="showPassword" class="h-4 w-4" />
                                    <Eye v-else class="h-4 w-4" />
                                </button>
                            </div>
                        </div>

                        <label class="flex cursor-pointer items-center gap-2.5 text-xs text-muted">
                            <span class="relative flex h-4 w-4 items-center justify-center">
                                <input v-model="form.remember" type="checkbox" class="peer h-4 w-4 appearance-none rounded border border-line bg-panel-2 checked:border-accent checked:bg-accent focus:outline-none" />
                                <Check class="pointer-events-none absolute h-3 w-3 text-[#04140f] opacity-0 peer-checked:opacity-100" />
                            </span>
                            Keep me signed in for 30 days
                        </label>

                        <button
                            type="submit"
                            class="btn btn-primary h-11 w-full"
                            :disabled="form.processing || !canSubmit"
                        >
                            <span v-if="form.processing" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                            <template v-else>
                                Sign in as {{ form.username || 'Linux user' }}
                                <ArrowRight class="h-4 w-4" />
                            </template>
                        </button>
                    </form>

                    <div class="mt-6 flex items-start gap-2.5 rounded-xl border border-line/60 bg-panel/40 px-3.5 py-3 text-[11px] leading-5 text-dim">
                        <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0 text-cyan" />
                        <span>
                            ServerDeck never stores your password. PAM validates it against
                            <span class="font-mono text-muted">/etc/pam.d/serverdeck</span> on this host.
                            New accounts start read-only; administrators can promote them after first sign-in.
                        </span>
                    </div>

                    <p class="mt-6 text-center text-[11px] leading-5 text-dim">
                        {{ host.os }} · {{ host.hostname }} · {{ host.kernel }}
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>
