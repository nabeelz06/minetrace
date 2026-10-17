/**
 * useUhfDesktopReader — reader UHF desktop R2000 (USB) di Receiving Area, dibaca LANGSUNG oleh browser
 * lewat Web Serial API (Chrome/Edge desktop, halaman HTTPS atau localhost). Tidak butuh aplikasi/driver
 * tambahan di PC selain driver USB-serial bawaan (CH34x / CDC-ACM).
 *
 * Link: 9600 8N1 (default unit), RTS WAJIB aktif — reader diam total tanpa RTS (lihat uhf_reader/docs).
 * Membaca tag: inventory real-time selama jendela pendek → kumpulkan EPC unik (hitungan + RSSI terkuat)
 * → kirim Stop berulang sampai aliran berhenti (inventory reader mengalir terus tanpa Stop).
 */
import { onBeforeUnmount, ref, shallowRef } from 'vue'
import { FrameParser, decodeRealtime, decodeVersion, frames } from '@/lib/uhf/r2000.js'

const QINHENG_VENDOR = 0x1a86
const sleep = ms => new Promise(r => setTimeout(r, ms))

export function useUhfDesktopReader({ baud = 9600, address = 0 } = {}) {
    const supported = typeof navigator !== 'undefined' && 'serial' in navigator
    const state = ref('idle')          // idle | connecting | connected | reading | error
    const error = ref('')
    const firmware = ref('')
    const port = shallowRef(null)

    let reader = null
    let listeners = new Set()
    const parser = new FrameParser()

    function onFrame(fn) { listeners.add(fn); return () => listeners.delete(fn) }

    async function write(bytes) {
        const w = port.value.writable.getWriter()
        try { await w.write(bytes) } finally { w.releaseLock() }
    }

    async function readLoop() {
        while (port.value?.readable) {
            reader = port.value.readable.getReader()
            try {
                while (true) {
                    const { value, done } = await reader.read()
                    if (done) break
                    for (const f of parser.feed(value)) if (f.sumOk) listeners.forEach(fn => fn(f))
                }
            } catch (e) {
                if (state.value !== 'idle') { error.value = e?.message || String(e); state.value = 'error' }
                break
            } finally {
                reader?.releaseLock()
                reader = null
            }
            if (state.value === 'idle') break
        }
    }

    async function open(p) {
        state.value = 'connecting'
        error.value = ''
        try {
            await p.open({ baudRate: baud, dataBits: 8, stopBits: 1, parity: 'none', flowControl: 'none' })
            await p.setSignals({ requestToSend: true, dataTerminalReady: true })
            port.value = p
            readLoop()
            await calm()
            firmware.value = await queryVersion()
            state.value = 'connected'
            return true
        } catch (e) {
            error.value = e?.message || String(e)
            state.value = 'error'
            try { await p.close() } catch { /* sudah tertutup */ }
            port.value = null
            return false
        }
    }

    /** Pilih port (dialog browser). anyPort=false menyaring jembatan QinHeng (vendor 1a86). */
    async function connect({ anyPort = false } = {}) {
        if (!supported) return false
        try {
            const p = await navigator.serial.requestPort(anyPort ? {} : { filters: [{ usbVendorId: QINHENG_VENDOR }] })
            return await open(p)
        } catch (e) {
            if (e?.name !== 'NotFoundError') { error.value = e?.message || String(e); state.value = 'error' }
            return false   // NotFoundError = pengguna menutup dialog
        }
    }

    /** Sambung otomatis ke port yang sudah pernah diizinkan (tanpa dialog). */
    async function autoConnect() {
        if (!supported || port.value) return false
        const ports = await navigator.serial.getPorts()
        return ports.length ? open(ports[0]) : false
    }

    async function queryVersion() {
        let version = ''
        const off = onFrame(f => { version = decodeVersion(f) || version })
        await write(frames.version(address))
        for (let i = 0; i < 10 && !version; i++) await sleep(100)
        off()
        return version
    }

    /** Hentikan inventory yang mungkin masih mengalir; ulangi sampai senyap. */
    async function calm() {
        for (let i = 0; i < 3; i++) {
            await write(frames.stopInventory(address))
            await sleep(120)
        }
        await sleep(250)
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
        const off = onFrame(f => {
            const t = decodeRealtime(f)
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
            await write(frames.realtimeInventory(address, 1))
            const start = Date.now()
            // Selesai lebih cepat bila satu tag sudah terbaca stabil (≥3 kali, ≥500 ms).
            while (Date.now() - start < windowMs) {
                await sleep(100)
                if (Date.now() - start >= 500 && seen.size === 1 && [...seen.values()][0].count >= 3) break
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
        port.value = null
        firmware.value = ''
        try { await reader?.cancel() } catch { /* sudah dibatalkan */ }
        try { await p?.close() } catch { /* sudah tertutup */ }
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

    return { supported, state, error, firmware, connect, autoConnect, readTags, disconnect }
}
