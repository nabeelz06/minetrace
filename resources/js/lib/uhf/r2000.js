/**
 * Protokol reader UHF desktop R2000 "0xA0" (PiSwords / UCM60x, jembatan USB-serial QinHeng 1a86:fe0c).
 * Port dari https://github.com/frebergguru/uhf_reader (src/uhf.c, docs/PROTOCOL.md) — murni, tanpa I/O.
 *
 * Bingkai (dua arah):  A0  Len  Adr  Cmd  Data…  Checksum
 *   Len      = panjang Data + 3  (seluruh bingkai = Len + 2 byte)
 *   Checksum = komplemen-dua dari jumlah 8-bit semua byte sebelumnya
 * Contoh terverifikasi: GetFirmwareVersion → A0 03 00 72 EB ; balasan A0 06 00 72 02 03 01 E2 (UCM601 v2.3)
 *
 * Inventory real-time (0x89) MENGALIR TERUS sampai StopInventory (0x8C) — selalu kirim stop setelah membaca.
 */

export const HEAD = 0xa0
export const CMD = {
    GET_FW_VERSION: 0x72,
    SET_BEEP: 0x7a,
    REALTIME_INV: 0x89,
    STOP_INV: 0x8c,
}
export const RC = { SUCCESS: 0x10, NO_TAG: 0x36 }

export function checksum(bytes) {
    let sum = 0
    for (const b of bytes) sum = (sum + b) & 0xff
    return (~sum + 1) & 0xff
}

/** @returns {Uint8Array} */
export function buildFrame(cmd, data = [], adr = 0x00) {
    const out = [HEAD, data.length + 3, adr & 0xff, cmd & 0xff, ...data]
    out.push(checksum(out))
    return Uint8Array.from(out)
}

export const frames = {
    version: (adr = 0) => buildFrame(CMD.GET_FW_VERSION, [], adr),
    realtimeInventory: (adr = 0, repeat = 1) => buildFrame(CMD.REALTIME_INV, [repeat], adr),
    stopInventory: (adr = 0) => buildFrame(CMD.STOP_INV, [], adr),
    beep: (on, adr = 0) => buildFrame(CMD.SET_BEEP, [on ? 1 : 0], adr),
}

/**
 * Parser aliran byte → bingkai. feed() mengembalikan daftar bingkai lengkap.
 * Sinkron ulang otomatis: byte pertama bingkai harus 0xA0; panjang tak masuk akal dibuang.
 */
export class FrameParser {
    constructor() { this.buf = [] }

    /** @param {Uint8Array|number[]} chunk @returns {{adr:number, cmd:number, data:Uint8Array, sumOk:boolean}[]} */
    feed(chunk) {
        const out = []
        for (const byte of chunk) {
            if (this.buf.length === 0 && byte !== HEAD) continue
            this.buf.push(byte)
            if (this.buf.length === 2) {
                const need = byte + 2
                if (need < 5 || need > 256) { this.buf = []; continue }
            }
            if (this.buf.length >= 2 && this.buf.length === this.buf[1] + 2) {
                const f = this.buf
                this.buf = []
                out.push({
                    adr: f[2],
                    cmd: f[3],
                    data: Uint8Array.from(f.slice(4, f.length - 1)),
                    sumOk: checksum(f.slice(0, f.length - 1)) === f[f.length - 1],
                })
            }
        }
        return out
    }
}

export function toHex(bytes) {
    return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('').toUpperCase()
}

// Tabel kalibrasi RSSI dari aplikasi vendor (uhf.c: para_B / para_C).
const PARA_B = [
    [43, 43, 45, 49, 43, 43, 45, 49], [43, 43, 45, 49, 43, 43, 45, 49], [43, 43, 45, 49, 43, 43, 45, 49],
    [53, 53, 48, 43, 49, 45, 43, 43], [47, 47, 47, 47, 46, 43, 43, 43],
]
const PARA_C = [
    [43, 43, 45, 49, 43, 43, 45, 49], [43, 43, 45, 49, 43, 43, 45, 49], [43, 43, 45, 49, 43, 43, 45, 49],
    [-283, -283, -283, -283, -283, -283, -283, -283], [-303, -283, -253, -238, -304, -313, -280, -266],
]

/** RSSI mentah 32-bit → dBm terkalibrasi (-90..0). */
export function rssiDbm(raw, epcLen) {
    raw >>>= 0
    const rssiMode = ((raw >>> 24) & 0xe0) >>> 5
    const hwMode = ((raw >>> 24) & 0x1e) >>> 1
    const mag = raw & 0x01ffffff
    if (hwMode > 4) return -90
    const q = Math.floor(mag / Math.max(epcLen, 1))
    if (q === 0) return -90
    const val = Math.trunc(PARA_B[hwMode][rssiMode] * Math.log10(q) + PARA_C[hwMode][rssiMode])
    return Math.max(-90, Math.min(0, val))
}

/**
 * Urai satu bingkai inventory real-time (0x89).
 *   tag   : ant(1) PC(2) EPC(epcLen) RSSI(4) Freq(3), epcLen = (PC[0] >> 3) * 2
 *   status: satu byte (0x10 sukses / 0x36 tidak ada tag)
 * @returns {{type:'tag', epc:string, antenna:number, rssi:number|null, freq:number|null} | {type:'status', code:number} | null}
 */
export function decodeRealtime(frame) {
    if (frame.cmd !== CMD.REALTIME_INV) return null
    const d = frame.data
    if (d.length <= 2) return { type: 'status', code: d.length ? d[0] : 0 }
    const epcLen = (d[1] >> 3) * 2
    if (epcLen <= 0 || 3 + epcLen > d.length) return null
    const epc = toHex(d.slice(3, 3 + epcLen))
    let i = 3 + epcLen
    let rssi = null
    let freq = null
    if (i + 4 <= d.length) {
        const raw = ((d[i] << 24) | (d[i + 1] << 16) | (d[i + 2] << 8) | d[i + 3]) >>> 0
        rssi = rssiDbm(raw, epcLen)
        i += 4
    }
    if (i + 3 <= d.length) freq = (d[i] << 16) | (d[i + 1] << 8) | d[i + 2]
    return { type: 'tag', epc, antenna: d[0], rssi, freq }
}

/** Balasan versi firmware (0x72) → "UCM601 v2.3". */
export function decodeVersion(frame) {
    if (frame.cmd !== CMD.GET_FW_VERSION || frame.data.length < 3) return null
    const [major, minor, model] = frame.data
    return `UCM60${model} v${major}.${minor}`
}
