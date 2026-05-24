/**
 * useI18n — Composable utama untuk internasionalisasi MINETRA.
 *
 * Arsitektur:
 * - Reactive shared ref (satu instance di seluruh app)
 * - Persist ke localStorage
 * - Sync dengan server preference via Inertia
 * - Support nested keys: t('batch.create'), t('auth.login')
 * - Support interpolasi: t('batch.created', { code: 'TMH-001' })
 * - Fallback ke bahasa id jika key tidak ditemukan
 */
import { ref, computed, watch } from 'vue'
import { router, usePage }      from '@inertiajs/vue3'
import id from '@/lang/id.js'
import en from '@/lang/en.js'

const STORAGE_KEY = 'minetra-lang'

// ─── Shared reactive ref (singleton per tab) ───────────────────────────────
const lang = ref(localStorage.getItem(STORAGE_KEY) ?? 'id')

// ─── Translation maps ─────────────────────────────────────────────────────
const translations = { id, en }

/**
 * Resolve nested key path dari objek flat.
 * Contoh: 'batch.create' → translations.id.batch.create
 */
function resolvePath(obj, path) {
    if (!path || !obj) return undefined
    return path.split('.').reduce((acc, key) => acc?.[key], obj)
}

/**
 * Interpolasi string dengan variabel.
 * Contoh: t('batch.created', { code: 'TMH-001' })
 *         → 'Batch TMH-001 berhasil dibuat di Lab Kimia.'
 */
function interpolate(str, vars) {
    if (!vars || typeof str !== 'string') return str
    return str.replace(/\{(\w+)\}/g, (_, key) =>
        vars[key] !== undefined ? vars[key] : `{${key}}`
    )
}

/**
 * Fungsi translasi utama.
 * @param {string} key    - Dot-notation key: 'module.key'
 * @param {object} vars   - Variabel untuk interpolasi
 * @param {string} fallback - Fallback jika key tidak ditemukan
 */
function t(key, vars, fallback) {
    const currentLang = lang.value
    const currentMap  = translations[currentLang] ?? translations.id
    const fallbackMap = translations.id

    // Coba bahasa aktif
    let value = resolvePath(currentMap, key)

    // Fallback ke bahasa Indonesia jika tidak ditemukan
    if (value === undefined) {
        value = resolvePath(fallbackMap, key)
    }

    // Fallback ke key itu sendiri (terlihat jika ada missing translation)
    if (value === undefined) {
        if (import.meta.env.DEV) {
            console.warn(`[useI18n] Missing translation key: "${key}" (lang: ${currentLang})`)
        }
        return fallback ?? key
    }

    return interpolate(value, vars)
}

/**
 * Toggle bahasa id ↔ en.
 * Sync ke server dan localStorage.
 */
function toggleLang() {
    const next = lang.value === 'id' ? 'en' : 'id'
    setLang(next)
}

/**
 * Set bahasa secara eksplisit.
 */
function setLang(newLang) {
    if (!['id', 'en'].includes(newLang)) return
    lang.value = newLang
    localStorage.setItem(STORAGE_KEY, newLang)
    document.documentElement.setAttribute('lang', newLang)

    // Sync ke server (non-blocking)
    router.patch('/user/language', { language: newLang }, {
        preserveState:  true,
        preserveScroll: true,
        replace:        true,
        onError: () => {
            // Tetap pakai local preference jika server error
        }
    })
}

/**
 * Format angka berat ke satuan yang sesuai (kg / ton / kT).
 */
function formatWeight(kg, precision = 2) {
    if (kg === null || kg === undefined || isNaN(kg)) return '—'
    const n = parseFloat(kg)
    if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(precision)} kT`
    if (n >= 1_000)     return `${(n / 1_000).toFixed(precision)} ton`
    return `${n.toLocaleString(lang.value === 'id' ? 'id-ID' : 'en-US', { maximumFractionDigits: precision })} kg`
}

/**
 * Format tanggal sesuai locale.
 */
function formatDate(dateStr) {
    if (!dateStr) return '—'
    try {
        return new Date(dateStr).toLocaleDateString(
            lang.value === 'id' ? 'id-ID' : 'en-US',
            { day: '2-digit', month: 'short', year: 'numeric' }
        )
    } catch { return dateStr }
}

/**
 * Format angka persentase.
 */
function formatPct(val, precision = 2) {
    if (val === null || val === undefined || isNaN(val)) return '—'
    return `${parseFloat(val).toFixed(precision)}%`
}

/**
 * Format currency USD.
 */
function formatUSD(val) {
    if (!val) return '—'
    return `USD ${parseFloat(val).toLocaleString('en-US', { maximumFractionDigits: 2 })}`
}

/**
 * Init: sync dari page props user.language (saat page load/navigate)
 */
function initFromPage() {
    try {
        const page = usePage()
        const userLang = page.props?.auth?.user?.language
        if (userLang && ['id', 'en'].includes(userLang)) {
            if (lang.value !== userLang) {
                lang.value = userLang
                localStorage.setItem(STORAGE_KEY, userLang)
                document.documentElement.setAttribute('lang', userLang)
            }
        }
    } catch {}
}

// ─── Export composable ────────────────────────────────────────────────────
export function useI18n() {
    return {
        // Core
        lang,
        t,
        toggleLang,
        setLang,
        initFromPage,
        // Formatters
        formatWeight,
        formatDate,
        formatPct,
        formatUSD,
        // Computed helpers
        isID: computed(() => lang.value === 'id'),
        isEN: computed(() => lang.value === 'en'),
    }
}