<script setup>
/**
 * RfidPanel.vue — Panel Hardware RFID + Simulation Mode
 *
 * ENDPOINT PATHS (setelah bug fix):
 *   Web UI → /api/v1/company/rfid/...  (middleware: web + auth session)
 *   ESP32  → /api/rfid/...             (public, no auth)
 *
 * MIXED CONTENT SOLUTION:
 *   HTTPS page → HTTP ESP32 IP = Chrome BLOCKS
 *   Fix: Tampilkan instruksi allow insecure content di Chrome
 *        + Gunakan Simulation tab jika beda jaringan
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Head, Link, router }                      from '@inertiajs/vue3'
import AppLayout                                    from '@/Layouts/AppLayout.vue'
import EmptyState                                   from '@/components/shared/EmptyState.vue'
import { useI18n }                                  from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    esp32_ip:         { type: String, default: '' },
    untagged_batches: { type: Array,  default: () => [] },
    tagged_batches:   { type: Array,  default: () => [] },
    pending_batch:    { type: Object, default: null },
    recent_activity:  { type: Array,  default: () => [] },
    stats:            Object,
    user_role:        String,
    company_id:       Number,
    current_time:     String,
})

const canManage = computed(() => ['company_admin','operator_lab_station','operator_wet'].includes(props.user_role))
const canAdmin  = computed(() => props.user_role === 'company_admin')

// ─── Active tab — default simulasi (paling sering dipakai) ─────────────────
const activeTab = ref('simulate')

// ═══════════════════════════════════════════════════════════════════════════
// MIXED CONTENT DETECTION & WARNING
// ═══════════════════════════════════════════════════════════════════════════
const isHttps           = ref(false)
const showMixedWarning  = ref(false)
const mixedContentDismissed = ref(false)

onMounted(() => {
    isHttps.value = window.location.protocol === 'https:'
    // Hanya tampilkan warning jika HTTPS dan ada IP ESP32
    if (isHttps.value && (props.esp32_ip || localStorage.getItem('esp32_ip_local'))) {
        showMixedWarning.value = !mixedContentDismissed.value
    }
})

function dismissMixedWarning() {
    mixedContentDismissed.value = true
    showMixedWarning.value      = false
    localStorage.setItem('rfid_mixed_warning_dismissed', '1')
}

// ═══════════════════════════════════════════════════════════════════════════
// ESP32 DEVICE SETUP
// ═══════════════════════════════════════════════════════════════════════════
const esp32IpInput = ref(props.esp32_ip ?? localStorage.getItem('esp32_ip_local') ?? '')
const espIpSaving  = ref(false)
const espStatus    = ref('unknown')

async function saveEspIp() {
    if (!esp32IpInput.value.trim()) { showToast('Masukkan IP ESP32 terlebih dahulu.', 'error'); return }
    espIpSaving.value = true
    try {
        // Simpan ke server (CompanySetting)
        const res = await apiFetch('/api/v1/company/rfid/device', 'POST', {
            esp32_ip: esp32IpInput.value.trim()
        })
        if (res.success) {
            // Simpan juga di localStorage untuk restore session berikutnya
            localStorage.setItem('esp32_ip_local', esp32IpInput.value.trim())
            showToast(`IP ESP32 disimpan: ${res.data?.esp32_ip}`, 'success')
            if (isHttps.value) showMixedWarning.value = true
            startEspPolling()
        } else {
            showToast(res.message ?? 'Gagal menyimpan IP.', 'error')
        }
    } catch (e) {
        showToast('Gagal: ' + e.message, 'error')
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
    espPollTimer = setInterval(pollEsp32, 1000)
}

async function pollEsp32() {
    const ip = esp32IpInput.value.trim()
    if (!ip) return
    const baseUrl = ip.startsWith('http') ? ip : `http://${ip}`
    try {
        const res = await fetch(`${baseUrl}/status`, { signal: AbortSignal.timeout(2500) })
        if (!res.ok) throw new Error()
        const d  = await res.json()
        espStatus.value  = 'online'
        espState.value   = d
        activeMode.value = d.mode ?? 'idle'
        updateLcd(d)
    } catch (err) {
        // Mixed content error → ESP32 online tapi browser blokir
        if (err?.message?.includes('Failed to fetch') || err?.message?.includes('NetworkError')) {
            espStatus.value = 'blocked'  // khusus: bukan offline, tapi diblokir
        } else {
            espStatus.value = 'offline'
        }
    }
}

function updateLcd(d) {
    if (lcdAnimTimer) { clearInterval(lcdAnimTimer); lcdAnimTimer = null }
    const pad = (s, n = 16) => (s + ' '.repeat(n)).substring(0, n)
    if (d.mode === 'idle' || !d.mode) {
        lcdRow0.value = pad('Sistem RFID'); lcdRow1.value = pad('Menunggu mode..'); return
    }
    const modeLabel = { register:'[ REGISTER ]', login:'[ LOGIN    ]', logout:'[ LOGOUT   ]' }
    if (d.status === 'waiting') {
        lcdRow0.value = pad(modeLabel[d.mode] || d.mode); lcdAnimIdx = 0
        const animate = () => { lcdRow1.value = pad('Tempel Tag' + LCD_DOTS[lcdAnimIdx++ % 4]) }
        animate(); lcdAnimTimer = setInterval(animate, 600)
    } else if (d.status === 'success') {
        const ok = { register:'Tag Terdaftar!', login:'Masuk Tercatat', logout:'Keluar Tercatat' }
        lcdRow0.value = pad(ok[d.mode] || 'Berhasil!'); lcdRow1.value = pad(d.last_name || '')
    } else if (d.status === 'failed') {
        const fail = { register:'Daftar Gagal!', login:'Masuk Gagal!', logout:'Keluar Gagal!' }
        lcdRow0.value = pad(fail[d.mode] || 'Gagal!'); lcdRow1.value = pad(d.last_message || 'Coba lagi')
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// MODE CONTROL — Kirim ke ESP32 (butuh satu jaringan)
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
            showToast(`ESP32 → Mode ${mode.toUpperCase()} aktif`, 'success')
            return true
        }
        showToast('ESP32 menolak perintah.', 'error'); return false
    } catch (err) {
        // Deteksi Mixed Content secara spesifik
        if (isHttps.value && err?.message?.includes('Failed to fetch')) {
            showToast('⚠️ Browser blokir HTTP request (Mixed Content). Ikuti instruksi di banner merah.', 'error')
            showMixedWarning.value = true
        } else {
            showToast('ESP32 tidak merespons. Pastikan satu jaringan WiFi.', 'error')
        }
        return false
    } finally {
        sendingMode.value = null
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// PENDING BATCH
// ═══════════════════════════════════════════════════════════════════════════
const selectedBatchId  = ref('')
const isPending        = ref(!!props.pending_batch)
const pendingData      = ref(props.pending_batch)
const pendingCountdown = ref(props.pending_batch ? 90 : 0)
const isSavingPending  = ref(false)
const isCancelling     = ref(false)
let   pendingTimer     = null

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
            isPending.value = false; pendingData.value = null
            router.reload({ only: ['pending_batch','untagged_batches','stats'] })
        }
    }, 1000)
}

const countdownPct = computed(() => Math.round((pendingCountdown.value / 90) * 100))

async function startRegisterSession() {
    if (!selectedBatchId.value) { showToast('Pilih batch terlebih dahulu.', 'error'); return }
    if (!esp32IpInput.value)    { showToast('Simpan IP ESP32 terlebih dahulu.', 'error'); return }
    isSavingPending.value = true
    try {
        const res = await apiFetch('/api/v1/company/rfid/pending-batch', 'POST', {
            batch_id: parseInt(selectedBatchId.value)
        })
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

async function cancelPendingSession() {
    isCancelling.value = true
    clearInterval(pendingTimer)
    try {
        await apiFetch('/api/v1/company/rfid/pending-batch', 'DELETE')
        isPending.value = false; pendingData.value = null; pendingCountdown.value = 0
        showToast('Sesi dibatalkan.', 'info')
        await sendModeToEsp32('idle')
        router.reload({ only: ['pending_batch','untagged_batches','stats'] })
    } finally {
        isCancelling.value = false
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// SIMULATION PANEL — Tidak butuh ESP32 fisik, lintas jaringan
// ═══════════════════════════════════════════════════════════════════════════
const simUid       = ref('')
const simBatchId   = ref('')
const simResult    = ref(null)
const isSimulating = ref(false)
const simAction    = ref(null)

/** Sanitasi UID — sama dengan yang dilakukan server */
function sanitizeUid(uid) {
    return uid.replace(/[\s:\-\.]/g, '').toUpperCase()
}

