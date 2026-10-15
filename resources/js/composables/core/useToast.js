/**
 * useToast — notifikasi singleton (satu antrean untuk seluruh aplikasi).
 * Ditampilkan oleh <ToastHost> (aria-live). Jenis: success | error | warning | info.
 * Kompatibel dengan inject('toast')(message, type) yang dipakai halaman lama.
 */
import { ref } from 'vue'

const toasts = ref([])
let seq = 0

export function pushToast(message, type = 'info', { duration } = {}) {
    const id = ++seq
    toasts.value = [...toasts.value.slice(-3), { id, message, type }]
    // Galat lebih lama di layar agar sempat dibaca (WCAG 2.2.1)
    const ttl = duration ?? (type === 'error' || type === 'warning' ? 8000 : 4500)
    if (ttl > 0) setTimeout(() => dismissToast(id), ttl)
    return id
}

export function dismissToast(id) {
    toasts.value = toasts.value.filter(t => t.id !== id)
}

export function useToast() {
    return {
        toasts,
        push: pushToast,
        dismiss: dismissToast,
        success: (m, o) => pushToast(m, 'success', o),
        error:   (m, o) => pushToast(m, 'error', o),
        warning: (m, o) => pushToast(m, 'warning', o),
        info:    (m, o) => pushToast(m, 'info', o),
    }
}
