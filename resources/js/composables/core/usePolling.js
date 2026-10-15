/**
 * usePolling — polling yang sadar visibilitas tab, dengan backoff saat gagal.
 *  - Jeda otomatis saat tab tersembunyi; lanjut + refresh langsung saat tab kembali terlihat.
 *  - Galat beruntun → interval dilipatgandakan (maks 8×) agar tidak membanjiri server.
 */
import { onBeforeUnmount, ref, watch } from 'vue'
import { useDocumentVisibility } from '@vueuse/core'

export function usePolling(fn, { interval = 5000, immediate = true, maxBackoff = 8 } = {}) {
    const visibility = useDocumentVisibility()
    const running = ref(false)
    const failures = ref(0)
    let timer = null
    let stopped = true

    const delay = () => interval * Math.min(2 ** failures.value, maxBackoff)

    async function tick() {
        if (stopped) return
        if (visibility.value === 'visible') {
            try {
                const ok = await fn()
                failures.value = ok === false ? failures.value + 1 : 0
            } catch {
                failures.value += 1
            }
        }
        if (!stopped) timer = setTimeout(tick, delay())
    }

    function start() {
        if (!stopped) return
        stopped = false
        running.value = true
        timer = setTimeout(tick, immediate ? 0 : delay())
    }

    function stop() {
        stopped = true
        running.value = false
        clearTimeout(timer)
    }

    watch(visibility, v => { if (v === 'visible' && !stopped) { clearTimeout(timer); timer = setTimeout(tick, 0) } })
    onBeforeUnmount(stop)

    return { start, stop, running, failures, refresh: () => { clearTimeout(timer); return tick() } }
}