function generateUid() {
    const bytes = Array.from({ length: 4 }, () =>
        Math.floor(Math.random() * 256).toString(16).padStart(2, '0')
    )
    simUid.value = bytes.join('').toUpperCase()
}

async function simulateRegister() {
    if (!simBatchId.value) { showToast('Pilih batch untuk di-tag.', 'error'); return }
    if (!simUid.value)     { showToast('Generate atau isi UID terlebih dahulu.', 'error'); return }

    isSimulating.value = true; simAction.value = 'register'; simResult.value = null
    try {
        // 1. Set pending batch di MINETRA backend
        const pendingRes = await apiFetch('/api/v1/company/rfid/pending-batch', 'POST', {
            batch_id: parseInt(simBatchId.value)
        })
        if (!pendingRes.success) { showToast(pendingRes.message ?? 'Gagal.', 'error'); return }

        // 2. Simulasi scan RFID — panggil endpoint publik (sama dengan ESP32)
        const scanRes = await fetch('/api/rfid/register', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body:    JSON.stringify({ uid: sanitizeUid(simUid.value) }),
        })
        const data    = await scanRes.json()
        simResult.value = { ...data, action: 'register', uid: sanitizeUid(simUid.value) }
        showToast(data.message, data.status === 'success' ? 'success' : 'error')
        if (data.status === 'success') router.reload({ only: ['tagged_batches','untagged_batches','recent_activity','stats'] })
    } catch (e) {
        showToast('Error: ' + e.message, 'error')
    } finally {
        isSimulating.value = false
    }
}

