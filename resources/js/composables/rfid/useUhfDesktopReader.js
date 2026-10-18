/**
 * useUhfDesktopReader — reader UHF desktop R2000 (USB) di Receiving Area, dibaca LANGSUNG oleh browser
 * lewat Web Serial API (Chrome/Edge desktop, halaman HTTPS atau localhost). Tidak butuh aplikasi/driver
 * tambahan di PC selain driver USB-serial bawaan (CH34x / CP210x / FTDI / CDC-ACM).
 *
 * Deteksi link: coba baud (pilihan → 9600 → 115200 → 57600 → 38400) × alamat (pilihan → 0x00 → 0xFF → 0x01)
 * dengan GetFirmwareVersion; kombinasi pertama yang menjawab dipakai, alamat diambil dari balasan.
 * UCM60x (uhf_reader) = 9600 / 0x00; R2000 standar Impinj = 115200 / 0x01 (0xFF = siaran).
 * RTS WAJIB aktif — UCM60x diam total tanpa RTS (lihat uhf_reader/docs).
 * Membaca tag: inventory real-time selama jendela pendek → kumpulkan EPC unik (hitungan + RSSI terkuat)
 * → kirim Stop berulang sampai aliran berhenti (inventory UCM60x mengalir terus tanpa Stop; R2000 standar
 * berhenti tiap putaran sehingga inventory dikirim ulang selama jendela).
 */
import { onBeforeUnmount, ref, shallowRef } from 'vue'
import { FrameParser, decodeRealtime, decodeVersion, frames } from '@/lib/uhf/r2000.js'

// QinHeng CH34x, Silicon Labs CP210x, FTDI, Prolific — jembatan USB-serial yang dipakai reader R2000.
const USB_SERIAL_VENDORS = [0x1a86, 0x10c4, 0x0403, 0x067b]
const FALLBACK_BAUDS = [9600, 115200, 57600, 38400]
const FALLBACK_ADDRESSES = [0x00, 0xff, 0x01]
const sleep = ms => new Promise(r => setTimeout(r, ms))
const unique = list => [...new Set(list)]

