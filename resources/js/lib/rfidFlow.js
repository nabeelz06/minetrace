/**
 * Aturan serah-terima RFID yang dipakai bersama beberapa komponen UI (Kesiapan alur, Scan Masuk/Keluar).
 * Fungsi murni: mudah diuji tanpa DOM/Vue (lihat __tests__/rfidFlow.test.js). Aturan yang rumit — arah yang benar-benar
 * bisa dihasilkan reader (arah tetap + pemetaan antena) — dihitung SERVER dan datang sebagai `reader.directions`;
 * di sini hanya disusun menjadi status yang bisa dibaca operator.
 */

export const DIRECTIONS = ['in', 'out']

/** Arah yang bisa dihasilkan sebuah reader; cadangan untuk respons lama tanpa `directions` (arah tetap, "any" = keduanya). */
export function readerDirections(reader) {
    if (Array.isArray(reader?.directions)) return reader.directions
    return reader?.direction === 'any' ? DIRECTIONS : [reader?.direction].filter(Boolean)
}

/** Apakah mode otorisasi memindahkan batch pada arah ini (scan_full mencakup keduanya). */
export function modeMoves(mode, direction) {
    return mode === 'scan_full'
        || (direction === 'in' && mode === 'scan_to_receive')
        || (direction === 'out' && mode === 'scan_to_dispatch')
}

/** Reader aktif yang terpasang di satu tahap. */
export function activeReadersAt(readers, stage) {
    return (readers ?? []).filter(r => r.is_active && r.stage === stage)
}

/**
 * Status satu jalur (tahap × arah), berurutan dari yang paling mendasar:
 *   none    → tidak ada reader aktif yang bisa membaca arah ini
 *   audit   → terbaca, tetapi mode tidak memindahkan batch (hanya dicatat)
 *   offline → mode memindahkan batch, namun tidak ada reader online
 *   ready   → ada reader online dan mode memindahkan batch
 *
 * @param {object[]} readersHere reader aktif di tahap itu (activeReadersAt)
 * @returns {{direction: string, readers: object[], status: 'none'|'audit'|'offline'|'ready'}}
 */
export function laneStatus(readersHere, mode, direction) {
    const readers = (readersHere ?? []).filter(r => readerDirections(r).includes(direction))
    const status = !readers.length ? 'none'
        : !modeMoves(mode, direction) ? 'audit'
        : readers.some(r => r.state === 'online') ? 'ready'
        : 'offline'

    return { direction, readers, status }
}
