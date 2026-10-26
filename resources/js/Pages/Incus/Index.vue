<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Boxes,
    Cable,
    CloudDownload,
    HardDrive,
    Layers,
    MemoryStick,
    Network,
    Play,
    Plus,
    RefreshCw,
    RotateCw,
    Search,
    Settings2,
    Snowflake,
    Square,
    SquareTerminal,
    TerminalSquare,
    Trash2,
    Zap,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import ConsoleTerminal from '@/Components/ConsoleTerminal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import MetricBar from '@/Components/MetricBar.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { api } from '@/lib/http';
import { formatBytes, formatCount } from '@/lib/host';
import { formatDateTime, timeAgo } from '@/lib/format';
import { pushToast } from '@/lib/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    incus: { type: Object, required: true },
    images: { type: Array, default: () => [] },
});

const page = usePage();
const canOperate = computed(() => page.props.permissions?.operate ?? false);

const pending = ref(null);
const working = ref(false);
const logInstance = ref(null);
const logText = ref('');
const logLoading = ref(false);
const createOpen = ref(false);
const consoleTarget = ref(null);
const imageSource = ref('cached');
const remoteImages = ref([]);
const remoteImagesLoading = ref(false);
const remoteImagesError = ref('');
const remoteImageSearch = ref('');

const createForm = useForm({
    name: '',
    type: 'container',
    image: '',
    start: true,
});

const instances = computed(() => props.incus.instances ?? []);
const remoteSlotKey = computed(() => (createForm.type === 'virtual-machine' ? 'virtual_machine' : 'container'));
const cachedImages = computed(() => (props.images ?? []).filter((image) => image.type === createForm.type));
const remoteImagesForType = computed(() => remoteImages.value.filter((image) => image[remoteSlotKey.value]));
const filteredRemoteImages = computed(() => {
    const query = remoteImageSearch.value.trim().toLowerCase();

    if (!query) return remoteImagesForType.value;

    return remoteImagesForType.value.filter((image) => [
        image.label,
        image.alias,
        image.os,
        image.release,
        image.variant,
        image.description,
    ].filter(Boolean).some((value) => String(value).toLowerCase().includes(query)));
});
const selectedImage = computed(() => cachedImages.value.find((image) => image.fingerprint === createForm.image) ?? null);
const selectedRemoteImage = computed(() => remoteImages.value.find((image) => image.ref === createForm.image) ?? null);
const totals = computed(() => instances.value.reduce((carry, instance) => ({
    memory: carry.memory + (instance.memory_used ?? 0),
    disk: carry.disk + (instance.disk_used ?? 0),
    processes: carry.processes + (instance.processes ?? 0),
}), { memory: 0, disk: 0, processes: 0 }));

async function loadRemoteImages() {
    if (remoteImages.value.length || remoteImagesLoading.value) return;

    remoteImagesLoading.value = true;
    remoteImagesError.value = '';

    try {
        const data = await api.get('/incus/remote-images');
        remoteImages.value = data.images ?? [];

        if (imageSource.value === 'remote' && !createForm.image) {
            createForm.image = remoteImagesForType.value[0]?.ref ?? '';
        }
    } catch (error) {
        remoteImagesError.value = error.message ?? 'The Incus image catalog could not be loaded.';
    } finally {
        remoteImagesLoading.value = false;
    }
}

function openCreate(type = 'container') {
    createForm.reset();
    createForm.clearErrors();
    createForm.type = type;
    createForm.name = `${type === 'virtual-machine' ? 'vm' : 'ct'}-${Math.random().toString(36).slice(2, 6)}`;
    createForm.start = true;
    remoteImageSearch.value = '';
    imageSource.value = (props.images ?? []).some((image) => image.type === type) ? 'cached' : 'remote';
    createForm.image = imageSource.value === 'cached'
        ? (props.images ?? []).find((image) => image.type === type)?.fingerprint ?? ''
        : remoteImages.value.find((image) => image[type === 'virtual-machine' ? 'virtual_machine' : 'container'])?.ref ?? '';
    createOpen.value = true;
    loadRemoteImages();
}

function setCreateType(type) {
    if (createForm.type === type) return;

    createForm.type = type;
    createForm.clearErrors('type', 'image');

    if (imageSource.value === 'cached') {
        if (!cachedImages.value.some((image) => image.fingerprint === createForm.image)) {
            createForm.image = cachedImages.value[0]?.fingerprint ?? '';
        }

        if (!createForm.image) {
            imageSource.value = 'remote';
        }
    }

    if (imageSource.value === 'remote' && !remoteImagesForType.value.some((image) => image.ref === createForm.image)) {
        createForm.image = remoteImagesForType.value[0]?.ref ?? '';
    }
}

function selectImageSource(source) {
    if (imageSource.value === source) return;

    imageSource.value = source;
    createForm.clearErrors('image');

    if (source === 'cached') {
        createForm.image = cachedImages.value[0]?.fingerprint ?? '';
    } else {
        createForm.image = filteredRemoteImages.value[0]?.ref ?? remoteImagesForType.value[0]?.ref ?? '';
        loadRemoteImages();
    }
}

function chooseRemoteImage(image) {
    createForm.image = image.ref;
    createForm.clearErrors('image');
}

function closeCreate(force = false) {
    if (createForm.processing && !force) return;
    createOpen.value = false;
}

function submitCreate() {
    createForm.post('/incus/create', {
        preserveScroll: true,
        onSuccess: () => closeCreate(true),
    });
}

const statusTone = (instance) => {
    if (instance.status === 'running') return 'accent';
    if (instance.status === 'frozen') return 'amber';
    return 'muted';
};

const statusDot = (instance) => {
    if (instance.status === 'running') return 'online';
    if (instance.status === 'frozen') return 'degraded';
    return 'maintenance';
};

function actionLabel(action) {
    return {
        start: 'Start',
        stop: 'Stop',
        restart: 'Restart',
        freeze: 'Freeze',
        unfreeze: 'Unfreeze',
        'force-stop': 'Force stop',
        delete: 'Delete',
    }[action] ?? action;
}

function actionsFor(instance) {
    if (!canOperate.value) return [];

    if (instance.status === 'running') return ['restart', 'freeze', 'stop', 'force-stop'];
    if (instance.status === 'frozen') return ['unfreeze', 'force-stop'];
    if (['stopped', 'shutdown'].includes(instance.status)) return ['start', 'delete'];

    return [];
}

const actionIcons = {
    start: Play,
    stop: Square,
    restart: RotateCw,
    freeze: Snowflake,
    unfreeze: Play,
    'force-stop': Zap,
    delete: Trash2,
};

const actionTone = {
    start: 'hover:border-accent/40 hover:text-accent',
    stop: 'hover:border-rose/40 hover:text-rose',
    restart: 'hover:border-cyan/40 hover:text-cyan',
    freeze: 'hover:border-amber/40 hover:text-amber',
    unfreeze: 'hover:border-accent/40 hover:text-accent',
    'force-stop': 'hover:border-rose/40 hover:text-rose',
    delete: 'hover:border-rose/40 hover:text-rose',
};

function requestAction(action, instance) {
    if (['stop', 'freeze', 'force-stop', 'delete'].includes(action)) {
        pending.value = { action, instance };
        return;
    }

    runAction(action, instance);
}

function runAction(action, instance) {
    working.value = true;

    if (action === 'delete') {
        router.post('/incus/delete', { name: instance.name }, {
            preserveScroll: true,
            onSuccess: () => {
                pushToast(`${instance.name} was deleted.`, 'success');
                pending.value = null;
            },
            onError: (errors) => pushToast(Object.values(errors)[0] ?? 'The Incus delete failed.', 'error'),
            onFinish: () => (working.value = false),
        });

        return;
    }

    router.post('/incus/action', { action, name: instance.name }, {
        preserveScroll: true,
        onSuccess: () => {
            pushToast(`${actionLabel(action)} completed for ${instance.name}.`, 'success');
            pending.value = null;
        },
        onError: (errors) => pushToast(Object.values(errors)[0] ?? 'The Incus action failed.', 'error'),
        onFinish: () => (working.value = false),
    });
}

function confirmPending() {
    if (!pending.value) return;

    runAction(pending.value.action, pending.value.instance);
}

const pendingTitle = computed(() => {
    if (!pending.value) return '';

    if (pending.value.action === 'delete') {
        return `Delete ${pending.value.instance.name}?`;
    }

    return `${actionLabel(pending.value.action)} instance?`;
});

const pendingDescription = computed(() => {
    if (!pending.value) return '';

    const { action, instance } = pending.value;

    if (action === 'delete') {
        return `${instance.name} and its root volume will be removed from Incus. ServerDeck only lists deletion for stopped instances. This cannot be undone.`;
    }

    if (action === 'force-stop') {
        return `${instance.name} will be stopped immediately without waiting for a clean shutdown. Unsaved work inside the instance may be lost.`;
    }

    if (action === 'freeze') {
        return `${instance.name} will be frozen until it is unfrozen. Its processes stay in memory but are paused.`;
    }

    return `${instance.name} will be asked to shut down cleanly, waiting up to 40 seconds. Use force stop if it does not respond.`;
});