export function useUhfDesktopReader({ baud = 9600, address = 0 } = {}) {
    const supported = typeof navigator !== 'undefined' && 'serial' in navigator
    const state = ref('idle')          // idle | connecting | connected | reading | error
    const error = ref('')
    const firmware = ref('')
    const link = ref(null)             // { baud, address } yang terdeteksi
    const port = shallowRef(null)

    let reader = null
    let loop = null
    let adr = address
    let standard = false               // R2000 standar: 0x89 satu putaran, perlu dikirim ulang
    let listeners = new Set()
    let parser = new FrameParser()

    function onFrame(fn) { listeners.add(fn); return () => listeners.delete(fn) }

    async function write(bytes) {
        const w = port.value.writable.getWriter()
        try { await w.write(bytes) } finally { w.releaseLock() }
    }

    async function readLoop(p) {
        while (p.readable && port.value === p) {
            reader = p.readable.getReader()
            try {
                while (true) {
                    const { value, done } = await reader.read()
                    if (done) break
                    for (const f of parser.feed(value)) listeners.forEach(fn => fn(f))
                }
            } catch (e) {
                if (port.value === p && ['connected', 'reading'].includes(state.value)) {
                    error.value = e?.message || String(e)
                    state.value = 'error'
                }
                break
            } finally {
                reader?.releaseLock()
                reader = null
            }
        }
    }

    async function openAt(p, rate) {
        await p.open({ baudRate: rate, dataBits: 8, stopBits: 1, parity: 'none', flowControl: 'none' })
        await p.setSignals({ requestToSend: true, dataTerminalReady: true })
        parser = new FrameParser()
        port.value = p
        loop = readLoop(p)
    }

    async function closePort(p) {
        if (port.value === p) port.value = null
        try { await reader?.cancel() } catch { /* sudah dibatalkan */ }
        try { await loop } catch { /* loop sudah berakhir */ }
        loop = null
        try { await p.close() } catch { /* sudah tertutup */ }
    }

    /** Kirim GetFirmwareVersion; balasan dari alamat mana pun diterima. */
    async function queryVersion(target, timeoutMs = 400) {
        let hit = null
        const off = onFrame(f => {
            const version = decodeVersion(f)
            if (version) hit = { version, address: f.adr }
        })
        try {
            await write(frames.version(target))
            for (let t = 0; t < timeoutMs && !hit; t += 50) await sleep(50)
        } finally {
            off()
        }
        return hit
    }

    /** Hentikan inventory yang mungkin masih mengalir; ulangi sampai senyap. */
    async function calm(targets = [adr]) {
        for (let i = 0; i < 3; i++) {
            for (const target of targets) await write(frames.stopInventory(target))
            await sleep(120)
        }
        await sleep(250)
    }

    async function open(p) {
        state.value = 'connecting'
        error.value = ''
        const addresses = unique([address, ...FALLBACK_ADDRESSES])
        try {
            for (const rate of unique([baud, ...FALLBACK_BAUDS])) {
                await openAt(p, rate)
                await calm(addresses)
                for (const target of addresses) {
                    const hit = await queryVersion(target)
                    if (!hit) continue
                    adr = hit.address
                    standard = hit.version.startsWith('R2000')
                    link.value = { baud: rate, address: adr }
                    firmware.value = `${hit.version} · ${rate} bps`
                    state.value = 'connected'
                    return true
                }
                await closePort(p)
            }
            // Tidak ada jawaban versi: buka di baud pilihan — sebagian firmware tidak menjawab 0x72
            // tetapi tetap melayani inventory. UI menampilkan "firmware tidak terbaca".
            await openAt(p, baud)
            adr = address
            standard = false
            link.value = { baud, address }
            firmware.value = ''
            state.value = 'connected'
            return true
        } catch (e) {
            error.value = e?.message || String(e)
            state.value = 'error'
            await closePort(p)
            return false
        }
    }

    /** Pilih port (dialog browser). anyPort=false menyaring jembatan USB-serial umum. */
    async function connect({ anyPort = false } = {}) {
        if (!supported) return false
        try {
            const filters = USB_SERIAL_VENDORS.map(usbVendorId => ({ usbVendorId }))
            const p = await navigator.serial.requestPort(anyPort ? {} : { filters })
            return await open(p)
        } catch (e) {
            if (e?.name !== 'NotFoundError') { error.value = e?.message || String(e); state.value = 'error' }
            return false   // NotFoundError = pengguna menutup dialog
        }
    }

    /** Sambung otomatis ke port yang sudah pernah diizinkan (tanpa dialog); utamakan jembatan USB-serial. */
    async function autoConnect() {
        if (!supported || port.value) return false
        const ports = await navigator.serial.getPorts()
        const known = ports.find(p => USB_SERIAL_VENDORS.includes(p.getInfo?.().usbVendorId))
        return ports.length ? open(known ?? ports[0]) : false
    }

    /**
     * Baca tag di depan antena.
     * @returns {Promise<{epc:string, count:number, rssi:number|null, antenna:number}[]>} urut: terbanyak terbaca, RSSI terkuat
     */
    async function readTags({ windowMs = 1500 } = {}) {
        if (!port.value || state.value === 'reading') return []
        state.value = 'reading'
        error.value = ''
        const seen = new Map()
        let again = false
        const off = onFrame(f => {
            const t = decodeRealtime(f)
            if (t?.type === 'round_end') { standard = true; again = true }
            if (t?.type === 'status' && standard) again = true
            if (t?.type !== 'tag') return
            const prev = seen.get(t.epc)
            seen.set(t.epc, {
                epc: t.epc,
                antenna: t.antenna,
                count: (prev?.count ?? 0) + 1,
                rssi: prev?.rssi == null ? t.rssi : Math.max(prev.rssi, t.rssi ?? -90),
            })
        })
        try {
            await write(frames.realtimeInventory(adr, 1))
            const start = Date.now()
            // Selesai lebih cepat bila satu tag sudah terbaca stabil (≥3 kali, ≥500 ms).
            while (Date.now() - start < windowMs) {
                await sleep(100)
                if (Date.now() - start >= 500 && seen.size === 1 && [...seen.values()][0].count >= 3) break
                if (again) { again = false; await write(frames.realtimeInventory(adr, 1)) }
            }
        } catch (e) {
            error.value = e?.message || String(e)
        } finally {
            off()
            try { await calm() } catch { /* port terputus */ }
            state.value = port.value ? 'connected' : 'idle'
        }
        return [...seen.values()].sort((a, b) => b.count - a.count || (b.rssi ?? -90) - (a.rssi ?? -90))
    }

    async function disconnect() {
        const p = port.value
        state.value = 'idle'
        firmware.value = ''
        link.value = null
        if (p) await closePort(p)
    }

    function onUnplug(e) {
        if (port.value && e.target === port.value) { disconnect(); error.value = 'unplugged'; state.value = 'error' }
    }
    if (supported) navigator.serial.addEventListener('disconnect', onUnplug)

    onBeforeUnmount(() => {
        if (supported) navigator.serial.removeEventListener('disconnect', onUnplug)
        listeners = new Set()
        disconnect()
    })

    return { supported, state, error, firmware, link, connect, autoConnect, readTags, disconnect }
}
