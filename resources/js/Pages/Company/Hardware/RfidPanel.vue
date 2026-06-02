<script setup>
/**
 * RfidPanel.vue — Panel Kontrol Hardware RFID MINETRA.
 *
 * ════════════════════════════════════════════════════════════════════
 * ARSITEKTUR:
 *   1. MINETRA Web (halaman ini) menyimpan IP ESP32 di backend
 *   2. Operator pilih batch → klik mode → Vue fetch langsung ke ESP32 IP
 *   3. ESP32 LCD menampilkan "Tempel Tag"
 *   4. Operator tempel RFID tag ke reader
 *   5. ESP32 POST ke MINETRA VPS → checkpoint/tag tersimpan
 *
 * ALUR 3 MODE:
 *   REGISTER : Lab Kimia — link RFID tag ke batch baru
 *   LOGIN    : Gudang masuk — checkpoint IN saat karung tiba
 *   LOGOUT   : Gudang keluar — checkpoint OUT saat karung pergi
 * ════════════════════════════════════════════════════════════════════
 */
import { ref, computed, onUnmounted } from 'vue'
import { Head, Link, router }          from '@inertiajs/vue3'
import AppLayout                        from '@/Layouts/AppLayout.vue'
import EmptyState                       from '@/components/shared/EmptyState.vue'
import StatusBadge                      from '@/components/shared/StatusBadge.vue'
import WeightDisplay                    from '@/components/shared/WeightDisplay.vue'
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
const canManage  = computed(() => ['company_admin', 'operator_lab_station', 'operator_wet'].includes(props.user_role))
const canAdmin   = computed(() => props.user_role === 'company_admin')

// ─── Active tab ────────────────────────────────────────────────────────────
const activeTab = ref('control')   // 'control' | 'tagged' | 'activity'

// ═══════════════════════════════════════════════════════════════════════════
// ESP32 DEVICE SETUP — Simpan IP ESP32 ke sistem
// ═══════════════════════════════════════════════════════════════════════════
const esp32IpInput  = ref(props.esp32_ip ?? '')
const espIpSaving   = ref(false)
const espStatus     = ref('unknown')  // 'online' | 'offline' | 'unknown'