async function openLogs(instance) {
    logInstance.value = instance;
    logText.value = '';
    logLoading.value = true;

    try {
        const data = await api.get(`/incus/logs?name=${encodeURIComponent(instance.name)}`);
        logText.value = data.output || 'No console log output.';
    } catch (error) {
        logText.value = error.message ?? 'Could not read the instance log.';
    } finally {
        logLoading.value = false;
    }
}

function closeLogs() {
    logInstance.value = null;
    logText.value = '';
}

function openConsole(instance, mode = 'shell') {
    if (!canOperate.value || instance.status !== 'running') return;

    consoleTarget.value = {
        target: 'incus',
        kind: instance.type,
        mode,
        name: instance.name,
        label: instance.name,
    };
}

function closeConsole() {
    consoleTarget.value = null;
}

const configOpen = ref(false);
const configInstance = ref(null);
const configDetail = ref(null);
const configLoading = ref(false);
const configSaving = ref(false);
const configError = ref('');
const configTab = ref('general');
const configFields = ref({
    description: '',
    cpu: '',
    memory: '',
    processes: '',
    autostart: false,
    nesting: false,
});
const rootSize = ref('');
const nicMode = ref('bridge');
const nicDraft = ref({ device: '', network: '', parent: '', vlan: '', mtu: '' });
const nicEdits = ref({});
const diskDraft = ref({ device: '', source_type: 'path', source: '', path: '', pool: '', volume: '', readonly: false });
const deviceDraft = ref({ device: '', type: '', selection: '', path: '' });
const deviceInventory = ref(null);
const deviceInventoryLoading = ref(false);
const deviceInventoryError = ref('');
const deviceRemoval = ref(null);
const deviceWorking = ref(false);

const configTabs = [
    { id: 'general', label: 'General' },
    { id: 'network', label: 'Network' },
    { id: 'disks', label: 'Disks' },
    { id: 'devices', label: 'Devices' },
];

const managedNetworks = computed(() => (configDetail.value?.networks ?? []).filter((network) => network.managed));
const hostBridges = computed(() => configDetail.value?.bridges ?? []);
const storagePools = computed(() => configDetail.value?.storage_pools ?? []);
const availableVolumes = computed(() => {
    const contentType = configInstance.value?.type === 'virtual-machine' ? 'block' : 'filesystem';

    return (configDetail.value?.storage_volumes ?? []).filter(
        (volume) => volume.available && volume.content_type === contentType,
    );
});
const nicDevices = computed(() => {
    const local = configDetail.value?.devices ?? {};

    return Object.entries(configDetail.value?.expanded_devices ?? {})
        .filter(([, device]) => device.type === 'nic')
        .map(([name, device]) => ({ name, ...device, local: Object.prototype.hasOwnProperty.call(local, name) }));
});
const diskDevices = computed(() => {
    const local = configDetail.value?.devices ?? {};

    return Object.entries(configDetail.value?.expanded_devices ?? {})
        .filter(([name, device]) => device.type === 'disk' && name !== 'root')
        .map(([name, device]) => ({ name, ...device, local: Object.prototype.hasOwnProperty.call(local, name) }));
});
const rootDisk = computed(() => configDetail.value?.expanded_devices?.root ?? null);
const rootDiskIsLocal = computed(() => Object.prototype.hasOwnProperty.call(configDetail.value?.devices ?? {}, 'root'));
const isVirtualMachine = computed(() => configInstance.value?.type === 'virtual-machine');

const passthroughDeviceTypes = ['unix-char', 'unix-block', 'gpu', 'usb', 'pci'];
const passthroughDevices = computed(() => {
    const local = configDetail.value?.devices ?? {};

    return Object.entries(configDetail.value?.expanded_devices ?? {})
        .filter(([, device]) => passthroughDeviceTypes.includes(device.type))
        .map(([name, device]) => ({
            name,
            ...device,
            local: Object.prototype.hasOwnProperty.call(local, name),
        }));
});
const availableDeviceTypes = computed(() => (isVirtualMachine.value
    ? [
        { id: 'gpu', label: 'Physical GPU', hint: 'Requires a stopped VM' },
        { id: 'usb', label: 'USB device', hint: 'Hotpluggable' },
        { id: 'pci', label: 'PCI device', hint: 'Requires a stopped VM' },
    ]
    : [
        { id: 'unix-char', label: 'Character device', hint: 'Hotpluggable' },
        { id: 'unix-block', label: 'Block device', hint: 'Hotpluggable' },
        { id: 'gpu', label: 'Physical GPU', hint: 'Hotpluggable' },
        { id: 'usb', label: 'USB device', hint: 'Hotpluggable' },
    ]));
const deviceLocked = computed(() => isVirtualMachine.value
    && ['gpu', 'pci'].includes(deviceDraft.value.type)
    && configDetail.value?.status === 'running');
const deviceChoices = computed(() => {
    const inventory = deviceInventory.value;

    if (!inventory) return [];

    if (deviceDraft.value.type === 'unix-char') {
        return (inventory.unix ?? [])
            .filter((device) => device.type === 'char')
            .map((device) => ({
                value: device.path,
                label: device.path,
                description: `${device.kind} · group ${device.group} · ${device.mode}`,
                warnings: device.warnings ?? [],
                used_by: device.used_by ?? [],
                data: device,
            }));
    }

    if (deviceDraft.value.type === 'unix-block') {
        const unix = (inventory.unix ?? []).filter((device) => device.type === 'block' && device.ready !== false);
        const block = (inventory.block ?? []).filter((device) => device.ready !== false).map((device) => ({
            path: device.path,
            kind: 'block',
            group: '—',
            mode: '—',
            warnings: device.warnings ?? [],
            used_by: device.used_by ?? [],
            data: device,
        }));

        return [...unix, ...block].map((device) => ({
            value: device.path,
            label: device.path,
            description: device.size
                ? `${formatBytes(device.size)} · ${device.model || 'block device'}`
                : `${device.kind} · group ${device.group} · ${device.mode}`,
            warnings: device.warnings ?? [],
            used_by: device.used_by ?? [],
            data: device,
        }));
    }

    if (deviceDraft.value.type === 'gpu') {
        return (inventory.gpus ?? []).map((device) => ({
            value: device.address,
            label: device.name,
            description: `${device.address} · IOMMU ${device.iommu_group || 'none'} · driver ${device.driver || 'none'}`,
            warnings: device.warnings ?? [],
            used_by: device.used_by ?? [],
            data: device,
        }));
    }

    if (deviceDraft.value.type === 'usb') {
        return (inventory.usb ?? []).map((device) => ({
            value: `${device.bus}:${device.device}`,
            label: [device.vendor, device.product].filter(Boolean).join(' ') || `USB ${device.vendor_id}:${device.product_id}`,
            description: `${device.vendor_id}:${device.product_id} · bus ${device.bus} device ${device.device}${device.serial ? ` · ${device.serial}` : ''}`,
            warnings: device.warnings ?? [],
            used_by: device.used_by ?? [],
            data: device,
        }));
    }

    if (deviceDraft.value.type === 'pci') {
        return (inventory.pci ?? [])
            .filter((device) => device.iommu_group)
            .map((device) => ({
                value: device.address,
                label: `${device.vendor} ${device.device}`,
                description: `${device.address} · ${device.class_name} · IOMMU ${device.iommu_group} · driver ${device.driver || 'none'}`,
                warnings: device.warnings ?? [],
                used_by: device.used_by ?? [],
                data: device,
            }));
    }

    return [];
});
const selectedDeviceChoice = computed(() => deviceChoices.value.find((choice) => choice.value === deviceDraft.value.selection) ?? null);
const selectedDeviceWarnings = computed(() => selectedDeviceChoice.value?.warnings ?? []);

function nextDeviceName(prefix, devices) {
    const used = new Set(Object.keys(devices ?? {}).map((name) => name.toLowerCase()));
    let index = 0;

    while (used.has(`${prefix}${index}`)) {
        index += 1;
    }

    return `${prefix}${index}`;
}

function resetNicDraft() {
    nicDraft.value = {
        device: nextDeviceName('eth', configDetail.value?.expanded_devices),
        network: '',
        parent: hostBridges.value[0]?.name ?? '',
        vlan: '',
        mtu: '',
    };
}

function resetDiskDraft() {
    diskDraft.value = {
        device: nextDeviceName('disk', configDetail.value?.expanded_devices),
        source_type: 'path',
        source: '',
        path: '',
        pool: storagePools.value[0]?.name ?? '',
        volume: '',
        readonly: false,
    };
}

