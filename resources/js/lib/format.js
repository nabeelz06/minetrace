/** format.js — format waktu/angka untuk UI. Waktu server UTC ditampilkan dalam WIB (Asia/Jakarta). */

const TZ = 'Asia/Jakarta'

export function formatTime(iso, lang = 'id') {
    if (!iso) return '—'
    return new Intl.DateTimeFormat(lang === 'en' ? 'en-GB' : 'id-ID', { timeZone: TZ, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(new Date(iso))
}

export function formatDateTime(iso, lang = 'id') {
    if (!iso) return '—'
    return new Intl.DateTimeFormat(lang === 'en' ? 'en-GB' : 'id-ID', {
        timeZone: TZ, day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false,
    }).format(new Date(iso))
}

/** "3 menit lalu" / "3 minutes ago" */
export function formatRelative(iso, lang = 'id') {
    if (!iso) return '—'
    const diffSec = Math.round((new Date(iso).getTime() - Date.now()) / 1000)
    const rtf = new Intl.RelativeTimeFormat(lang === 'en' ? 'en' : 'id', { numeric: 'auto' })
    const abs = Math.abs(diffSec)
    if (abs < 60) return rtf.format(diffSec, 'second')
    if (abs < 3600) return rtf.format(Math.round(diffSec / 60), 'minute')
    if (abs < 86400) return rtf.format(Math.round(diffSec / 3600), 'hour')
    return rtf.format(Math.round(diffSec / 86400), 'day')
}

/** Berat dengan satuan yang sesuai (kg / ton) dan pemisah locale. */
export function formatWeightKg(kg, lang = 'id') {
    if (kg === null || kg === undefined || Number.isNaN(Number(kg))) return '—'
    const n = Number(kg)
    const nf = new Intl.NumberFormat(lang === 'en' ? 'en-US' : 'id-ID', { maximumFractionDigits: 2 })
    return n >= 1000 ? `${nf.format(n / 1000)} ton` : `${nf.format(n)} kg`
}

/** UID dikelompokkan per 2 karakter agar mudah dibaca/dicocokkan: A1B2C3D4 → A1 B2 C3 D4 */
export function groupUid(uid) {
    return String(uid ?? '').replace(/(.{2})/g, '$1 ').trim()
}

/** UID dikelompokkan per 4 karakter dengan tanda hubung: E2800000... → E280-0000-... (format "Tag terpasang"). */
export function formatUidGrouped(uid) {
    return String(uid ?? '').replace(/(.{4})/g, '$1-').replace(/-$/, '')
}

/** 8 karakter terakhir (paling unik) dengan prefiks "…": …DEE5-9CCC. Sisanya dibuang, bukan disamarkan. */
export function formatUidShort(uid) {
    const u = String(uid ?? '')

    return u.length <= 8 ? formatUidGrouped(u) : `…${formatUidGrouped(u.slice(-8))}`
}

export function newEventId() {
    return globalThis.crypto?.randomUUID?.() ?? `evt-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}
