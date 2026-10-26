import { reactive } from 'vue';

export const toasts = reactive([]);

let nextId = 1;

export function dismissToast(id) {
    const index = toasts.findIndex((toast) => toast.id === id);

    if (index !== -1) {
        toasts.splice(index, 1);
    }
}

export function pushToast(message, type = 'success', title = null) {
    const id = nextId++;

    toasts.push({
        id,
        message,
        type,
        title: title ?? (type === 'error' ? 'Something went wrong' : type === 'info' ? 'Heads up' : 'Done'),
    });

    window.setTimeout(() => dismissToast(id), type === 'error' ? 7000 : 4500);
}

export function useToasts() {
    return { toasts, pushToast, dismissToast };
}