function resetDeviceDraft(type = null) {
    const nextType = type ?? availableDeviceTypes.value[0]?.id ?? 'usb';
    const prefix = {
        'unix-char': 'dev',
        'unix-block': 'blk',
        gpu: 'gpu',
        usb: 'usb',
        pci: 'pci',
    }[nextType] ?? 'device';

    deviceDraft.value = {
        device: nextDeviceName(prefix, configDetail.value?.expanded_devices),
        type: nextType,
        selection: '',
        path: '',
    };

    const first = deviceChoices.value[0];
    deviceDraft.value.selection = first?.value ?? '';
}

async function loadDeviceInventory(force = false) {
    if (deviceInventoryLoading.value || (deviceInventory.value && !force)) return;

    deviceInventoryLoading.value = true;
    deviceInventoryError.value = '';

    try {
        deviceInventory.value = await api.get(`/api/devices${force ? '?refresh=1' : ''}`);

        if (!deviceDraft.value.selection) {
            deviceDraft.value.selection = deviceChoices.value[0]?.value ?? '';
        }
    } catch (error) {
        deviceInventoryError.value = errorMessage(error, 'The host device inventory could not be loaded.');
    } finally {
        deviceInventoryLoading.value = false;
    }
}

function setConfigTab(tab) {
    configTab.value = tab;

    if (tab === 'devices') {
        if (!deviceDraft.value.type) {
            resetDeviceDraft();
        }

        loadDeviceInventory();
    }
}

function setDeviceType(type) {
    resetDeviceDraft(type);
}

function syncNicEdits() {
    const edits = {};

    for (const [name, device] of Object.entries(configDetail.value?.devices ?? {})) {
        if (device.type !== 'nic') continue;

        edits[name] = {
            vlan: device.vlan ?? '',
            mtu: device.mtu ?? '',
            saving: false,
        };
    }

    nicEdits.value = edits;
}

function applyConfigDetail(detail) {
    configDetail.value = detail;
    configFields.value = {
        description: detail.description ?? '',
        cpu: detail.config?.['limits.cpu'] ?? '',
        memory: detail.config?.['limits.memory'] ?? '',
        processes: detail.config?.['limits.processes'] ?? '',
        autostart: detail.config?.['boot.autostart'] === 'true',
        nesting: detail.config?.['security.nesting'] === 'true',
    };
    rootSize.value = detail.root_size || detail.expanded_devices?.root?.size || '';

    if (!nicDraft.value.device) {
        resetNicDraft();
    } else if (!nicDraft.value.parent && hostBridges.value.length) {
        nicDraft.value.parent = hostBridges.value[0].name;
    }

    if (!diskDraft.value.pool && storagePools.value.length) {
        diskDraft.value.pool = storagePools.value[0].name;
    }

    if (!deviceDraft.value.type) {
        resetDeviceDraft();
    }

    syncNicEdits();
}

async function loadConfig(instance) {
    configLoading.value = true;
    configError.value = '';

    try {
        applyConfigDetail(await api.get(`/incus/detail?name=${encodeURIComponent(instance.name)}`));
    } catch (error) {
        configError.value = errorMessage(error, 'Could not read the instance configuration.');
    } finally {
        configLoading.value = false;
    }
}

function errorMessage(error, fallback) {
    const errors = error?.payload?.errors;

    if (errors && typeof errors === 'object') {
        return Object.values(errors)[0] ?? error.message ?? fallback;
    }

    return error?.message ?? fallback;
}

async function openConfig(instance) {
    configInstance.value = instance;
    configDetail.value = null;
    configFields.value = {
        description: instance.description ?? '',
        cpu: '',
        memory: '',
        processes: '',
        autostart: false,
        nesting: false,
    };
    nicDraft.value = { device: '', network: '', parent: '', vlan: '', mtu: '' };
    diskDraft.value = { device: '', source_type: 'path', source: '', path: '', pool: '', volume: '', readonly: false };
    deviceDraft.value = { device: '', type: '', selection: '', path: '' };
    configTab.value = 'general';
    configOpen.value = true;
    await loadConfig(instance);
}

function closeConfig(force = false) {
    if (configSaving.value && !force) return;

    configOpen.value = false;
    configInstance.value = null;
    configDetail.value = null;
    configError.value = '';
}

async function refreshInstanceList() {
    router.reload({ preserveScroll: true, preserveState: true });
}

async function saveConfig() {
    if (!configInstance.value) return;

    configSaving.value = true;
    configError.value = '';

    try {
        const config = {
            'limits.cpu': configFields.value.cpu,
            'limits.memory': configFields.value.memory,
            'limits.processes': configFields.value.processes,
            'boot.autostart': configFields.value.autostart ? 'true' : 'false',
        };

        if (!isVirtualMachine.value) {
            config['security.nesting'] = configFields.value.nesting ? 'true' : 'false';
        }

        await api.post('/incus/config', {
            name: configInstance.value.name,
            description: configFields.value.description,
            config,
        });

        pushToast(`${configInstance.value.name} configuration saved.`, 'success');
        await loadConfig(configInstance.value);
        refreshInstanceList();
    } catch (error) {
        configError.value = errorMessage(error, 'The configuration could not be saved.');
    } finally {
        configSaving.value = false;
    }
}

async function addNic() {
    if (!configInstance.value) return;

    configSaving.value = true;
    configError.value = '';

    try {
        await api.post('/incus/config/device', {
            name: configInstance.value.name,
            device: nicDraft.value.device,
            type: 'nic',
            network: nicMode.value === 'network' ? nicDraft.value.network : null,
            parent: nicMode.value === 'bridge' ? nicDraft.value.parent : null,
            vlan: nicDraft.value.vlan || null,
            mtu: nicDraft.value.mtu || null,
        });

        pushToast(`Network device ${nicDraft.value.device} attached.`, 'success');
        await loadConfig(configInstance.value);
        resetNicDraft();
        refreshInstanceList();
    } catch (error) {
        configError.value = errorMessage(error, 'The network device could not be added.');
    } finally {
        configSaving.value = false;
    }
}

async function saveNic(device) {
    const edit = nicEdits.value[device];
    if (!edit || !configInstance.value) return;

    edit.saving = true;
    configError.value = '';

    try {
        for (const key of ['vlan', 'mtu']) {
            await api.post('/incus/config/device/set', {
                name: configInstance.value.name,
                device,
                key,
                value: edit[key] === '' ? null : String(edit[key]),
            });
        }

        pushToast(`${device} updated.`, 'success');
        await loadConfig(configInstance.value);
        refreshInstanceList();
    } catch (error) {
        configError.value = errorMessage(error, `The ${device} settings could not be saved.`);
    } finally {
        edit.saving = false;
    }
}

async function overrideDevice(device) {
    if (!configInstance.value) return;

    configSaving.value = true;
    configError.value = '';

    try {
        await api.post('/incus/config/device/override', {
            name: configInstance.value.name,
            device,
        });
        pushToast(`${device} is now locally editable.`, 'success');
        await loadConfig(configInstance.value);
    } catch (error) {
        configError.value = errorMessage(error, `The ${device} device could not be customized.`);
    } finally {
        configSaving.value = false;
    }
}

async function addDisk() {
    if (!configInstance.value) return;

    configSaving.value = true;
    configError.value = '';

    try {
        await api.post('/incus/config/device', {
            name: configInstance.value.name,
            device: diskDraft.value.device,
            type: 'disk',
            source_type: diskDraft.value.source_type,
            source: diskDraft.value.source_type === 'path' ? diskDraft.value.source : null,
            path: diskDraft.value.source_type === 'path' || !isVirtualMachine.value ? diskDraft.value.path : null,
            pool: diskDraft.value.source_type === 'volume' ? diskDraft.value.pool : null,
            volume: diskDraft.value.source_type === 'volume' ? diskDraft.value.volume : null,
            readonly: diskDraft.value.readonly,
        });

        pushToast(`Disk ${diskDraft.value.device} attached.`, 'success');
        await loadConfig(configInstance.value);
        resetDiskDraft();
        refreshInstanceList();
    } catch (error) {
        configError.value = errorMessage(error, 'The disk could not be attached.');
    } finally {
        configSaving.value = false;
    }
}

async function attachDevice() {
    if (!configInstance.value || !deviceDraft.value.device || deviceLocked.value) return;

    const choice = selectedDeviceChoice.value;

    if (!choice) {
        configError.value = 'Choose a host device to pass through.';
        return;
    }

    configSaving.value = true;
    configError.value = '';

    const payload = {
        name: configInstance.value.name,
        device: deviceDraft.value.device,
        type: deviceDraft.value.type,
    };

    if (['unix-char', 'unix-block'].includes(deviceDraft.value.type)) {
        payload.source = choice.value;
        payload.path = deviceDraft.value.path || null;
    } else if (deviceDraft.value.type === 'gpu') {
        payload.gputype = 'physical';
        payload.pci = choice.value;
    } else if (deviceDraft.value.type === 'pci') {
        payload.address = choice.value;
    } else if (deviceDraft.value.type === 'usb') {
        payload.vendor_id = choice.data.vendor_id;
        payload.product_id = choice.data.product_id;
        payload.serial = choice.data.serial || null;
        payload.bus = choice.data.bus;
        payload.devnum = choice.data.device;
    }

    try {
        await api.post('/incus/config/device', payload);
        pushToast(`Device ${deviceDraft.value.device} attached.`, 'success');
        await loadConfig(configInstance.value);
        resetDeviceDraft(deviceDraft.value.type);
        refreshInstanceList();
        loadDeviceInventory(true);
    } catch (error) {
        configError.value = errorMessage(error, 'The device could not be attached.');
    } finally {
        configSaving.value = false;
    }
}

