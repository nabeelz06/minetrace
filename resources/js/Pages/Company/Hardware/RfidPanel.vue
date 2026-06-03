<script setup>
/**
 * RfidPanel.vue — Panel Hardware RFID + Simulation Mode
 *
 * FITUR BARU:
 * ✅ Tab "Simulasi" — test tanpa ESP32, lintas jaringan
 *    → Generate UID acak → pilih aksi → langsung panggil MINETRA API
 *    → Tidak butuh ESP32 online, tidak butuh satu jaringan
 *
 * SOLUSI BEDA JARINGAN:
 *    Simulation Panel memanggil /api/rfid/* langsung ke MINETRA VPS
 *    tanpa melalui ESP32. Cocok untuk demo stakeholder.
 *
 * ALUR PRODUKSI (ESP32 tersedia & satu jaringan):
 *    Browser → ESP32 IP /set-mode → ESP32 baca kartu → MINETRA VPS
 *
 * ALUR SIMULASI (lintas jaringan / tanpa ESP32):
 *    Browser → MINETRA VPS /api/rfid/* langsung
 */
import { ref, computed, onUnmounted } from 'vue'
import { Head, Link, router }          from '@inertiajs/vue3'
import AppLayout                        from '@/Layouts/AppLayout.vue'
import EmptyState                       from '@/components/shared/EmptyState.vue'
import { useI18n }                      from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    esp32_ip:         { type: String, default: '' },
    untagged_batches: { type: Array,  default: () => [] },
    tagged_batches:   { type: Array,  default: () => [] },
    pending_batch:    { type: Object, default: null },
    recent_activity:  { type: Array,  default: () => [] },
    stats:            Object,
    user_role:        String,
    current_time:     String,
})

// ─── Role capabilities ─────────────────────────────────────────────────────
const canManage = computed(() => ['company_admin','operator_lab_station','operator_wet'].includes(props.user_role))
const canAdmin  = computed(() => props.user_role === 'company_admin')

// ─── Active tab ─────────────────────────────────────────────────────────────
const activeTab = ref('simulate') // default ke simulate karena paling sering dipakai

// ═══════════════════════════════════════════════════════════════════════════
// ESP32 DEVICE SETUP
// ═══════════════════════════════════════════════════════════════════════════
const esp32IpInput = ref(props.esp32_ip ?? '')
const espIpSaving  = ref(false)
const espStatus    = ref('unknown')

