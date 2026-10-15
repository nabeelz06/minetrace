/**
 * brand.js — identitas MINETRACE dan penyimpanan preferensi (tema/bahasa).
 *
 * Nama tampilan utama berasal dari config('app.name') yang dibagikan lewat
 * props Inertia (`app.name`); konstanta di sini hanya cadangan sebelum props tersedia.
 */
export const BRAND = {
    name:      'MINETRACE',
    taglineId: 'Sistem Ketertelusuran Mineral',
    taglineEn: 'Mineral Traceability System',
}

export const THEME_KEY = 'minetrace-theme'
export const LANG_KEY  = 'minetrace-lang'

// Kunci lama (pra-rebrand). Dibaca sekali lalu dimigrasikan agar preferensi pengguna tidak hilang.
const LEGACY_KEYS = {
    [THEME_KEY]: 'minetra-theme',
    [LANG_KEY]:  'minetra-lang',
}

/** Baca preferensi; bila kunci baru kosong, ambil dari kunci lama, tulis ke kunci baru, hapus yang lama. */
export function readPref(key) {
    try {
        let value = localStorage.getItem(key)
        const legacy = LEGACY_KEYS[key]
        if (value === null && legacy) {
            const old = localStorage.getItem(legacy)
            if (old !== null) {
                value = old
                localStorage.setItem(key, old)
            }
        }
        if (legacy) localStorage.removeItem(legacy)
        return value
    } catch {
        return null
    }
}

export function writePref(key, value) {
    try {
        localStorage.setItem(key, value)
    } catch {
        // penyimpanan diblokir (mode privat) — preferensi hanya berlaku di sesi ini
    }
}