async function resizeRoot() {
    if (!configInstance.value || !rootSize.value) return;
    configSaving.value = true;
    configError.value = '';

    try {
        await api.post('/incus/config/device/set', {
            name: configInstance.value.name,
            device: 'root',
            key: 'size',
            value: rootSize.value,
        });
        pushToast(`Root disk resized to ${rootSize.value}.`, 'success');
        await loadConfig(configInstance.value);
        refreshInstanceList();
    } catch (error) {
        configError.value = errorMessage(error, 'The root disk could not be resized.');
    } finally {
        configSaving.value = false;
    }
}

async function toggleReadonly(device) {
    if (!configInstance.value) return;

    configSaving.value = true;
    configError.value = '';

    try {
        await api.post('/incus/config/device/set', {
            name: configInstance.value.name,
            device: device.name,
            key: 'readonly',
            value: device.readonly === 'true' ? 'false' : 'true',
        });
        pushToast(`${device.name} is now ${device.readonly === 'true' ? 'writable' : 'read-only'}.`, 'success');
        await loadConfig(configInstance.value);
    } catch (error) {
        configError.value = errorMessage(error, `The ${device.name} settings could not be saved.`);
    } finally {
        configSaving.value = false;
    }
}

function requestDeviceRemoval(name, label) {
    deviceRemoval.value = { name, label };
}

const deviceTypeLabels = {
    'unix-char': 'character device',
    'unix-block': 'block device',
    gpu: 'GPU',
    usb: 'USB',
    pci: 'PCI',
};

function passthroughSummary(device) {
    if (device.type === 'unix-char' || device.type === 'unix-block') {
        return `${device.source || '—'}${device.path ? ` → ${device.path}` : ''}`;
    }

    if (device.type === 'gpu') {
        return `${device.pci || device.id || 'all GPUs'} · ${device.gputype || 'physical'}`;
    }

    if (device.type === 'usb') {
        const id = [device.vendorid, device.productid].filter(Boolean).join(':') || 'USB device';
        const selector = device.serial || [device.busnum, device.devnum].filter((value) => value !== undefined && value !== null).join(':');

        return `${id}${selector ? ` · ${selector}` : ''}`;
    }

    if (device.type === 'pci') {
        return device.address || '—';
    }

    return device.type;
}

async function confirmDeviceRemoval() {
    if (!deviceRemoval.value || !configInstance.value) return;

    deviceWorking.value = true;
    configError.value = '';

    try {
        await api.post('/incus/config/device/remove', {
            name: configInstance.value.name,
            device: deviceRemoval.value.name,
        });
        pushToast(`Device ${deviceRemoval.value.name} removed.`, 'success');
        deviceRemoval.value = null;
        await loadConfig(configInstance.value);
        refreshInstanceList();
    } catch (error) {
        configError.value = errorMessage(error, 'The device could not be removed.');
    } finally {
        deviceWorking.value = false;
    }
}
</script>

