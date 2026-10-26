<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Boxes,
    Database,
    KeyRound,
    Layers,
    Pencil,
    Play,
    Plus,
    RefreshCw,
    Rocket,
    RotateCw,
    Search,
    Square,
    Trash2,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { formatDateTime, timeAgo } from '@/lib/format';
import { pushToast } from '@/lib/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    dokploy: { type: Object, required: true },
});

const page = usePage();
const canOperate = computed(() => page.props.permissions?.operate ?? false);
const canManageSettings = computed(() => page.props.permissions?.manageUsers ?? false);

const search = ref('');
const modal = ref(null);
const projectTarget = ref(null);
const environmentTarget = ref(null);
const pending = ref(null);
const working = ref(false);

const projectForm = useForm({ name: '', description: '' });
const projectEditForm = useForm({ name: '', description: '' });
const environmentForm = useForm({ project_id: '', name: '', description: '' });
const applicationForm = useForm({
    environment_id: '',
    name: '',
    description: '',
    docker_image: 'nginx:alpine',
    deploy: true,
});

const projects = computed(() => props.dokploy.projects ?? []);
const counts = computed(() => props.dokploy.counts ?? {});
const configured = computed(() => props.dokploy.configured ?? false);
const available = computed(() => props.dokploy.available ?? false);

const filteredProjects = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) return projects.value;

    return projects.value
        .map((project) => {
            const projectMatches = `${project.name} ${project.description}`.toLowerCase().includes(term);

            const environments = project.environments
                .map((environment) => {
                    const environmentMatches = `${environment.name} ${environment.description}`.toLowerCase().includes(term);
                    const resources = environmentMatches
                        ? environment.resources
                        : environment.resources.filter((resource) => `${resource.name} ${resource.app_name} ${resource.type_label} ${resource.description}`.toLowerCase().includes(term));

                    if (!projectMatches && !environmentMatches && resources.length === 0) return null;

                    return { ...environment, resources };
                })
                .filter(Boolean);

            return projectMatches || environments.length ? { ...project, environments } : null;
        })
        .filter(Boolean);
});

const resourceIcons = {
    application: Rocket,
    compose: Layers,
    postgres: Database,
    redis: Database,
    mysql: Database,
    mariadb: Database,
    mongo: Database,
    libsql: Database,
};

const actionIcons = {
    start: Play,
    stop: Square,
    redeploy: RotateCw,
    delete: Trash2,
    remove: Trash2,
};

const actionTone = {
    start: 'hover:border-accent/40 hover:text-accent',
    stop: 'hover:border-amber/40 hover:text-amber',
    redeploy: 'hover:border-cyan/40 hover:text-cyan',
    delete: 'hover:border-rose/40 hover:text-rose',
    remove: 'hover:border-rose/40 hover:text-rose',
};

function actionLabel(action) {
    return {
        start: 'Start',
        stop: 'Stop',
        redeploy: 'Redeploy',
        delete: 'Delete',
        remove: 'Remove',
    }[action] ?? action;
}

function actionsFor(resource) {
    if (!canOperate.value) return [];

    if (['application', 'compose'].includes(resource.type)) {
        return resource.running ? ['stop', 'redeploy', 'delete'] : ['start', 'redeploy', 'delete'];
    }

    return resource.running ? ['stop', 'remove'] : ['start', 'remove'];
}

function resourceTone(resource) {
    if (resource.attention) return 'rose';
    if (resource.running) return 'accent';
    return 'muted';
}

function resourceDot(resource) {
    if (resource.attention) return 'offline';
    if (resource.running) return 'online';
    return 'maintenance';
}

function resourceStatus(resource) {
    if (resource.status === 'done' || resource.status === 'running') return 'running';
    if (resource.status === 'idle') return 'stopped';
    return resource.status || 'unknown';
}

function runningCount(environment) {
    return environment.resources.filter((resource) => resource.running).length;
}

function openProject() {
    projectForm.reset();
    projectForm.clearErrors();
    modal.value = 'project';
}

function openEditProject(project) {
    projectTarget.value = project;
    projectEditForm.name = project.name;
    projectEditForm.description = project.description ?? '';
    projectEditForm.clearErrors();
    modal.value = 'edit-project';
}

function openEnvironment(project) {
    projectTarget.value = project;
    environmentForm.reset();
    environmentForm.clearErrors();
    environmentForm.project_id = project.id;
    modal.value = 'environment';
}

