export function timeAgo(value) {
    if (!value) return '—';

    const date = new Date(value);
    const seconds = Math.floor((Date.now() - date.getTime()) / 1000);

    if (seconds < 45) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;

    return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' }).format(date);
}

export function formatDateTime(value) {
    if (!value) return '—';

    return new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

export function formatTime(value) {
    if (!value) return '—';

    return new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    }).format(new Date(value));
}

export function formatClock(value = new Date()) {
    return new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
        timeZone: 'UTC',
    }).format(new Date(value));
}

export function formatDate(value) {
    if (!value) return '—';

    return new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

export function formatDuration(seconds) {
    if (seconds === null || seconds === undefined) return '—';

    const total = Math.max(0, Number(seconds));

    if (total < 60) return `${Math.round(total)}s`;

    const minutes = Math.floor(total / 60);
    const remaining = Math.round(total % 60);

    if (minutes < 60) return remaining ? `${minutes}m ${remaining}s` : `${minutes}m`;

    const hours = Math.floor(minutes / 60);
    return `${hours}h ${minutes % 60}m`;
}

export function formatUptime(seconds) {
    if (!seconds) return '—';

    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${minutes}m`;

    return `${minutes}m`;
}

export function formatMemory(mb) {
    if (mb === null || mb === undefined) return '—';

    return mb >= 1024 ? `${(mb / 1024).toFixed(mb % 1024 === 0 ? 0 : 1)} GB` : `${mb} MB`;
}

export function formatNumber(value) {
    return new Intl.NumberFormat('en-US').format(value);
}

export function shortSha(hash) {
    return hash ? hash.slice(0, 7) : '—';
}

export function percentTone(value) {
    if (value >= 85) return 'rose';
    if (value >= 65) return 'amber';
    return 'accent';
}

export function initials(name) {
    return (name || '?')
        .split(' ')
        .filter(Boolean)
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

export function titleCase(value) {
    return (value || '')
        .replace(/[._-]/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}
