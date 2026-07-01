<script setup>
/**
 * RfidFieldMode.vue — Mode Lapangan RFID (Progressive Authorization)
 *
 * Sebelumnya bernama MobileRfidScan.vue. Di-rename + diperluas sebagai
 * bagian dari konsolidasi navigasi RFID (Section 3 Part B):
 *   - RfidPanel.vue     → konsol admin/supervisor (desktop, konfigurasi penuh)
 *   - RfidFieldMode.vue → interface operator lapangan (mobile, satu tujuan: scan)
 *
 * Operator role + mobile User-Agent di-redirect otomatis ke sini oleh
 * HardwareController::rfidPanel() (lihat B2). company_admin/company_auditor
 * tetap melihat RfidPanel.vue penuh, dengan link manual ke halaman ini
 * ("Buka Mode Lapangan").
 *
 * Tampilan mobile-first untuk operator lapangan:
 *   1. Lihat mode otorisasi per stage (audit_only | scan_to_receive | scan_to_dispatch)
 *   2. Simulasi/eksekusi scan dengan live batch match + preview CCTV
 *   3. Admin: ubah mode otorisasi per stage
 *
 * Tiga mode otorisasi:
 *   audit_only       → scan = audit trail saja (standar)
 *   scan_to_receive  → scan IN = otomatis terima batch ke stage ini
 *   scan_to_dispatch → scan OUT = otomatis kirim batch ke stage berikutnya
 */
import { ref, computed, onUnmounted } from 'vue'
import { Head, Link, router }         from '@inertiajs/vue3'
import AppLayout                      from '@/Layouts/AppLayout.vue'
import { useI18n }                    from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    authorization_modes: { type: Object,  default: () => ({}) },
    valid_modes:         { type: Array,   default: () => [] },
    tagged_batches:      { type: Array,   default: () => [] },
    recent_activity:     { type: Array,   default: () => [] },
    user_role:           String,
    company_id:          Number,
    // Kode company (mis. 'TIMAH') — WAJIB dikirim sebagai header
    // X-Company-Code pada setiap panggilan /api/rfid/*. Endpoint ini
    // berjalan di bawah middleware group 'api' (stateless, tanpa session),
    // sehingga tanpa header ini request akan jatuh ke
    // RFID_DEFAULT_COMPANY_CODE (.env) alih-alih company milik user login.
    company_code:        { type: String, default: null },
    // true jika user boleh melihat link balik ke panel penuh (B3)
    can_view_full_panel: { type: Boolean, default: false },
    current_time:        String,
})

const isAdmin   = computed(() => props.user_role === 'company_admin')
const activeTab = ref('modes')

// ─── Bilingual labels (B5) ──────────────────────────────────────────────────
const t = computed(() => ({
    title:          lang.value === 'en' ? 'Field RFID Scan'            : 'Mode Lapangan RFID',
    subtitle:       lang.value === 'en' ? 'Progressive Authorization Model' : 'Progressive Authorization Model',
    openFullPanel:  lang.value === 'en' ? 'Open Full Panel'            : 'Buka Panel Lengkap',
    tabModes:       lang.value === 'en' ? 'Mode'                       : 'Mode',
    tabScan:        lang.value === 'en' ? 'Scan'                       : 'Scan',
    tabLog:         lang.value === 'en' ? 'Log'                        : 'Log',
    adminOnlyMode:  lang.value === 'en'
        ? 'Only company_admin can change authorization mode.'
        : 'Hanya company_admin yang dapat mengubah mode otorisasi.',
    scanIn:         lang.value === 'en' ? 'Scan IN'  : 'Scan IN',
    scanOut:        lang.value === 'en' ? 'Scan OUT' : 'Scan OUT',
    processing:     lang.value === 'en' ? 'Processing...' : 'Memproses...',
    noActivity:     lang.value === 'en' ? 'No RFID activity yet.' : 'Belum ada aktivitas RFID.',
    cctvPreview:    lang.value === 'en' ? 'Latest CCTV Snapshot' : 'Snapshot CCTV Terbaru',
    waitingSnapshot: lang.value === 'en' ? 'Waiting for snapshot capture...' : 'Menunggu snapshot terekam...',
    area:           lang.value === 'en' ? 'Area' : 'Area',
}))

// ─── Stages ──────────────────────────────────────────────────────────────────
const stages = [
    { key: 'receiving',     label: 'Receiving Area',   icon: '📥' },
    { key: 'wet_process',   label: 'Wet Process',      icon: '💧' },
    { key: 'dry_process',   label: 'Dry Process',      icon: '🔥' },
    { key: 'warehouse',     label: 'LTJ Warehouse',    icon: '🏭' },
    { key: 'project_plant', label: 'Lab Analisis LTJ', icon: '⚗️' },
]

