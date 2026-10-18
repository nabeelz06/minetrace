/**
 * identity.js — UID tag kanonik (padanan JS dari App\Services\Hardware\Uhf\UhfTagIdentity).
 *
 * UID = HURUF BESAR heksadesimal dari byte EPC saja (tanpa PC, CRC, TID), tanpa spasi/titik dua/strip/titik,
 * panjang genap, 4..50 karakter. Vektor uji bersama PHP: tests/Fixtures/uhf/identity.json.
 * Pembaca mana pun yang menghasilkan string berbeda untuk tag fisik yang sama membuat tag "asing" di gate.
 */
export const UID_MIN_HEX = 4
export const UID_MAX_HEX = 50

/** @param {string} input @returns {string|null} null bila bukan heksadesimal sah */
export function canonicalUid(input) {
    const uid = String(input ?? '').trim().replace(/[ :\-.]/g, '').toUpperCase()
    if (uid === '' || uid.length % 2 !== 0 || !/^[0-9A-F]+$/.test(uid)) return null
    if (uid.length < UID_MIN_HEX || uid.length > UID_MAX_HEX) return null
    return uid
}
