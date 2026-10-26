export async function request(url, { method = 'GET', data = null, headers = {}, ...rest } = {}) {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(data ? { 'Content-Type': 'application/json' } : {}),
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            ...headers,
        },
        ...(data ? { body: JSON.stringify(data) } : {}),
        ...rest,
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(payload.message || `Request failed (${response.status})`);
        error.status = response.status;
        error.payload = payload;

        throw error;
    }

    return payload;
}

export const api = {
    get: (url) => request(url),
    post: (url, data) => request(url, { method: 'POST', data }),
    put: (url, data) => request(url, { method: 'PUT', data }),
    delete: (url) => request(url, { method: 'DELETE' }),
};
