/**
 * chafon.js — protokol Chafon/RM09 untuk reader UHF desktop Electron HW-VX6336 (USB-serial CH340, 57600 8N1).
 * Terverifikasi pada perangkat nyata (inventory EPC Gen2 polling). Padanan Python: tools/uhf/chafon-poll.py.
 *
 * Bingkai: Len | Adr | Cmd | Status | Data... | CRC16(LSB, MSB)
 *   Len = jumlah byte SETELAH Len (termasuk CRC). CRC16: poly 0x8408, awal 0xFFFF, atas Len..Data.
 * Inventory (Cmd 0x01): Data = Num, lalu Num × [EPClen, EPC...]. Reader hanya menjawab saat dipoll
 * (tidak mengirim apa pun secara pasif), jadi host harus mengirim POLL berkala (~300 ms).
 */
export const CHAFON_BAUD = 57600
export const CHAFON_CMD_INVENTORY = 0x01
const MIN_LEN = 4            // Adr + Cmd + CRC(2)

/** Perintah inventory EPC Gen2, alamat siaran 0xFF (CRC sudah dihitung). */
export const POLL = Uint8Array.of(0x04, 0xff, 0x01, 0x1b, 0xb4)

/** @param {ArrayLike<number>} bytes @returns {number} */
export function crc16(bytes) {
    let crc = 0xffff
    for (const b of bytes) {
        crc ^= b
        for (let i = 0; i < 8; i++) crc = crc & 1 ? (crc >>> 1) ^ 0x8408 : crc >>> 1
    }
    return crc & 0xffff
}

/** Bangun bingkai: Len dihitung otomatis, CRC ditambahkan. */
export function buildFrame(adr, cmd, data = []) {
    const body = [data.length + 4, adr, cmd, ...data]
    const crc = crc16(body)
    return Uint8Array.from([...body, crc & 0xff, crc >> 8])
}

/**
 * Parser aliran byte → bingkai valid. Potongan (chunk) boleh terbelah di mana pun; byte sampah di depan
 * dan bingkai ber-CRC rusak dibuang satu byte demi satu sampai sinkron kembali.
 */
export class ChafonParser {
    /** @param {number|null} expectedCmd bila diisi, byte yang bukan awal bingkai berisi Cmd ini dibuang lebih cepat (resync tanpa menunggu Len palsu terpenuhi) */
    constructor(expectedCmd = CHAFON_CMD_INVENTORY) {
        this.buf = []
        this.expectedCmd = expectedCmd
    }

    /** @param {ArrayLike<number>} chunk @returns {{adr:number, cmd:number, status:number, data:Uint8Array, raw:Uint8Array}[]} */
    feed(chunk) {
        for (const b of chunk) this.buf.push(b)
        const out = []
        while (this.buf.length) {
            const len = this.buf[0]
            if (len < MIN_LEN) { this.buf.shift(); continue }
            if (this.expectedCmd !== null && this.buf.length >= 3 && this.buf[2] !== this.expectedCmd) { this.buf.shift(); continue }
            if (this.buf.length < len + 1) break
            const raw = this.buf.slice(0, len + 1)
            const crc = raw[len - 1] | (raw[len] << 8)
            if (crc16(raw.slice(0, len - 1)) !== crc) { this.buf.shift(); continue }
            this.buf = this.buf.slice(len + 1)
            out.push({
                adr: raw[1],
                cmd: raw[2],
                status: raw.length > 5 ? raw[3] : 0,
                data: Uint8Array.from(raw.slice(4, len - 1)),
                raw: Uint8Array.from(raw),
            })
        }
        return out
    }
}

const hex = bytes => Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('').toUpperCase()

/**
 * Jawaban inventory → daftar EPC (hex huruf besar). Status "tidak ada tag" (mis. 0xFB) → [].
 * @returns {{status:number, epcs:string[]}|null} null bila bukan balasan inventory atau datanya cacat
 */
export function decodeInventory(frame) {
    if (!frame || frame.cmd !== CHAFON_CMD_INVENTORY) return null
    const d = frame.data
    if (d.length === 0) return { status: frame.status, epcs: [] }
    const epcs = []
    let i = 1
    for (let n = 0; n < d[0]; n++) {
        const size = d[i]
        if (size === undefined || size === 0 || i + 1 + size > d.length) return null
        epcs.push(hex(d.slice(i + 1, i + 1 + size)))
        i += 1 + size
    }
    return { status: frame.status, epcs }
}