async function simulateScan(action) {
    if (!simUid.value) { showToast('Generate atau isi UID terlebih dahulu.', 'error'); return }
    isSimulating.value = true; simAction.value = action; simResult.value = null
    try {
        const endpoint = action === 'login' ? '/api/rfid/login' : '/api/rfid/logout'
        const res  = await fetch(endpoint, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body:    JSON.stringify({ uid: sanitizeUid(simUid.value) }),
        })
        const data = await res.json()
        simResult.value = { ...data, action, uid: sanitizeUid(simUid.value) }
        showToast(data.message, data.status === 'success' ? 'success' : 'error')
        if (data.status === 'success') router.reload({ only: ['tagged_batches','recent_activity','stats'] })
    } catch (e) {
        showToast('Error: ' + e.message, 'error')
    } finally {
        isSimulating.value = false
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// REMOVE TAG
// ═══════════════════════════════════════════════════════════════════════════
const removingTagId = ref(null)

async function removeTag(batch) {
    if (!confirm(`Hapus RFID tag (UID: ${batch.rfid_tag}) dari batch ${batch.batch_code}?`)) return
    removingTagId.value = batch.id
    try {
        const res = await apiFetch(`/api/v1/company/rfid/batches/${batch.id}/tag`, 'DELETE')
        showToast(res.message, res.success ? 'success' : 'error')
        if (res.success) router.reload({ only: ['tagged_batches','untagged_batches','stats'] })
    } catch { showToast('Gagal menghapus tag.', 'error') }
    finally { removingTagId.value = null }
}

// ═══════════════════════════════════════════════════════════════════════════
// TOAST
// ═══════════════════════════════════════════════════════════════════════════
const toastMsg     = ref('')
const toastType    = ref('info')
const toastVisible = ref(false)
let   toastTimer   = null

function showToast(msg, type = 'info') {
    toastMsg.value = msg; toastType.value = type; toastVisible.value = true
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => { toastVisible.value = false }, 4500)
}

// ═══════════════════════════════════════════════════════════════════════════
// API HELPERS (session-based, CSRF token)
// ═══════════════════════════════════════════════════════════════════════════
async function apiFetch(url, method = 'GET', body = null) {
    const opts = {
        method,
        credentials: 'include',   // kirim session cookie
        headers: {
            'Content-Type':  'application/json',
            'Accept':        'application/json',
            'X-CSRF-TOKEN':  document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            'X-Requested-With': 'XMLHttpRequest',
        },
    }
    if (body) opts.body = JSON.stringify(body)
    const res = await fetch(url, opts)
    if (!res.ok && res.status === 401) {
        showToast('Sesi habis. Silakan refresh halaman.', 'error')
        throw new Error('Unauthenticated')
    }
    return res.json()
}

// ─── Helpers ──────────────────────────────────────────────────────────────
function getStageLabel(stage) {
    const m = { lab_kimia:'Lab Kimia', receiving:'Receiving', wet_process:'Wet',
                dry_process:'Dry', warehouse:'Warehouse', project_plant:'Lab LTJ' }
    return m[stage] ?? stage?.replace(/_/g,' ')
}

function getActionLabel(action) {
    const m = { rfid_register:'Tag Terdaftar', rfid_checkpoint_in:'CP Masuk', rfid_checkpoint_out:'CP Keluar' }
    return m[action] ?? action?.replace(/_/g,' ')
}

function getActionColor(action) {
    const m = {
        rfid_register:       'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300',
        rfid_checkpoint_in:  'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300',
        rfid_checkpoint_out: 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
    }
    return m[action] ?? 'bg-muted text-muted-foreground'
}

function getSimActionStyle(action) {
    const m = {
        register: { bg:'bg-violet-100 dark:bg-violet-900/30', text:'text-violet-700 dark:text-violet-300', label:'REGISTER' },
        login:    { bg:'bg-emerald-100 dark:bg-emerald-900/30', text:'text-emerald-700 dark:text-emerald-300', label:'CHECKPOINT IN' },
        logout:   { bg:'bg-red-100 dark:bg-red-900/30', text:'text-red-600 dark:text-red-400', label:'CHECKPOINT OUT' },
    }
    return m[action] ?? { bg:'bg-muted', text:'text-muted-foreground', label:action }
}

// ─── Static arrays — dipindahkan dari template untuk menghindari
// Vue parser error akibat quotes di dalam HTML attribute ─────────
const chromeSteps = [
    { num: '1', text: 'Klik ikon kunci (🔒) di address bar sebelah kiri URL' },
    { num: '2', text: 'Klik Pengaturan situs (Site settings)' },
    { num: '3', text: 'Gulir ke bagian Konten tidak aman (Insecure content)' },
    { num: '4', text: 'Ubah dari Blokir menjadi Izinkan (Allow)' },
    { num: '5', text: 'Refresh halaman Ctrl+R lalu coba kembali' },
]

// ─── Cleanup ──────────────────────────────────────────────────────────────
onUnmounted(() => {
    clearInterval(espPollTimer); clearInterval(lcdAnimTimer)
    clearInterval(pendingTimer); clearTimeout(toastTimer)
})

if (props.esp32_ip) { esp32IpInput.value = props.esp32_ip; startEspPolling() }
</script>