async function saveEspIp() {
    if (!esp32IpInput.value.trim()) { showToast('Masukkan IP ESP32 terlebih dahulu.', 'error'); return }
    espIpSaving.value = true
    try {
        const res = await apiFetch('/api/v1/rfid/device', 'POST', { esp32_ip: esp32IpInput.value.trim() })
        if (res.success) {
            showToast(`IP ESP32 disimpan: ${res.data.esp32_ip}`, 'success')
            startEspPolling()
        } else {
            showToast(res.message ?? 'Gagal menyimpan IP.', 'error')
        }
    } catch (e) {
        showToast('Gagal menyimpan: ' + e.message, 'error')
    } finally {
        espIpSaving.value = false
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// ESP32 STATUS POLLING
// ═══════════════════════════════════════════════════════════════════════════
const espState   = ref(null)
const lcdRow0    = ref('Sistem RFID    ')
const lcdRow1    = ref('Menunggu mode..')
const activeMode = ref('idle')

let espPollTimer = null
let lcdAnimTimer = null
let lcdAnimIdx   = 0

const LCD_DOTS = ['', '.', '..', '...']

function startEspPolling() {
    if (espPollTimer) clearInterval(espPollTimer)
    pollEsp32()
    espPollTimer = setInterval(pollEsp32, 800)
}

async function pollEsp32() {
    const ip = esp32IpInput.value.trim()
    if (!ip) return
    const baseUrl = ip.startsWith('http') ? ip : `http://${ip}`
    try {
        const res = await fetch(`${baseUrl}/status`, { signal: AbortSignal.timeout(2500) })
        if (!res.ok) throw new Error()
        const d = await res.json()
        espStatus.value  = 'online'
        espState.value   = d
        activeMode.value = d.mode ?? 'idle'
        updateLcd(d)
    } catch {
        espStatus.value = 'offline'
    }
}

function updateLcd(d) {
    if (lcdAnimTimer) { clearInterval(lcdAnimTimer); lcdAnimTimer = null }
    const pad = (s, n = 16) => (s + ' '.repeat(n)).substring(0, n)

    if (d.mode === 'idle' || !d.mode) {
        lcdRow0.value = pad('Sistem RFID')
        lcdRow1.value = pad('Menunggu mode..')
        return
    }

    const modeLabel = { register: '[ REGISTER ]', login: '[ LOGIN    ]', logout: '[ LOGOUT   ]' }
    const label     = modeLabel[d.mode] || d.mode

    if (d.status === 'waiting') {
        lcdRow0.value = pad(label)
        lcdAnimIdx    = 0
        const animate = () => { lcdRow1.value = pad('Tempel Tag' + LCD_DOTS[lcdAnimIdx++ % 4]) }
        animate()
        lcdAnimTimer = setInterval(animate, 600)
    } else if (d.status === 'scanning') {
        lcdRow0.value = pad(label); lcdRow1.value = pad('Memproses...')
    } else if (d.status === 'success') {
        const ok = { register: 'Tag Terdaftar!', login: 'Masuk Tercatat', logout: 'Keluar Tercatat' }
        lcdRow0.value = pad(ok[d.mode] || 'Berhasil!')
        lcdRow1.value = pad(d.last_name || d.last_message || '')
    } else if (d.status === 'failed') {
        const fail = { register: 'Daftar Gagal!', login: 'Masuk Gagal!', logout: 'Keluar Gagal!' }
        lcdRow0.value = pad(fail[d.mode] || 'Gagal!')
        lcdRow1.value = pad(d.last_message || 'Coba lagi')
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// PENDING BATCH
// ═══════════════════════════════════════════════════════════════════════════
const selectedBatchId    = ref('')
const isPending          = ref(!!props.pending_batch)
const pendingData        = ref(props.pending_batch)
const pendingCountdown   = ref(props.pending_batch ? 90 : 0)
const isSavingPending    = ref(false)
const isCancelling       = ref(false)
let   pendingTimer       = null

if (props.pending_batch) startPendingCountdown(90, props.pending_batch)

function startPendingCountdown(seconds, data) {
    pendingData.value      = data
    isPending.value        = true
    pendingCountdown.value = seconds
    if (pendingTimer) clearInterval(pendingTimer)
    pendingTimer = setInterval(() => {
        pendingCountdown.value--
        if (pendingCountdown.value <= 0) {
            clearInterval(pendingTimer)
            isPending.value   = false
            pendingData.value = null
            router.reload({ only: ['pending_batch','untagged_batches','stats'] })
        }
    }, 1000)
}

const countdownPct = computed(() => Math.round((pendingCountdown.value / 90) * 100))

async function cancelPendingSession() {
    isCancelling.value = true
    clearInterval(pendingTimer)
    try {
        await apiFetch('/api/v1/company/rfid/pending-batch', 'DELETE')
        isPending.value   = false
        pendingData.value = null
        pendingCountdown.value = 0
        showToast('Sesi dibatalkan.', 'info')
        await sendModeToEsp32('idle')
        router.reload({ only: ['pending_batch','untagged_batches','stats'] })
    } finally {
        isCancelling.value = false
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// MODE CONTROL — Kirim perintah ke ESP32 (butuh satu jaringan)
// ═══════════════════════════════════════════════════════════════════════════
const sendingMode = ref(null)

async function sendModeToEsp32(mode) {
    const ip = esp32IpInput.value.trim()
    if (!ip) { showToast('IP ESP32 belum disimpan.', 'error'); return false }
    sendingMode.value = mode
    const baseUrl     = ip.startsWith('http') ? ip : `http://${ip}`
    try {
        const res = await fetch(`${baseUrl}/set-mode`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ mode }),
            signal:  AbortSignal.timeout(4000),
        })
        if (res.ok) {
            const modeLabel = { register:'REGISTER', login:'LOGIN', logout:'LOGOUT', idle:'IDLE' }
            showToast(`ESP32 → Mode ${modeLabel[mode] ?? mode} aktif`, 'success')
            return true
        } else {
            showToast('ESP32 menolak perintah.', 'error')
            return false
        }
    } catch {
        showToast('ESP32 tidak merespons. Gunakan mode Simulasi jika beda jaringan.', 'error')
        return false
    } finally {
        sendingMode.value = null
    }
}

async function startRegisterSession() {
    if (!selectedBatchId.value) { showToast('Pilih batch terlebih dahulu.', 'error'); return }
    if (!esp32IpInput.value)    { showToast('Simpan IP ESP32 terlebih dahulu.', 'error'); return }
    isSavingPending.value = true
    try {
        const res = await apiFetch('/api/v1/company/rfid/pending-batch', 'POST', { batch_id: parseInt(selectedBatchId.value) })
        if (!res.success) { showToast(res.message ?? 'Gagal memulai sesi.', 'error'); return }
        startPendingCountdown(90, res.data)
        showToast(`Sesi aktif untuk ${res.data.batch_code}. Tempel tag sekarang!`, 'success')
        await sendModeToEsp32('register')
    } catch (e) {
        showToast('Error: ' + e.message, 'error')
    } finally {
        isSavingPending.value = false
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// SIMULATION PANEL — Tidak butuh ESP32, lintas jaringan
// ═══════════════════════════════════════════════════════════════════════════
const simUid          = ref('')          // UID yang akan di-simulasikan
const simBatchId      = ref('')          // Batch untuk Register
const simResult       = ref(null)        // Hasil dari MINETRA API
const isSimulating    = ref(false)
const simAction       = ref(null)        // aksi terakhir yang disimulasikan

/** Generate UID acak 4-byte (format hex, seperti RFID fisik) */
function generateUid() {
    const bytes = Array.from({ length: 4 }, () =>
        Math.floor(Math.random() * 256).toString(16).padStart(2, '0')
    )
    simUid.value = bytes.join('').toUpperCase()
}

/**
 * Simulasi REGISTER: set pending batch → panggil /api/rfid/register
 * Menggunakan UID yang diinput manual — hasilnya sama persis dengan scan fisik.
 */
async function simulateRegister() {
    if (!simBatchId.value) { showToast('Pilih batch untuk di-tag.', 'error'); return }
    if (!simUid.value)     { showToast('Generate atau isi UID terlebih dahulu.', 'error'); return }

    isSimulating.value = true
    simAction.value    = 'register'
    simResult.value    = null

    try {
        // 1. Set pending batch di MINETRA
        const pendingRes = await apiFetch('/api/v1/company/rfid/pending-batch', 'POST', {
            batch_id: parseInt(simBatchId.value)
        })
        if (!pendingRes.success) {
            showToast(pendingRes.message ?? 'Gagal set pending batch.', 'error')
            return
        }

        // 2. Simulasi scan → panggil endpoint yang sama dengan yang dipanggil ESP32
        const scanRes = await fetch('/api/rfid/register', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body:    JSON.stringify({ uid: simUid.value }),
        })
        const data    = await scanRes.json()
        simResult.value = { ...data, action: 'register', uid: simUid.value }
        showToast(data.message, data.status === 'success' ? 'success' : 'error')

        if (data.status === 'success') {
            router.reload({ only: ['tagged_batches','untagged_batches','recent_activity','stats'] })
        }
    } catch (e) {
        showToast('Error: ' + e.message, 'error')
    } finally {
        isSimulating.value = false
    }
}

/**
 * Simulasi LOGIN (Checkpoint IN) atau LOGOUT (Checkpoint OUT)
 * Langsung panggil endpoint MINETRA — sama dengan yang dipanggil ESP32 fisik.
 */
async function simulateScan(action) {
    if (!simUid.value) { showToast('Generate atau isi UID terlebih dahulu.', 'error'); return }

    isSimulating.value = true
    simAction.value    = action
    simResult.value    = null

    try {
        const endpoint = action === 'login' ? '/api/rfid/login' : '/api/rfid/logout'
        const res = await fetch(endpoint, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body:    JSON.stringify({ uid: simUid.value }),
        })
        const data    = await res.json()
        simResult.value = { ...data, action, uid: simUid.value }
        showToast(data.message, data.status === 'success' ? 'success' : 'error')

        if (data.status === 'success') {
            router.reload({ only: ['tagged_batches','recent_activity','stats'] })
        }
    } catch (e) {
        showToast('Error: ' + e.message, 'error')
    } finally {
        isSimulating.value = false
    }
}

// ─── Warna per aksi simulasi ──────────────────────────────────────────────
function getSimActionStyle(action) {
    const m = {
        register: { bg: 'bg-violet-100 dark:bg-violet-900/30', text: 'text-violet-700 dark:text-violet-300', label: 'REGISTER' },
        login:    { bg: 'bg-emerald-100 dark:bg-emerald-900/30', text: 'text-emerald-700 dark:text-emerald-300', label: 'CHECKPOINT IN' },
        logout:   { bg: 'bg-red-100 dark:bg-red-900/30', text: 'text-red-600 dark:text-red-400', label: 'CHECKPOINT OUT' },
    }
    return m[action] ?? { bg: 'bg-muted', text: 'text-muted-foreground', label: action }
}

// ═══════════════════════════════════════════════════════════════════════════
// REMOVE TAG
// ═══════════════════════════════════════════════════════════════════════════
const removingTagId = ref(null)

async function removeTag(batch) {
    if (!confirm(lang.value === 'en'
        ? `Remove RFID tag (UID: ${batch.rfid_tag}) from batch ${batch.batch_code}?`
        : `Hapus RFID tag (UID: ${batch.rfid_tag}) dari batch ${batch.batch_code}?`)) return
    removingTagId.value = batch.id
    try {
        const res = await apiFetch(`/api/v1/rfid/batches/${batch.id}/tag`, 'DELETE')
        showToast(res.message, res.success ? 'success' : 'error')
        if (res.success) router.reload({ only: ['tagged_batches','untagged_batches','stats'] })
    } catch {
        showToast('Gagal menghapus tag.', 'error')
    } finally {
        removingTagId.value = null
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// TOAST
// ═══════════════════════════════════════════════════════════════════════════
const toastMsg     = ref('')
const toastType    = ref('info')
const toastVisible = ref(false)
let   toastTimer   = null

function showToast(msg, type = 'info') {
    toastMsg.value     = msg
    toastType.value    = type
    toastVisible.value = true
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => { toastVisible.value = false }, 4500)
}

// ═══════════════════════════════════════════════════════════════════════════
// API HELPERS
// ═══════════════════════════════════════════════════════════════════════════
async function apiFetch(url, method = 'GET', body = null) {
    const opts = {
        method,
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
    }
    if (body) opts.body = JSON.stringify(body)
    const res = await fetch(url, opts)
    return res.json()
}

// ─── Helpers ──────────────────────────────────────────────────────────────
function getStageLabel(stage) {
    const m = {
        lab_kimia:'Lab Kimia', receiving:'Receiving', wet_process:'Wet',
        dry_process:'Dry', warehouse:'Warehouse', project_plant:'Lab LTJ',
    }
    return m[stage] ?? stage?.replace(/_/g,' ')
}

function getActionLabel(action) {
    const map = {
        rfid_register:       lang.value === 'en' ? 'Tag Registered'  : 'Tag Terdaftar',
        rfid_checkpoint_in:  lang.value === 'en' ? 'Checkpoint IN'    : 'CP Masuk',
        rfid_checkpoint_out: lang.value === 'en' ? 'Checkpoint OUT'   : 'CP Keluar',
    }
    return map[action] ?? action?.replace(/_/g,' ')
}

function getActionColor(action) {
    const m = {
        rfid_register:       'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300',
        rfid_checkpoint_in:  'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300',
        rfid_checkpoint_out: 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
    }
    return m[action] ?? 'bg-muted text-muted-foreground'
}

// ─── Cleanup ──────────────────────────────────────────────────────────────
onUnmounted(() => {
    clearInterval(espPollTimer)
    clearInterval(lcdAnimTimer)
    clearInterval(pendingTimer)
    clearTimeout(toastTimer)
})

// Auto-start ESP32 polling jika IP sudah tersimpan
if (props.esp32_ip) {
    esp32IpInput.value = props.esp32_ip
    startEspPolling()
}
</script>

<template>
<AppLayout>
    <Head :title="lang === 'en' ? 'RFID Hardware Panel' : 'Panel Hardware RFID'"/>
    <template #header>{{ lang === 'en' ? 'RFID Hardware Panel' : 'Panel Hardware RFID' }}</template>

    <!-- Toast -->
    <Transition name="toast">
        <div v-if="toastVisible"
             class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-5 py-2.5 rounded-xl border text-sm font-semibold shadow-xl whitespace-nowrap"
             :class="{
                 'bg-emerald-50 dark:bg-emerald-950/90 border-emerald-400 text-emerald-700 dark:text-emerald-300': toastType==='success',
                 'bg-red-50 dark:bg-red-950/90 border-red-400 text-red-700 dark:text-red-300': toastType==='error',
                 'bg-card border-border text-foreground': toastType==='info',
             }">
            {{ toastMsg }}
        </div>
    </Transition>

    <div class="space-y-5">

        <!-- ══════════════════════════════════════════════════
             HEADER
        ══════════════════════════════════════════════════ -->
        <div class="flex items-start justify-between gap-4 animate-fade-in">
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-2xl">📡</span>
                    <h1 class="font-heading text-xl font-bold text-foreground">
                        {{ lang === 'en' ? 'RFID Batch Tracking' : 'Tracking Batch via RFID' }}
                    </h1>
                </div>
                <p class="text-sm text-muted-foreground mt-0.5">
                    {{ lang === 'en'
                        ? 'Register RFID tags to mineral bags, track arrivals and departures.'
                        : 'Daftarkan tag RFID ke karung mineral, lacak masuk/keluar gudang.' }}
                </p>
            </div>
            <div class="text-right hidden md:block">
                <div class="rounded-xl border border-border bg-card px-4 py-2">
                    <p class="text-[10px] text-muted-foreground uppercase tracking-wider">Waktu Sistem</p>
                    <p class="font-mono text-xs font-bold text-foreground">{{ current_time }}</p>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             NETWORK WARNING — Jika beda jaringan dengan ESP32
        ══════════════════════════════════════════════════ -->
        <div class="rounded-2xl border border-amber-200 dark:border-amber-800/40 p-4 animate-fade-in delay-50"
             style="background:rgba(245,158,11,0.05)">
            <div class="flex items-start gap-3">
                <span class="text-xl flex-shrink-0">⚠️</span>
                <div>
                    <p class="text-sm font-bold text-amber-700 dark:text-amber-400">
                        {{ lang === 'en' ? 'Different Network?' : 'Beda Jaringan?' }}
                    </p>
                    <p class="text-xs text-muted-foreground mt-0.5 leading-relaxed">
                        {{ lang === 'en'
                            ? 'If browser and ESP32 are on different WiFi networks, use the Simulation tab below to test without hardware. For production, ensure both are on the same network.'
                            : 'Jika browser dan ESP32 berada di jaringan WiFi berbeda, gunakan tab Simulasi di bawah untuk test tanpa hardware. Untuk produksi, pastikan keduanya satu jaringan.' }}
                    </p>
                    <div class="flex items-center gap-4 mt-2 text-xs">
                        <span class="font-semibold text-emerald-600 dark:text-emerald-400">
                            ✅ {{ lang === 'en' ? 'Simulation: works across networks' : 'Simulasi: bisa lintas jaringan' }}
                        </span>
                        <span class="text-muted-foreground">
                            📡 {{ lang === 'en' ? 'ESP32 Control: requires same network' : 'Kontrol ESP32: butuh satu jaringan' }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             STATS
        ══════════════════════════════════════════════════ -->
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div v-for="(item, i) in [
                { label_id:'Batch Belum Ter-tag', label_en:'Untagged Batches', value: stats?.untagged_count, icon:'📦', color:'text-amber-600 dark:text-amber-400' },
                { label_id:'Batch Ter-tag',       label_en:'Tagged Batches',   value: stats?.tagged_count,   icon:'🪪', color:'text-primary' },
                { label_id:'Total Aktivitas RFID',label_en:'RFID Activities',  value: stats?.activity_count, icon:'📊', color:'text-emerald-600 dark:text-emerald-400' },
            ]" :key="item.label_id"
                 class="rounded-2xl border border-border bg-card p-4 animate-fade-in"
                 :style="`animation-delay:${i*50}ms`">
                <div class="flex items-start justify-between mb-2">
                    <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider leading-tight">
                        {{ lang === 'en' ? item.label_en : item.label_id }}
                    </p>
                    <span class="text-xl">{{ item.icon }}</span>
                </div>
                <p class="font-heading text-3xl font-bold tabular-nums" :class="item.color">{{ item.value ?? 0 }}</p>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             TABS
        ══════════════════════════════════════════════════ -->
        <div class="flex gap-1 p-1 rounded-2xl border border-border bg-muted/30 w-fit animate-fade-in delay-100">
            <button @click="activeTab = 'simulate'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold flex items-center gap-1.5"
                    :style="activeTab === 'simulate'
                        ? 'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 3px rgba(0,0,0,0.1);'
                        : 'color:hsl(var(--muted-foreground));'">
                🎮 {{ lang === 'en' ? 'Simulation' : 'Simulasi' }}
                <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full text-white bg-emerald-500">
                    {{ lang === 'en' ? 'No ESP32' : 'Tanpa ESP32' }}
                </span>
            </button>
            <button @click="activeTab = 'esp32'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold flex items-center gap-1.5"
                    :style="activeTab === 'esp32'
                        ? 'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 3px rgba(0,0,0,0.1);'
                        : 'color:hsl(var(--muted-foreground));'">
                📡 {{ lang === 'en' ? 'ESP32 Control' : 'Kontrol ESP32' }}
                <span v-if="espStatus === 'online'"
                      class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"/>
                <span v-else-if="espStatus === 'offline'"
                      class="w-1.5 h-1.5 rounded-full bg-red-500"/>
            </button>
            <button @click="activeTab = 'tagged'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold"
                    :style="activeTab === 'tagged'
                        ? 'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 3px rgba(0,0,0,0.1);'
                        : 'color:hsl(var(--muted-foreground));'">
                🪪 {{ lang === 'en' ? 'Tagged Batches' : 'Batch Ter-tag' }}
                <span class="ml-1 text-[9px] font-bold px-1.5 py-0.5 rounded-full text-white"
                      style="background:hsl(var(--primary))">{{ stats?.tagged_count ?? 0 }}</span>
            </button>
            <button @click="activeTab = 'activity'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold"
                    :style="activeTab === 'activity'
                        ? 'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 3px rgba(0,0,0,0.1);'
                        : 'color:hsl(var(--muted-foreground));'">
                📋 Log
            </button>
        </div>

        <!-- ══════════════════════════════════════════════════
             TAB: SIMULASI — Tidak butuh ESP32, lintas jaringan
        ══════════════════════════════════════════════════ -->
        <div v-show="activeTab === 'simulate'" class="space-y-5">

            <!-- Info box -->
            <div class="rounded-2xl border border-primary/20 p-4"
                 style="background:hsl(var(--primary)/0.04)">
                <div class="flex items-start gap-3">
                    <span class="text-xl flex-shrink-0">🎮</span>
                    <div>
                        <p class="text-sm font-bold text-foreground">
                            {{ lang === 'en' ? 'Simulation Mode — No Hardware Required' : 'Mode Simulasi — Tidak Butuh Hardware' }}
                        </p>
                        <p class="text-xs text-muted-foreground mt-0.5 leading-relaxed">
                            {{ lang === 'en'
                                ? 'Simulate RFID scans by providing a UID manually. The system calls the same API endpoints as the physical ESP32, so all data will be saved exactly as if a real card was scanned.'
                                : 'Simulasikan scan RFID dengan memasukkan UID secara manual. Sistem memanggil endpoint API yang sama dengan ESP32 fisik, sehingga semua data tersimpan persis seperti scan kartu sungguhan.' }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- UID Input + Generator -->
            <div class="rounded-2xl border border-border bg-card p-5 space-y-4">
                <h3 class="font-heading text-sm font-bold text-foreground">
                    🪪 {{ lang === 'en' ? 'RFID Tag UID' : 'UID Tag RFID' }}
                </h3>

                <div class="flex items-center gap-3">
                    <input v-model="simUid" type="text"
                           placeholder="Contoh: A3B2C1D0"
                           class="flex-1 h-11 px-4 rounded-xl border border-border bg-background text-sm font-mono font-bold text-foreground uppercase outline-none focus:border-primary focus:ring-1 focus:ring-primary/20"
                           @input="simUid = simUid.toUpperCase()"/>
                    <button @click="generateUid"
                            class="flex items-center gap-2 px-4 h-11 rounded-xl text-sm font-bold border border-border bg-card hover:bg-muted active:scale-[0.97] text-muted-foreground hover:text-foreground flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
                        </svg>
                        {{ lang === 'en' ? 'Generate UID' : 'Generate UID' }}
                    </button>
                </div>
                <p class="text-[11px] text-muted-foreground">
                    {{ lang === 'en'
                        ? 'For Register: generate a new random UID (simulates a new unregistered card). For Login/Logout: use the UID already registered to a batch.'
                        : 'Untuk Register: generate UID acak baru (simulasi kartu baru). Untuk Login/Logout: gunakan UID yang sudah terdaftar di batch.' }}
                </p>
            </div>

            <!-- 3 Simulasi Actions -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                <!-- Simulasi Register -->
                <div class="rounded-2xl border border-violet-200 dark:border-violet-800/40 p-5 space-y-3"
                     style="background:rgba(124,58,237,0.04)">
                    <div class="flex items-center gap-2">
                        <span class="text-xl">🪪</span>
                        <div>
                            <p class="text-xs font-bold text-foreground">Register Tag</p>
                            <p class="text-[10px] text-muted-foreground">
                                {{ lang === 'en' ? 'Link UID to a batch' : 'Hubungkan UID ke batch' }}
                            </p>
                        </div>
                    </div>

                    <select v-model="simBatchId"
                            class="w-full h-9 px-2.5 rounded-xl border border-border bg-background text-xs text-foreground outline-none focus:border-primary">
                        <option value="" disabled>
                            {{ lang === 'en' ? 'Select batch without tag...' : 'Pilih batch belum punya tag...' }}
                        </option>
                        <option v-for="b in untagged_batches" :key="b.id" :value="b.id">
                            {{ b.batch_code }} ({{ b.product_code }})
                        </option>
                    </select>

                    <button @click="simulateRegister"
                            :disabled="!simBatchId || !simUid || isSimulating"
                            class="w-full h-10 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#7c3aed,#5b21b6)">
                        <svg v-if="isSimulating && simAction === 'register'" class="w-4 h-4 animate-spin inline-block mr-1.5" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        {{ isSimulating && simAction === 'register'
                            ? 'Menyimpan...'
                            : (lang === 'en' ? '▶ Simulate Register' : '▶ Simulasi Register') }}
                    </button>
                </div>

                <!-- Simulasi Login / Checkpoint IN -->
                <div class="rounded-2xl border border-emerald-200 dark:border-emerald-800/40 p-5 space-y-3"
                     style="background:rgba(5,150,105,0.04)">
                    <div class="flex items-center gap-2">
                        <span class="text-xl">📥</span>
                        <div>
                            <p class="text-xs font-bold text-foreground">Login / Checkpoint IN</p>
                            <p class="text-[10px] text-muted-foreground">
                                {{ lang === 'en' ? 'Batch arrives at warehouse' : 'Karung tiba di gudang' }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-border bg-muted/30 p-2.5">
                        <p class="text-[10px] text-muted-foreground leading-relaxed">
                            {{ lang === 'en'
                                ? 'Use a UID already registered to a batch. The system will find the batch automatically.'
                                : 'Gunakan UID yang sudah terdaftar ke batch. Sistem akan mencari batch secara otomatis.' }}
                        </p>
                    </div>

                    <button @click="simulateScan('login')"
                            :disabled="!simUid || isSimulating"
                            class="w-full h-10 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#059669,#047857)">
                        <svg v-if="isSimulating && simAction === 'login'" class="w-4 h-4 animate-spin inline-block mr-1.5" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        {{ isSimulating && simAction === 'login'
                            ? 'Menyimpan...'
                            : (lang === 'en' ? '▶ Simulate Login (IN)' : '▶ Simulasi Login (Masuk)') }}
                    </button>
                </div>

                <!-- Simulasi Logout / Checkpoint OUT -->
                <div class="rounded-2xl border border-red-200 dark:border-red-800/40 p-5 space-y-3"
                     style="background:rgba(220,38,38,0.04)">
                    <div class="flex items-center gap-2">
                        <span class="text-xl">📤</span>
                        <div>
                            <p class="text-xs font-bold text-foreground">Logout / Checkpoint OUT</p>
                            <p class="text-[10px] text-muted-foreground">
                                {{ lang === 'en' ? 'Batch leaves the warehouse' : 'Karung keluar dari gudang' }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-border bg-muted/30 p-2.5">
                        <p class="text-[10px] text-muted-foreground leading-relaxed">
                            {{ lang === 'en'
                                ? 'Use a UID of a batch that has been checked-in. The system will record the departure.'
                                : 'Gunakan UID batch yang sudah masuk (login). Sistem akan mencatat keberangkatan.' }}
                        </p>
                    </div>

                    <button @click="simulateScan('logout')"
                            :disabled="!simUid || isSimulating"
                            class="w-full h-10 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#dc2626,#b91c1c)">
                        <svg v-if="isSimulating && simAction === 'logout'" class="w-4 h-4 animate-spin inline-block mr-1.5" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        {{ isSimulating && simAction === 'logout'
                            ? 'Menyimpan...'
                            : (lang === 'en' ? '▶ Simulate Logout (OUT)' : '▶ Simulasi Logout (Keluar)') }}
                    </button>
                </div>
            </div>

            <!-- Hasil Simulasi -->
            <Transition name="slide">
                <div v-if="simResult"
                     class="rounded-2xl border p-5"
                     :class="simResult.status === 'success'
                         ? 'border-emerald-300 dark:border-emerald-700'
                         : 'border-red-300 dark:border-red-700'"
                     :style="simResult.status === 'success'
                         ? 'background:rgba(16,185,129,0.06)'
                         : 'background:rgba(220,38,38,0.06)'">

                    <div class="flex items-center gap-3 mb-3">
                        <span class="text-2xl">{{ simResult.status === 'success' ? '✅' : '❌' }}</span>
                        <div>
                            <p class="text-sm font-bold text-foreground">
                                {{ simResult.status === 'success'
                                    ? (lang === 'en' ? 'Simulation Successful' : 'Simulasi Berhasil')
                                    : (lang === 'en' ? 'Simulation Failed' : 'Simulasi Gagal') }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ simResult.message }}
                            </p>
                        </div>
                        <div class="ml-auto">
                            <span class="text-[10px] font-bold px-2 py-1 rounded-lg"
                                  :class="getSimActionStyle(simResult.action).bg + ' ' + getSimActionStyle(simResult.action).text">
                                {{ getSimActionStyle(simResult.action).label }}
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="rounded-xl border border-border bg-card p-2.5">
                            <p class="text-[10px] text-muted-foreground">UID</p>
                            <p class="text-xs font-mono font-bold text-amber-600 dark:text-amber-400">{{ simResult.uid }}</p>
                        </div>
                        <div v-if="simResult.batch_code" class="rounded-xl border border-border bg-card p-2.5">
                            <p class="text-[10px] text-muted-foreground">Batch</p>
                            <p class="text-xs font-mono font-bold text-foreground">{{ simResult.batch_code }}</p>
                        </div>
                        <div v-if="simResult.product" class="rounded-xl border border-border bg-card p-2.5">
                            <p class="text-[10px] text-muted-foreground">Produk</p>
                            <p class="text-xs font-bold text-foreground">{{ simResult.product }}</p>
                        </div>
                        <div v-if="simResult.detail" class="rounded-xl border border-border bg-card p-2.5 col-span-2 md:col-span-1">
                            <p class="text-[10px] text-muted-foreground">Detail</p>
                            <p class="text-[11px] text-foreground leading-relaxed">{{ simResult.detail }}</p>
                        </div>
                    </div>
                </div>
            </Transition>
        </div>

        <!-- ══════════════════════════════════════════════════
             TAB: ESP32 CONTROL (butuh satu jaringan)
        ══════════════════════════════════════════════════ -->
        <div v-show="activeTab === 'esp32'" class="space-y-5">

            <!-- Device Setup -->
            <div class="rounded-2xl border border-border bg-card p-5">
                <div class="flex items-center gap-2 mb-3">
                    <span class="text-base">🔌</span>
                    <h3 class="font-heading text-sm font-bold text-foreground">
                        {{ lang === 'en' ? 'ESP32 Device' : 'Perangkat ESP32' }}
                    </h3>
                    <div class="ml-auto flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full"
                             :class="{
                                 'bg-emerald-500 animate-pulse': espStatus==='online',
                                 'bg-red-500': espStatus==='offline',
                                 'bg-gray-400': espStatus==='unknown',
                             }"/>
                        <span class="text-xs font-mono font-bold"
                              :class="{
                                  'text-emerald-600 dark:text-emerald-400': espStatus==='online',
                                  'text-red-500': espStatus==='offline',
                                  'text-muted-foreground': espStatus==='unknown',
                              }">
                            {{ espStatus === 'online' ? 'ONLINE' : espStatus === 'offline' ? 'OFFLINE' : '—' }}
                        </span>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <input v-model="esp32IpInput" type="text"
                           placeholder="Contoh: 192.168.1.50"
                           class="flex-1 h-10 px-3 rounded-xl border border-border bg-background text-sm font-mono text-foreground outline-none focus:border-primary focus:ring-1 focus:ring-primary/20"/>
                    <button @click="saveEspIp"
                            :disabled="espIpSaving || !esp32IpInput"
                            class="flex items-center gap-2 px-4 h-10 rounded-xl text-sm font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                            style="background:linear-gradient(135deg,hsl(var(--primary)),hsl(207 30% 28%))">
                        {{ espIpSaving ? 'Menyimpan...' : 'Simpan & Hubungkan' }}
                    </button>
                </div>
                <p class="text-[10px] text-muted-foreground mt-2">
                    ⚠️ {{ lang === 'en'
                        ? 'Browser and ESP32 must be on the same WiFi network.'
                        : 'Browser dan ESP32 harus berada di jaringan WiFi yang sama.' }}
                </p>
            </div>

            <!-- Pending Banner -->
            <Transition name="slide">
                <div v-if="isPending"
                     class="rounded-2xl border-2 border-amber-400 dark:border-amber-600 p-5"
                     style="background:rgba(245,158,11,0.06)">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-xl bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
                            <span class="text-2xl animate-pulse">🪪</span>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-bold text-amber-700 dark:text-amber-400">⚡ Sesi Registrasi Aktif</p>
                            <p class="text-xs text-foreground font-semibold mt-0.5">
                                Batch: <span class="font-mono">{{ pendingData?.batch_code }}</span> ({{ pendingData?.product_code }})
                            </p>
                            <p class="text-xs text-muted-foreground">ESP32 dalam mode REGISTER. Tempel tag RFID ke karung sekarang.</p>
                            <div class="mt-2.5 space-y-1">
                                <div class="flex justify-between text-[10px]">
                                    <span class="text-muted-foreground font-mono uppercase">Timeout</span>
                                    <span class="font-black font-mono" :class="pendingCountdown<=15?'text-red-500':'text-amber-600'">{{ pendingCountdown }}s</span>
                                </div>
                                <div class="h-1.5 rounded-full bg-amber-100 dark:bg-amber-900/30 overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-1000"
                                         :class="pendingCountdown<=15?'bg-red-500':'bg-amber-400'"
                                         :style="`width:${countdownPct}%`"/>
                                </div>
                            </div>
                        </div>
                        <button @click="cancelPendingSession" :disabled="isCancelling"
                                class="flex-shrink-0 px-3 py-2 rounded-xl border border-amber-400 text-xs font-bold text-amber-700 dark:text-amber-400 hover:bg-amber-100 active:scale-[0.97] disabled:opacity-60">
                            Batal
                        </button>
                    </div>
                </div>
            </Transition>

            <!-- LCD Preview + Mode Control Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

                <!-- LCD Preview -->
                <div class="rounded-2xl border border-border bg-card p-5 flex flex-col items-center gap-3">
                    <p class="text-[10px] font-mono font-semibold text-muted-foreground uppercase tracking-widest">
                        ◼ LCD ESP32 LIVE PREVIEW
                    </p>
                    <div class="rounded-xl overflow-hidden w-full max-w-[300px]"
                         style="background:#0d1a0f;border:2px solid #1a2e1c;padding:16px 18px;box-shadow:0 0 0 3px #080e09,0 0 0 5px #1a2e1c;">
                        <div class="font-mono text-lg leading-none mb-1.5"
                             style="color:#1aff6e;text-shadow:0 0 8px rgba(26,255,110,0.6);letter-spacing:0.1em;white-space:pre;">
                            {{ lcdRow0 }}
                        </div>
                        <div class="font-mono text-lg leading-none"
                             :style="activeMode==='idle'?'color:#0d7a37':'color:#1aff6e;text-shadow:0 0 8px rgba(26,255,110,0.5)'"
                             style="letter-spacing:0.1em;white-space:pre;">
                            {{ lcdRow1 }}
                        </div>
                    </div>
                    <div v-if="espState?.last_uid" class="w-full rounded-xl border border-border bg-muted/30 p-3 text-center">
                        <p class="text-[10px] text-muted-foreground uppercase tracking-wider">Last UID</p>
                        <p class="font-mono text-base font-bold text-amber-600 dark:text-amber-400">{{ espState.last_uid }}</p>
                        <p class="text-[10px] text-muted-foreground mt-0.5">{{ espState.last_message }}</p>
                    </div>
                </div>

                <!-- Mode Control -->
                <div class="rounded-2xl border border-border bg-card p-5 space-y-3">
                    <h3 class="font-heading text-sm font-bold text-foreground">🎛️ Mode Control ESP32</h3>

                    <!-- Register mode -->
                    <div v-if="canManage" class="space-y-2 p-3 rounded-xl border"
                         :class="activeMode==='register'?'border-violet-400 bg-violet-50 dark:bg-violet-950/10':'border-border bg-muted/20'">
                        <p class="text-xs font-bold text-foreground">🪪 Mode REGISTER</p>
                        <div class="flex gap-2">
                            <select v-model="selectedBatchId"
                                    class="flex-1 h-9 px-2.5 rounded-xl border border-border bg-background text-xs text-foreground">
                                <option value="" disabled>Pilih batch belum punya tag...</option>
                                <option v-for="b in untagged_batches" :key="b.id" :value="b.id">
                                    {{ b.batch_code }} ({{ b.product_code }})
                                </option>
                            </select>
                            <button @click="startRegisterSession"
                                    :disabled="!selectedBatchId||isSavingPending||isPending||espStatus!=='online'"
                                    class="px-3 h-9 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                                    style="background:linear-gradient(135deg,#7c3aed,#5b21b6)">
                                🪪 {{ isSavingPending ? '...' : 'Register' }}
                            </button>
                        </div>
                    </div>

                    <!-- Login mode -->
                    <div class="flex items-center justify-between p-3 rounded-xl border"
                         :class="activeMode==='login'?'border-emerald-400 bg-emerald-50 dark:bg-emerald-950/10':'border-border bg-muted/20'">
                        <div>
                            <p class="text-xs font-bold text-foreground">📥 Mode LOGIN (CP IN)</p>
                            <p class="text-[11px] text-muted-foreground">Karung tiba di gudang</p>
                        </div>
                        <button @click="sendModeToEsp32('login')"
                                :disabled="sendingMode==='login'||espStatus!=='online'"
                                class="px-3 h-9 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                                style="background:linear-gradient(135deg,#059669,#047857)">
                            → Aktifkan
                        </button>
                    </div>

                    <!-- Logout mode -->
                    <div class="flex items-center justify-between p-3 rounded-xl border"
                         :class="activeMode==='logout'?'border-red-400 bg-red-50 dark:bg-red-950/10':'border-border bg-muted/20'">
                        <div>
                            <p class="text-xs font-bold text-foreground">📤 Mode LOGOUT (CP OUT)</p>
                            <p class="text-[11px] text-muted-foreground">Karung keluar gudang</p>
                        </div>
                        <button @click="sendModeToEsp32('logout')"
                                :disabled="sendingMode==='logout'||espStatus!=='online'"
                                class="px-3 h-9 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                                style="background:linear-gradient(135deg,#dc2626,#b91c1c)">
                            → Aktifkan
                        </button>
                    </div>

                    <!-- Idle -->
                    <button @click="sendModeToEsp32('idle')"
                            :disabled="espStatus!=='online'"
                            class="w-full h-10 rounded-xl text-xs font-bold border border-border text-muted-foreground hover:bg-muted disabled:opacity-40 active:scale-[0.97]">
                        ⏹ Stop — Kembali Idle
                    </button>

                    <div v-if="espStatus==='offline'"
                         class="flex items-start gap-2 p-3 rounded-xl border border-amber-200 dark:border-amber-800/40 bg-amber-50 dark:bg-amber-950/10">
                        <span class="text-sm flex-shrink-0">💡</span>
                        <p class="text-[11px] text-amber-700 dark:text-amber-400">
                            ESP32 offline. Gunakan tab <strong>Simulasi</strong> untuk test tanpa hardware.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             TAB: TAGGED BATCHES
        ══════════════════════════════════════════════════ -->
        <div v-show="activeTab === 'tagged'">
            <EmptyState v-if="!tagged_batches.length"
                icon="🪪"
                :title="lang==='en'?'No tagged batches yet':'Belum ada batch yang ter-tag'"
                :description="lang==='en'
                    ?'Use Simulation or ESP32 Register mode to tag a batch.'
                    :'Gunakan Simulasi atau mode Register ESP32 untuk tag batch.'"
            />
            <div v-else class="rounded-2xl border border-border bg-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/30">
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Batch</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">UID Tag</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Stage</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Checkpoint Terakhir</th>
                                <th class="px-4 py-3 w-20"/>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="(batch, i) in tagged_batches" :key="batch.id"
                                class="group hover:bg-muted/20"
                                :style="`animation:fade-in 0.22s ease-out ${i*25}ms both`">
                                <td class="px-5 py-3.5">
                                    <Link :href="`/company/batches/${batch.id}`"
                                          class="font-mono text-xs font-bold text-foreground hover:text-primary">
                                        {{ batch.batch_code }}
                                    </Link>
                                    <p class="text-[10px] text-muted-foreground">{{ batch.product_code }}</p>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="font-mono text-xs font-bold text-amber-600 dark:text-amber-400">{{ batch.rfid_tag }}</span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-muted text-muted-foreground">
                                        {{ getStageLabel(batch.process_stage) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <p v-if="batch.last_checkpoint" class="text-[11px] text-muted-foreground">
                                        {{ batch.last_checkpoint.stage?.replace(/_/g,' ') }}
                                        · {{ batch.last_checkpoint.recorded_at_diff }}
                                    </p>
                                    <span v-else class="text-[10px] text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <button v-if="canAdmin"
                                            @click="removeTag(batch)"
                                            :disabled="removingTagId===batch.id"
                                            class="opacity-0 group-hover:opacity-100 text-[10px] font-bold px-2 py-1 rounded-lg text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 active:scale-[0.97] disabled:opacity-40 transition-all">
                                        Hapus
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             TAB: ACTIVITY LOG
        ══════════════════════════════════════════════════ -->
        <div v-show="activeTab === 'activity'">
            <EmptyState v-if="!recent_activity.length"
                icon="📋"
                :title="lang==='en'?'No RFID activity yet':'Belum ada aktivitas RFID'"
                :description="lang==='en'
                    ?'Scan events (real or simulated) will appear here.'
                    :'Event scan (fisik maupun simulasi) akan muncul di sini.'"
            />
            <div v-else class="rounded-2xl border border-border bg-card overflow-hidden">
                <div class="divide-y divide-border">
                    <div v-for="(act, i) in recent_activity" :key="act.id"
                         class="flex items-start gap-3 px-5 py-3.5 hover:bg-muted/20"
                         :style="`animation:fade-in 0.22s ease-out ${i*20}ms both`">
                        <span class="text-[10px] font-bold px-2 py-1 rounded-lg mt-0.5 flex-shrink-0"
                              :class="getActionColor(act.action)">
                            {{ getActionLabel(act.action) }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <Link :href="`/company/batches/${act.batch_id}`"
                                      class="font-mono text-xs font-bold text-foreground hover:text-primary">
                                    {{ act.batch_code }}
                                </Link>
                                <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-muted text-muted-foreground">
                                    {{ act.product_code }}
                                </span>
                            </div>
                            <p class="text-[10px] text-muted-foreground mt-0.5">
                                UID: <span class="font-mono text-amber-600 dark:text-amber-400">{{ act.rfid_uid }}</span>
                                · {{ act.weight_kg }} kg
                            </p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-[11px] font-semibold text-foreground">{{ act.recorded_at_diff }}</p>
                            <p class="text-[10px] text-muted-foreground font-mono">{{ act.recorded_at }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</AppLayout>
</template>

<style scoped>
@keyframes fade-in { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
.animate-fade-in { animation: fade-in 0.28s ease-out both; }
.delay-50  { animation-delay: 50ms; }
.delay-100 { animation-delay: 100ms; }

.toast-enter-active { transition: all 0.2s ease; }
.toast-leave-active { transition: all 0.15s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateX(-50%) translateY(20px); }

.slide-enter-active { transition: all 0.25s ease-out; }
.slide-leave-active { transition: all 0.2s ease-in; }
.slide-enter-from, .slide-leave-to { opacity: 0; transform: translateY(-8px); }
</style>