// ─── Toast ────────────────────────────────────────────────────────────────────
const toastMsg    = ref(''); const toastType = ref('info'); const toastVisible = ref(false)
let toastTimer    = null

function showToast(msg, type = 'info') {
    toastMsg.value = msg; toastType.value = type; toastVisible.value = true
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => { toastVisible.value = false }, 4500)
}

// ─── Common request headers (X-Company-Code + X-Requested-With) ─────────────
function scanHeaders() {
    return {
        'Content-Type':      'application/json',
        'Accept':            'application/json',
        'X-CSRF-TOKEN':      document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        'X-Requested-With':  'XMLHttpRequest',
        ...(props.company_code ? { 'X-Company-Code': props.company_code } : {}),
    }
}

// ─── Mode change ──────────────────────────────────────────────────────────────
const savingStage = ref(null)

async function setMode(stage, mode) {
    if (!isAdmin.value) { showToast(t.value.adminOnlyMode, 'error'); return }
    savingStage.value = stage

    try {
        const res = await fetch('/api/v1/company/rfid/authorization-modes', {
            method: 'POST',
            credentials: 'include',
            headers: scanHeaders(),
            body: JSON.stringify({ stage, mode }),
        })
        const data = await res.json()
        if (data.success) {
            showToast(`Mode ${stage} → ${mode}`, 'success')
            router.reload({ only: ['authorization_modes'] })
        } else {
            showToast(data.message ?? 'Gagal.', 'error')
        }
    } catch (e) {
        showToast('Error: ' + e.message, 'error')
    } finally {
        savingStage.value = null
    }
}

// ─── Scan ─────────────────────────────────────────────────────────────────────
const simUid       = ref('')
const simStage     = ref('receiving')
const simResult    = ref(null)
const isSimulating = ref(false)
const simAction    = ref(null)

function generateUid() {
    simUid.value = Array.from({ length: 4 }, () =>
        Math.floor(Math.random() * 256).toString(16).padStart(2, '0')
    ).join('').toUpperCase()
}

/** Getarkan perangkat sebagai konfirmasi hasil scan (sukses vs gagal berbeda pola) */
function vibrateResult(success) {
    if (!('vibrate' in navigator)) return
    navigator.vibrate(success ? [40, 60, 40] : [200])
}

async function simulateScan(action) {
    if (!simUid.value || !simStage.value) {
        showToast('Masukkan UID dan pilih stage.', 'error'); return
    }

    isSimulating.value = true; simAction.value = action; simResult.value = null

    const endpoint = action === 'scan_in' ? '/api/rfid/login' : '/api/rfid/logout'
    try {
        const res = await fetch(endpoint, {
            method: 'POST',
            headers: scanHeaders(),
            body: JSON.stringify({ uid: simUid.value.toUpperCase(), stage: simStage.value }),
        })
        const data = await res.json()
        simResult.value = { ...data, action, uid: simUid.value, stage: simStage.value }
        showToast(data.message, data.status === 'success' ? 'success' : 'error')
        vibrateResult(data.status === 'success')

        if (data.status === 'success') {
            startSnapshotPolling(data.batch_id)
            router.reload({ only: ['tagged_batches', 'recent_activity'] })
        }
    } catch (e) {
        showToast('Error: ' + e.message, 'error')
        vibrateResult(false)
    } finally {
        isSimulating.value = false
    }
}

// ─── CCTV Snapshot Preview (poll recent-snapshots setelah scan) ──────────────
const latestSnapshot    = ref(null)
const isPollingSnapshot = ref(false)
let snapshotPollTimer    = null
let snapshotPollAttempts = 0