async function saveEspIp() {
    if (!esp32IpInput.value.trim()) { showToast('Masukkan IP ESP32 terlebih dahulu.', 'error'); return; }

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
// ESP32 STATUS POLLING — Poll status LCD setiap 800ms
// ═══════════════════════════════════════════════════════════════════════════
const espState     = ref(null)     // response dari ESP32 /status
const lcdRow0      = ref('Sistem RFID    ')
const lcdRow1      = ref('Menunggu mode..')
const activeMode   = ref('idle')

let espPollTimer   = null
let lcdAnimTimer   = null
let lcdAnimIdx     = 0

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
        espStatus.value = 'online'
        espState.value  = d
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
    const label = modeLabel[d.mode] || d.mode

    if (d.status === 'waiting') {
        lcdRow0.value = pad(label)
        lcdAnimIdx = 0
        const animate = () => { lcdRow1.value = pad('Tempel Tag' + LCD_DOTS[lcdAnimIdx++ % 4]) }
        animate()
        lcdAnimTimer = setInterval(animate, 600)
    } else if (d.status === 'scanning') {
        lcdRow0.value = pad(label)
        lcdRow1.value = pad('Memproses...')
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
// PENDING BATCH — Set batch mana yang akan di-tag
// ═══════════════════════════════════════════════════════════════════════════
const selectedBatchId = ref('')
const isPending       = ref(!!props.pending_batch)
const pendingData     = ref(props.pending_batch)
const pendingCountdown = ref(props.pending_batch ? 90 : 0)
let   pendingTimer    = null

if (props.pending_batch) startPendingCountdown(90, props.pending_batch)

function startPendingCountdown(seconds, data) {
    pendingData.value     = data
    isPending.value       = true
    pendingCountdown.value = seconds
    if (pendingTimer) clearInterval(pendingTimer)
    pendingTimer = setInterval(() => {
        pendingCountdown.value--
        if (pendingCountdown.value <= 0) {
            clearInterval(pendingTimer)
            isPending.value = false
            pendingData.value = null
            router.reload({ only: ['pending_batch', 'untagged_batches', 'stats'] })
        }
    }, 1000)
}

const isSavingPending  = ref(false)
const isCancelling     = ref(false)

async function startRegisterSession() {
    if (!selectedBatchId.value) { showToast('Pilih batch terlebih dahulu.', 'error'); return }
    if (!esp32IpInput.value)    { showToast('Simpan IP ESP32 terlebih dahulu.', 'error'); return }

    isSavingPending.value = true
    try {
        // 1. Beri tahu MINETRA backend batch mana yang akan di-tag
        const res = await apiFetch('/api/v1/rfid/pending-batch', 'POST', { batch_id: parseInt(selectedBatchId.value) })
        if (!res.success) { showToast(res.message ?? 'Gagal memulai sesi.', 'error'); return }

        startPendingCountdown(90, res.data)
        showToast(`Sesi aktif untuk ${res.data.batch_code}. Tempel tag sekarang!`, 'success')

        // 2. Kirim perintah mode REGISTER ke ESP32
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
        await apiFetch('/api/v1/rfid/pending-batch', 'DELETE')
        isPending.value = false
        pendingData.value = null
        pendingCountdown.value = 0
        showToast('Sesi dibatalkan.', 'info')
        await sendModeToEsp32('idle')
        router.reload({ only: ['pending_batch', 'untagged_batches', 'stats'] })
    } finally {
        isCancelling.value = false
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// MODE CONTROL — Kirim perintah mode ke ESP32 langsung dari browser
// ═══════════════════════════════════════════════════════════════════════════
const sendingMode = ref(null)  // 'register' | 'login' | 'logout' | 'idle' | null

async function sendModeToEsp32(mode) {
    const ip = esp32IpInput.value.trim()
    if (!ip) { showToast('IP ESP32 belum disimpan.', 'error'); return false }

    sendingMode.value = mode
    const baseUrl = ip.startsWith('http') ? ip : `http://${ip}`

    try {
        const res = await fetch(`${baseUrl}/set-mode`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode }),
            signal: AbortSignal.timeout(4000),
        })

        if (res.ok) {
            const modeLabel = { register: 'REGISTER', login: 'LOGIN', logout: 'LOGOUT', idle: 'IDLE' }
            showToast(`ESP32 → Mode ${modeLabel[mode] ?? mode} aktif`, 'success')
            return true
        } else {
            showToast('ESP32 menolak perintah. Cek koneksi.', 'error')
            return false
        }
    } catch {
        showToast('ESP32 tidak merespons. Pastikan dalam jaringan yang sama.', 'error')
        return false
    } finally {
        sendingMode.value = null
    }
}

async function activateLoginMode() {
    await sendModeToEsp32('login')
}

async function activateLogoutMode() {
    await sendModeToEsp32('logout')
}

async function activateIdleMode() {
    await sendModeToEsp32('idle')
    isPending.value = false
}

// ═══════════════════════════════════════════════════════════════════════════
// REMOVE TAG — Hapus RFID tag dari batch
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
        if (res.success) router.reload({ only: ['tagged_batches', 'untagged_batches', 'stats'] })
    } catch (e) {
        showToast('Gagal menghapus tag.', 'error')
    } finally {
        removingTagId.value = null
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// TOAST
// ═══════════════════════════════════════════════════════════════════════════
const toastMsg   = ref('')
const toastType  = ref('info')
const toastVisible = ref(false)
let   toastTimer = null

function showToast(msg, type = 'info') {
    toastMsg.value   = msg
    toastType.value  = type
    toastVisible.value = true
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => { toastVisible.value = false }, 4000)
}

