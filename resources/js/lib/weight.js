/**
 * weight.js — pengolah angka berat dari kolom isian operator (popup verifikasi berat).
 *
 * Operator di lapangan mengetik "1250,5" (koma desimal), "1.250,5" (format Indonesia) atau "1250.5" (keypad angka).
 * parseWeight menerima semuanya dan mengembalikan Number, atau null bila bukan angka yang jelas.
 */

export function parseWeight(input) {
    if (input === null || input === undefined) return null
    let s = String(input).trim().replace(/\s+/g, '')
    if (s === '') return null

    const dots = (s.match(/\./g) || []).length
    const commas = (s.match(/,/g) || []).length

    if (dots && commas) {
        // Pemisah yang muncul paling akhir adalah desimal; yang lain pemisah ribuan.
        s = s.lastIndexOf(',') > s.lastIndexOf('.') ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '')
    } else if (commas === 1 && !dots) {
        s = s.replace(',', '.')                 // "12,5" → 12.5
    } else if (dots > 1 && !commas) {
        s = s.replace(/\./g, '')                // "1.250.000" → 1250000
    } else if (commas > 1 && !dots) {
        s = s.replace(/,/g, '')                 // "1,250,000" → 1250000
    }

    if (!/^\d+(\.\d+)?$/.test(s)) return null
    const n = Number(s)

    return Number.isFinite(n) ? n : null
}

/** Selisih berat baru terhadap berat tercatat: { diff (kg, + = lebih berat), pct (%) }. */
export function weightDiff(current, next) {
    const cur = Number(current)
    const nxt = Number(next)
    if (!Number.isFinite(cur) || !Number.isFinite(nxt)) return { diff: 0, pct: 0 }
    const diff = Math.round((nxt - cur) * 1000) / 1000

    return { diff, pct: cur > 0 ? Math.round(diff / cur * 10000) / 100 : 0 }
}

/** Nada visual selisih: same (sama) | small | large (perlu dicek ulang). Ambang tampilan saja; server yang menandai alert. */
export function diffTone(diff, pct, largePct = 10) {
    if (Math.abs(diff) < 0.0005) return 'same'

    return Math.abs(pct) >= largePct ? 'large' : 'small'
}