function startSnapshotPolling() {
    stopSnapshotPolling()
    latestSnapshot.value    = null
    isPollingSnapshot.value = true
    snapshotPollAttempts    = 0

    snapshotPollTimer = setInterval(async () => {
        snapshotPollAttempts++
        try {
            const res  = await fetch('/api/v1/company/cctv/recent-snapshots', {
                credentials: 'include',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            const json  = await res.json()
            const match = (json.data ?? []).find(s => s.rfid_uid === simUid.value.toUpperCase())
            if (match) {
                latestSnapshot.value = match
                stopSnapshotPolling()
            }
        } catch {
            // polling gagal diam-diam — tidak kritis, UI tetap tampilkan "menunggu"
        }

        // Batasi polling maksimum ~16 detik (job delay 2s + probe + capture)
        if (snapshotPollAttempts >= 8) stopSnapshotPolling()
    }, 2000)
}

function stopSnapshotPolling() {
    if (snapshotPollTimer) clearInterval(snapshotPollTimer)
    snapshotPollTimer       = null
    isPollingSnapshot.value = false
}

onUnmounted(() => stopSnapshotPolling())

// ─── Helpers ─────────────────────────────────────────────────────────────────
function modeColor(mode) {
    return {
        'audit_only':       'border-muted bg-muted/20 text-muted-foreground',
        'scan_to_receive':  'border-emerald-300 dark:border-emerald-700/50 bg-emerald-50 dark:bg-emerald-950/20 text-emerald-700 dark:text-emerald-300',
        'scan_to_dispatch': 'border-blue-300 dark:border-blue-700/50 bg-blue-50 dark:bg-blue-950/20 text-blue-700 dark:text-blue-300',
    }[mode] ?? 'border-border bg-card text-foreground'
}

function modeIcon(mode) {
    return { 'audit_only': '📋', 'scan_to_receive': '✅', 'scan_to_dispatch': '🚀' }[mode] ?? '❓'
}

function modeLabel(mode) {
    return {
        'audit_only':       'Audit Only',
        'scan_to_receive':  'Auto-Receive',
        'scan_to_dispatch': 'Auto-Dispatch',
    }[mode] ?? mode
}
</script>

<template>
<AppLayout>
    <Head :title="t.title"/>
    <template #header>{{ t.title }} — Progressive Authorization</template>

    <!-- Toast -->
    <Transition name="toast">
        <div v-if="toastVisible"
             class="fixed bottom-5 left-1/2 -translate-x-1/2 z-50 px-5 py-2.5 rounded-xl border text-sm font-semibold shadow-xl whitespace-nowrap"
             :class="{
                 'bg-emerald-50 dark:bg-emerald-950/90 border-emerald-400 text-emerald-700': toastType==='success',
                 'bg-red-50 dark:bg-red-950/90 border-red-400 text-red-700': toastType==='error',
                 'bg-card border-border text-foreground': toastType==='info',
             }">{{ toastMsg }}</div>
    </Transition>

    <div class="max-w-lg mx-auto space-y-4">

        <!-- Header -->
        <div class="text-center py-2 space-y-1">
            <h1 class="font-heading text-lg font-bold text-foreground">📱 {{ t.title }}</h1>
            <p class="text-xs text-muted-foreground">{{ t.subtitle }}</p>
            <!-- B3: Link balik ke panel penuh — hanya untuk admin/auditor -->
            <Link v-if="can_view_full_panel"
                  href="/company/hardware/rfid"
                  class="inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline mt-1">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                {{ t.openFullPanel }}
            </Link>
        </div>

        <!-- Info Banner -->
        <div class="rounded-xl border border-primary/20 p-4" style="background:hsl(var(--primary)/0.04)">
            <p class="text-xs font-bold text-foreground mb-2">Mode Otorisasi RFID</p>
            <div class="space-y-1.5">
                <div class="flex items-center gap-2 text-[11px] text-muted-foreground">
                    <span class="text-sm">📋</span>
                    <strong class="text-foreground">Audit Only</strong> — scan = log audit saja (manual confirm via web)
                </div>
                <div class="flex items-center gap-2 text-[11px] text-muted-foreground">
                    <span class="text-sm">✅</span>
                    <strong class="text-foreground">Auto-Receive</strong> — scan IN = otomatis terima batch ke stage
                </div>
                <div class="flex items-center gap-2 text-[11px] text-muted-foreground">
                    <span class="text-sm">🚀</span>
                    <strong class="text-foreground">Auto-Dispatch</strong> — scan OUT = otomatis kirim ke stage berikutnya
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 p-1 rounded-xl border border-border bg-muted/30">
            <button @click="activeTab='modes'"
                    class="flex-1 py-2 rounded-lg text-xs font-semibold transition-all"
                    :style="activeTab==='modes'?'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 2px rgba(0,0,0,0.08);':'color:hsl(var(--muted-foreground));'">
                🛡 {{ t.tabModes }}
            </button>
            <button @click="activeTab='scan'"
                    class="flex-1 py-2 rounded-lg text-xs font-semibold transition-all"
                    :style="activeTab==='scan'?'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 2px rgba(0,0,0,0.08);':'color:hsl(var(--muted-foreground));'">
                📡 {{ t.tabScan }}
            </button>
            <button @click="activeTab='log'"
                    class="flex-1 py-2 rounded-lg text-xs font-semibold transition-all"
                    :style="activeTab==='log'?'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 2px rgba(0,0,0,0.08);':'color:hsl(var(--muted-foreground));'">
                📋 {{ t.tabLog }}
            </button>
        </div>

        <!-- ── TAB: MODES ──────────────────────────────────────────────── -->
        <div v-show="activeTab==='modes'" class="space-y-3">
            <div v-for="stage in stages" :key="stage.key"
                 class="rounded-xl border bg-card p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span>{{ stage.icon }}</span>
                        <p class="text-xs font-bold text-foreground">{{ stage.label }}</p>
                    </div>
                    <span class="text-[9px] font-bold px-2 py-0.5 rounded border"
                          :class="modeColor(authorization_modes[stage.key]?.mode)">
                        {{ modeIcon(authorization_modes[stage.key]?.mode) }}
                        {{ modeLabel(authorization_modes[stage.key]?.mode) }}
                    </span>
                </div>

                <p class="text-[10px] text-muted-foreground">
                    {{ authorization_modes[stage.key]?.description }}
                </p>

                <!-- Mode selector — admin only -->
                <div v-if="isAdmin" class="grid grid-cols-3 gap-1.5">
                    <button v-for="vm in valid_modes" :key="vm.mode"
                            @click="setMode(stage.key, vm.mode)"
                            :disabled="savingStage === stage.key || authorization_modes[stage.key]?.mode === vm.mode"
                            class="py-1.5 px-2 rounded-lg text-[10px] font-semibold border transition-all active:scale-[0.97] disabled:opacity-50"
                            :class="authorization_modes[stage.key]?.mode === vm.mode
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-border hover:bg-muted text-muted-foreground'">
                        {{ modeIcon(vm.mode) }} {{ vm.label.split('-')[0] }}
                    </button>
                </div>
            </div>

            <p v-if="!isAdmin" class="text-center text-[11px] text-muted-foreground">
                ℹ️ {{ t.adminOnlyMode }}
            </p>
        </div>

        <!-- ── TAB: SCAN ──────────────────────────────────────────────── -->
        <div v-show="activeTab==='scan'" class="space-y-4">
            <!-- UID -->
            <div class="rounded-xl border border-border bg-card p-4 space-y-3">
                <p class="text-xs font-bold text-foreground">UID Tag RFID</p>
                <div class="flex gap-2">
                    <input v-model="simUid" type="text" placeholder="A3B2C1D0"
                           class="flex-1 h-10 px-3 rounded-lg border border-border bg-background text-sm font-mono font-bold text-foreground uppercase outline-none focus:border-primary"/>
                    <button @click="generateUid"
                            class="px-3 h-10 rounded-lg text-xs font-bold border border-border hover:bg-muted text-muted-foreground">
                        Generate
                    </button>
                </div>

                <div>
                    <p class="text-[10px] text-muted-foreground mb-1.5">Stage</p>
                    <select v-model="simStage"
                            class="w-full h-9 px-3 rounded-lg border border-border bg-background text-xs font-semibold text-foreground outline-none">
                        <option v-for="s in stages" :key="s.key" :value="s.key">
                            {{ s.icon }} {{ s.label }}
                            — {{ modeLabel(authorization_modes[s.key]?.mode ?? 'audit_only') }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Scan buttons — target sentuh besar untuk penggunaan lapangan -->
            <div class="grid grid-cols-2 gap-3">
                <button @click="simulateScan('scan_in')"
                        :disabled="!simUid||isSimulating"
                        class="h-16 rounded-xl text-sm font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                        style="background:linear-gradient(135deg,#059669,#047857)">
                    <span v-if="isSimulating&&simAction==='scan_in'" class="text-xs">{{ t.processing }}</span>
                    <span v-else>📥 {{ t.scanIn }}</span>
                </button>
                <button @click="simulateScan('scan_out')"
                        :disabled="!simUid||isSimulating"
                        class="h-16 rounded-xl text-sm font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                        style="background:linear-gradient(135deg,#dc2626,#b91c1c)">
                    <span v-if="isSimulating&&simAction==='scan_out'" class="text-xs">{{ t.processing }}</span>
                    <span v-else>📤 {{ t.scanOut }}</span>
                </button>
            </div>

            <!-- Result — indikator besar, jelas sukses/gagal -->
            <Transition name="slide">
                <div v-if="simResult" class="rounded-xl border-2 p-4"
                     :class="simResult.status==='success'?'border-emerald-400 bg-emerald-50 dark:bg-emerald-950/20':'border-red-400 bg-red-50 dark:bg-red-950/20'">
                    <div class="flex items-start gap-3 mb-2">
                        <span class="text-3xl leading-none">{{ simResult.status==='success' ? '✅' : '❌' }}</span>
                        <div class="flex-1">
                            <p class="text-sm font-bold text-foreground">{{ simResult.message }}</p>
                            <p v-if="simResult.batch_code" class="text-xs font-mono font-semibold text-foreground mt-0.5">
                                {{ simResult.batch_code }}
                            </p>
                            <p v-if="simResult.stage_label" class="text-[10px] text-muted-foreground mt-0.5">
                                {{ t.area }}: {{ simResult.stage_label }}
                            </p>
                        </div>
                    </div>
                    <!-- Progressive auth result -->
                    <div v-if="simResult.transition_attempted" class="mt-2 rounded-lg p-2 border"
                         :class="simResult.transition_success ? 'border-emerald-200 bg-emerald-50 dark:bg-emerald-950/20' : 'border-amber-200 bg-amber-50 dark:bg-amber-950/20'">
                        <p class="text-[10px] font-bold"
                           :class="simResult.transition_success ? 'text-emerald-700' : 'text-amber-600'">
                            {{ simResult.transition_success
                                ? '🔄 ' + simResult.transition_message
                                : '⚠️ Transisi tidak berlaku: ' + simResult.transition_reason }}
                        </p>
                    </div>

                    <!-- CCTV Snapshot Preview — poll recent-snapshots pasca scan sukses -->
                    <div v-if="simResult.status==='success'" class="mt-3 pt-3 border-t border-border/50">
                        <p class="text-[10px] font-bold text-muted-foreground uppercase tracking-wide mb-2">
                            {{ t.cctvPreview }}
                        </p>
                        <div v-if="latestSnapshot" class="rounded-lg overflow-hidden border border-border">
                            <img :src="latestSnapshot.photo_url" :alt="latestSnapshot.camera_name"
                                 class="w-full aspect-video object-cover"/>
                            <div class="px-2 py-1.5 bg-muted/40 flex items-center justify-between">
                                <span class="text-[10px] font-semibold text-foreground">{{ latestSnapshot.camera_name }}</span>
                                <span class="text-[9px] text-muted-foreground">{{ latestSnapshot.captured_at }}</span>
                            </div>
                        </div>
                        <div v-else-if="isPollingSnapshot"
                             class="rounded-lg border border-dashed border-border p-4 flex items-center justify-center gap-2 text-[11px] text-muted-foreground">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                            </svg>
                            {{ t.waitingSnapshot }}
                        </div>
                    </div>
                </div>
            </Transition>
        </div>

        <!-- ── TAB: LOG ──────────────────────────────────────────────── -->
        <div v-show="activeTab==='log'" class="space-y-2">
            <p v-if="!recent_activity.length" class="text-center text-xs text-muted-foreground py-8">
                {{ t.noActivity }}
            </p>
            <div v-for="act in recent_activity.slice(0, 20)" :key="act.id"
                 class="rounded-xl border border-border bg-card p-3">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded"
                          :class="act.action?.includes('in') ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700' : 'bg-red-100 dark:bg-red-900/30 text-red-600'">
                        {{ act.action?.includes('in') ? 'SCAN IN' : 'SCAN OUT' }}
                    </span>
                    <span class="text-[10px] text-muted-foreground">{{ act.recorded_at_diff }}</span>
                </div>
                <p class="text-xs font-mono font-bold text-foreground">{{ act.batch_code }}</p>
                <p class="text-[10px] text-muted-foreground">
                    UID: {{ act.rfid_uid }} · {{ act.scan_stage ?? act.stage }}
                    <span v-if="!act.is_verified" class="ml-1 text-amber-500">⏳</span>
                </p>
            </div>
        </div>

    </div>
</AppLayout>
</template>

<style scoped>
.toast-enter-active { transition:all 0.2s ease; }
.toast-leave-active { transition:all 0.15s ease; }
.toast-enter-from, .toast-leave-to { opacity:0; transform:translateX(-50%) translateY(16px); }

.slide-enter-active { transition:all 0.2s ease-out; }
.slide-leave-active { transition:all 0.15s ease-in; }
.slide-enter-from, .slide-leave-to { opacity:0; transform:translateY(-6px); }
</style>