<template>
    <Head title="LXC/VM" />

    <div class="space-y-5">
        <PageHeader
            title="LXC/VM"
            subtitle="System containers and virtual machines managed by the Incus daemon on this host."
        >
            <template #eyebrow>
                <span class="inline-flex flex-wrap items-center gap-2 rounded-full border border-line bg-panel/60 px-2.5 py-1 text-[11px] text-muted">
                    <StatusDot :status="incus.available ? 'online' : 'offline'" pulse />
                    Incus
                    <span class="text-dim">·</span>
                    {{ incus.counts?.running ?? 0 }} running of {{ incus.counts?.all ?? 0 }}
                    <span class="text-dim">·</span>
                    {{ formatBytes(totals.memory) }} in use
                </span>
            </template>
            <template #actions>
                <button v-if="canOperate" type="button" class="btn btn-primary btn-sm" @click="openCreate()">
                    <Plus class="h-3.5 w-3.5" /> New instance
                </button>
                <button type="button" class="btn btn-sm" @click="router.reload({ preserveScroll: true, preserveState: true })">
                    <RefreshCw class="h-3.5 w-3.5" /> Refresh
                </button>
            </template>
        </PageHeader>

        <div v-if="!incus.available" class="flex items-start gap-2.5 rounded-xl border border-rose/25 bg-rose/10 px-4 py-3 text-[11px] leading-5 text-rose">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ incus.error }}</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Instances" :value="incus.counts?.all ?? 0" :subtitle="`${incus.counts?.running ?? 0} running · ${incus.counts?.stopped ?? 0} stopped`" :icon="Boxes" tone="cyan" detail="System containers and VMs" />
            <StatCard label="Memory in use" :value="formatBytes(totals.memory)" subtitle="Across all running instances" :icon="MemoryStick" tone="accent" :detail="`${formatCount(totals.processes)} processes inside instances`" />
            <StatCard label="Root disk in use" :value="formatBytes(totals.disk)" subtitle="Instance root volumes" :icon="HardDrive" tone="cyan" detail="Live usage reported by Incus" />
            <StatCard label="Virtualisation" :value="`${instances.filter((instance) => instance.type === 'virtual-machine').length} VMs`" :subtitle="`${instances.filter((instance) => instance.type === 'container').length} containers`" :icon="Layers" tone="accent" detail="Mixed workloads supported" />
        </div>

        <div v-if="instances.length" class="grid gap-4 xl:grid-cols-2">
            <section v-for="instance in instances" :key="instance.name" class="panel overflow-hidden">
                <div class="panel-header">
                    <div class="flex min-w-0 items-center gap-2.5">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-cyan/20 bg-cyan/10 text-cyan">
                            <Boxes class="h-4 w-4" />
                        </span>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h2 class="truncate text-sm font-semibold text-bright">{{ instance.name }}</h2>
                                <span class="rounded bg-white/[0.05] px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wider text-dim">{{ instance.type }}</span>
                            </div>
                            <p class="mt-0.5 truncate text-[11px] text-dim">{{ instance.image || instance.description || instance.architecture }}</p>
                        </div>
                    </div>
                    <span class="badge" :class="{
                        'border-accent/25 bg-accent/10 text-accent': statusTone(instance) === 'accent',
                        'border-amber/25 bg-amber/10 text-amber': statusTone(instance) === 'amber',
                        'border-line bg-panel-2 text-dim': statusTone(instance) === 'muted',
                    }">
                        <StatusDot :status="statusDot(instance)" />
                        {{ instance.status }}
                    </span>
                </div>

                <div class="space-y-4 p-5">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2">
                            <div class="text-[11px] uppercase tracking-wider text-dim">IPv4</div>
                            <div class="mt-0.5 truncate font-mono text-[11px] text-muted">{{ instance.ipv4?.length ? instance.ipv4.join(', ') : 'none' }}</div>
                        </div>
                        <div class="rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2">
                            <div class="text-[11px] uppercase tracking-wider text-dim">Processes</div>
                            <div class="mt-0.5 font-mono text-[11px] text-muted">{{ formatCount(instance.processes) }}</div>
                        </div>
                    </div>

                    <MetricBar label="Memory" :value="instance.memory_percent" :detail="`${formatBytes(instance.memory_used)} of ${formatBytes(instance.memory_total)}`" />
                    <MetricBar label="Root disk" :value="instance.disk_percent" :detail="`${formatBytes(instance.disk_used)} of ${formatBytes(instance.disk_total)}`" />

                    <div class="flex flex-wrap items-center gap-2 border-t border-line/60 pt-3 text-[11px]">
                        <span class="text-dim">Project <span class="font-mono text-muted">{{ instance.project }}</span></span>
                        <span class="text-line-bright">·</span>
                        <span class="text-dim">Started <span class="text-muted">{{ instance.started_at ? timeAgo(instance.started_at) : '—' }}</span></span>
                        <span class="text-line-bright">·</span>
                        <span class="text-dim">Created <span class="text-muted">{{ instance.created_at ? formatDateTime(instance.created_at) : '—' }}</span></span>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line/60 pt-3">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <template v-if="canOperate && instance.status === 'running'">
                                <button
                                    type="button"
                                    class="btn btn-sm"
                                    :title="instance.type === 'virtual-machine' ? 'Root shell through the Incus VM agent' : 'Root shell through incus exec'"
                                    @click="openConsole(instance, 'shell')"
                                >
                                    <SquareTerminal class="h-3 w-3" /> Open shell
                                </button>
                                <button
                                    v-if="instance.type === 'virtual-machine'"
                                    type="button"
                                    class="btn btn-sm"
                                    title="Serial console attached through the Incus daemon"
                                    @click="openConsole(instance, 'serial')"
                                >
                                    <Cable class="h-3 w-3" /> Serial console
                                </button>
                            </template>
                            <button v-if="canOperate" type="button" class="btn btn-sm" title="Configure CPU, memory, network and disks" @click="openConfig(instance)">
                                <Settings2 class="h-3 w-3" /> Configure
                            </button>
                            <button type="button" class="btn btn-sm" @click="openLogs(instance)">
                                <TerminalSquare class="h-3 w-3" /> Console log
                            </button>
                        </div>
                        <div v-if="actionsFor(instance).length" class="flex items-center gap-1">
                            <button
                                v-for="action in actionsFor(instance)"
                                :key="action"
                                type="button"
                                class="grid h-7 w-7 place-items-center rounded-md border border-line bg-panel-2 text-muted transition"
                                :class="actionTone[action]"
                                :title="`${actionLabel(action)} ${instance.name}`"
                                @click="requestAction(action, instance)"
                            >
                                <component :is="actionIcons[action]" class="h-3 w-3" />
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <section v-else class="panel">
            <EmptyState :icon="Boxes" title="No Incus instances" :description="canOperate ? 'Create a container or virtual machine from a cached or remote Incus image.' : 'No Incus instances exist on this host yet.'">
                <button v-if="canOperate" type="button" class="btn btn-primary btn-sm" @click="openCreate()">
                    <Plus class="h-3 w-3" /> Create instance
                </button>
            </EmptyState>
        </section>

        <div class="flex items-start gap-2.5 rounded-xl border border-line/60 bg-panel/40 px-4 py-3 text-[11px] leading-5 text-dim">
            <Network class="mt-0.5 h-3.5 w-3.5 shrink-0 text-cyan" />
            <span>
                Instance state, memory and disk usage come directly from the Incus API on {{ page.props.host?.hostname ?? 'this host' }}.
                Lifecycle actions are restricted by role and written to the audit trail.
            </span>
        </div>

        <Modal :show="createOpen" title="New Incus instance" description="Create a system container or virtual machine from a cached or remote Incus image." max-width="max-w-xl" @close="closeCreate">
            <form class="space-y-4" @submit.prevent="submitCreate">
                <div>
                    <div class="label">Instance type</div>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            class="rounded-xl border px-3 py-2.5 text-left transition"
                            :class="createForm.type === 'container' ? 'border-accent/35 bg-accent/10' : 'border-line bg-panel-2/40 hover:border-line-bright'"
                            @click="setCreateType('container')"
                        >
                            <div class="flex items-center gap-2 text-[11px] font-medium" :class="createForm.type === 'container' ? 'text-accent' : 'text-muted'">
                                <Boxes class="h-3.5 w-3.5" /> System container
                            </div>
                            <p class="mt-1 text-[11px] leading-4 text-dim">Shares the host kernel. Fast and lightweight.</p>
                        </button>
                        <button
                            type="button"
                            class="rounded-xl border px-3 py-2.5 text-left transition"
                            :class="createForm.type === 'virtual-machine' ? 'border-cyan/35 bg-cyan/10' : 'border-line bg-panel-2/40 hover:border-line-bright'"
                            @click="setCreateType('virtual-machine')"
                        >
                            <div class="flex items-center gap-2 text-[11px] font-medium" :class="createForm.type === 'virtual-machine' ? 'text-cyan' : 'text-muted'">
                                <Layers class="h-3.5 w-3.5" /> Virtual machine
                            </div>
                            <p class="mt-1 text-[11px] leading-4 text-dim">Full hardware virtualisation with its own kernel.</p>
                        </button>
                    </div>
                    <p v-if="createForm.errors.type" class="mt-1 text-[11px] text-rose">{{ createForm.errors.type }}</p>
                </div>

                <div>
                    <label for="incus-name" class="label">Instance name</label>
                    <input id="incus-name" v-model="createForm.name" class="input font-mono" maxlength="63" autocomplete="off" spellcheck="false" />
                    <p class="mt-1 text-[11px] text-dim">Unique on this host. Letters, numbers, dots, dashes and underscores.</p>
                    <p v-if="createForm.errors.name" class="mt-1 text-[11px] text-rose">{{ createForm.errors.name }}</p>
                </div>

                <div>
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <div class="label mb-0">Image</div>
                        <div class="inline-flex rounded-lg border border-line bg-panel-2/50 p-0.5">
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1 text-[11px] font-medium transition"
                                :class="imageSource === 'cached' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'"
                                @click="selectImageSource('cached')"
                            >
                                Cached · {{ cachedImages.length }}
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1 text-[11px] font-medium transition"
                                :class="imageSource === 'remote' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'"
                                @click="selectImageSource('remote')"
                            >
                                Incus server · {{ remoteImagesForType.length }}
                            </button>
                        </div>
                    </div>

                    <template v-if="imageSource === 'cached'">
                        <select v-if="cachedImages.length" id="incus-image" v-model="createForm.image" class="input">
                            <option v-for="image in cachedImages" :key="image.fingerprint" :value="image.fingerprint">
                                {{ image.label }} · {{ formatBytes(image.size) }}
                            </option>
                        </select>
                        <div v-else class="rounded-lg border border-amber/25 bg-amber/10 px-3 py-2.5 text-[11px] leading-5 text-amber">
                            <p>No {{ createForm.type === 'virtual-machine' ? 'virtual machine' : 'container' }} images are cached on this host.</p>
                            <button type="button" class="mt-1 font-medium text-amber underline decoration-amber/40 underline-offset-2 hover:text-amber/80" @click="selectImageSource('remote')">
                                Choose one from the Incus image server instead
                            </button>
                        </div>
                        <p v-if="selectedImage" class="mt-1.5 truncate font-mono text-[11px] text-dim" :title="selectedImage.fingerprint">
                            {{ selectedImage.fingerprint }}
                        </p>
                    </template>

                    <template v-else>
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-dim" />
                            <input
                                v-model="remoteImageSearch"
                                class="input pl-9"
                                placeholder="Search Ubuntu, Debian, Alpine, Rocky…"
                                autocomplete="off"
                                spellcheck="false"
                            />
                        </div>

                        <div v-if="remoteImagesLoading" class="mt-2 flex items-center gap-2 rounded-lg border border-line bg-panel-2/30 px-3 py-2.5 text-[11px] text-dim">
                            <span class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                            Loading the Incus image catalog…
                        </div>
                        <div v-else-if="remoteImagesError" class="mt-2 rounded-lg border border-rose/25 bg-rose/10 px-3 py-2.5 text-[11px] leading-5 text-rose">
                            {{ remoteImagesError }}
                            <button type="button" class="ml-1 font-medium underline decoration-rose/40 underline-offset-2 hover:text-rose/80" @click="loadRemoteImages">Retry</button>
                        </div>
                        <div v-else class="mt-2 max-h-56 overflow-y-auto rounded-xl border border-line bg-panel-2/30">
                            <button
                                v-for="image in filteredRemoteImages"
                                :key="image.ref"
                                type="button"
                                class="flex w-full items-center gap-3 border-b border-line/40 px-3 py-2 text-left transition last:border-b-0 hover:bg-white/[0.03]"
                                :class="createForm.image === image.ref ? 'bg-cyan/[0.07]' : ''"
                                @click="chooseRemoteImage(image)"
                            >
                                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-md border border-line bg-panel text-dim">
                                    <CloudDownload class="h-3 w-3" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[11px] font-medium" :class="createForm.image === image.ref ? 'text-cyan' : 'text-bright'">{{ image.label }}</span>
                                    <span class="mt-0.5 block truncate font-mono text-[10px] text-dim">{{ image.ref }}</span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="badge border-line bg-panel-2 text-dim">{{ image.architecture }}</span>
                                    <span class="mt-1 block font-mono text-[10px] text-dim">{{ formatBytes(image[remoteSlotKey]?.size ?? 0) }}</span>
                                </span>
                            </button>
                            <p v-if="!filteredRemoteImages.length" class="px-3 py-4 text-center text-[11px] text-dim">
                                {{ remoteImagesForType.length ? 'No images match that search.' : 'No images are available for this instance type.' }}
                            </p>
                        </div>

                        <p v-if="selectedRemoteImage" class="mt-1.5 truncate font-mono text-[11px] text-dim" :title="selectedRemoteImage.ref">
                            {{ selectedRemoteImage.ref }} · {{ selectedRemoteImage.architecture }}
                        </p>
                        <p class="mt-1.5 text-[11px] leading-4 text-dim">
                            The image is downloaded from the Incus image server when the instance is created.
                        </p>
                    </template>

                    <p v-if="createForm.errors.image" class="mt-1 text-[11px] text-rose">{{ createForm.errors.image }}</p>
                </div>

                <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-line bg-panel-2/30 px-3 py-2.5">
                    <input v-model="createForm.start" type="checkbox" class="mt-0.5 h-3.5 w-3.5 rounded border-line bg-panel-2" />
                    <span>
                        <span class="block text-[11px] font-medium text-bright">Start after creation</span>
                        <span class="mt-0.5 block text-[11px] leading-4 text-dim">Boot the instance immediately using the default profile and network.</span>
                    </span>
                </label>

                <div class="flex justify-end gap-2 border-t border-line/60 pt-4">
                    <button type="button" class="btn btn-sm" :disabled="createForm.processing" @click="closeCreate">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="createForm.processing || !createForm.name || !createForm.image">
                        <span v-if="createForm.processing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                        <Plus v-else class="h-3 w-3" />
                        Create instance
                    </button>
                </div>
            </form>
        </Modal>

        <ConfirmDialog
            :show="Boolean(pending)"
            :title="pendingTitle"
            :description="pendingDescription"
            :confirm-label="pending ? actionLabel(pending.action) : 'Confirm'"
            :danger="pending ? ['freeze', 'stop', 'force-stop', 'delete'].includes(pending.action) : false"
            :loading="working"
            @close="pending = null"
            @confirm="confirmPending"
        />

        <Modal :show="Boolean(logInstance)" :title="logInstance ? `Console log · ${logInstance.name}` : 'Console log'" :description="logInstance?.image" max-width="max-w-3xl" @close="closeLogs">
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] text-dim">Boot console output reported by Incus</p>
                    <button type="button" class="btn btn-sm" :disabled="logLoading" @click="openLogs(logInstance)">
                        <RefreshCw class="h-3 w-3" :class="{ 'animate-spin': logLoading }" /> Refresh
                    </button>
                </div>
                <pre class="max-h-[52vh] min-h-[220px] overflow-auto rounded-xl border border-line bg-[#04080c] p-4 font-mono text-[11px] leading-5 text-muted">{{ logLoading ? 'Reading instance log…' : (logText || 'No console log output.') }}</pre>
            </div>
        </Modal>

        <Modal :show="configOpen" :title="configInstance ? `Configure · ${configInstance.name}` : 'Configure instance'" description="CPU, memory, network devices, disks and host pass-through are applied through the Incus API." max-width="max-w-3xl" @close="closeConfig">
            <div v-if="configLoading" class="flex items-center gap-2 rounded-xl border border-line bg-panel-2/30 px-4 py-6 text-[11px] text-dim">
                <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
                Reading the instance configuration…
            </div>

            <div v-else-if="!configDetail" class="space-y-3">
                <div class="rounded-xl border border-rose/25 bg-rose/10 px-4 py-3 text-[11px] leading-5 text-rose">
                    {{ configError || 'The instance configuration could not be loaded.' }}
                </div>
                <button type="button" class="btn btn-sm" @click="configInstance && loadConfig(configInstance)">
                    <RefreshCw class="h-3 w-3" /> Retry
                </button>
            </div>

            <div v-else class="space-y-4">
                <div v-if="configError" class="rounded-xl border border-rose/25 bg-rose/10 px-3 py-2.5 text-[11px] leading-5 text-rose">
                    {{ configError }}
                </div>

                <div class="inline-flex rounded-lg border border-line bg-panel-2/50 p-0.5">
                    <button
                        v-for="tab in configTabs"
                        :key="tab.id"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-[11px] font-medium transition"
                        :class="configTab === tab.id ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'"
                        @click="setConfigTab(tab.id)"
                    >
                        {{ tab.label }}
                    </button>
                </div>

                <template v-if="configTab === 'general'">
                    <div>
                        <label for="config-description" class="label">Description</label>
                        <input id="config-description" v-model="configFields.description" class="input" maxlength="255" placeholder="Optional description" autocomplete="off" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label for="config-cpu" class="label">CPU limit</label>
                            <input id="config-cpu" v-model="configFields.cpu" class="input" placeholder="2 or 0-3" autocomplete="off" spellcheck="false" />
                            <p class="mt-1 text-[11px] leading-4 text-dim">vCPU count, range or pinning list.</p>
                        </div>
                        <div>
                            <label for="config-memory" class="label">Memory limit</label>
                            <input id="config-memory" v-model="configFields.memory" class="input" placeholder="2GiB or 50%" autocomplete="off" spellcheck="false" />
                            <p class="mt-1 text-[11px] leading-4 text-dim">Leave empty for no limit.</p>
                        </div>
                        <div>
                            <label for="config-processes" class="label">Process limit</label>
                            <input id="config-processes" v-model="configFields.processes" class="input" type="number" min="1" max="100000" placeholder="Unlimited" />
                            <p class="mt-1 text-[11px] leading-4 text-dim">Maximum PIDs inside the instance.</p>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex items-start gap-2.5 rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2.5">
                            <input v-model="configFields.autostart" type="checkbox" class="mt-0.5 h-3.5 w-3.5 rounded border-line bg-panel-2" />
                            <span>
                                <span class="block text-[11px] font-medium text-bright">Start at boot</span>
                                <span class="mt-0.5 block text-[11px] leading-4 text-dim">Ask Incus to start this instance when the host boots.</span>
                            </span>
                        </label>
                        <label v-if="!isVirtualMachine" class="flex items-start gap-2.5 rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2.5">
                            <input v-model="configFields.nesting" type="checkbox" class="mt-0.5 h-3.5 w-3.5 rounded border-line bg-panel-2" />
                            <span>
                                <span class="block text-[11px] font-medium text-bright">Allow nesting</span>
                                <span class="mt-0.5 block text-[11px] leading-4 text-dim">Let this container run Docker or another container runtime.</span>
                            </span>
                        </label>
                    </div>

                    <p class="text-[11px] leading-5 text-dim">
                        Instance type <span class="font-mono text-muted">{{ configInstance.type }}</span>
                        <span class="text-line-bright">·</span>
                        Profiles <span class="font-mono text-muted">{{ (configDetail.profiles ?? []).join(', ') || 'none' }}</span>
                        <span class="text-line-bright">·</span>
                        Image <span class="text-muted">{{ configDetail.image || 'unknown' }}</span>
                    </p>
                </template>

                <template v-else-if="configTab === 'network'">
                    <div class="space-y-2.5">
                        <div v-for="device in nicDevices" :key="device.name" class="rounded-xl border border-line/70 bg-panel-2/30 p-3.5">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="font-mono text-[11px] font-medium text-bright">{{ device.name }}</p>
                                        <span class="badge border-line bg-panel-2 text-dim">{{ device.local ? 'local' : 'profile' }}</span>
                                    </div>
                                    <p class="mt-1 truncate font-mono text-[11px] text-dim">
                                        {{ device.network ? `network=${device.network}` : `parent=${device.parent || '—'}` }}
                                        <span class="text-line-bright">·</span>
                                        {{ device.vlan ? `VLAN ${device.vlan}` : 'untagged' }}
                                        <span class="text-line-bright">·</span>
                                        {{ device.mtu ? `MTU ${device.mtu}` : 'auto MTU' }}
                                    </p>
                                </div>
                            </div>

                            <div v-if="device.local && nicEdits[device.name]" class="mt-3 flex flex-wrap items-end gap-2">
                                <div class="w-28">
                                    <label :for="`nic-vlan-${device.name}`" class="label">VLAN</label>
                                    <input :id="`nic-vlan-${device.name}`" v-model="nicEdits[device.name].vlan" class="input" inputmode="numeric" placeholder="Untagged" />
                                </div>
                                <div class="w-32">
                                    <label :for="`nic-mtu-${device.name}`" class="label">MTU</label>
                                    <input :id="`nic-mtu-${device.name}`" v-model="nicEdits[device.name].mtu" class="input" inputmode="numeric" placeholder="Auto" />
                                </div>
                                <button type="button" class="btn btn-sm" :disabled="nicEdits[device.name].saving || configSaving" @click="saveNic(device.name)">
                                    <span v-if="nicEdits[device.name].saving" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                    Save
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" :disabled="configSaving" @click="requestDeviceRemoval(device.name, 'network device')">
                                    <Trash2 class="h-3 w-3" /> Remove
                                </button>
                            </div>
                            <div v-else class="mt-3 flex flex-wrap items-center gap-2">
                                <p class="text-[11px] text-dim">Inherited from a profile. Copy it locally before editing VLAN or MTU.</p>
                                <button type="button" class="btn btn-sm" :disabled="configSaving" @click="overrideDevice(device.name)">
                                    Customize
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-line/70 bg-panel/50 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">Attach network device</h3>
                            <div class="inline-flex rounded-lg border border-line bg-panel-2/50 p-0.5">
                                <button type="button" class="rounded-md px-2.5 py-1 text-[11px] font-medium transition" :class="nicMode === 'bridge' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'" @click="nicMode = 'bridge'">
                                    Host bridge
                                </button>
                                <button type="button" class="rounded-md px-2.5 py-1 text-[11px] font-medium transition" :class="nicMode === 'network' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'" @click="nicMode = 'network'">
                                    Managed network
                                </button>
                            </div>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="nic-device" class="label">Device name</label>
                                <input id="nic-device" v-model="nicDraft.device" class="input font-mono" placeholder="eth1" autocomplete="off" spellcheck="false" />
                            </div>
                            <div v-if="nicMode === 'bridge'">
                                <label for="nic-parent" class="label">Host bridge</label>
                                <select id="nic-parent" v-model="nicDraft.parent" class="input">
                                    <option v-for="bridge in hostBridges" :key="bridge.name" :value="bridge.name">{{ bridge.name }}</option>
                                </select>
                                <p v-if="!hostBridges.length" class="mt-1 text-[11px] text-amber">No selectable host bridges were found.</p>
                            </div>
                            <div v-else>
                                <label for="nic-network" class="label">Managed network</label>
                                <select id="nic-network" v-model="nicDraft.network" class="input">
                                    <option value="" disabled>Choose a managed network</option>
                                    <option v-for="network in managedNetworks" :key="network.name" :value="network.name">{{ network.name }}</option>
                                </select>
                                <p v-if="!managedNetworks.length" class="mt-1 text-[11px] text-amber">No managed Incus networks exist yet.</p>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label for="nic-vlan" class="label">VLAN</label>
                                    <input id="nic-vlan" v-model="nicDraft.vlan" class="input" inputmode="numeric" placeholder="Untagged" />
                                </div>
                                <div>
                                    <label for="nic-mtu" class="label">MTU</label>
                                    <input id="nic-mtu" v-model="nicDraft.mtu" class="input" inputmode="numeric" placeholder="Auto" />
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 flex justify-end">
                            <button
                                type="button"
                                class="btn btn-primary btn-sm"
                                :disabled="configSaving || !nicDraft.device || (nicMode === 'bridge' ? !nicDraft.parent : !nicDraft.network)"
                                @click="addNic"
                            >
                                <Plus class="h-3 w-3" /> Attach network
                            </button>
                        </div>
                    </div>
                </template>

                <template v-else-if="configTab === 'disks'">
                    <div class="rounded-xl border border-line/70 bg-panel-2/30 p-3.5">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">Root disk</h3>
                                <p class="mt-1 font-mono text-[11px] text-dim">
                                    {{ rootDisk?.pool || 'default' }}
                                    <span class="text-line-bright">·</span>
                                    {{ rootDisk?.size || 'default size' }}
                                </p>
                            </div>
                            <span class="badge border-line bg-panel-2 text-dim">{{ rootDiskIsLocal ? 'local' : 'profile' }}</span>
                        </div>

                        <div v-if="!rootDiskIsLocal" class="mt-3 flex flex-wrap items-center gap-2">
                            <p class="text-[11px] text-dim">The root disk is inherited from a profile.</p>
                            <button type="button" class="btn btn-sm" :disabled="configSaving" @click="overrideDevice('root')">
                                Customize root disk
                            </button>
                        </div>
                        <div v-else class="mt-3 flex flex-wrap items-end gap-2">
                            <div class="w-44">
                                <label for="root-size" class="label">Size</label>
                                <input id="root-size" v-model="rootSize" class="input font-mono" placeholder="20GiB" autocomplete="off" spellcheck="false" />
                            </div>
                            <button type="button" class="btn btn-sm" :disabled="configSaving || !rootSize" @click="resizeRoot">
                                Resize
                            </button>
                        </div>
                        <p class="mt-2 text-[11px] leading-4 text-dim">Root disks can only grow while the instance exists.</p>
                    </div>

                    <div class="space-y-2.5">
                        <div v-for="device in diskDevices" :key="device.name" class="rounded-xl border border-line/70 bg-panel-2/30 p-3.5">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="font-mono text-[11px] font-medium text-bright">{{ device.name }}</p>
                                        <span class="badge border-line bg-panel-2 text-dim">{{ device.local ? 'local' : 'profile' }}</span>
                                    </div>
                                    <p class="mt-1 truncate font-mono text-[11px] text-dim">
                                        {{ device.path || 'no mount path' }}
                                        <span class="text-line-bright">·</span>
                                        {{ device.pool ? `${device.pool}/${device.source}` : device.source }}
                                    </p>
                                </div>
                                <span class="badge" :class="device.readonly === 'true' ? 'border-amber/25 bg-amber/10 text-amber' : 'border-line bg-panel-2 text-dim'">
                                    {{ device.readonly === 'true' ? 'read-only' : 'read-write' }}
                                </span>
                            </div>

                            <div class="mt-2.5 flex flex-wrap items-center gap-2">
                                <template v-if="device.local">
                                    <button type="button" class="btn btn-sm" :disabled="configSaving" @click="toggleReadonly(device)">
                                        Make {{ device.readonly === 'true' ? 'writable' : 'read-only' }}
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger" :disabled="configSaving" @click="requestDeviceRemoval(device.name, device.path || device.name)">
                                        <Trash2 class="h-3 w-3" /> Detach
                                    </button>
                                </template>
                                <template v-else>
                                    <p class="text-[11px] text-dim">Inherited from a profile.</p>
                                    <button type="button" class="btn btn-sm" :disabled="configSaving" @click="overrideDevice(device.name)">
                                        Customize
                                    </button>
                                </template>
                            </div>
                        </div>
                        <p v-if="!diskDevices.length" class="rounded-xl border border-dashed border-line/70 px-4 py-6 text-center text-[11px] text-dim">
                            No additional disks are attached to this instance.
                        </p>
                    </div>

                    <div class="rounded-xl border border-line/70 bg-panel/50 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">Attach disk</h3>
                            <div class="inline-flex rounded-lg border border-line bg-panel-2/50 p-0.5">
                                <button
                                    type="button"
                                    class="rounded-md px-2.5 py-1 text-[11px] font-medium transition"
                                    :class="diskDraft.source_type === 'path' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'"
                                    :disabled="isVirtualMachine"
                                    @click="diskDraft.source_type = 'path'"
                                >
                                    Host path
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md px-2.5 py-1 text-[11px] font-medium transition"
                                    :class="diskDraft.source_type === 'volume' ? 'bg-white/[0.08] text-bright' : 'text-dim hover:text-muted'"
                                    @click="diskDraft.source_type = 'volume'"
                                >
                                    Storage volume
                                </button>
                            </div>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="disk-device" class="label">Device name</label>
                                <input id="disk-device" v-model="diskDraft.device" class="input font-mono" placeholder="data" autocomplete="off" spellcheck="false" />
                            </div>

                            <template v-if="diskDraft.source_type === 'path'">
                                <div>
                                    <label for="disk-source" class="label">Host path</label>
                                    <input id="disk-source" v-model="diskDraft.source" class="input font-mono" placeholder="/storage/share" autocomplete="off" spellcheck="false" />
                                </div>
                                <div>
                                    <label for="disk-path" class="label">Mount path</label>
                                    <input id="disk-path" v-model="diskDraft.path" class="input font-mono" placeholder="/mnt/share" autocomplete="off" spellcheck="false" />
                                </div>
                            </template>

                            <template v-else>
                                <div>
                                    <label for="disk-pool" class="label">Storage pool</label>
                                    <select id="disk-pool" v-model="diskDraft.pool" class="input">
                                        <option v-for="pool in storagePools" :key="pool.name" :value="pool.name">{{ pool.name }} · {{ pool.driver }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="disk-volume" class="label">Volume</label>
                                    <select id="disk-volume" v-model="diskDraft.volume" class="input">
                                        <option value="" disabled>{{ availableVolumes.length ? 'Choose an available volume' : 'No available volumes' }}</option>
                                        <option v-for="volume in availableVolumes" :key="`${volume.pool}/${volume.name}`" :value="volume.name">
                                            {{ volume.name }} · {{ volume.content_type }}{{ volume.size ? ` · ${volume.size}` : '' }}
                                        </option>
                                    </select>
                                </div>
                                <div v-if="!isVirtualMachine">
                                    <label for="disk-volume-path" class="label">Mount path</label>
                                    <input id="disk-volume-path" v-model="diskDraft.path" class="input font-mono" placeholder="/mnt/volume" autocomplete="off" spellcheck="false" />
                                </div>
                            </template>

                            <label class="flex items-start gap-2.5 self-end rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2.5">
                                <input v-model="diskDraft.readonly" type="checkbox" class="mt-0.5 h-3.5 w-3.5 rounded border-line bg-panel-2" />
                                <span>
                                    <span class="block text-[11px] font-medium text-bright">Read-only</span>
                                    <span class="mt-0.5 block text-[11px] leading-4 text-dim">Prevent the instance from writing to this disk.</span>
                                </span>
                            </label>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <p class="text-[11px] text-dim">
                                {{ isVirtualMachine ? 'Virtual machines accept block storage volumes.' : 'Bind mounts and filesystem volumes are mounted at the chosen path.' }}
                            </p>
                            <button
                                type="button"
                                class="btn btn-primary btn-sm"
                                :disabled="configSaving || !diskDraft.device || (diskDraft.source_type === 'path' ? (!diskDraft.source || !diskDraft.path) : (!diskDraft.pool || !diskDraft.volume || (!isVirtualMachine && !diskDraft.path)))"
                                @click="addDisk"
                            >
                                <Plus class="h-3 w-3" /> Attach disk
                            </button>
                        </div>
                    </div>
                </template>

                <template v-else-if="configTab === 'devices'">
                    <div v-if="deviceInventoryLoading" class="flex items-center gap-2 rounded-xl border border-line bg-panel-2/30 px-4 py-6 text-[11px] text-dim">
                        <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
                        Scanning host PCI, USB and device nodes…
                    </div>

                    <div v-else-if="deviceInventoryError" class="space-y-3">
                        <div class="rounded-xl border border-rose/25 bg-rose/10 px-4 py-3 text-[11px] leading-5 text-rose">
                            {{ deviceInventoryError }}
                        </div>
                        <button type="button" class="btn btn-sm" @click="loadDeviceInventory(true)">
                            <RefreshCw class="h-3 w-3" /> Retry
                        </button>
                    </div>

                    <template v-else>
                        <div v-if="deviceInventory && !deviceInventory.available" class="rounded-xl border border-rose/25 bg-rose/10 px-4 py-3 text-[11px] leading-5 text-rose">
                            {{ deviceInventory.error || 'The host device inventory is unavailable.' }}
                        </div>

                        <div v-if="deviceInventory?.warnings?.length" class="rounded-xl border border-amber/25 bg-amber/10 px-4 py-3 text-[11px] leading-5 text-amber">
                            <div class="flex items-start gap-2">
                                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <div class="space-y-1">
                                    <p v-for="warning in deviceInventory.warnings" :key="warning">{{ warning }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-2.5">
                            <div v-for="device in passthroughDevices" :key="device.name" class="rounded-xl border border-line/70 bg-panel-2/30 p-3.5">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="font-mono text-[11px] font-medium text-bright">{{ device.name }}</p>
                                            <span class="badge border-cyan/25 bg-cyan/10 text-cyan">{{ deviceTypeLabels[device.type] ?? device.type }}</span>
                                            <span class="badge border-line bg-panel-2 text-dim">{{ device.local ? 'local' : 'profile' }}</span>
                                        </div>
                                        <p class="mt-1 truncate font-mono text-[11px] text-dim">{{ passthroughSummary(device) }}</p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button v-if="device.local" type="button" class="btn btn-sm btn-danger" :disabled="configSaving" @click="requestDeviceRemoval(device.name, deviceTypeLabels[device.type] ?? device.type)">
                                            <Trash2 class="h-3 w-3" /> Detach
                                        </button>
                                        <button v-else type="button" class="btn btn-sm" :disabled="configSaving" @click="overrideDevice(device.name)">
                                            Customize
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p v-if="!passthroughDevices.length" class="rounded-xl border border-dashed border-line/70 px-4 py-6 text-center text-[11px] text-dim">
                                No host devices are passed through to this instance.
                            </p>
                        </div>

                        <div class="rounded-xl border border-line/70 bg-panel/50 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">Pass through a host device</h3>
                                    <p class="mt-0.5 text-[11px] text-dim">Devices are attached immediately through the Incus API and recorded in the audit trail.</p>
                                </div>
                                <button type="button" class="btn btn-sm" :disabled="deviceInventoryLoading" @click="loadDeviceInventory(true)">
                                    <RefreshCw class="h-3 w-3" :class="{ 'animate-spin': deviceInventoryLoading }" /> Rescan host
                                </button>
                            </div>

                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                <button
                                    v-for="option in availableDeviceTypes"
                                    :key="option.id"
                                    type="button"
                                    class="rounded-lg border px-3 py-2 text-left transition"
                                    :class="deviceDraft.type === option.id ? 'border-cyan/35 bg-cyan/10' : 'border-line bg-panel-2/40 hover:border-line-bright'"
                                    @click="setDeviceType(option.id)"
                                >
                                    <span class="block text-[11px] font-medium text-bright">{{ option.label }}</span>
                                    <span class="mt-0.5 block text-[11px] text-dim">{{ option.hint }}</span>
                                </button>
                            </div>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="device-name" class="label">Device name</label>
                                    <input id="device-name" v-model="deviceDraft.device" class="input font-mono" placeholder="gpu0" autocomplete="off" spellcheck="false" />
                                </div>
                                <div>
                                    <label for="device-choice" class="label">Host device</label>
                                    <select id="device-choice" v-model="deviceDraft.selection" class="input">
                                        <option value="" disabled>{{ deviceChoices.length ? 'Choose a device' : 'No matching host devices' }}</option>
                                        <option v-for="choice in deviceChoices" :key="choice.value" :value="choice.value">
                                            {{ choice.label }} — {{ choice.description }}
                                        </option>
                                    </select>
                                </div>
                                <div v-if="deviceDraft.type === 'unix-char' || deviceDraft.type === 'unix-block'">
                                    <label for="device-path" class="label">Path inside instance</label>
                                    <input id="device-path" v-model="deviceDraft.path" class="input font-mono" placeholder="Defaults to the host path" autocomplete="off" spellcheck="false" />
                                    <p class="mt-1 text-[11px] leading-4 text-dim">Optional. The device keeps its host major/minor numbers.</p>
                                </div>
                            </div>

                            <div v-if="selectedDeviceChoice" class="mt-3 rounded-lg border border-line/70 bg-panel-2/30 px-3 py-2.5 text-[11px] leading-5 text-dim">
                                <p class="font-mono text-muted">{{ selectedDeviceChoice.description }}</p>
                                <p v-if="selectedDeviceChoice.used_by?.length" class="mt-1 text-amber">
                                    Already used by
                                    <span v-for="(use, index) in selectedDeviceChoice.used_by" :key="`${use.runtime}-${use.owner}-${use.device}`">
                                        {{ index ? ', ' : '' }}{{ use.runtime }} {{ use.owner }}{{ use.device ? ` (${use.device})` : '' }}
                                    </span>
                                </p>
                            </div>

                            <div v-if="selectedDeviceWarnings.length" class="mt-3 rounded-lg border border-amber/25 bg-amber/10 px-3 py-2.5 text-[11px] leading-5 text-amber">
                                <p v-for="warning in selectedDeviceWarnings" :key="warning">{{ warning }}</p>
                            </div>

                            <div v-if="deviceLocked" class="mt-3 rounded-lg border border-amber/25 bg-amber/10 px-3 py-2.5 text-[11px] leading-5 text-amber">
                                Stop this virtual machine before attaching a GPU or PCI device. Incus cannot hotplug these device types into a running VM.
                            </div>

                            <div v-if="!deviceChoices.length && deviceInventory?.available" class="mt-3 rounded-lg border border-line px-3 py-2.5 text-[11px] text-dim">
                                No compatible host devices were found for this device type.
                            </div>

                            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-[11px] text-dim">
                                    Passing a device removes it from host use while the instance runs. Ensure the host driver and IOMMU group can be released safely.
                                </p>
                                <button
                                    type="button"
                                    class="btn btn-primary btn-sm"
                                    :disabled="configSaving || deviceLocked || !deviceDraft.device || !deviceDraft.selection"
                                    @click="attachDevice"
                                >
                                    <span v-if="configSaving" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                    <Plus v-else class="h-3 w-3" /> Attach device
                                </button>
                            </div>
                        </div>
                    </template>
                </template>
            </div>

            <template #footer>
                <button type="button" class="btn btn-sm" :disabled="configSaving" @click="closeConfig()">
                    Close
                </button>
                <button v-if="configDetail && configTab === 'general'" type="button" class="btn btn-primary btn-sm" :disabled="configSaving" @click="saveConfig">
                    <span v-if="configSaving" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                    Save configuration
                </button>
            </template>
        </Modal>

        <ConfirmDialog
            :show="Boolean(deviceRemoval)"
            :title="deviceRemoval ? `Remove ${deviceRemoval.name}?` : 'Remove device?'"
            :description="deviceRemoval ? `${deviceRemoval.name} (${deviceRemoval.label}) will be detached from ${configInstance?.name}. The underlying host path or volume is not deleted.` : ''"
            confirm-label="Remove"
            :danger="true"
            :loading="deviceWorking"
            @close="deviceRemoval = null"
            @confirm="confirmDeviceRemoval"
        />

        <ConsoleTerminal
            v-if="consoleTarget"
            :target="consoleTarget.target"
            :kind="consoleTarget.kind"
            :mode="consoleTarget.mode"
            :name="consoleTarget.name"
            :label="consoleTarget.label"
            @close="closeConsole"
        />
    </div>
</template>