function openApplication(project, environment) {
    projectTarget.value = project;
    environmentTarget.value = environment;
    applicationForm.reset();
    applicationForm.clearErrors();
    applicationForm.environment_id = environment.id;
    applicationForm.docker_image = 'nginx:alpine';
    applicationForm.deploy = true;
    modal.value = 'application';
}

function closeModal(force = false) {
    if ((projectForm.processing || projectEditForm.processing || environmentForm.processing || applicationForm.processing) && !force) return;
    modal.value = null;
    projectTarget.value = null;
    environmentTarget.value = null;
}

function submitProject() {
    projectForm.post('/dokploy/projects', {
        preserveScroll: true,
        onSuccess: () => closeModal(true),
    });
}

function submitProjectEdit() {
    if (!projectTarget.value) return;

    projectEditForm.put(`/dokploy/projects/${projectTarget.value.id}`, {
        preserveScroll: true,
        onSuccess: () => closeModal(true),
    });
}

function submitEnvironment() {
    environmentForm.post('/dokploy/environments', {
        preserveScroll: true,
        onSuccess: () => closeModal(true),
    });
}

function submitApplication() {
    applicationForm.post('/dokploy/applications', {
        preserveScroll: true,
        onSuccess: () => closeModal(true),
    });
}

function confirmProjectDelete(project) {
    pending.value = { kind: 'project', project };
}

function confirmEnvironmentDelete(project, environment) {
    pending.value = { kind: 'environment', project, environment };
}

function requestResourceAction(resource, action) {
    if (['start'].includes(action)) {
        runResourceAction(resource, action);
        return;
    }

    pending.value = { kind: 'resource', resource, action };
}

function runResourceAction(resource, action) {
    working.value = true;

    router.post(`/dokploy/resources/${resource.type}/${resource.id}/action`, { action }, {
        preserveScroll: true,
        onSuccess: () => {
            pending.value = null;
        },
        onError: (errors) => {
            pushToast(Object.values(errors)[0] ?? 'The Dokploy action failed.', 'error');
        },
        onFinish: () => (working.value = false),
    });
}

function confirmPending() {
    if (!pending.value) return;

    if (pending.value.kind === 'project') {
        working.value = true;
        router.delete(`/dokploy/projects/${pending.value.project.id}`, {
            preserveScroll: true,
            onSuccess: () => (pending.value = null),
            onFinish: () => (working.value = false),
        });

        return;
    }

    if (pending.value.kind === 'environment') {
        working.value = true;
        router.delete(`/dokploy/environments/${pending.value.environment.id}`, {
            preserveScroll: true,
            onSuccess: () => (pending.value = null),
            onFinish: () => (working.value = false),
        });

        return;
    }

    if (pending.value.kind === 'resource') {
        runResourceAction(pending.value.resource, pending.value.action);
    }
}

const pendingTitle = computed(() => {
    if (!pending.value) return '';

    if (pending.value.kind === 'project') return `Delete ${pending.value.project.name}?`;
    if (pending.value.kind === 'environment') return `Delete ${pending.value.environment.name}?`;

    return `${actionLabel(pending.value.action)} ${pending.value.resource.name}?`;
});

const pendingDescription = computed(() => {
    if (!pending.value) return '';

    if (pending.value.kind === 'project') {
        return `${pending.value.project.name} and every environment and service inside it will be removed from Dokploy. This cannot be undone.`;
    }

    if (pending.value.kind === 'environment') {
        return `${pending.value.environment.name} in ${pending.value.project.name} will be removed. Services still attached to it may block the deletion.`;
    }

    const resource = pending.value.resource;

    if (['delete', 'remove'].includes(pending.value.action)) {
        return `${resource.name} will be ${resource.type === 'application' || resource.type === 'compose' ? 'deleted' : 'removed'} from Dokploy${resource.type === 'compose' ? ' without deleting its volumes' : ''}. This cannot be undone.`;
    }

    if (pending.value.action === 'stop') {
        return `${resource.name} will be stopped. Traffic to this service may be interrupted immediately.`;
    }

    return `${resource.name} will be redeployed from its configured source. The current container is replaced when the build finishes.`;
});

const pendingConfirmLabel = computed(() => {
    if (!pending.value) return 'Confirm';
    if (pending.value.kind === 'project' || pending.value.kind === 'environment') return 'Delete';

    return actionLabel(pending.value.action);
});
</script>

