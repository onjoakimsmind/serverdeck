export const serverStatus = {
    online: {
        label: 'Online',
        dot: 'bg-accent',
        text: 'text-accent',
        badge: 'border-accent/25 bg-accent/10 text-accent',
    },
    degraded: {
        label: 'Degraded',
        dot: 'bg-amber',
        text: 'text-amber',
        badge: 'border-amber/25 bg-amber/10 text-amber',
    },
    offline: {
        label: 'Offline',
        dot: 'bg-rose',
        text: 'text-rose',
        badge: 'border-rose/25 bg-rose/10 text-rose',
    },
    maintenance: {
        label: 'Maintenance',
        dot: 'bg-cyan',
        text: 'text-cyan',
        badge: 'border-cyan/25 bg-cyan/10 text-cyan',
    },
};

export const serviceStatus = {
    running: {
        label: 'Running',
        dot: 'bg-accent',
        text: 'text-accent',
        badge: 'border-accent/25 bg-accent/10 text-accent',
    },
    stopped: {
        label: 'Stopped',
        dot: 'bg-dim',
        text: 'text-muted',
        badge: 'border-line bg-panel-2 text-muted',
    },
    failed: {
        label: 'Failed',
        dot: 'bg-rose',
        text: 'text-rose',
        badge: 'border-rose/25 bg-rose/10 text-rose',
    },
};

export const deploymentStatus = {
    queued: {
        label: 'Queued',
        dot: 'bg-dim',
        text: 'text-muted',
        badge: 'border-line bg-panel-2 text-muted',
    },
    running: {
        label: 'Running',
        dot: 'bg-cyan',
        text: 'text-cyan',
        badge: 'border-cyan/25 bg-cyan/10 text-cyan',
    },
    succeeded: {
        label: 'Succeeded',
        dot: 'bg-accent',
        text: 'text-accent',
        badge: 'border-accent/25 bg-accent/10 text-accent',
    },
    failed: {
        label: 'Failed',
        dot: 'bg-rose',
        text: 'text-rose',
        badge: 'border-rose/25 bg-rose/10 text-rose',
    },
    cancelled: {
        label: 'Cancelled',
        dot: 'bg-dim',
        text: 'text-muted',
        badge: 'border-line bg-panel-2 text-muted',
    },
};

export const severity = {
    critical: {
        label: 'Critical',
        dot: 'bg-rose',
        text: 'text-rose',
        badge: 'border-rose/25 bg-rose/10 text-rose',
        border: 'border-l-rose',
    },
    warning: {
        label: 'Warning',
        dot: 'bg-amber',
        text: 'text-amber',
        badge: 'border-amber/25 bg-amber/10 text-amber',
        border: 'border-l-amber',
    },
    info: {
        label: 'Info',
        dot: 'bg-cyan',
        text: 'text-cyan',
        badge: 'border-cyan/25 bg-cyan/10 text-cyan',
        border: 'border-l-cyan',
    },
};

export const logLevel = {
    debug: { label: 'Debug', text: 'text-dim', dot: 'bg-dim', badge: 'border-line bg-panel-2 text-muted' },
    info: { label: 'Info', text: 'text-cyan', dot: 'bg-cyan', badge: 'border-cyan/20 bg-cyan/10 text-cyan' },
    warning: { label: 'Warning', text: 'text-amber', dot: 'bg-amber', badge: 'border-amber/25 bg-amber/10 text-amber' },
    error: { label: 'Error', text: 'text-rose', dot: 'bg-rose', badge: 'border-rose/25 bg-rose/10 text-rose' },
};

export const roleStyles = {
    admin: { label: 'Administrator', badge: 'border-accent/25 bg-accent/10 text-accent' },
    operator: { label: 'Operator', badge: 'border-cyan/25 bg-cyan/10 text-cyan' },
    viewer: { label: 'Viewer', badge: 'border-line bg-panel-2 text-muted' },
};

export function metaFor(group, key) {
    const map = {
        server: serverStatus,
        service: serviceStatus,
        deployment: deploymentStatus,
        severity,
        log: logLevel,
        role: roleStyles,
    }[group];

    return map?.[key] ?? {
        label: key ?? 'Unknown',
        dot: 'bg-dim',
        text: 'text-muted',
        badge: 'border-line bg-panel-2 text-muted',
        border: 'border-l-line',
    };
}
