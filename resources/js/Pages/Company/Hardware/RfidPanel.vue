<script setup>
/**
 * RfidPanel.vue — Panel RFID Batch Tracking (Mode Manual)
 *
 * MODE MANUAL — RFID hanya merekam audit trail:
 *   Scan IN  → catat log bahwa batch terdeteksi memasuki area
 *   Scan OUT → catat log bahwa batch terdeteksi meninggalkan area
 *
 * Perpindahan stage resmi TIDAK dilakukan oleh RFID scan.
 * Operator menggunakan web panel untuk konfirmasi penerimaan/pengiriman.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Head, Link, router }                     from '@inertiajs/vue3'
import AppLayout                                  from '@/Layouts/AppLayout.vue'
import EmptyState                                 from '@/components/shared/EmptyState.vue'
import { useI18n }                                from '@/composables/core/useI18n.js'

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

// ─── Permissions ──────────────────────────────────────────────────────────────
const canManage = computed(() => ['company_admin','operator_lab_station','operator_wet',
    'operator_dry','operator_warehouse','operator_lab_ltj'].includes(props.user_role))
const canAdmin  = computed(() => props.user_role === 'company_admin')

// ─── Tabs ─────────────────────────────────────────────────────────────────────
const activeTab = ref('esp32')

// ─── Stage config ─────────────────────────────────────────────────────────────
const availableStages = [
    { stage: 'receiving',     label_id: 'Receiving Area',   label_en: 'Receiving Area'   },
    { stage: 'wet_process',   label_id: 'Wet Process',      label_en: 'Wet Process'      },
    { stage: 'dry_process',   label_id: 'Dry Process',      label_en: 'Dry Process'      },
    { stage: 'warehouse',     label_id: 'LTJ Warehouse',    label_en: 'LTJ Warehouse'    },
    { stage: 'project_plant', label_id: 'Lab Analisis LTJ', label_en: 'LTJ Lab Analysis' },
]

const chromeSteps = [
    { num:'1', text:'Klik ikon kunci di address bar' },
    { num:'2', text:'Pengaturan situs (Site settings)' },
    { num:'3', text:'Konten tidak aman → Izinkan (Allow)' },
    { num:'4', text:'Refresh halaman (Ctrl+R)' },
]

// ─── Mixed Content warning ─────────────────────────────────────────────────────
const isHttps               = ref(false)
const showMixedWarning      = ref(false)
const mixedContentDismissed = ref(false)

onMounted(() => {
    isHttps.value = window.location.protocol === 'https:'
    const dismissed = localStorage.getItem('rfid_mixed_warning_dismissed') === '1'
    mixedContentDismissed.value = dismissed
    if (isHttps.value && props.esp32_ip && !dismissed) showMixedWarning.value = true
})

function dismissMixedWarning() {
    mixedContentDismissed.value = true
    showMixedWarning.value = false
    localStorage.setItem('rfid_mixed_warning_dismissed', '1')
}

// ─── ESP32 Device ─────────────────────────────────────────────────────────────
const esp32IpInput = ref(props.esp32_ip ?? localStorage.getItem('esp32_ip_local') ?? '')
const espIpSaving  = ref(false)
const espStatus    = ref('unknown')

async function saveEspIp() {
    if (!esp32IpInput.value.trim()) { showToast('Masukkan IP ESP32.', 'error'); return }
    espIpSaving.value = true
    try {
        const res = await apiFetch('/api/v1/company/rfid/device', 'POST', {
            esp32_ip: esp32IpInput.value.trim()
        })
        if (res.success) {
            localStorage.setItem('esp32_ip_local', esp32IpInput.value.trim())
            showToast('IP ESP32 disimpan', 'success')
            if (isHttps.value && !mixedContentDismissed.value) showMixedWarning.value = true
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

// ─── ESP32 Polling & LCD ──────────────────────────────────────────────────────
const espState   = ref(null)
const lcdRow0    = ref('Sistem RFID    ')
const lcdRow1    = ref('Mode: Manual...')
const espMode    = ref('idle')

let espPollTimer = null
let lcdAnimTimer = null
let lcdAnimIdx   = 0
const LCD_DOTS   = ['', '.', '..', '...']

function startEspPolling() {
    if (espPollTimer) clearInterval(espPollTimer)
    pollEsp32(); espPollTimer = setInterval(pollEsp32, 1000)
}

async function pollEsp32() {
    const ip = esp32IpInput.value.trim()
    if (!ip) return
    const baseUrl = ip.startsWith('http') ? ip : `http://${ip}`
    try {
        const res = await fetch(`${baseUrl}/status`, { signal: AbortSignal.timeout(2500) })
        if (!res.ok) throw new Error()
        const d = await res.json()
        espStatus.value = 'online'; espState.value = d; espMode.value = d.mode ?? 'idle'
        updateLcd(d)
    } catch (err) {
        espStatus.value = (err?.message?.includes('Failed to fetch') && isHttps.value)
            ? 'blocked' : 'offline'
    }
}

function updateLcd(d) {
    if (lcdAnimTimer) { clearInterval(lcdAnimTimer); lcdAnimTimer = null }
    const pad = (s, n = 16) => (s + ' '.repeat(n)).substring(0, n)

    if (d.mode === 'idle' || !d.mode) {
        lcdRow0.value = pad('Sistem RFID')
        lcdRow1.value = pad('Mode Manual')
        return
    }

    // Label LCD untuk mode baru: scan_in / scan_out / register
    const modeLabel = {
        register: '[ REGISTER ]',
        scan_in:  '[ SCAN  IN ]',
        scan_out: '[ SCAN OUT ]',
    }

    if (d.status === 'waiting') {
        lcdRow0.value = pad(modeLabel[d.mode] || d.mode)
        lcdAnimIdx = 0
        const animate = () => { lcdRow1.value = pad('Tempel Tag' + LCD_DOTS[lcdAnimIdx++ % 4]) }
        animate(); lcdAnimTimer = setInterval(animate, 600)
    } else if (d.status === 'success') {
        const ok = {
            register: 'Tag Terdaftar!',
            scan_in:  'Scan IN OK!',
            scan_out: 'Scan OUT OK!',
        }
        lcdRow0.value = pad(ok[d.mode] || 'Berhasil!')
        lcdRow1.value = pad(d.last_name || '')
    } else if (d.status === 'failed') {
        const fail = {
            register: 'Daftar Gagal!',
            scan_in:  'Scan IN Gagal!',
            scan_out: 'Scan OUT Gagal',
        }
        lcdRow0.value = pad(fail[d.mode] || 'Gagal!')
        lcdRow1.value = pad(d.last_message || '')
    }
}

// ─── Active Mode ──────────────────────────────────────────────────────────────
// Mode: 'scan_in' | 'scan_out' | 'register' | 'idle'
// (bukan 'login'/'logout' lagi — sesuai ApiRfidController)
const activeModeInfo = ref(null)
const isSavingMode   = ref(null)
const scanInStage    = ref('wet_process')
const scanOutStage   = ref('wet_process')

async function activateMode(mode, stage) {
    isSavingMode.value = mode
    try {
        const res = await apiFetch('/api/v1/company/rfid/set-mode', 'POST', {
            mode,
            stage: mode === 'idle' ? null : stage,
        })
        if (!res.success) { showToast(res.message ?? 'Gagal set mode.', 'error'); return }
        activeModeInfo.value = mode === 'idle' ? null : res.data
        showToast(res.message, 'success')
        // Kirim mode ke ESP32 jika online
        if (esp32IpInput.value.trim() && espStatus.value === 'online') {
            await sendModeToEsp32(mode, stage)
        }
    } catch (e) {
        showToast('Error: ' + e.message, 'error')
    } finally {
        isSavingMode.value = null
    }
}

async function sendModeToEsp32(mode, stage = null) {
    const ip = esp32IpInput.value.trim()
    if (!ip) return false
    const baseUrl = ip.startsWith('http') ? ip : `http://${ip}`
    try {
        const res = await fetch(`${baseUrl}/set-mode`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode, stage }),
            signal: AbortSignal.timeout(4000),
        })
        if (res.ok) { showToast(`ESP32 → mode ${mode.toUpperCase()} aktif`, 'success'); return true }
        showToast('ESP32 menolak perintah.', 'error'); return false
    } catch (err) {
        if (isHttps.value && err?.message?.includes('Failed to fetch')) {
            showMixedWarning.value = true
            showToast('Browser blokir HTTP ke ESP32. Lihat instruksi.', 'error')
        } else {
            showToast('ESP32 tidak merespons.', 'error')
        }
        return false
    }
}

// ─── Pending Batch (Register) ─────────────────────────────────────────────────
const selectedBatchId  = ref('')
const isPending        = ref(!!props.pending_batch)
const pendingData      = ref(props.pending_batch)
const pendingCountdown = ref(props.pending_batch ? 90 : 0)
const isSavingPending  = ref(false)
const isCancelling     = ref(false)
let   pendingTimer     = null

if (props.pending_batch) startPendingCountdown(90, props.pending_batch)

function startPendingCountdown(seconds, data) {
    pendingData.value = data; isPending.value = true; pendingCountdown.value = seconds
    if (pendingTimer) clearInterval(pendingTimer)
    pendingTimer = setInterval(() => {
        pendingCountdown.value--
        if (pendingCountdown.value <= 0) {
            clearInterval(pendingTimer); isPending.value = false; pendingData.value = null
            router.reload({ only: ['pending_batch','untagged_batches','stats'] })
        }
    }, 1000)
}

const countdownPct = computed(() => Math.round((pendingCountdown.value / 90) * 100))

async function startRegisterSession() {
    if (!selectedBatchId.value) { showToast('Pilih batch terlebih dahulu.', 'error'); return }
    isSavingPending.value = true
    try {
        const res = await apiFetch('/api/v1/company/rfid/pending-batch', 'POST', {
            batch_id: parseInt(selectedBatchId.value)
        })
        if (!res.success) { showToast(res.message ?? 'Gagal.', 'error'); return }
        startPendingCountdown(90, res.data)
        showToast(`Sesi registrasi aktif: ${res.data.batch_code}`, 'success')
        if (esp32IpInput.value.trim() && espStatus.value === 'online') {
            await sendModeToEsp32('register', null)
        }
    } catch (e) {
        showToast('Error: ' + e.message, 'error')
    } finally {
        isSavingPending.value = false
    }
}

async function cancelPendingSession() {
    isCancelling.value = true; clearInterval(pendingTimer)
    try {
        await apiFetch('/api/v1/company/rfid/pending-batch', 'DELETE')
        isPending.value = false; pendingData.value = null; pendingCountdown.value = 0
        showToast('Sesi registrasi dibatalkan.', 'info')
        if (esp32IpInput.value.trim() && espStatus.value === 'online') {
            await sendModeToEsp32('idle', null)
        }
        router.reload({ only: ['pending_batch','untagged_batches','stats'] })
    } finally {
        isCancelling.value = false
    }
}

// ─── Simulation ───────────────────────────────────────────────────────────────
const simUid          = ref('')
const simBatchId      = ref('')
const simResult       = ref(null)
const isSimulating    = ref(false)
const simAction       = ref(null)
const simScanInStage  = ref('wet_process')
const simScanOutStage = ref('wet_process')

function sanitizeUid(uid) { return uid.replace(/[\s:\-\.]/g, '').toUpperCase() }
function generateUid() {
    simUid.value = Array.from({ length: 4 }, () =>
        Math.floor(Math.random() * 256).toString(16).padStart(2, '0')
    ).join('').toUpperCase()
}

async function simulateRegister() {
    if (!simBatchId.value || !simUid.value) return
    isSimulating.value = true; simAction.value = 'register'; simResult.value = null
    try {
        const pendingRes = await apiFetch('/api/v1/company/rfid/pending-batch', 'POST', {
            batch_id: parseInt(simBatchId.value)
        })
        if (!pendingRes.success) { showToast(pendingRes.message ?? 'Gagal.', 'error'); return }

        const scanRes = await fetch('/api/rfid/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ uid: sanitizeUid(simUid.value) }),
        })
        const data = await scanRes.json()
        simResult.value = { ...data, action: 'register', uid: sanitizeUid(simUid.value) }
        showToast(data.message, data.status === 'success' ? 'success' : 'error')
        if (data.status === 'success') {
            router.reload({ only: ['tagged_batches','untagged_batches','recent_activity','stats'] })
        }
    } catch (e) { showToast('Error: ' + e.message, 'error') }
    finally { isSimulating.value = false }
}

// Simulate Scan IN / Scan OUT — mode baru, hanya audit trail
async function simulateScan(action, stage) {
    if (!simUid.value || !stage) return
    isSimulating.value = true; simAction.value = action; simResult.value = null
    try {
        // Set mode di cache dulu
        await apiFetch('/api/v1/company/rfid/set-mode', 'POST', { mode: action, stage })

        // Kirim scan ke endpoint ESP32 (endpoint sama: login→scan_in, logout→scan_out)
        const endpoint = action === 'scan_in' ? '/api/rfid/login' : '/api/rfid/logout'
        const res = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ uid: sanitizeUid(simUid.value), stage }),
        })
        const data = await res.json()
        simResult.value = { ...data, action, uid: sanitizeUid(simUid.value), stage }
        showToast(data.message, data.status === 'success' ? 'success' : 'error')
        if (data.status === 'success') {
            router.reload({ only: ['tagged_batches','recent_activity','stats','untagged_batches'] })
        }
    } catch (e) { showToast('Error: ' + e.message, 'error') }
    finally { isSimulating.value = false }
}

// ─── Remove Tag ───────────────────────────────────────────────────────────────
const removingTagId = ref(null)

async function removeTag(batch) {
    if (!confirm(`Hapus tag UID ${batch.rfid_tag} dari batch ${batch.batch_code}?`)) return
    removingTagId.value = batch.id
    try {
        const res = await apiFetch(`/api/v1/company/rfid/batches/${batch.id}/tag`, 'DELETE')
        showToast(res.message, res.success ? 'success' : 'error')
        if (res.success) router.reload({ only: ['tagged_batches','untagged_batches','stats'] })
    } catch { showToast('Gagal menghapus tag.', 'error') }
    finally { removingTagId.value = null }
}

// ─── Toast ────────────────────────────────────────────────────────────────────
const toastMsg  = ref(''); const toastType = ref('info'); const toastVisible = ref(false)
let toastTimer  = null

function showToast(msg, type = 'info') {
    toastMsg.value = msg; toastType.value = type; toastVisible.value = true
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => { toastVisible.value = false }, 4500)
}

// ─── API Helper ───────────────────────────────────────────────────────────────
async function apiFetch(url, method = 'GET', body = null) {
    const opts = {
        method, credentials: 'include',
        headers: {
            'Content-Type': 'application/json', 'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            'X-Requested-With': 'XMLHttpRequest',
        },
    }
    if (body) opts.body = JSON.stringify(body)
    const res = await fetch(url, opts)
    if (!res.ok && res.status === 401) {
        showToast('Sesi habis. Refresh halaman.', 'error'); throw new Error('Unauthenticated')
    }
    return res.json()
}

// ─── Display Helpers ──────────────────────────────────────────────────────────
function getStageName(stage) {
    const found = availableStages.find(s => s.stage === stage)
    return found ? (lang.value === 'en' ? found.label_en : found.label_id)
        : (stage?.replace(/_/g, ' ') ?? '—')
}

// Badge untuk activity log — sesuai nama action baru (rfid_scan_in / rfid_scan_out)
function getActionBadge(action) {
    const m = {
        rfid_register:  { label: 'Register', cls: 'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300' },
        rfid_scan_in:   { label: 'Scan IN',  cls: 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300' },
        rfid_scan_out:  { label: 'Scan OUT', cls: 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400' },
        // Legacy aliases (data lama di DB)
        rfid_checkpoint_in:  { label: 'Scan IN',  cls: 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300' },
        rfid_checkpoint_out: { label: 'Scan OUT', cls: 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400' },
    }
    return m[action] ?? { label: action?.split('_').pop() ?? '?', cls: 'bg-muted text-muted-foreground' }
}

// Badge untuk hasil simulasi
function getSimStyle(action) {
    const m = {
        register: { label: 'REGISTER',  cls: 'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300' },
        scan_in:  { label: 'SCAN IN',   cls: 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300' },
        scan_out: { label: 'SCAN OUT',  cls: 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400' },
    }
    return m[action] ?? { label: action ?? '?', cls: 'bg-muted text-muted-foreground' }
}

// Active mode badge config
const activeModeStyle = computed(() => {
    const m = activeModeInfo.value?.mode
    if (m === 'scan_in')  return { dot: 'bg-emerald-500', border: 'border-emerald-400 bg-emerald-50 dark:bg-emerald-950/20 text-emerald-700 dark:text-emerald-400' }
    if (m === 'scan_out') return { dot: 'bg-red-500',     border: 'border-red-400 bg-red-50 dark:bg-red-950/20 text-red-600 dark:text-red-400' }
    if (m === 'register') return { dot: 'bg-violet-500',  border: 'border-violet-400 bg-violet-50 dark:bg-violet-950/20 text-violet-700 dark:text-violet-400' }
    return { dot: 'bg-gray-400', border: 'border-border bg-muted text-muted-foreground' }
})

const activeModeLabel = computed(() => {
    const m = activeModeInfo.value?.mode
    if (m === 'scan_in')  return `SCAN IN · ${activeModeInfo.value?.label ?? ''}`
    if (m === 'scan_out') return `SCAN OUT · ${activeModeInfo.value?.label ?? ''}`
    if (m === 'register') return `REGISTER AKTIF`
    return ''
})

// ─── Cleanup ──────────────────────────────────────────────────────────────────
onUnmounted(() => {
    clearInterval(espPollTimer); clearInterval(lcdAnimTimer)
    clearInterval(pendingTimer); clearTimeout(toastTimer)
})

if (props.esp32_ip) { esp32IpInput.value = props.esp32_ip; startEspPolling() }
</script>

<template>
<AppLayout>
    <Head :title="lang==='en' ? 'RFID Panel' : 'Panel RFID'"/>
    <template #header>{{ lang==='en' ? 'RFID Batch Tracking' : 'Tracking Batch RFID' }}</template>

    <!-- Toast -->
    <Transition name="toast">
        <div v-if="toastVisible"
             class="fixed bottom-5 left-1/2 -translate-x-1/2 z-50 px-5 py-2.5 rounded-xl border text-sm font-semibold shadow-xl whitespace-nowrap"
             :class="{
                 'bg-emerald-50 dark:bg-emerald-950/90 border-emerald-400 text-emerald-700 dark:text-emerald-300': toastType==='success',
                 'bg-red-50 dark:bg-red-950/90 border-red-400 text-red-700 dark:text-red-300': toastType==='error',
                 'bg-amber-50 dark:bg-amber-950/90 border-amber-400 text-amber-700 dark:text-amber-300': toastType==='warning',
                 'bg-card border-border text-foreground': toastType==='info',
             }">{{ toastMsg }}</div>
    </Transition>

    <div class="space-y-4">

        <!-- ── Header ───────────────────────────────────────────────────── -->
        <div class="flex items-start justify-between gap-4 animate-fade-in">
            <div>
                <h1 class="font-heading text-xl font-bold text-foreground flex items-center gap-2">
                    <span>📡</span>
                    {{ lang==='en' ? 'RFID Batch Tracking' : 'Tracking Batch RFID' }}
                </h1>
                <p class="text-xs text-muted-foreground mt-0.5">{{ current_time }}</p>
            </div>
            <!-- Active Mode Badge -->
            <div v-if="activeModeInfo && activeModeInfo.mode !== 'idle'"
                 class="flex items-center gap-2 px-3 py-1.5 rounded-xl border font-semibold text-xs"
                 :class="activeModeStyle.border">
                <div class="w-1.5 h-1.5 rounded-full animate-pulse" :class="activeModeStyle.dot"/>
                {{ activeModeLabel }}
                <button @click="activateMode('idle', null)" class="ml-1 opacity-50 hover:opacity-100 text-xs font-bold">✕</button>
            </div>
        </div>

        <!-- ── Manual Mode Info Banner ──────────────────────────────────── -->
        <div class="rounded-xl border border-primary/20 p-4 animate-fade-in"
             style="background:hsl(var(--primary)/0.04)">
            <div class="flex items-start gap-3">
                <span class="text-lg flex-shrink-0 mt-0.5">🏭</span>
                <div class="flex-1">
                    <p class="text-xs font-bold text-foreground mb-1">
                        {{ lang==='en' ? 'Manual Confirmation Mode (Industrial Standard)' : 'Mode Konfirmasi Manual (Standar Industri)' }}
                    </p>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ lang==='en'
                            ? 'RFID scan only records an audit trail log. Stage transition must be confirmed manually by the operator via the web panel (Receive / Send buttons). This ensures physical verification by the responsible operator.'
                            : 'Scan RFID hanya merekam log audit trail. Perpindahan stage harus dikonfirmasi manual oleh operator melalui web panel (tombol Terima / Kirim). Ini memastikan verifikasi fisik oleh operator yang bertanggung jawab.' }}
                    </p>
                    <div class="flex items-center gap-4 mt-2 flex-wrap">
                        <div class="flex items-center gap-1.5 text-[10px] font-semibold text-muted-foreground">
                            <div class="w-3 h-3 rounded-full bg-emerald-500 flex items-center justify-center">
                                <svg class="w-2 h-2 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            {{ lang==='en' ? 'Scan IN → log recorded' : 'Scan IN → log tercatat' }}
                        </div>
                        <div class="flex items-center gap-1.5 text-[10px] font-semibold text-muted-foreground">
                            <div class="w-3 h-3 rounded-full bg-emerald-500 flex items-center justify-center">
                                <svg class="w-2 h-2 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            {{ lang==='en' ? 'Scan OUT → log recorded' : 'Scan OUT → log tercatat' }}
                        </div>
                        <div class="flex items-center gap-1.5 text-[10px] font-semibold text-amber-600 dark:text-amber-400">
                            <span>👆</span>
                            {{ lang==='en'
                                ? 'Stage move → operator confirms via web'
                                : 'Pindah stage → operator konfirmasi di web' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Mixed Content Warning ─────────────────────────────────────── -->
        <Transition name="slide">
            <div v-if="showMixedWarning && !mixedContentDismissed"
                 class="rounded-xl border border-amber-300 dark:border-amber-700/50 p-4"
                 style="background:rgba(245,158,11,0.05)">
                <div class="flex items-start gap-3">
                    <span class="text-base flex-shrink-0 mt-0.5">⚠️</span>
                    <div class="flex-1">
                        <p class="text-xs font-bold text-amber-700 dark:text-amber-400 mb-2">
                            Mixed Content — Chrome memblokir koneksi HTTP ke ESP32 dari halaman HTTPS
                        </p>
                        <div class="flex flex-wrap gap-3">
                            <div v-for="step in chromeSteps" :key="step.num" class="flex items-center gap-1.5">
                                <span class="w-4 h-4 rounded-full bg-amber-500 text-white text-[9px] font-bold flex items-center justify-center flex-shrink-0">{{ step.num }}</span>
                                <span class="text-[11px] text-foreground">{{ step.text }}</span>
                            </div>
                        </div>
                    </div>
                    <button @click="dismissMixedWarning"
                            class="flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center text-muted-foreground hover:bg-muted text-xs">✕</button>
                </div>
            </div>
        </Transition>

        <!-- ── Stats ─────────────────────────────────────────────────────── -->
        <div class="grid grid-cols-3 gap-3 animate-fade-in">
            <div v-for="item in [
                { label: lang==='en'?'Untagged':'Belum Ter-tag', value:stats?.untagged_count, color:'text-amber-600 dark:text-amber-400', icon:'🏷' },
                { label: lang==='en'?'Tagged':'Ter-tag',         value:stats?.tagged_count,   color:'text-foreground',                   icon:'🪪' },
                { label: lang==='en'?'Scan Events':'Total Scan', value:stats?.activity_count, color:'text-emerald-600 dark:text-emerald-400', icon:'📡' },
            ]" :key="item.label"
                 class="rounded-xl border border-border bg-card px-4 py-3">
                <div class="flex items-center gap-1.5 mb-1">
                    <span class="text-sm">{{ item.icon }}</span>
                    <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">{{ item.label }}</p>
                </div>
                <p class="font-heading text-2xl font-bold tabular-nums" :class="item.color">{{ item.value ?? 0 }}</p>
            </div>
        </div>

        <!-- ── Tabs ──────────────────────────────────────────────────────── -->
        <div class="flex gap-1 p-1 rounded-xl border border-border bg-muted/30 w-fit animate-fade-in">
            <button @click="activeTab='esp32'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5"
                    :style="activeTab==='esp32'?'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 2px rgba(0,0,0,0.08);':'color:hsl(var(--muted-foreground));'">
                📡 ESP32
                <span v-if="espStatus==='online'"      class="w-1.5 h-1.5 rounded-full bg-emerald-500"/>
                <span v-else-if="espStatus==='blocked'" class="text-[9px] px-1 rounded bg-red-100 dark:bg-red-900/30 text-red-600">BLOCKED</span>
                <span v-else-if="espStatus==='offline'" class="w-1.5 h-1.5 rounded-full bg-red-500"/>
            </button>
            <button @click="activeTab='simulate'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold"
                    :style="activeTab==='simulate'?'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 2px rgba(0,0,0,0.08);':'color:hsl(var(--muted-foreground));'">
                🎮 {{ lang==='en' ? 'Simulate' : 'Simulasi' }}
            </button>
            <button @click="activeTab='tagged'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1"
                    :style="activeTab==='tagged'?'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 2px rgba(0,0,0,0.08);':'color:hsl(var(--muted-foreground));'">
                🪪 {{ lang==='en' ? 'Tagged' : 'Ter-tag' }}
                <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full text-white" style="background:hsl(var(--primary))">{{ stats?.tagged_count ?? 0 }}</span>
            </button>
            <button @click="activeTab='activity'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold"
                    :style="activeTab==='activity'?'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 2px rgba(0,0,0,0.08);':'color:hsl(var(--muted-foreground));'">
                📋 {{ lang==='en' ? 'Audit Log' : 'Log Audit' }}
            </button>
        </div>

        <!-- ══════════════════════════════════════════════
             TAB: ESP32 CONTROL
        ══════════════════════════════════════════════ -->
        <div v-show="activeTab==='esp32'" class="space-y-4 animate-fade-in">
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">

                <!-- Mode Control (3/5) -->
                <div class="lg:col-span-3 space-y-4">

                    <!-- Device IP -->
                    <div class="rounded-xl border border-border bg-card p-4">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs font-bold text-foreground">Perangkat ESP32</p>
                            <div class="flex items-center gap-1.5">
                                <div class="w-1.5 h-1.5 rounded-full"
                                     :class="{
                                         'bg-emerald-500 animate-pulse': espStatus==='online',
                                         'bg-red-500': espStatus==='offline'||espStatus==='blocked',
                                         'bg-gray-300 dark:bg-gray-600': espStatus==='unknown',
                                     }"/>
                                <span class="text-[10px] font-mono font-semibold"
                                      :class="{
                                          'text-emerald-600 dark:text-emerald-400': espStatus==='online',
                                          'text-red-500': espStatus==='offline'||espStatus==='blocked',
                                          'text-muted-foreground': espStatus==='unknown',
                                      }">
                                    {{ { online:'ONLINE', offline:'OFFLINE', blocked:'BLOCKED', unknown:'—' }[espStatus] }}
                                </span>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <input v-model="esp32IpInput" type="text"
                                   placeholder="192.168.1.50"
                                   class="flex-1 h-9 px-3 rounded-lg border border-border bg-background text-sm font-mono text-foreground outline-none focus:border-primary transition-colors"/>
                            <button @click="saveEspIp" :disabled="espIpSaving||!esp32IpInput"
                                    class="px-3 h-9 rounded-lg text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                                    style="background:linear-gradient(135deg,hsl(var(--primary)),hsl(207 30% 28%))">
                                {{ espIpSaving ? '...' : (lang==='en'?'Connect':'Hubungkan') }}
                            </button>
                        </div>
                    </div>

                    <!-- Mode Buttons -->
                    <div class="rounded-xl border border-border bg-card p-4 space-y-3">
                        <div class="flex items-center justify-between mb-1">
                            <p class="text-xs font-bold text-foreground">Mode Operasi RFID</p>
                            <span class="text-[9px] px-2 py-0.5 rounded-lg font-bold"
                                  style="background:hsl(var(--primary)/0.1);color:hsl(var(--primary))">
                                {{ lang==='en' ? 'Manual Mode' : 'Mode Manual' }}
                            </span>
                        </div>

                        <!-- ── REGISTER ─────────────────────────────────── -->
                        <div class="rounded-lg border p-3 space-y-2"
                             :class="espMode==='register'
                                 ? 'border-violet-300 dark:border-violet-700/50 bg-violet-50 dark:bg-violet-950/10'
                                 : 'border-border'">
                            <p class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                                <span>🪪</span> Register Tag
                            </p>
                            <div class="flex gap-2">
                                <select v-model="selectedBatchId"
                                        class="flex-1 h-8 px-2 rounded-lg border border-border bg-background text-xs text-foreground outline-none">
                                    <option value="" disabled>{{ lang==='en' ? 'Select batch...' : 'Pilih batch...' }}</option>
                                    <option v-for="b in untagged_batches" :key="b.id" :value="b.id">
                                        {{ b.batch_code }} · {{ b.product_code }} · {{ b.process_stage?.replace(/_/g,' ') }}
                                    </option>
                                </select>
                                <button @click="startRegisterSession"
                                        :disabled="!selectedBatchId||isSavingPending||isPending"
                                        class="px-3 h-8 rounded-lg text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                                        style="background:linear-gradient(135deg,#7c3aed,#5b21b6)">
                                    {{ isSavingPending ? '...' : 'Aktif' }}
                                </button>
                            </div>
                        </div>

                        <!-- ── SCAN IN ──────────────────────────────────── -->
                        <div class="rounded-lg border p-3 space-y-2"
                             :class="activeModeInfo?.mode==='scan_in'
                                 ? 'border-emerald-300 dark:border-emerald-700/50 bg-emerald-50 dark:bg-emerald-950/10'
                                 : 'border-border'">
                            <div class="flex items-center justify-between">
                                <p class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                                    <span>📥</span>
                                    {{ lang==='en' ? 'Scan IN — Record Entry' : 'Scan IN — Rekam Masuk Area' }}
                                </p>
                                <span v-if="activeModeInfo?.mode==='scan_in'"
                                      class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">
                                    ACTIVE
                                </span>
                            </div>
                            <p class="text-[10px] text-muted-foreground -mt-1">
                                {{ lang==='en'
                                    ? 'Logs that a batch entered the area. No stage change.'
                                    : 'Catat bahwa batch memasuki area. Tidak memindah stage.' }}
                            </p>
                            <div class="flex gap-2">
                                <select v-model="scanInStage"
                                        class="flex-1 h-8 px-2 rounded-lg border border-border bg-background text-xs font-semibold text-foreground outline-none">
                                    <option v-for="s in availableStages" :key="s.stage" :value="s.stage">
                                        {{ lang==='en' ? s.label_en : s.label_id }}
                                    </option>
                                </select>
                                <button @click="activateMode('scan_in', scanInStage)"
                                        :disabled="!scanInStage||isSavingMode==='scan_in'"
                                        class="px-3 h-8 rounded-lg text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                                        style="background:linear-gradient(135deg,#059669,#047857)">
                                    <svg v-if="isSavingMode==='scan_in'" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                    <span v-else>Aktif</span>
                                </button>
                            </div>
                        </div>

                        <!-- ── SCAN OUT ─────────────────────────────────── -->
                        <div class="rounded-lg border p-3 space-y-2"
                             :class="activeModeInfo?.mode==='scan_out'
                                 ? 'border-red-300 dark:border-red-700/50 bg-red-50 dark:bg-red-950/10'
                                 : 'border-border'">
                            <div class="flex items-center justify-between">
                                <p class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                                    <span>📤</span>
                                    {{ lang==='en' ? 'Scan OUT — Record Exit' : 'Scan OUT — Rekam Keluar Area' }}
                                </p>
                                <span v-if="activeModeInfo?.mode==='scan_out'"
                                      class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400">
                                    ACTIVE
                                </span>
                            </div>
                            <p class="text-[10px] text-muted-foreground -mt-1">
                                {{ lang==='en'
                                    ? 'Logs that a batch left the area. No status change.'
                                    : 'Catat bahwa batch meninggalkan area. Tidak mengubah status.' }}
                            </p>
                            <div class="flex gap-2">
                                <select v-model="scanOutStage"
                                        class="flex-1 h-8 px-2 rounded-lg border border-border bg-background text-xs font-semibold text-foreground outline-none">
                                    <option v-for="s in availableStages" :key="s.stage" :value="s.stage">
                                        {{ lang==='en' ? s.label_en : s.label_id }}
                                    </option>
                                </select>
                                <button @click="activateMode('scan_out', scanOutStage)"
                                        :disabled="!scanOutStage||isSavingMode==='scan_out'"
                                        class="px-3 h-8 rounded-lg text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                                        style="background:linear-gradient(135deg,#dc2626,#b91c1c)">
                                    <svg v-if="isSavingMode==='scan_out'" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                    <span v-else>Aktif</span>
                                </button>
                            </div>
                        </div>

                        <!-- Stop -->
                        <button @click="activateMode('idle', null)" :disabled="isSavingMode==='idle'"
                                class="w-full h-8 rounded-lg text-xs font-semibold border border-border text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-40 active:scale-[0.97] transition-all">
                            ⏹ {{ lang==='en' ? 'Stop / Idle' : 'Stop / Idle' }}
                        </button>
                    </div>
                </div>

                <!-- LCD Preview (2/5) -->
                <div class="lg:col-span-2 rounded-xl border border-border bg-card p-4 flex flex-col items-center gap-3">
                    <p class="text-[10px] font-mono font-semibold text-muted-foreground uppercase tracking-widest self-start">LCD ESP32</p>

                    <!-- LCD screen -->
                    <div class="rounded-lg overflow-hidden w-full"
                         style="background:#0d1a0f;border:2px solid #1a2e1c;padding:14px 16px;box-shadow:0 0 0 2px #080e09,0 0 0 4px #1a2e1c">
                        <div class="font-mono leading-none mb-1.5"
                             style="font-size:15px;color:#1aff6e;text-shadow:0 0 8px rgba(26,255,110,0.6);letter-spacing:0.1em;white-space:pre">
                            {{ lcdRow0 }}
                        </div>
                        <div class="font-mono leading-none"
                             :style="espMode==='idle'
                                 ? 'font-size:15px;color:#0d7a37'
                                 : 'font-size:15px;color:#1aff6e;text-shadow:0 0 8px rgba(26,255,110,0.5)'"
                             style="letter-spacing:0.1em;white-space:pre">
                            {{ lcdRow1 }}
                        </div>
                    </div>

                    <!-- Last scan UID -->
                    <div v-if="espState?.last_uid"
                         class="w-full rounded-lg border border-border bg-muted/20 p-2.5 text-center">
                        <p class="text-[9px] text-muted-foreground uppercase tracking-wider">Last UID Scanned</p>
                        <p class="font-mono text-sm font-bold text-amber-600 dark:text-amber-400">{{ espState.last_uid }}</p>
                        <p class="text-[10px] text-muted-foreground mt-0.5">{{ espState.last_message }}</p>
                    </div>

                    <!-- Pending register countdown -->
                    <Transition name="slide">
                        <div v-if="isPending"
                             class="w-full rounded-lg border-2 border-amber-400 dark:border-amber-600 p-3"
                             style="background:rgba(245,158,11,0.06)">
                            <div class="flex items-center justify-between mb-1.5">
                                <p class="text-xs font-bold text-amber-700 dark:text-amber-400">
                                    🪪 {{ pendingData?.batch_code }} — Registrasi Aktif
                                </p>
                                <button @click="cancelPendingSession" :disabled="isCancelling"
                                        class="text-[10px] font-bold text-amber-600 hover:text-amber-800 dark:text-amber-400 disabled:opacity-50">
                                    Batal
                                </button>
                            </div>
                            <div class="space-y-1">
                                <div class="flex justify-between text-[10px]">
                                    <span class="text-muted-foreground">Timeout</span>
                                    <span class="font-mono font-bold"
                                          :class="pendingCountdown<=15?'text-red-500':'text-amber-600 dark:text-amber-400'">
                                        {{ pendingCountdown }}s
                                    </span>
                                </div>
                                <div class="h-1 rounded-full bg-amber-100 dark:bg-amber-900/30 overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-1000"
                                         :class="pendingCountdown<=15?'bg-red-500':'bg-amber-400'"
                                         :style="`width:${countdownPct}%`"/>
                                </div>
                            </div>
                        </div>
                    </Transition>

                    <!-- Blocked warning -->
                    <div v-if="espStatus==='blocked'"
                         class="w-full p-2.5 rounded-lg border border-amber-200 dark:border-amber-800/40 bg-amber-50 dark:bg-amber-950/10 text-center">
                        <p class="text-[11px] text-amber-700 dark:text-amber-400 font-semibold">Mixed Content — Chrome memblokir</p>
                        <button @click="showMixedWarning=true; mixedContentDismissed=false"
                                class="text-[10px] text-amber-600 dark:text-amber-400 underline mt-0.5">
                            Lihat instruksi
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════
             TAB: SIMULASI
        ══════════════════════════════════════════════ -->
        <div v-show="activeTab==='simulate'" class="space-y-4 animate-fade-in">

            <!-- Info banner simulasi -->
            <div class="rounded-xl border border-border bg-muted/30 px-4 py-3 flex items-center gap-2">
                <span class="text-sm">🎮</span>
                <p class="text-xs text-muted-foreground">
                    {{ lang==='en'
                        ? 'Simulation mode — test RFID scanning without physical hardware. Results are recorded as audit trail only.'
                        : 'Mode simulasi — uji scan RFID tanpa hardware fisik. Hasil dicatat sebagai audit trail saja.' }}
                </p>
            </div>

            <!-- UID Input -->
            <div class="rounded-xl border border-border bg-card p-4 space-y-3">
                <div class="flex items-center gap-3">
                    <p class="text-xs font-bold text-foreground flex-shrink-0">UID Tag RFID</p>
                    <input v-model="simUid" type="text"
                           placeholder="A3B2C1D0"
                           class="flex-1 h-9 px-3 rounded-lg border border-border bg-background text-sm font-mono font-bold text-foreground uppercase outline-none focus:border-primary"
                           @input="simUid = sanitizeUid($event.target.value)"/>
                    <button @click="generateUid"
                            class="flex-shrink-0 px-3 h-9 rounded-lg text-xs font-bold border border-border bg-card hover:bg-muted active:scale-[0.97] text-muted-foreground">
                        {{ lang==='en' ? 'Generate' : 'Generate' }}
                    </button>
                </div>
                <div v-if="simUid" class="flex items-center gap-2 text-[11px]">
                    <span class="text-muted-foreground">UID aktif:</span>
                    <span class="font-mono font-bold text-amber-600 dark:text-amber-400">{{ simUid }}</span>
                </div>
            </div>

            <!-- 3 Action columns -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

                <!-- Register -->
                <div class="rounded-xl border border-border bg-card p-4 space-y-3">
                    <div>
                        <p class="text-xs font-bold text-foreground">🪪 Register Tag</p>
                        <p class="text-[10px] text-muted-foreground mt-0.5">Link UID ke batch baru</p>
                    </div>
                    <select v-model="simBatchId"
                            class="w-full h-8 px-2 rounded-lg border border-border bg-background text-xs text-foreground outline-none">
                        <option value="" disabled>{{ lang==='en' ? 'Select batch...' : 'Pilih batch...' }}</option>
                        <option v-for="b in untagged_batches" :key="b.id" :value="b.id">
                            {{ b.batch_code }} · {{ b.product_code }}
                        </option>
                    </select>
                    <button @click="simulateRegister"
                            :disabled="!simBatchId||!simUid||isSimulating"
                            class="w-full h-9 rounded-lg text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#7c3aed,#5b21b6)">
                        <svg v-if="isSimulating&&simAction==='register'" class="w-3.5 h-3.5 animate-spin inline-block mr-1" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        {{ isSimulating&&simAction==='register' ? 'Mendaftar...' : 'Simulate Register' }}
                    </button>
                </div>

                <!-- Scan IN -->
                <div class="rounded-xl border border-emerald-200 dark:border-emerald-800/30 bg-card p-4 space-y-3">
                    <div>
                        <p class="text-xs font-bold text-foreground flex items-center gap-1.5">
                            📥 Scan IN
                            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">
                                Audit Trail
                            </span>
                        </p>
                        <p class="text-[10px] text-muted-foreground mt-0.5">
                            {{ lang==='en' ? 'Records batch entering area' : 'Catat batch memasuki area' }}
                        </p>
                    </div>
                    <select v-model="simScanInStage"
                            class="w-full h-8 px-2 rounded-lg border border-border bg-background text-xs font-semibold text-foreground outline-none">
                        <option v-for="s in availableStages" :key="s.stage" :value="s.stage">
                            {{ lang==='en' ? s.label_en : s.label_id }}
                        </option>
                    </select>
                    <button @click="simulateScan('scan_in', simScanInStage)"
                            :disabled="!simUid||isSimulating"
                            class="w-full h-9 rounded-lg text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#059669,#047857)">
                        <svg v-if="isSimulating&&simAction==='scan_in'" class="w-3.5 h-3.5 animate-spin inline-block mr-1" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        {{ isSimulating&&simAction==='scan_in' ? 'Memproses...' : 'Simulate Scan IN' }}
                    </button>
                </div>

                <!-- Scan OUT -->
                <div class="rounded-xl border border-red-200 dark:border-red-800/30 bg-card p-4 space-y-3">
                    <div>
                        <p class="text-xs font-bold text-foreground flex items-center gap-1.5">
                            📤 Scan OUT
                            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400">
                                Audit Trail
                            </span>
                        </p>
                        <p class="text-[10px] text-muted-foreground mt-0.5">
                            {{ lang==='en' ? 'Records batch leaving area' : 'Catat batch meninggalkan area' }}
                        </p>
                    </div>
                    <select v-model="simScanOutStage"
                            class="w-full h-8 px-2 rounded-lg border border-border bg-background text-xs font-semibold text-foreground outline-none">
                        <option v-for="s in availableStages" :key="s.stage" :value="s.stage">
                            {{ lang==='en' ? s.label_en : s.label_id }}
                        </option>
                    </select>
                    <button @click="simulateScan('scan_out', simScanOutStage)"
                            :disabled="!simUid||isSimulating"
                            class="w-full h-9 rounded-lg text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#dc2626,#b91c1c)">
                        <svg v-if="isSimulating&&simAction==='scan_out'" class="w-3.5 h-3.5 animate-spin inline-block mr-1" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        {{ isSimulating&&simAction==='scan_out' ? 'Memproses...' : 'Simulate Scan OUT' }}
                    </button>
                </div>
            </div>

            <!-- Simulation Result -->
            <Transition name="slide">
                <div v-if="simResult"
                     class="rounded-xl border p-4"
                     :class="simResult.status==='success'
                         ? 'border-emerald-300 dark:border-emerald-700/50'
                         : 'border-red-300 dark:border-red-700/50'"
                     :style="simResult.status==='success'
                         ? 'background:rgba(16,185,129,0.04)'
                         : 'background:rgba(220,38,38,0.04)'">
                    <div class="flex items-start gap-3 mb-3">
                        <span class="text-lg flex-shrink-0">{{ simResult.status==='success' ? '✅' : '❌' }}</span>
                        <div class="flex-1">
                            <p class="text-xs font-bold text-foreground">
                                {{ simResult.status==='success'
                                    ? (lang==='en' ? 'Scan recorded successfully' : 'Scan berhasil tercatat')
                                    : (lang==='en' ? 'Scan failed' : 'Scan gagal') }}
                                — {{ simResult.message }}
                            </p>
                            <!-- Manual mode note — TIDAK ada "auto moved" lagi -->
                            <p v-if="simResult.status==='success' && simResult.action !== 'register'"
                               class="text-[10px] text-muted-foreground mt-1 flex items-center gap-1">
                                <span>👆</span>
                                {{ lang==='en'
                                    ? 'Stage transition must be confirmed manually via web panel.'
                                    : 'Perpindahan stage harus dikonfirmasi manual via web panel.' }}
                            </p>
                        </div>
                        <span class="text-[10px] font-bold px-2 py-1 rounded-md flex-shrink-0"
                              :class="getSimStyle(simResult.action).cls">
                            {{ getSimStyle(simResult.action).label }}
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <div class="rounded-lg border border-border bg-card px-3 py-1.5">
                            <p class="text-[9px] text-muted-foreground">UID</p>
                            <p class="text-xs font-mono font-bold text-amber-600 dark:text-amber-400">{{ simResult.uid }}</p>
                        </div>
                        <div v-if="simResult.batch_code" class="rounded-lg border border-border bg-card px-3 py-1.5">
                            <p class="text-[9px] text-muted-foreground">Batch</p>
                            <p class="text-xs font-mono font-bold text-foreground">{{ simResult.batch_code }}</p>
                        </div>
                        <div v-if="simResult.stage_label" class="rounded-lg border border-border bg-card px-3 py-1.5">
                            <p class="text-[9px] text-muted-foreground">Area</p>
                            <p class="text-xs font-bold text-foreground">{{ simResult.stage_label }}</p>
                        </div>
                        <div v-if="simResult.timestamp" class="rounded-lg border border-border bg-card px-3 py-1.5">
                            <p class="text-[9px] text-muted-foreground">Waktu</p>
                            <p class="text-xs font-mono text-muted-foreground">{{ simResult.timestamp }}</p>
                        </div>
                    </div>
                </div>
            </Transition>
        </div>

        <!-- ══════════════════════════════════════════════
             TAB: TAGGED BATCHES
        ══════════════════════════════════════════════ -->
        <div v-show="activeTab==='tagged'" class="animate-fade-in">
            <EmptyState v-if="!tagged_batches.length"
                icon="🪪"
                :title="lang==='en'?'No tagged batches':'Belum ada batch ter-tag'"
                :description="lang==='en'?'Register RFID tags using Register mode.':'Daftarkan tag RFID melalui mode Register.'"
            />
            <div v-else class="rounded-xl border border-border bg-card overflow-hidden">
                <div class="px-4 py-3 border-b border-border flex items-center justify-between">
                    <p class="text-xs font-bold text-foreground">
                        {{ lang==='en' ? 'Tagged Batches' : 'Batch Ter-tag' }}
                    </p>
                    <span class="text-[10px] text-muted-foreground">{{ tagged_batches.length }} batch</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-border bg-muted/20">
                                <th class="text-left px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Batch</th>
                                <th class="text-left px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">UID Tag</th>
                                <th class="text-left px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Stage</th>
                                <th class="text-left px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ lang==='en' ? 'Last Scan' : 'Scan Terakhir' }}
                                </th>
                                <th class="px-4 py-2.5 w-16"/>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="batch in tagged_batches" :key="batch.id" class="group hover:bg-muted/20">
                                <td class="px-4 py-3">
                                    <Link :href="`/company/batches/${batch.id}`"
                                          class="font-mono text-xs font-bold text-foreground hover:text-primary transition-colors">
                                        {{ batch.batch_code }}
                                    </Link>
                                    <p class="text-[10px] text-muted-foreground">{{ batch.product_code }} · {{ batch.weight_kg }} kg</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-mono text-xs font-bold text-amber-600 dark:text-amber-400">{{ batch.rfid_tag }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-muted text-muted-foreground">
                                        {{ getStageName(batch.process_stage) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <template v-if="batch.last_checkpoint">
                                        <p class="text-[11px] text-foreground font-medium">
                                            {{ batch.last_checkpoint.stage?.replace(/_/g,' ') }}
                                            <span v-if="batch.last_checkpoint.is_rfid"
                                                  class="text-[9px] ml-1 px-1 rounded bg-primary/10 text-primary">RFID</span>
                                        </p>
                                        <p class="text-[10px] text-muted-foreground">{{ batch.last_checkpoint.recorded_at_diff }}</p>
                                    </template>
                                    <span v-else class="text-[10px] text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-3">
                                    <button v-if="canAdmin" @click="removeTag(batch)" :disabled="removingTagId===batch.id"
                                            class="opacity-0 group-hover:opacity-100 text-[10px] font-bold px-2 py-1 rounded-lg text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 active:scale-[0.97] disabled:opacity-40 transition-all">
                                        {{ lang==='en' ? 'Remove' : 'Hapus' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════
             TAB: AUDIT LOG
        ══════════════════════════════════════════════ -->
        <div v-show="activeTab==='activity'" class="animate-fade-in">
            <EmptyState v-if="!recent_activity.length"
                icon="📋"
                :title="lang==='en'?'No RFID activity yet':'Belum ada aktivitas RFID'"
                :description="lang==='en'?'Scan events will appear here.':'Event scan akan muncul di sini.'"
            />
            <div v-else class="rounded-xl border border-border bg-card overflow-hidden">
                <div class="px-4 py-3 border-b border-border flex items-center justify-between">
                    <div>
                        <p class="text-xs font-bold text-foreground">
                            {{ lang==='en' ? 'RFID Audit Log' : 'Log Audit RFID' }}
                        </p>
                        <p class="text-[10px] text-muted-foreground mt-0.5">
                            {{ lang==='en'
                                ? 'All scans recorded. Stage transitions are separate (web panel).'
                                : 'Semua scan tercatat. Perpindahan stage terpisah (web panel).' }}
                        </p>
                    </div>
                    <span class="text-[10px] text-muted-foreground">{{ recent_activity.length }} event</span>
                </div>
                <div class="divide-y divide-border">
                    <div v-for="act in recent_activity" :key="act.id"
                         class="flex items-center gap-3 px-4 py-3 hover:bg-muted/20 transition-colors">

                        <!-- Action badge — TANPA "auto" badge lagi -->
                        <span class="text-[10px] font-bold px-2.5 py-1 rounded-lg flex-shrink-0 min-w-[62px] text-center"
                              :class="getActionBadge(act.action).cls">
                            {{ getActionBadge(act.action).label }}
                        </span>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <Link :href="`/company/batches/${act.batch_id}`"
                                      class="font-mono text-xs font-bold text-foreground hover:text-primary transition-colors">
                                    {{ act.batch_code }}
                                </Link>
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-muted text-muted-foreground">
                                    {{ act.product_code }}
                                </span>
                                <span v-if="act.scan_stage"
                                      class="text-[10px] px-1.5 py-0.5 rounded bg-muted/60 text-muted-foreground">
                                    {{ getStageName(act.scan_stage) }}
                                </span>
                                <!-- Pending verification indicator -->
                                <span v-if="!act.is_verified"
                                      class="text-[9px] px-1.5 py-0.5 rounded border border-amber-300 dark:border-amber-700/50 text-amber-600 dark:text-amber-400 font-semibold">
                                    {{ lang==='en' ? '⏳ Pending confirm' : '⏳ Tunggu konfirmasi' }}
                                </span>
                            </div>
                            <p class="text-[10px] text-muted-foreground mt-0.5">
                                UID: <span class="font-mono text-amber-600 dark:text-amber-400">{{ act.rfid_uid }}</span>
                                · {{ act.weight_kg }} kg
                            </p>
                        </div>

                        <div class="text-right flex-shrink-0">
                            <p class="text-[11px] font-medium text-muted-foreground">{{ act.recorded_at_diff }}</p>
                            <p class="text-[9px] text-muted-foreground/60">{{ act.recorded_at?.split(' ')[0] }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</AppLayout>
</template>

<style scoped>
@keyframes fade-in { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:translateY(0)} }
.animate-fade-in { animation: fade-in 0.25s ease-out both; }

.toast-enter-active { transition:all 0.2s ease; }
.toast-leave-active { transition:all 0.15s ease; }
.toast-enter-from, .toast-leave-to { opacity:0; transform:translateX(-50%) translateY(16px); }

.slide-enter-active { transition:all 0.2s ease-out; }
.slide-leave-active { transition:all 0.15s ease-in; }
.slide-enter-from, .slide-leave-to { opacity:0; transform:translateY(-6px); }
</style>