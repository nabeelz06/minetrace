/**
 * useTheme — tema Sistem / Terang / Gelap.
 * Urutan prioritas: pilihan lokal (minetrace-theme) → preferensi akun (user.dark_mode) → sistem.
 * 'system' mengikuti prefers-color-scheme secara langsung (termasuk saat OS berganti).
 */
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { THEME_KEY, readPref, writePref } from '@/lib/brand'

const mode = ref('system')   // 'system' | 'light' | 'dark'
let mql = null
let started = false

const systemDark = () => !!window.matchMedia?.('(prefers-color-scheme: dark)').matches

function apply() {
    const dark = mode.value === 'dark' || (mode.value === 'system' && systemDark())
    document.documentElement.classList.toggle('dark', dark)
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light'
}

function init(serverPref) {
    if (started) return
    started = true
    const local = readPref(THEME_KEY)
    mode.value = ['light', 'dark', 'system'].includes(local)
        ? local
        : (serverPref === true ? 'dark' : serverPref === false ? 'light' : 'system')
    mql = window.matchMedia?.('(prefers-color-scheme: dark)')
    mql?.addEventListener?.('change', () => { if (mode.value === 'system') apply() })
    apply()
}

export function useTheme() {
    const page = usePage()
    init(page.props?.auth?.user?.dark_mode)

    function setMode(next) {
        if (!['system', 'light', 'dark'].includes(next)) return
        mode.value = next
        writePref(THEME_KEY, next)
        apply()
        // Sinkron ke akun hanya untuk pilihan eksplisit (kolom dark_mode boolean)
        if (page.props?.auth?.user && next !== 'system') {
            router.patch('/user/dark-mode', { dark_mode: next === 'dark' }, { preserveState: true, preserveScroll: true, replace: true })
        }
    }

    return {
        mode,
        isDark: computed(() => document.documentElement.classList.contains('dark')),
        setMode,
        cycle: () => setMode(mode.value === 'system' ? 'light' : mode.value === 'light' ? 'dark' : 'system'),
    }
}
