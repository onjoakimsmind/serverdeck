export function formatBytes(value, digits = 1) {
    const bytes = Number(value ?? 0);

    if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';

    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    const power = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);

    return `${(bytes / 1024 ** power).toFixed(power === 0 ? 0 : digits)} ${units[power]}`;
}

export function formatRate(bytesPerSecond) {
    return `${formatBytes(bytesPerSecond, 1)}/s`;
}

export function formatCount(value) {
    return new Intl.NumberFormat('en-US').format(Number(value ?? 0));
}

export function healthTone(status) {
    return {
        ok: 'accent',
        info: 'cyan',
        warning: 'amber',
        critical: 'rose',
    }[status] ?? 'muted';
}

export function healthText(status) {
    return {
        ok: 'Healthy',
        info: 'Info',
        warning: 'Warning',
        critical: 'Critical',
    }[status] ?? 'Unknown';
}

export function percentTone(value) {
    if (value >= 90) return 'rose';
    if (value >= 75) return 'amber';
    return 'accent';
}

export function shortContainer(name) {
    return (name ?? '')
        .replace(/\.(\d+)\.([a-z0-9]+)$/i, '')
        .replace(/^dokploy$/i, 'dokploy');
}