<template>
    <Head title="Dokploy" />

    <div class="space-y-5">
        <PageHeader
            title="Dokploy"
            subtitle="Projects, environments and services managed by Dokploy on this host."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot :status="available ? 'online' : configured ? 'degraded' : 'maintenance'" pulse />
                    Dokploy
                    <span class="text-dim">·</span>
                    {{ dokploy.url }}
                    <template v-if="available">
                        <span class="text-dim">·</span>
                        {{ counts.projects ?? 0 }} projects
                        <span class="text-dim">·</span>
                        {{ counts.running ?? 0 }}/{{ counts.resources ?? 0 }} services running
                    </template>
                </span>
            </template>
            <template #actions>
                <button v-if="canOperate && available" type="button" class="btn btn-primary btn-sm" @click="openProject">
                    <Plus class="h-3.5 w-3.5" /> New project
                </button>
                <button type="button" class="btn btn-sm" @click="router.reload({ preserveScroll: true, preserveState: true })">
                    <RefreshCw class="h-3.5 w-3.5" /> Refresh
                </button>
            </template>
        </PageHeader>

        <section v-if="!configured" class="panel p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                <div class="grid h-11 w-11 shrink-0 place-items-center rounded-xl border border-cyan/20 bg-cyan/10 text-cyan">
                    <KeyRound class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-semibold text-bright">Connect the Dokploy API</h2>
                    <p class="mt-1.5 max-w-2xl text-xs leading-6 text-muted">
                        ServerDeck talks to Dokploy through its REST API using an API key. Create a key in Dokploy under
                        <span class="font-mono text-bright">Settings → API Keys</span>, then store it here. The key is encrypted
                        with the application key and is never shown back in full.
                    </p>
                    <Link v-if="canManageSettings" href="/settings" class="btn btn-primary btn-sm mt-4">
                        <KeyRound class="h-3 w-3" /> Open settings
                    </Link>
                    <p v-else class="mt-4 text-[11px] text-dim">Ask a ServerDeck administrator to add the Dokploy API key in Settings.</p>
                </div>
            </div>
        </section>

        <div v-else-if="dokploy.error" class="flex items-start gap-2.5 rounded-xl border border-rose/25 bg-rose/10 px-4 py-3 text-[11px] leading-5 text-rose">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <div class="min-w-0">
                <p class="font-medium">Dokploy API request failed</p>
                <p class="mt-0.5 break-words">{{ dokploy.error }}</p>
                <Link v-if="canManageSettings" href="/settings" class="mt-2 inline-block font-medium text-rose underline decoration-rose/40 underline-offset-2">
                    Check the API token in Settings
                </Link>
            </div>
        </div>

        <template v-if="available">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Projects" :value="counts.projects ?? 0" :subtitle="`${counts.environments ?? 0} environments`" :icon="Rocket" tone="accent" detail="Dokploy project boundaries" />
                <StatCard label="Applications" :value="counts.applications ?? 0" subtitle="Application services" :icon="Boxes" tone="cyan" :detail="`${counts.running ?? 0} running across all services`" />
                <StatCard label="Compose stacks" :value="counts.compose ?? 0" subtitle="Docker Compose workloads" :icon="Layers" tone="accent" detail="Managed through Dokploy" />
                <StatCard label="Data services" :value="counts.databases ?? 0" subtitle="Postgres, Redis, MySQL, Mongo" :icon="Database" tone="cyan" :detail="(counts.attention ?? 0) > 0 ? `${counts.attention} service needs attention` : 'No failed services reported'" />
            </div>

            <section class="panel overflow-hidden">
                <div class="panel-header flex-wrap gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-bright">Projects</h2>
                        <p class="mt-0.5 text-[11px] text-dim">Every project and environment visible to this API key</p>
                    </div>
                    <div class="relative">
                        <Search class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-dim" />
                        <input v-model="search" type="search" class="input h-8 w-64 py-1 pl-8 text-xs" placeholder="Search projects and services…" aria-label="Search Dokploy projects" />
                    </div>
                </div>

                <div v-if="filteredProjects.length" class="space-y-4 p-4">
                    <article v-for="project in filteredProjects" :key="project.id" class="overflow-hidden rounded-xl border border-line/70 bg-panel-2/20">
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line/60 bg-panel/40 px-4 py-3.5">
                            <div class="flex min-w-0 items-center gap-2.5">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-accent/20 bg-accent/10 text-accent">
                                    <Rocket class="h-4 w-4" />
                                </span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="truncate text-sm font-semibold text-bright">{{ project.name }}</h3>
                                        <span class="badge border-line bg-panel-2 text-dim">{{ project.environments.length }} env</span>
                                    </div>
                                    <p class="mt-0.5 truncate text-[11px] text-dim">
                                        {{ project.description || 'No description' }}
                                        <template v-if="project.created_at"> · created {{ timeAgo(project.created_at) }}</template>
                                    </p>
                                </div>
                            </div>

                            <div v-if="canOperate" class="flex items-center gap-1.5">
                                <button type="button" class="btn btn-sm" @click="openEnvironment(project)">
                                    <Plus class="h-3 w-3" /> Environment
                                </button>
                                <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-cyan/40 hover:text-cyan" title="Edit project" @click="openEditProject(project)">
                                    <Pencil class="h-3 w-3" />
                                </button>
                                <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-rose/40 hover:text-rose" title="Delete project" @click="confirmProjectDelete(project)">
                                    <Trash2 class="h-3 w-3" />
                                </button>
                            </div>
                        </div>

                        <div class="space-y-3 p-3.5">
                            <div v-for="environment in project.environments" :key="environment.id" class="overflow-hidden rounded-lg border border-line/60 bg-base/30">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line/50 px-3.5 py-2.5">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="truncate text-[11px] font-semibold text-bright">{{ environment.name }}</h4>
                                            <span v-if="environment.is_default" class="rounded bg-white/[0.05] px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wider text-dim">default</span>
                                        </div>
                                        <p class="mt-0.5 truncate text-[11px] text-dim">{{ environment.description || 'No description' }}</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[11px] text-dim">{{ runningCount(environment) }}/{{ environment.resources.length }} running</span>
                                        <button v-if="canOperate" type="button" class="btn btn-sm" @click="openApplication(project, environment)">
                                            <Plus class="h-3 w-3" /> App
                                        </button>
                                        <button v-if="canOperate" type="button" class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition hover:border-rose/40 hover:text-rose" title="Delete environment" @click="confirmEnvironmentDelete(project, environment)">
                                            <Trash2 class="h-3 w-3" />
                                        </button>
                                    </div>
                                </div>

                                <div v-if="environment.resources.length" class="divide-y divide-line/40">
                                    <div v-for="resource in environment.resources" :key="`${resource.type}-${resource.id}`" class="flex flex-wrap items-center gap-3 px-3.5 py-2.5 transition hover:bg-white/[0.02]">
                                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border border-line bg-panel-2 text-muted">
                                            <component :is="resourceIcons[resource.type] ?? Boxes" class="h-3.5 w-3.5" />
                                        </span>

                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="truncate text-[11px] font-medium text-bright">{{ resource.name }}</span>
                                                <span class="rounded bg-white/[0.05] px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wider text-dim">{{ resource.type_label }}</span>
                                            </div>
                                            <p class="mt-0.5 truncate font-mono text-[11px] text-dim" :title="resource.app_name || resource.id">
                                                {{ resource.app_name || resource.id }}<template v-if="resource.description"> · {{ resource.description }}</template>
                                            </p>
                                        </div>

                                        <span class="badge shrink-0" :class="{
                                            'border-accent/25 bg-accent/10 text-accent': resourceTone(resource) === 'accent',
                                            'border-rose/25 bg-rose/10 text-rose': resourceTone(resource) === 'rose',
                                            'border-line bg-panel-2 text-dim': resourceTone(resource) === 'muted',
                                        }">
                                            <StatusDot :status="resourceDot(resource)" />
                                            {{ resourceStatus(resource) }}
                                        </span>

                                        <span class="hidden shrink-0 text-[11px] text-dim lg:inline">{{ resource.created_at ? formatDateTime(resource.created_at) : '—' }}</span>

                                        <div v-if="actionsFor(resource).length" class="flex shrink-0 items-center gap-1">
                                            <button
                                                v-for="action in actionsFor(resource)"
                                                :key="action"
                                                type="button"
                                                class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition"
                                                :class="actionTone[action]"
                                                :title="`${actionLabel(action)} ${resource.name}`"
                                                @click="requestResourceAction(resource, action)"
                                            >
                                                <component :is="actionIcons[action]" class="h-3 w-3" />
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div v-else class="px-3.5 py-4 text-[11px] text-dim">
                                    No services in this environment yet. Create an application from a Docker image to get started.
                                </div>
                            </div>

                            <p v-if="!project.environments.length" class="rounded-lg border border-dashed border-line px-3.5 py-4 text-[11px] text-dim">
                                This project has no environments. Create one to add services.
                            </p>
                        </div>
                    </article>
                </div>

                <EmptyState v-else :icon="Rocket" title="No projects matched" :description="search ? 'Try a different project or service name.' : 'Create a Dokploy project from ServerDeck to get started.'">
                    <button v-if="canOperate && !search" type="button" class="btn btn-primary btn-sm" @click="openProject">
                        <Plus class="h-3 w-3" /> Create project
                    </button>
                </EmptyState>

                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line/70 px-5 py-3 text-[11px] text-dim">
                    <span>{{ filteredProjects.length }} of {{ projects.length }} projects</span>
                    <span>Dokploy {{ dokploy.generated_at ? `synced ${timeAgo(dokploy.generated_at)}` : 'not synced' }}</span>
                </div>
            </section>

            <div class="flex items-start gap-2.5 rounded-xl border border-line/60 bg-panel/40 px-4 py-3 text-[11px] leading-5 text-dim">
                <Database class="mt-0.5 h-3.5 w-3.5 shrink-0 text-cyan" />
                <span>
                    Project and service state comes from the Dokploy API at {{ dokploy.url }}. ServerDeck deliberately reads
                    only safe project summaries; application environment variables and database credentials are never requested
                    or displayed. Lifecycle actions are restricted by role and written to the audit trail.
                </span>
            </div>
        </template>

        <Modal :show="modal === 'project'" title="New Dokploy project" description="Dokploy creates a production environment automatically." max-width="max-w-lg" @close="closeModal">
            <form class="space-y-4" @submit.prevent="submitProject">
                <div>
                    <label for="dokploy-project-name" class="label">Project name</label>
                    <input id="dokploy-project-name" v-model="projectForm.name" class="input" maxlength="80" autocomplete="off" placeholder="storefront" />
                    <p v-if="projectForm.errors.name" class="mt-1 text-[11px] text-rose">{{ projectForm.errors.name }}</p>
                </div>
                <div>
                    <label for="dokploy-project-description" class="label">Description</label>
                    <textarea id="dokploy-project-description" v-model="projectForm.description" class="input min-h-20 resize-y" maxlength="500" placeholder="What this project hosts" />
                    <p v-if="projectForm.errors.description" class="mt-1 text-[11px] text-rose">{{ projectForm.errors.description }}</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-line/60 pt-4">
                    <button type="button" class="btn btn-sm" :disabled="projectForm.processing" @click="closeModal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="projectForm.processing || !projectForm.name">
                        <span v-if="projectForm.processing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                        <Plus v-else class="h-3 w-3" /> Create project
                    </button>
                </div>
            </form>
        </Modal>

        <Modal :show="modal === 'edit-project'" :title="projectTarget ? `Edit ${projectTarget.name}` : 'Edit project'" description="Rename the project or update its description in Dokploy." max-width="max-w-lg" @close="closeModal">
            <form class="space-y-4" @submit.prevent="submitProjectEdit">
                <div>
                    <label for="dokploy-edit-name" class="label">Project name</label>
                    <input id="dokploy-edit-name" v-model="projectEditForm.name" class="input" maxlength="80" autocomplete="off" />
                    <p v-if="projectEditForm.errors.name" class="mt-1 text-[11px] text-rose">{{ projectEditForm.errors.name }}</p>
                </div>
                <div>
                    <label for="dokploy-edit-description" class="label">Description</label>
                    <textarea id="dokploy-edit-description" v-model="projectEditForm.description" class="input min-h-20 resize-y" maxlength="500" />
                    <p v-if="projectEditForm.errors.description" class="mt-1 text-[11px] text-rose">{{ projectEditForm.errors.description }}</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-line/60 pt-4">
                    <button type="button" class="btn btn-sm" :disabled="projectEditForm.processing" @click="closeModal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="projectEditForm.processing || !projectEditForm.name">Save project</button>
                </div>
            </form>
        </Modal>

        <Modal :show="modal === 'environment'" title="New environment" :description="projectTarget ? `Add an environment to ${projectTarget.name}.` : 'Add a Dokploy environment.'" max-width="max-w-lg" @close="closeModal">
            <form class="space-y-4" @submit.prevent="submitEnvironment">
                <div>
                    <label for="dokploy-environment-name" class="label">Environment name</label>
                    <input id="dokploy-environment-name" v-model="environmentForm.name" class="input" maxlength="80" autocomplete="off" placeholder="staging" />
                    <p v-if="environmentForm.errors.name" class="mt-1 text-[11px] text-rose">{{ environmentForm.errors.name }}</p>
                    <p v-if="environmentForm.errors.project_id" class="mt-1 text-[11px] text-rose">{{ environmentForm.errors.project_id }}</p>
                </div>
                <div>
                    <label for="dokploy-environment-description" class="label">Description</label>
                    <textarea id="dokploy-environment-description" v-model="environmentForm.description" class="input min-h-20 resize-y" maxlength="500" placeholder="Pre-production workloads" />
                    <p v-if="environmentForm.errors.description" class="mt-1 text-[11px] text-rose">{{ environmentForm.errors.description }}</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-line/60 pt-4">
                    <button type="button" class="btn btn-sm" :disabled="environmentForm.processing" @click="closeModal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="environmentForm.processing || !environmentForm.name">
                        <span v-if="environmentForm.processing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                        <Plus v-else class="h-3 w-3" /> Create environment
                    </button>
                </div>
            </form>
        </Modal>

        <Modal :show="modal === 'application'" title="New application" :description="environmentTarget ? `Create a Docker-image application in ${environmentTarget.name}.` : 'Create a Dokploy application.'" max-width="max-w-xl" @close="closeModal">
            <form class="space-y-4" @submit.prevent="submitApplication">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="dokploy-app-name" class="label">Application name</label>
                        <input id="dokploy-app-name" v-model="applicationForm.name" class="input" maxlength="80" autocomplete="off" placeholder="api" />
                        <p v-if="applicationForm.errors.name" class="mt-1 text-[11px] text-rose">{{ applicationForm.errors.name }}</p>
                    </div>
                    <div>
                        <label for="dokploy-app-image" class="label">Docker image</label>
                        <input id="dokploy-app-image" v-model="applicationForm.docker_image" class="input font-mono" maxlength="300" autocomplete="off" placeholder="nginx:alpine" />
                        <p v-if="applicationForm.errors.docker_image" class="mt-1 text-[11px] text-rose">{{ applicationForm.errors.docker_image }}</p>
                    </div>
                </div>
                <div>
                    <label for="dokploy-app-description" class="label">Description</label>
                    <textarea id="dokploy-app-description" v-model="applicationForm.description" class="input min-h-20 resize-y" maxlength="500" placeholder="What this application runs" />
                    <p v-if="applicationForm.errors.description" class="mt-1 text-[11px] text-rose">{{ applicationForm.errors.description }}</p>
                    <p v-if="applicationForm.errors.environment_id" class="mt-1 text-[11px] text-rose">{{ applicationForm.errors.environment_id }}</p>
                </div>
                <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-line bg-panel-2/30 px-3 py-2.5">
                    <input v-model="applicationForm.deploy" type="checkbox" class="mt-0.5 h-3.5 w-3.5 rounded border-line bg-panel-2" />
                    <span>
                        <span class="block text-[11px] font-medium text-bright">Deploy after creation</span>
                        <span class="mt-0.5 block text-[11px] leading-4 text-dim">Queue a Docker deployment immediately. You can also deploy it later from the project list.</span>
                    </span>
                </label>
                <p class="rounded-lg border border-line/60 bg-panel-2/30 px-3 py-2 text-[11px] leading-5 text-dim">
                    The application is created with the <span class="font-mono text-muted">docker</span> source type. Use a public image
                    reference such as <span class="font-mono text-muted">nginx:alpine</span> or a registry path your Dokploy host can pull.
                </p>
                <div class="flex justify-end gap-2 border-t border-line/60 pt-4">
                    <button type="button" class="btn btn-sm" :disabled="applicationForm.processing" @click="closeModal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="applicationForm.processing || !applicationForm.name || !applicationForm.docker_image">
                        <span v-if="applicationForm.processing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                        <Plus v-else class="h-3 w-3" /> Create application
                    </button>
                </div>
            </form>
        </Modal>

        <ConfirmDialog
            :show="Boolean(pending)"
            :title="pendingTitle"
            :description="pendingDescription"
            :confirm-label="pendingConfirmLabel"
            :loading="working"
            @close="pending = null"
            @confirm="confirmPending"
        />
    </div>
</template>
