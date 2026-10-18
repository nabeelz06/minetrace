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
 *
 * Dua protokol, dipilih otomatis saat menyambung:
 *   - chafon (Electron HW-VX6336, 57600): reader DIAM sampai dipoll → host mengirim POLL tiap 300 ms; balasan
 *     berisi daftar EPC di medan antena (ref `live`, reaktif). Diprioritaskan karena paling cepat dicoba (±0,5 dtk).
 *   - r2000 (UCM60x/R2000): alur di atas.
 */
import { onBeforeUnmount, ref, shallowRef } from 'vue'
import { FrameParser, decodeRealtime, decodeVersion, frames } from '@/lib/uhf/r2000.js'
import { CHAFON_BAUD, ChafonParser, POLL, decodeInventory } from '@/lib/uhf/chafon.js'

// QinHeng CH34x, Silicon Labs CP210x, FTDI, Prolific — jembatan USB-serial yang dipakai reader R2000.
const USB_SERIAL_VENDORS = [0x1a86, 0x10c4, 0x0403, 0x067b]
const POLL_MS = 300
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
    const protocol = ref('')           // '' | 'chafon' | 'r2000'
    const live = ref([])               // chafon: EPC yang sedang berada di depan antena (diperbarui tiap poll)
    const port = shallowRef(null)

    let reader = null
    let loop = null
    let adr = address
    let standard = false               // R2000 standar: 0x89 satu putaran, perlu dikirim ulang
    let listeners = new Set()
    let parser = new FrameParser()
    let pollTimer = null
    let offLive = null
    let polling = false

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

    async function openAt(p, rate, proto = 'r2000') {
        await p.open({ baudRate: rate, dataBits: 8, stopBits: 1, parity: 'none', flowControl: 'none' })
        await p.setSignals({ requestToSend: true, dataTerminalReady: true })
        parser = proto === 'chafon' ? new ChafonParser() : new FrameParser()
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

    // ─── Chafon (HW-VX6336) ───────────────────────────────────────────────

    /** Buka di 57600 dan poll sekali: balasan ber-CRC valid = protokol Chafon. */
    async function probeChafon(p) {
        await openAt(p, CHAFON_BAUD, 'chafon')
        let ok = false
        const off = onFrame(f => { if (decodeInventory(f)) ok = true })
        try {
            for (let i = 0; i < 3 && !ok; i++) {
                await write(POLL)
                for (let t = 0; t < 250 && !ok; t += 50) await sleep(50)
            }
        } finally {
            off()
        }
        return ok
    }

    /** Balasan poll = kumpulan tag SAAT INI di medan antena; balasan "tidak ada tag" mengosongkan daftar. */
    function trackLive(f) {
        const r = decodeInventory(f)
        if (!r) return
        const next = r.epcs
        const cur = live.value
        if (next.length !== cur.length || next.some((e, i) => e !== cur[i])) live.value = next
    }

    function startPolling() {
        stopPolling()
        offLive = onFrame(trackLive)
        pollTimer = setInterval(async () => {
            if (polling || !port.value) return
            polling = true
            try { await write(POLL) } catch (e) {
                if (port.value && ['connected', 'reading'].includes(state.value)) { error.value = e?.message || String(e); state.value = 'error' }
            } finally { polling = false }
        }, POLL_MS)
    }

    function stopPolling() {
        if (pollTimer) clearInterval(pollTimer)
        pollTimer = null
        offLive?.()
        offLive = null
        live.value = []
    }

    /** Tag di depan antena selama jendela pendek → unik, urut paling sering terbaca. Reader tidak melaporkan RSSI. */
    async function readChafon(windowMs) {
        const counts = new Map()
        const off = onFrame(f => {
            const r = decodeInventory(f)
            r?.epcs.forEach(e => counts.set(e, (counts.get(e) ?? 0) + 1))
        })
        try {
            const start = Date.now()
            while (Date.now() - start < windowMs) {
                await sleep(100)
                // Cukup bila satu tag sudah terbaca di ≥2 putaran poll.
                if (counts.size === 1 && [...counts.values()][0] >= 2) break
            }
        } finally {
            off()
        }
        return [...counts].map(([epc, count]) => ({ epc, count, rssi: null, antenna: 1 })).sort((a, b) => b.count - a.count)
    }

    async function open(p) {
        state.value = 'connecting'
        error.value = ''
        const addresses = unique([address, ...FALLBACK_ADDRESSES])
        try {
            if (await probeChafon(p)) {
                protocol.value = 'chafon'
                link.value = { baud: CHAFON_BAUD, address: 0xff }
                firmware.value = `Chafon/RM09 · ${CHAFON_BAUD} bps`
                state.value = 'connected'
                startPolling()
                return true
            }
            await closePort(p)
            protocol.value = 'r2000'
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
            stopPolling()
            protocol.value = ''
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
        if (protocol.value === 'chafon') {
            try {
                return await readChafon(Math.min(windowMs, 1200))
            } finally {
                state.value = port.value ? 'connected' : 'idle'
            }
        }
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
        stopPolling()
        protocol.value = ''
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

    return { supported, state, error, firmware, link, protocol, live, connect, autoConnect, readTags, disconnect }
}