<template>
<AppLayout>
    <Head :title="lang==='en' ? 'RFID Hardware Panel' : 'Panel Hardware RFID'"/>
    <template #header>{{ lang==='en' ? 'RFID Hardware Panel' : 'Panel Hardware RFID' }}</template>

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

        <!-- ══════════════════════════════
             HEADER
        ══════════════════════════════ -->
        <div class="flex items-start justify-between gap-4 animate-fade-in">
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-2xl">📡</span>
                    <h1 class="font-heading text-xl font-bold text-foreground">
                        {{ lang==='en' ? 'RFID Batch Tracking' : 'Tracking Batch via RFID' }}
                    </h1>
                </div>
                <p class="text-sm text-muted-foreground mt-0.5">
                    {{ lang==='en'
                        ? 'Register RFID tags to mineral bags, track arrivals and departures.'
                        : 'Daftarkan tag RFID ke karung mineral, lacak masuk/keluar gudang.' }}
                </p>
            </div>
            <div class="hidden md:block rounded-xl border border-border bg-card px-4 py-2">
                <p class="text-[10px] text-muted-foreground uppercase tracking-wider">Waktu Sistem</p>
                <p class="font-mono text-xs font-bold text-foreground">{{ current_time }}</p>
            </div>
        </div>

        <!-- ══════════════════════════════
             MIXED CONTENT WARNING BANNER
             Muncul saat site HTTPS + ada ESP32 IP
        ══════════════════════════════ -->
        <Transition name="slide">
            <div v-if="showMixedWarning && !mixedContentDismissed"
                 class="rounded-2xl border-2 border-red-400 dark:border-red-600 p-5 animate-fade-in"
                 style="background:rgba(220,38,38,0.06)">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-xl bg-red-100 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0 text-2xl">🚧</div>
                    <div class="flex-1 space-y-3">
                        <div>
                            <p class="text-sm font-bold text-red-700 dark:text-red-400">
                                ⚠️ Mixed Content — Chrome Memblokir Koneksi ke ESP32
                            </p>
                            <p class="text-xs text-muted-foreground mt-1 leading-relaxed">
                                Halaman ini menggunakan HTTPS, sedangkan ESP32 menggunakan HTTP.
                                Chrome secara default memblokir request HTTP dari halaman HTTPS.
                                <strong>Operator yang berada satu jaringan dengan ESP32</strong> perlu mengizinkan konten tidak aman:
                            </p>
                        </div>

                        <!-- Step-by-step Chrome instructions -->
                        <div class="rounded-xl border border-red-200 dark:border-red-800/40 p-4 space-y-2"
                             style="background:rgba(220,38,38,0.04)">
                            <p class="text-[11px] font-bold text-red-700 dark:text-red-400 uppercase tracking-wider">
                                Langkah Aktifkan di Chrome / Edge:
                            </p>
                            <div class="space-y-1.5">
                                <div v-for="step in chromeSteps" :key="step.num" class="flex items-start gap-2.5">
                                    <span class="w-5 h-5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center flex-shrink-0 mt-0.5">{{ step.num }}</span>
                                    <p class="text-xs text-foreground">{{ step.text }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Shortcut URL Chrome -->
                        <div class="flex items-center gap-3 p-3 rounded-xl border border-border bg-card">
                            <div class="flex-1">
                                <p class="text-[10px] text-muted-foreground uppercase tracking-wider">Atau buka langsung di Chrome</p>
                                <p class="text-xs font-mono text-foreground mt-0.5">chrome://settings/content/insecureContent</p>
                                <p class="text-[10px] text-muted-foreground mt-0.5">Tambahkan <code class="bg-muted px-1 rounded">https://mining-traceability.com</code></p>
                            </div>
                            <button @click="() => {
                                navigator.clipboard?.writeText('chrome://settings/content/insecureContent')
                                showToast('URL disalin ke clipboard', 'info')
                            }" class="flex-shrink-0 px-3 h-8 rounded-xl text-xs font-semibold border border-border hover:bg-muted active:scale-[0.97]">
                                Salin
                            </button>
                        </div>

                        <!-- Alternatif: Simulasi tab -->
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800/40">
                            <span class="text-lg flex-shrink-0">💡</span>
                            <p class="text-xs text-emerald-700 dark:text-emerald-400">
                                <strong>Alternatif tanpa setting Chrome:</strong>
                                Gunakan tab <strong>🎮 Simulasi</strong> — tidak memerlukan koneksi ke ESP32 sama sekali.
                            </p>
                            <button @click="activeTab='simulate'; dismissMixedWarning()"
                                    class="flex-shrink-0 px-3 h-8 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 active:scale-[0.97]">
                                Buka Simulasi
                            </button>
                        </div>
                    </div>
                    <button @click="dismissMixedWarning"
                            class="flex-shrink-0 w-7 h-7 rounded-lg border border-border flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted text-xs">
                        ✕
                    </button>
                </div>
            </div>
        </Transition>

        <!-- ══════════════════════════════
             STATS
        ══════════════════════════════ -->
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div v-for="(item, i) in [
                { label_id:'Batch Belum Ter-tag', label_en:'Untagged Batches', value:stats?.untagged_count, icon:'📦', color:'text-amber-600 dark:text-amber-400' },
                { label_id:'Batch Ter-tag',       label_en:'Tagged Batches',   value:stats?.tagged_count,   icon:'🪪', color:'text-primary' },
                { label_id:'Total Aktivitas RFID',label_en:'RFID Activities',  value:stats?.activity_count, icon:'📊', color:'text-emerald-600 dark:text-emerald-400' },
            ]" :key="item.label_id"
                 class="rounded-2xl border border-border bg-card p-4 animate-fade-in"
                 :style="`animation-delay:${i*50}ms`">
                <div class="flex items-start justify-between mb-2">
                    <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider leading-tight">
                        {{ lang==='en' ? item.label_en : item.label_id }}
                    </p>
                    <span class="text-xl">{{ item.icon }}</span>
                </div>
                <p class="font-heading text-3xl font-bold tabular-nums" :class="item.color">{{ item.value ?? 0 }}</p>
            </div>
        </div>

        <!-- ══════════════════════════════
             TABS
        ══════════════════════════════ -->
        <div class="flex flex-wrap gap-1 p-1 rounded-2xl border border-border bg-muted/30 w-fit animate-fade-in delay-100">
            <button @click="activeTab='simulate'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold flex items-center gap-1.5"
                    :style="activeTab==='simulate' ? 'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:hsl(var(--muted-foreground));'">
                🎮 {{ lang==='en' ? 'Simulation' : 'Simulasi' }}
                <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full text-white bg-emerald-500">
                    {{ lang==='en' ? 'No ESP32' : 'Tanpa ESP32' }}
                </span>
            </button>
            <button @click="activeTab='esp32'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold flex items-center gap-1.5"
                    :style="activeTab==='esp32' ? 'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:hsl(var(--muted-foreground));'">
                📡 ESP32
                <span v-if="espStatus==='online'"     class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"/>
                <span v-else-if="espStatus==='blocked'" class="text-[9px] font-bold px-1 py-0.5 rounded bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400">BLOCKED</span>
                <span v-else-if="espStatus==='offline'" class="w-2 h-2 rounded-full bg-red-500"/>
            </button>
            <button @click="activeTab='tagged'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold"
                    :style="activeTab==='tagged' ? 'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:hsl(var(--muted-foreground));'">
                🪪 {{ lang==='en' ? 'Tagged' : 'Ter-tag' }}
                <span class="ml-1 text-[9px] font-bold px-1.5 py-0.5 rounded-full text-white" style="background:hsl(var(--primary))">{{ stats?.tagged_count ?? 0 }}</span>
            </button>
            <button @click="activeTab='activity'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold"
                    :style="activeTab==='activity' ? 'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:hsl(var(--muted-foreground));'">
                📋 Log
            </button>
        </div>

        <!-- ══════════════════════════════
             TAB: SIMULASI
        ══════════════════════════════ -->
        <div v-show="activeTab==='simulate'" class="space-y-5">

            <!-- Info box -->
            <div class="rounded-2xl border p-4" style="border-color:hsl(var(--primary)/0.2);background:hsl(var(--primary)/0.04)">
                <div class="flex items-start gap-3">
                    <span class="text-xl flex-shrink-0">🎮</span>
                    <div>
                        <p class="text-sm font-bold text-foreground">
                            {{ lang==='en' ? 'Simulation Mode — No Hardware Required' : 'Mode Simulasi — Tidak Butuh Hardware' }}
                        </p>
                        <p class="text-xs text-muted-foreground mt-0.5 leading-relaxed">
                            {{ lang==='en'
                                ? 'Calls the same API endpoints as the physical ESP32. All data is saved identically. Works across different networks.'
                                : 'Memanggil endpoint API yang sama dengan ESP32 fisik. Semua data tersimpan identik. Bisa lintas jaringan.' }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- UID Input -->
            <div class="rounded-2xl border border-border bg-card p-5 space-y-3">
                <h3 class="font-heading text-sm font-bold text-foreground">🪪 UID Tag RFID</h3>
                <div class="flex items-center gap-3">
                    <input v-model="simUid" type="text"
                           placeholder="Contoh: A3B2C1D0"
                           class="flex-1 h-11 px-4 rounded-xl border border-border bg-background text-sm font-mono font-bold text-foreground uppercase outline-none focus:border-primary"
                           @input="simUid = sanitizeUid($event.target.value)"/>
                    <button @click="generateUid"
                            class="flex items-center gap-2 px-4 h-11 rounded-xl text-sm font-bold border border-border bg-card hover:bg-muted active:scale-[0.97] text-muted-foreground flex-shrink-0">
                        🔄 {{ lang==='en' ? 'Generate' : 'Generate UID' }}
                    </button>
                </div>
                <p class="text-[11px] text-muted-foreground">
                    {{ lang==='en'
                        ? 'Register: use a new UID. Login/Logout: use a UID already registered to a batch.'
                        : 'Register: gunakan UID baru. Login/Logout: gunakan UID yang sudah ter-register ke batch.' }}
                </p>
                <div v-if="simUid" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg"
                     style="background:hsl(var(--primary)/0.08)">
                    <span class="text-[10px] text-muted-foreground">UID aktif:</span>
                    <span class="font-mono text-sm font-bold text-amber-600 dark:text-amber-400">{{ simUid }}</span>
                </div>
            </div>

            <!-- 3 Aksi Simulasi -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                <!-- REGISTER -->
                <div class="rounded-2xl border border-violet-200 dark:border-violet-800/40 p-5 space-y-3"
                     style="background:rgba(124,58,237,0.04)">
                    <div class="flex items-center gap-2">
                        <span class="text-xl">🪪</span>
                        <div>
                            <p class="text-xs font-bold text-foreground">Register Tag</p>
                            <p class="text-[10px] text-muted-foreground">Hubungkan UID ke batch</p>
                        </div>
                    </div>
                    <select v-model="simBatchId"
                            class="w-full h-9 px-2.5 rounded-xl border border-border bg-background text-xs text-foreground outline-none focus:border-primary">
                        <option value="" disabled>Pilih batch belum punya tag...</option>
                        <option v-for="b in untagged_batches" :key="b.id" :value="b.id">
                            {{ b.batch_code }} ({{ b.product_code }}) — {{ getStageLabel(b.process_stage) }}
                        </option>
                    </select>
                    <div v-if="untagged_batches.length === 0"
                         class="text-[10px] text-amber-600 dark:text-amber-400 font-semibold">
                        ⚠️ Semua batch sudah ter-tag atau tidak ada batch aktif
                    </div>
                    <button @click="simulateRegister"
                            :disabled="!simBatchId||!simUid||isSimulating"
                            class="w-full h-10 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#7c3aed,#5b21b6)">
                        <svg v-if="isSimulating&&simAction==='register'" class="w-4 h-4 animate-spin inline-block mr-1" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        {{ isSimulating&&simAction==='register' ? 'Menyimpan...' : '▶ Simulasi Register' }}
                    </button>
                </div>

                <!-- LOGIN / CP IN -->
                <div class="rounded-2xl border border-emerald-200 dark:border-emerald-800/40 p-5 space-y-3"
                     style="background:rgba(5,150,105,0.04)">
                    <div class="flex items-center gap-2">
                        <span class="text-xl">📥</span>
                        <div>
                            <p class="text-xs font-bold text-foreground">Login / Checkpoint IN</p>
                            <p class="text-[10px] text-muted-foreground">Karung tiba di gudang</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border bg-muted/30 p-2.5">
                        <p class="text-[10px] text-muted-foreground leading-relaxed">
                            Gunakan UID yang sudah ter-register ke batch. Sistem mencari batch secara otomatis.
                        </p>
                    </div>
                    <button @click="simulateScan('login')"
                            :disabled="!simUid||isSimulating"
                            class="w-full h-10 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#059669,#047857)">
                        <svg v-if="isSimulating&&simAction==='login'" class="w-4 h-4 animate-spin inline-block mr-1" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        {{ isSimulating&&simAction==='login' ? 'Menyimpan...' : '▶ Simulasi Login (Masuk)' }}
                    </button>
                </div>

                <!-- LOGOUT / CP OUT -->
                <div class="rounded-2xl border border-red-200 dark:border-red-800/40 p-5 space-y-3"
                     style="background:rgba(220,38,38,0.04)">
                    <div class="flex items-center gap-2">
                        <span class="text-xl">📤</span>
                        <div>
                            <p class="text-xs font-bold text-foreground">Logout / Checkpoint OUT</p>
                            <p class="text-[10px] text-muted-foreground">Karung keluar dari gudang</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border bg-muted/30 p-2.5">
                        <p class="text-[10px] text-muted-foreground leading-relaxed">
                            Gunakan UID yang sudah melakukan login. Sistem mencatat keberangkatan.
                        </p>
                    </div>
                    <button @click="simulateScan('logout')"
                            :disabled="!simUid||isSimulating"
                            class="w-full h-10 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#dc2626,#b91c1c)">
                        <svg v-if="isSimulating&&simAction==='logout'" class="w-4 h-4 animate-spin inline-block mr-1" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        {{ isSimulating&&simAction==='logout' ? 'Menyimpan...' : '▶ Simulasi Logout (Keluar)' }}
                    </button>
                </div>
            </div>

            <!-- Hasil Simulasi -->
            <Transition name="slide">
                <div v-if="simResult" class="rounded-2xl border p-5"
                     :class="simResult.status==='success' ? 'border-emerald-300 dark:border-emerald-700' : 'border-red-300 dark:border-red-700'"
                     :style="simResult.status==='success' ? 'background:rgba(16,185,129,0.06)' : 'background:rgba(220,38,38,0.06)'">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-2xl">{{ simResult.status==='success' ? '✅' : '❌' }}</span>
                        <div class="flex-1">
                            <p class="text-sm font-bold text-foreground">
                                {{ simResult.status==='success' ? 'Simulasi Berhasil' : 'Simulasi Gagal' }}
                            </p>
                            <p class="text-xs text-muted-foreground">{{ simResult.message }}</p>
                        </div>
                        <span class="text-[10px] font-bold px-2 py-1 rounded-lg"
                              :class="getSimActionStyle(simResult.action).bg + ' ' + getSimActionStyle(simResult.action).text">
                            {{ getSimActionStyle(simResult.action).label }}
                        </span>
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

        <!-- ══════════════════════════════
             TAB: ESP32 CONTROL
        ══════════════════════════════ -->
        <div v-show="activeTab==='esp32'" class="space-y-5">

            <!-- Device Setup -->
            <div class="rounded-2xl border border-border bg-card p-5">
                <div class="flex items-center gap-2 mb-3">
                    <span class="text-base">🔌</span>
                    <h3 class="font-heading text-sm font-bold text-foreground">Perangkat ESP32</h3>
                    <div class="ml-auto flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full"
                             :class="{
                                 'bg-emerald-500 animate-pulse': espStatus==='online',
                                 'bg-red-500': espStatus==='offline'||espStatus==='blocked',
                                 'bg-gray-400': espStatus==='unknown',
                             }"/>
                        <span class="text-xs font-mono font-bold"
                              :class="{
                                  'text-emerald-600 dark:text-emerald-400': espStatus==='online',
                                  'text-red-500': espStatus==='offline'||espStatus==='blocked',
                                  'text-muted-foreground': espStatus==='unknown',
                              }">
                            {{ espStatus==='blocked' ? 'BLOCKED' : espStatus==='online' ? 'ONLINE' : espStatus==='offline' ? 'OFFLINE' : '—' }}
                        </span>
                    </div>
                </div>
                <div class="flex gap-3">
                    <input v-model="esp32IpInput" type="text"
                           placeholder="Contoh: 192.168.1.50"
                           class="flex-1 h-10 px-3 rounded-xl border border-border bg-background text-sm font-mono text-foreground outline-none focus:border-primary"/>
                    <button @click="saveEspIp" :disabled="espIpSaving||!esp32IpInput"
                            class="flex items-center gap-2 px-4 h-10 rounded-xl text-sm font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                            style="background:linear-gradient(135deg,hsl(var(--primary)),hsl(207 30% 28%))">
                        {{ espIpSaving ? 'Menyimpan...' : 'Simpan & Hubungkan' }}
                    </button>
                </div>
                <p class="text-[10px] text-muted-foreground mt-2">
                    ⚠️ Browser dan ESP32 harus berada di jaringan WiFi yang sama.
                    Jika muncul error "BLOCKED", ikuti instruksi Mixed Content di banner merah.
                </p>
            </div>

            <!-- Pending Banner -->
            <Transition name="slide">
                <div v-if="isPending" class="rounded-2xl border-2 border-amber-400 dark:border-amber-600 p-5"
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

            <!-- LCD + Mode Control -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

                <!-- LCD Preview -->
                <div class="rounded-2xl border border-border bg-card p-5 flex flex-col items-center gap-3">
                    <p class="text-[10px] font-mono font-semibold text-muted-foreground uppercase tracking-widest">◼ LCD ESP32 LIVE PREVIEW</p>
                    <div class="rounded-xl overflow-hidden w-full max-w-[300px]"
                         style="background:#0d1a0f;border:2px solid #1a2e1c;padding:16px 18px;box-shadow:0 0 0 3px #080e09,0 0 0 5px #1a2e1c">
                        <div class="font-mono text-lg leading-none mb-1.5"
                             style="color:#1aff6e;text-shadow:0 0 8px rgba(26,255,110,0.6);letter-spacing:0.1em;white-space:pre">{{ lcdRow0 }}</div>
                        <div class="font-mono text-lg leading-none"
                             :style="activeMode==='idle'?'color:#0d7a37':'color:#1aff6e;text-shadow:0 0 8px rgba(26,255,110,0.5)'"
                             style="letter-spacing:0.1em;white-space:pre">{{ lcdRow1 }}</div>
                    </div>
                    <div v-if="espState?.last_uid" class="w-full rounded-xl border border-border bg-muted/30 p-3 text-center">
                        <p class="text-[10px] text-muted-foreground uppercase tracking-wider">Last UID Scanned</p>
                        <p class="font-mono text-base font-bold text-amber-600 dark:text-amber-400">{{ espState.last_uid }}</p>
                        <p class="text-[10px] text-muted-foreground mt-0.5">{{ espState.last_message }}</p>
                    </div>
                    <!-- Blocked warning di LCD tab -->
                    <div v-if="espStatus==='blocked'"
                         class="w-full p-3 rounded-xl border border-red-200 dark:border-red-800/40 bg-red-50 dark:bg-red-950/10 text-center">
                        <p class="text-xs text-red-600 dark:text-red-400 font-semibold">🚧 Koneksi diblokir Mixed Content</p>
                        <button @click="showMixedWarning=true; mixedContentDismissed=false"
                                class="mt-1 text-[10px] font-bold text-red-600 dark:text-red-400 underline">
                            Lihat instruksi
                        </button>
                    </div>
                </div>

                <!-- Mode Control -->
                <div class="rounded-2xl border border-border bg-card p-5 space-y-3">
                    <h3 class="font-heading text-sm font-bold text-foreground">🎛️ Kontrol Mode ESP32</h3>

                    <!-- Register -->
                    <div v-if="canManage" class="space-y-2 p-3 rounded-xl border"
                         :class="activeMode==='register'?'border-violet-400 bg-violet-50 dark:bg-violet-950/10':'border-border bg-muted/20'">
                        <p class="text-xs font-bold text-foreground">🪪 Mode REGISTER</p>
                        <div class="flex gap-2">
                            <select v-model="selectedBatchId"
                                    class="flex-1 h-9 px-2.5 rounded-xl border border-border bg-background text-xs text-foreground outline-none">
                                <option value="" disabled>Pilih batch belum punya tag...</option>
                                <option v-for="b in untagged_batches" :key="b.id" :value="b.id">
                                    {{ b.batch_code }} ({{ b.product_code }})
                                </option>
                            </select>
                            <button @click="startRegisterSession"
                                    :disabled="!selectedBatchId||isSavingPending||isPending||espStatus!=='online'"
                                    class="px-3 h-9 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                                    style="background:linear-gradient(135deg,#7c3aed,#5b21b6)">
                                🪪 Register
                            </button>
                        </div>
                    </div>

                    <!-- Login -->
                    <div class="flex items-center justify-between p-3 rounded-xl border"
                         :class="activeMode==='login'?'border-emerald-400 bg-emerald-50 dark:bg-emerald-950/10':'border-border bg-muted/20'">
                        <div>
                            <p class="text-xs font-bold text-foreground">📥 Mode LOGIN (CP IN)</p>
                            <p class="text-[11px] text-muted-foreground">Karung tiba di gudang</p>
                        </div>
                        <button @click="sendModeToEsp32('login')" :disabled="sendingMode==='login'||espStatus!=='online'"
                                class="px-3 h-9 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                                style="background:linear-gradient(135deg,#059669,#047857)">
                            → Aktifkan
                        </button>
                    </div>

                    <!-- Logout -->
                    <div class="flex items-center justify-between p-3 rounded-xl border"
                         :class="activeMode==='logout'?'border-red-400 bg-red-50 dark:bg-red-950/10':'border-border bg-muted/20'">
                        <div>
                            <p class="text-xs font-bold text-foreground">📤 Mode LOGOUT (CP OUT)</p>
                            <p class="text-[11px] text-muted-foreground">Karung keluar gudang</p>
                        </div>
                        <button @click="sendModeToEsp32('logout')" :disabled="sendingMode==='logout'||espStatus!=='online'"
                                class="px-3 h-9 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                                style="background:linear-gradient(135deg,#dc2626,#b91c1c)">
                            → Aktifkan
                        </button>
                    </div>

                    <!-- Idle -->
                    <button @click="sendModeToEsp32('idle')" :disabled="espStatus!=='online'"
                            class="w-full h-10 rounded-xl text-xs font-bold border border-border text-muted-foreground hover:bg-muted disabled:opacity-40 active:scale-[0.97]">
                        ⏹ Stop — Kembali Idle
                    </button>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════
             TAB: TAGGED BATCHES
        ══════════════════════════════ -->
        <div v-show="activeTab==='tagged'">
            <EmptyState v-if="!tagged_batches.length"
                icon="🪪"
                :title="lang==='en'?'No tagged batches yet':'Belum ada batch yang ter-tag'"
                :description="lang==='en'?'Use Simulation or Register mode to tag a batch.':'Gunakan Simulasi atau mode Register untuk tag batch.'"
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
                            <tr v-for="(batch, i) in tagged_batches" :key="batch.id" class="group hover:bg-muted/20">
                                <td class="px-5 py-3.5">
                                    <Link :href="`/company/batches/${batch.id}`"
                                          class="font-mono text-xs font-bold text-foreground hover:text-primary">{{ batch.batch_code }}</Link>
                                    <p class="text-[10px] text-muted-foreground">{{ batch.product_code }}</p>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="font-mono text-xs font-bold text-amber-600 dark:text-amber-400">{{ batch.rfid_tag }}</span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-muted text-muted-foreground">{{ getStageLabel(batch.process_stage) }}</span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <template v-if="batch.last_checkpoint">
                                        <p class="text-[11px] text-muted-foreground">{{ batch.last_checkpoint.stage?.replace(/_/g,' ') }}</p>
                                        <p class="text-[10px] text-muted-foreground">{{ batch.last_checkpoint.recorded_at_diff }}</p>
                                    </template>
                                    <span v-else class="text-[10px] text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <button v-if="canAdmin" @click="removeTag(batch)" :disabled="removingTagId===batch.id"
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

        <!-- ══════════════════════════════
             TAB: ACTIVITY LOG
        ══════════════════════════════ -->
        <div v-show="activeTab==='activity'">
            <EmptyState v-if="!recent_activity.length"
                icon="📋"
                :title="lang==='en'?'No RFID activity yet':'Belum ada aktivitas RFID'"
                :description="lang==='en'?'Scan events will appear here.':'Event scan akan muncul di sini.'"
            />
            <div v-else class="rounded-2xl border border-border bg-card overflow-hidden">
                <div class="divide-y divide-border">
                    <div v-for="(act, i) in recent_activity" :key="act.id"
                         class="flex items-start gap-3 px-5 py-3.5 hover:bg-muted/20">
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
                                <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-muted text-muted-foreground">{{ act.product_code }}</span>
                            </div>
                            <p class="text-[10px] text-muted-foreground mt-0.5">
                                UID: <span class="font-mono text-amber-600 dark:text-amber-400">{{ act.rfid_uid }}</span> · {{ act.weight_kg }} kg
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
@keyframes fade-in { from { opacity:0; transform:translateY(5px); } to { opacity:1; transform:translateY(0); } }
.animate-fade-in { animation: fade-in 0.28s ease-out both; }
.delay-100 { animation-delay: 100ms; }

.toast-enter-active { transition: all 0.2s ease; }
.toast-leave-active { transition: all 0.15s ease; }
.toast-enter-from, .toast-leave-to { opacity:0; transform:translateX(-50%) translateY(20px); }

.slide-enter-active { transition: all 0.25s ease-out; }
.slide-leave-active { transition: all 0.2s ease-in; }
.slide-enter-from, .slide-leave-to { opacity:0; transform:translateY(-8px); }
</style>