// ═══════════════════════════════════════════════════════════════════════════
// API HELPERS
// ═══════════════════════════════════════════════════════════════════════════
async function apiFetch(url, method = 'GET', body = null) {
    const opts = {
        method,
        credentials: 'include',
        headers: {
            'Content-Type':  'application/json',
            'Accept':        'application/json',
            'X-CSRF-TOKEN':  document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
    }
    if (body) opts.body = JSON.stringify(body)
    const res  = await fetch(url, opts)
    return res.json()
}

// ─── Helpers ───────────────────────────────────────────────────────────────
const countdownPct = computed(() => Math.round((pendingCountdown.value / 90) * 100))

function getActionLabel(action) {
    const map = {
        rfid_register:       lang.value === 'en' ? 'Tag Registered'   : 'Tag Terdaftar',
        rfid_checkpoint_in:  lang.value === 'en' ? 'Checkpoint IN'     : 'Checkpoint Masuk',
        rfid_checkpoint_out: lang.value === 'en' ? 'Checkpoint OUT'    : 'Checkpoint Keluar',
    }
    return map[action] ?? action?.replace(/_/g, ' ')
}

function getActionColor(action) {
    const m = {
        rfid_register:       'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300',
        rfid_checkpoint_in:  'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300',
        rfid_checkpoint_out: 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
    }
    return m[action] ?? 'bg-muted text-muted-foreground'
}

function getStageLabel(stage) {
    const m = {
        lab_kimia: 'Lab Kimia', receiving: 'Receiving',
        wet_process: 'Wet', dry_process: 'Dry',
        warehouse: 'Warehouse', project_plant: 'Lab LTJ',
    }
    return m[stage] ?? stage?.replace(/_/g, ' ')
}

// ─── Cleanup ───────────────────────────────────────────────────────────────
onUnmounted(() => {
    clearInterval(espPollTimer)
    clearInterval(lcdAnimTimer)
    clearInterval(pendingTimer)
    clearTimeout(toastTimer)
})

// Auto-start polling jika IP sudah ada
if (props.esp32_ip) {
    esp32IpInput.value = props.esp32_ip
    startEspPolling()
}
</script>

<template>
<AppLayout>
    <Head :title="lang === 'en' ? 'RFID Hardware Panel' : 'Panel Hardware RFID'"/>
    <template #header>{{ lang === 'en' ? 'RFID Hardware Panel' : 'Panel Hardware RFID' }}</template>

    <!-- Toast notification -->
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
                        ? 'Register RFID tags to mineral bags, track arrivals and departures via ESP32.'
                        : 'Daftarkan tag RFID ke karung mineral, lacak masuk/keluar via ESP32 RC522.' }}
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
             ALUR KERJA INFO BOX
        ══════════════════════════════════════════════════ -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 animate-fade-in delay-50">
            <div v-for="(step, i) in [
                {
                    num:'1', icon:'🪪', color:'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300',
                    title_id:'Register Tag', title_en:'Register Tag',
                    desc_id:'Lab Kimia — pilih batch, klik Register, tempel tag ke karung',
                    desc_en:'Lab Kimia — select batch, click Register, tap tag on bag',
                },
                {
                    num:'2', icon:'📥', color:'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300',
                    title_id:'Login / Masuk', title_en:'Login / Entry',
                    desc_id:'Depan gudang — karung tiba, scan → checkpoint IN tercatat',
                    desc_en:'Warehouse entry — bag arrives, scan → checkpoint IN recorded',
                },
                {
                    num:'3', icon:'📤', color:'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
                    title_id:'Logout / Keluar', title_en:'Logout / Exit',
                    desc_id:'Pintu keluar — karung pergi, scan → checkpoint OUT tercatat',
                    desc_en:'Exit door — bag leaves, scan → checkpoint OUT recorded',
                },
            ]" :key="step.num"
                 class="rounded-2xl border border-border bg-card p-4"
                 :style="`animation: fade-in 0.25s ease-out ${i*60}ms both`">
                <div class="flex items-center gap-2.5 mb-2">
                    <span class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-black text-white"
                          style="background: hsl(var(--primary))">{{ step.num }}</span>
                    <span class="text-lg">{{ step.icon }}</span>
                    <p class="text-xs font-bold text-foreground">{{ lang === 'en' ? step.title_en : step.title_id }}</p>
                </div>
                <p class="text-[11px] text-muted-foreground leading-relaxed">
                    {{ lang === 'en' ? step.desc_en : step.desc_id }}
                </p>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             ESP32 DEVICE SETUP — Input + Save IP
        ══════════════════════════════════════════════════ -->
        <div class="rounded-2xl border border-border bg-card p-5 animate-fade-in delay-100">
            <div class="flex items-center gap-2 mb-3">
                <span class="text-base">🔌</span>
                <h3 class="font-heading text-sm font-bold text-foreground">
                    {{ lang === 'en' ? 'ESP32 Device' : 'Perangkat ESP32' }}
                </h3>
                <!-- Online/Offline dot -->
                <div class="ml-auto flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full"
                         :class="{
                             'bg-emerald-500 animate-pulse': espStatus === 'online',
                             'bg-red-500': espStatus === 'offline',
                             'bg-gray-400': espStatus === 'unknown',
                         }"/>
                    <span class="text-xs font-mono font-bold"
                          :class="{
                              'text-emerald-600 dark:text-emerald-400': espStatus === 'online',
                              'text-red-500': espStatus === 'offline',
                              'text-muted-foreground': espStatus === 'unknown',
                          }">
                        {{ espStatus === 'online' ? 'ONLINE' : espStatus === 'offline' ? 'OFFLINE' : '—' }}
                    </span>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <input v-model="esp32IpInput" type="text"
                       placeholder="Contoh: 192.168.1.50 atau http://192.168.1.50"
                       class="flex-1 h-10 px-3 rounded-xl border border-border bg-background text-sm font-mono text-foreground outline-none focus:border-primary focus:ring-1 focus:ring-primary/20"/>
                <button @click="saveEspIp"
                        :disabled="espIpSaving || !esp32IpInput"
                        class="flex items-center gap-2 px-4 h-10 rounded-xl text-sm font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                        style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));">
                    <svg v-if="espIpSaving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    {{ espIpSaving ? 'Menyimpan...' : (lang === 'en' ? 'Save & Connect' : 'Simpan & Hubungkan') }}
                </button>
            </div>
            <p class="text-[10px] text-muted-foreground mt-2">
                ⚠️ {{ lang === 'en'
                    ? 'Browser must be on the same WiFi network as the ESP32 device.'
                    : 'Browser harus berada di jaringan WiFi yang sama dengan ESP32.' }}
            </p>
        </div>

        <!-- ══════════════════════════════════════════════════
             PENDING REGISTER BANNER
        ══════════════════════════════════════════════════ -->
        <Transition name="slide">
            <div v-if="isPending"
                 class="rounded-2xl border-2 border-amber-400 dark:border-amber-600 p-5"
                 style="background:rgba(245,158,11,0.06)">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-xl bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
                        <span class="text-2xl animate-pulse">🪪</span>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-bold text-amber-700 dark:text-amber-400">
                            ⚡ {{ lang === 'en' ? 'Registration Session Active' : 'Sesi Registrasi Aktif' }}
                        </p>
                        <p class="text-xs text-foreground font-semibold mt-0.5">
                            Batch: <span class="font-mono">{{ pendingData?.batch_code }}</span>
                            ({{ pendingData?.product_code }})
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ lang === 'en'
                                ? 'ESP32 is in REGISTER mode. Tap the RFID tag on the bag NOW.'
                                : 'ESP32 dalam mode REGISTER. Tempel tag RFID ke karung SEKARANG.' }}
                        </p>
                        <!-- Countdown bar -->
                        <div class="mt-2.5 space-y-1">
                            <div class="flex justify-between text-[10px]">
                                <span class="text-muted-foreground font-mono uppercase tracking-wider">Timeout</span>
                                <span class="font-black font-mono"
                                      :class="pendingCountdown <= 15 ? 'text-red-500' : 'text-amber-600'">
                                    {{ pendingCountdown }}s
                                </span>
                            </div>
                            <div class="h-1.5 rounded-full bg-amber-100 dark:bg-amber-900/30 overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-1000"
                                     :class="pendingCountdown <= 15 ? 'bg-red-500' : 'bg-amber-400'"
                                     :style="`width: ${countdownPct}%`"/>
                            </div>
                        </div>
                    </div>
                    <button @click="cancelPendingSession"
                            :disabled="isCancelling"
                            class="flex-shrink-0 px-3 py-2 rounded-xl border border-amber-400 text-xs font-bold text-amber-700 dark:text-amber-400 hover:bg-amber-100 dark:hover:bg-amber-900/30 active:scale-[0.97] disabled:opacity-60">
                        {{ lang === 'en' ? 'Cancel' : 'Batal' }}
                    </button>
                </div>
            </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════
             MAIN GRID — LCD Preview + Mode Control
        ══════════════════════════════════════════════════ -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

            <!-- LCD PREVIEW ──────────────────────────────────── -->
            <div class="rounded-2xl border border-border bg-card p-5 flex flex-col items-center gap-3 animate-fade-in delay-150">
                <p class="text-[10px] font-mono font-semibold text-muted-foreground uppercase tracking-widest">
                    ◼ LCD ESP32 LIVE PREVIEW
                </p>
                <!-- LCD screen simulation -->
                <div class="rounded-xl overflow-hidden w-full max-w-[300px]"
                     style="background:#0d1a0f; border:2px solid #1a2e1c; padding:16px 18px; box-shadow:0 0 0 3px #080e09, 0 0 0 5px #1a2e1c, 0 0 20px rgba(0,200,80,0.1);">
                    <div class="font-mono text-lg leading-none mb-1.5"
                         style="color:#1aff6e; text-shadow:0 0 8px rgba(26,255,110,0.6); letter-spacing:0.1em; white-space:pre;">
                        {{ lcdRow0 }}
                    </div>
                    <div class="font-mono text-lg leading-none"
                         :style="activeMode === 'idle' ? 'color:#0d7a37' : 'color:#1aff6e; text-shadow:0 0 8px rgba(26,255,110,0.5)'"
                         style="letter-spacing:0.1em; white-space:pre;">
                        {{ lcdRow1 }}
                    </div>
                </div>
                <!-- ESP32 last scan info -->
                <div v-if="espState?.last_uid"
                     class="w-full rounded-xl border border-border bg-muted/30 p-3 text-center">
                    <p class="text-[10px] text-muted-foreground uppercase tracking-wider">Last UID Scanned</p>
                    <p class="font-mono text-base font-bold text-amber-600 dark:text-amber-400">{{ espState.last_uid }}</p>
                    <p class="text-[10px] text-muted-foreground mt-0.5">{{ espState.last_message }}</p>
                </div>
            </div>

            <!-- MODE CONTROL PANEL ───────────────────────────── -->
            <div class="rounded-2xl border border-border bg-card p-5 space-y-4 animate-fade-in delay-200">
                <h3 class="font-heading text-sm font-bold text-foreground">
                    🎛️ {{ lang === 'en' ? 'Mode Control' : 'Kontrol Mode ESP32' }}
                </h3>

                <!-- REGISTER MODE — perlu pilih batch dulu -->
                <div v-if="canManage" class="space-y-2 p-4 rounded-xl border"
                     :class="activeMode === 'register' ? 'border-violet-400 bg-violet-50 dark:bg-violet-950/10' : 'border-border bg-muted/20'">
                    <p class="text-xs font-bold text-foreground flex items-center gap-2">
                        🪪 {{ lang === 'en' ? 'REGISTER Mode — Link Tag to Batch' : 'Mode REGISTER — Hubungkan Tag ke Batch' }}
                        <span v-if="activeMode === 'register'"
                              class="text-[9px] font-bold px-2 py-0.5 rounded-full bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300">
                            ACTIVE
                        </span>
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        {{ lang === 'en'
                            ? 'Select the batch that will receive the RFID tag from the bag.'
                            : 'Pilih batch yang akan menerima tag RFID dari karung.' }}
                    </p>
                    <div class="flex gap-2">
                        <select v-model="selectedBatchId"
                                class="flex-1 h-9 px-2.5 rounded-xl border border-border bg-background text-xs text-foreground outline-none focus:border-primary">
                            <option value="" disabled>
                                {{ lang === 'en' ? 'Select batch without tag...' : 'Pilih batch belum punya tag...' }}
                            </option>
                            <option v-for="b in untagged_batches" :key="b.id" :value="b.id">
                                {{ b.batch_code }} ({{ b.product_code }}) — {{ getStageLabel(b.process_stage) }}
                            </option>
                        </select>
                        <button @click="startRegisterSession"
                                :disabled="!selectedBatchId || isSavingPending || isPending || espStatus !== 'online'"
                                class="flex items-center gap-1.5 px-3 h-9 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97] flex-shrink-0"
                                style="background:linear-gradient(135deg,#7c3aed,#5b21b6)">
                            <svg v-if="isSavingPending" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            🪪 {{ lang === 'en' ? 'Register' : 'Register' }}
                        </button>
                    </div>
                </div>

                <!-- LOGIN MODE -->
                <div class="p-4 rounded-xl border"
                     :class="activeMode === 'login' ? 'border-emerald-400 bg-emerald-50 dark:bg-emerald-950/10' : 'border-border bg-muted/20'">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold text-foreground flex items-center gap-2">
                                📥 {{ lang === 'en' ? 'LOGIN Mode — Checkpoint IN' : 'Mode LOGIN — Checkpoint Masuk' }}
                                <span v-if="activeMode === 'login'"
                                      class="text-[9px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">
                                    ACTIVE
                                </span>
                            </p>
                            <p class="text-[11px] text-muted-foreground mt-0.5">
                                {{ lang === 'en'
                                    ? 'Scan bags arriving at the warehouse entrance.'
                                    : 'Scan karung yang tiba di depan gudang.' }}
                            </p>
                        </div>
                        <button @click="activateLoginMode"
                                :disabled="sendingMode === 'login' || espStatus !== 'online'"
                                class="flex-shrink-0 flex items-center gap-1.5 px-3 h-9 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                                style="background:linear-gradient(135deg,#059669,#047857)">
                            <svg v-if="sendingMode === 'login'" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            {{ lang === 'en' ? '→ Activate' : '→ Aktifkan' }}
                        </button>
                    </div>
                </div>

                <!-- LOGOUT MODE -->
                <div class="p-4 rounded-xl border"
                     :class="activeMode === 'logout' ? 'border-red-400 bg-red-50 dark:bg-red-950/10' : 'border-border bg-muted/20'">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold text-foreground flex items-center gap-2">
                                📤 {{ lang === 'en' ? 'LOGOUT Mode — Checkpoint OUT' : 'Mode LOGOUT — Checkpoint Keluar' }}
                                <span v-if="activeMode === 'logout'"
                                      class="text-[9px] font-bold px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400">
                                    ACTIVE
                                </span>
                            </p>
                            <p class="text-[11px] text-muted-foreground mt-0.5">
                                {{ lang === 'en'
                                    ? 'Scan bags leaving through the exit door.'
                                    : 'Scan karung yang keluar melalui pintu keluar.' }}
                            </p>
                        </div>
                        <button @click="activateLogoutMode"
                                :disabled="sendingMode === 'logout' || espStatus !== 'online'"
                                class="flex-shrink-0 flex items-center gap-1.5 px-3 h-9 rounded-xl text-xs font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                                style="background:linear-gradient(135deg,#dc2626,#b91c1c)">
                            <svg v-if="sendingMode === 'logout'" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            {{ lang === 'en' ? '→ Activate' : '→ Aktifkan' }}
                        </button>
                    </div>
                </div>

                <!-- IDLE / STOP -->
                <button @click="activateIdleMode"
                        :disabled="sendingMode === 'idle' || espStatus !== 'online'"
                        class="w-full h-10 rounded-xl text-xs font-bold border border-border text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-40 active:scale-[0.97] transition-all">
                    ⏹ {{ lang === 'en' ? 'Stop — Return to Idle' : 'Stop — Kembali Idle' }}
                </button>

                <!-- Network warning -->
                <div v-if="espStatus === 'offline'" class="flex items-start gap-2 p-3 rounded-xl border border-amber-200 dark:border-amber-800/40 bg-amber-50 dark:bg-amber-950/10">
                    <span class="text-sm flex-shrink-0">⚠️</span>
                    <p class="text-[11px] text-amber-700 dark:text-amber-400">
                        {{ lang === 'en'
                            ? 'ESP32 is offline. Make sure you\'re on the same WiFi as the device and the IP is correct.'
                            : 'ESP32 offline. Pastikan Anda menggunakan WiFi yang sama dengan ESP32 dan IP sudah benar.' }}
                    </p>
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
                { label_id:'Total Scan RFID',     label_en:'Total RFID Scans', value: stats?.activity_count, icon:'📊', color:'text-emerald-600 dark:text-emerald-400' },
            ]" :key="item.label_id"
                 class="rounded-2xl border border-border bg-card p-4 animate-fade-in"
                 :style="`animation-delay: ${i*50}ms`">
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
        <div class="flex gap-1 p-1 rounded-2xl border border-border bg-muted/30 w-fit animate-fade-in delay-250">
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
                📋 {{ lang === 'en' ? 'RFID Activity Log' : 'Log Aktivitas RFID' }}
                <span v-if="stats?.activity_count > 0"
                      class="ml-1 text-[9px] font-bold px-1.5 py-0.5 rounded-full text-white bg-emerald-500">
                    {{ stats.activity_count }}
                </span>
            </button>
        </div>

        <!-- ══════════════════════════════════════════════════
             TAB: TAGGED BATCHES
        ══════════════════════════════════════════════════ -->
        <div v-show="activeTab === 'tagged'">
            <EmptyState v-if="!tagged_batches.length"
                icon="🪪"
                :title="lang === 'en' ? 'No tagged batches yet' : 'Belum ada batch yang ter-tag'"
                :description="lang === 'en'
                    ? 'Register RFID tags to batches using the Register mode above.'
                    : 'Daftarkan tag RFID ke batch menggunakan mode Register di atas.'"
            />

            <div v-else class="rounded-2xl border border-border bg-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/30">
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ lang === 'en' ? 'Batch' : 'Batch' }}
                                </th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    UID RFID Tag
                                </th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ lang === 'en' ? 'Stage' : 'Stage' }}
                                </th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ lang === 'en' ? 'Last Checkpoint' : 'Checkpoint Terakhir' }}
                                </th>
                                <th class="px-4 py-3 w-20"/>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="(batch, i) in tagged_batches" :key="batch.id"
                                class="group hover:bg-muted/20"
                                :style="`animation: fade-in 0.22s ease-out ${i*25}ms both`">
                                <td class="px-5 py-3.5">
                                    <Link :href="`/company/batches/${batch.id}`"
                                          class="font-mono text-xs font-bold text-foreground hover:text-primary">
                                        {{ batch.batch_code }}
                                    </Link>
                                    <p class="text-[10px] text-muted-foreground">{{ batch.product_code }}</p>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="font-mono text-xs font-bold text-amber-600 dark:text-amber-400">
                                        {{ batch.rfid_tag }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-muted text-muted-foreground">
                                        {{ getStageLabel(batch.process_stage) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <p v-if="batch.last_checkpoint" class="text-[11px] text-muted-foreground">
                                        {{ batch.last_checkpoint.stage?.replace(/_/g,' ') }}
                                    </p>
                                    <p v-if="batch.last_checkpoint" class="text-[10px] text-muted-foreground">
                                        {{ batch.last_checkpoint.recorded_at_diff }}
                                    </p>
                                    <span v-else class="text-[10px] text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <button v-if="canAdmin"
                                            @click="removeTag(batch)"
                                            :disabled="removingTagId === batch.id"
                                            class="opacity-0 group-hover:opacity-100 text-[10px] font-bold px-2 py-1 rounded-lg text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 active:scale-[0.97] disabled:opacity-40 transition-all">
                                        {{ lang === 'en' ? 'Remove' : 'Hapus' }}
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
                :title="lang === 'en' ? 'No RFID activity yet' : 'Belum ada aktivitas RFID'"
                :description="lang === 'en'
                    ? 'RFID scan events will appear here after bags are scanned.'
                    : 'Event scan RFID akan muncul di sini setelah karung di-scan.'"
            />

            <div v-else class="rounded-2xl border border-border bg-card overflow-hidden">
                <div class="divide-y divide-border">
                    <div v-for="(act, i) in recent_activity" :key="act.id"
                         class="flex items-start gap-3 px-5 py-3.5 hover:bg-muted/20"
                         :style="`animation: fade-in 0.22s ease-out ${i*20}ms both`">
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
.animate-fade-in   { animation: fade-in 0.28s ease-out both; }
.delay-50  { animation-delay: 50ms;  }
.delay-100 { animation-delay: 100ms; }
.delay-150 { animation-delay: 150ms; }
.delay-200 { animation-delay: 200ms; }
.delay-250 { animation-delay: 250ms; }

.toast-enter-active { transition: all 0.2s ease; }
.toast-leave-active { transition: all 0.15s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateX(-50%) translateY(20px); }

.slide-enter-active { transition: all 0.25s ease-out; }
.slide-leave-active { transition: all 0.2s ease-in; }
.slide-enter-from, .slide-leave-to { opacity: 0; transform: translateY(-8px); }
</style>