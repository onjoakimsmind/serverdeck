export function isMacPlatform() {
    if (typeof navigator === 'undefined') {
        return false;
    }

    const platform = navigator.userAgentData?.platform || navigator.platform || '';

    if (/mac/i.test(platform)) {
        return true;
    }

    return /macintosh|mac os x|iphone|ipad|ipod/i.test(navigator.userAgent || '');
}
