<script setup>
/**
 * MobileRfidScan.vue — Progressive Authorization RFID Mobile Interface
 *
 * Tampilan mobile-first untuk operator lapangan:
 *   1. Lihat mode otorisasi per stage (audit_only | scan_to_receive | scan_to_dispatch)
 *   2. Simulasi scan untuk testing di lapangan
 *   3. Admin: ubah mode otorisasi per stage
 *
 * Tiga mode otorisasi:
 *   audit_only       → scan = audit trail saja (standar)
 *   scan_to_receive  → scan IN = otomatis terima batch ke stage ini
 *   scan_to_dispatch → scan OUT = otomatis kirim batch ke stage berikutnya
 */
import { ref, computed } from 'vue'
import { Head, router }  from '@inertiajs/vue3'
import AppLayout         from '@/Layouts/AppLayout.vue'
import { useI18n }       from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    authorization_modes: { type: Object,  default: () => ({}) },
    valid_modes:         { type: Array,   default: () => [] },
    tagged_batches:      { type: Array,   default: () => [] },
    recent_activity:     { type: Array,   default: () => [] },
    user_role:           String,
    company_id:          Number,
    current_time:        String,
})

const isAdmin   = computed(() => props.user_role === 'company_admin')
const activeTab = ref('modes')

// ─── Stages ──────────────────────────────────────────────────────────────────
const stages = [
    { key: 'receiving',     label: 'Receiving Area',   icon: '📥' },
    { key: 'wet_process',   label: 'Wet Process',      icon: '💧' },
    { key: 'dry_process',   label: 'Dry Process',      icon: '🔥' },
    { key: 'warehouse',     label: 'LTJ Warehouse',    icon: '🏭' },
    { key: 'project_plant', label: 'Lab Analisis LTJ', icon: '⚗️' },
]

// ─── Mode change ──────────────────────────────────────────────────────────────
const savingStage = ref(null)
const toastMsg    = ref(''); const toastType = ref('info'); const toastVisible = ref(false)
let toastTimer    = null

function showToast(msg, type = 'info') {
    toastMsg.value = msg; toastType.value = type; toastVisible.value = true
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => { toastVisible.value = false }, 4500)
}

async function setMode(stage, mode) {
    if (!isAdmin.value) { showToast('Hanya admin yang dapat mengubah mode.', 'error'); return }
    savingStage.value = stage

    try {
        const res = await fetch('/api/v1/company/rfid/authorization-modes', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
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

// ─── Simulation ───────────────────────────────────────────────────────────────
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

async function simulateScan(action) {
    if (!simUid.value || !simStage.value) {
        showToast('Masukkan UID dan pilih stage.', 'error'); return
    }

    isSimulating.value = true; simAction.value = action; simResult.value = null

    const endpoint = action === 'scan_in' ? '/api/rfid/login' : '/api/rfid/logout'
    try {
        const res = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ uid: simUid.value.toUpperCase(), stage: simStage.value }),
        })
        const data = await res.json()
        simResult.value = { ...data, action, uid: simUid.value, stage: simStage.value }
        showToast(data.message, data.status === 'success' ? 'success' : 'error')
    } catch (e) {
        showToast('Error: ' + e.message, 'error')
    } finally {
        isSimulating.value = false
    }
}

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
    <Head title="Mobile RFID Scan"/>
    <template #header>Mobile RFID — Progressive Authorization</template>

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
        <div class="text-center py-2">
            <h1 class="font-heading text-lg font-bold text-foreground">📱 Mobile RFID Scan</h1>
            <p class="text-xs text-muted-foreground mt-0.5">Progressive Authorization Model</p>
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
                🛡 Mode
            </button>
            <button @click="activeTab='scan'"
                    class="flex-1 py-2 rounded-lg text-xs font-semibold transition-all"
                    :style="activeTab==='scan'?'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 2px rgba(0,0,0,0.08);':'color:hsl(var(--muted-foreground));'">
                📡 Scan
            </button>
            <button @click="activeTab='log'"
                    class="flex-1 py-2 rounded-lg text-xs font-semibold transition-all"
                    :style="activeTab==='log'?'background:hsl(var(--card));color:hsl(var(--foreground));box-shadow:0 1px 2px rgba(0,0,0,0.08);':'color:hsl(var(--muted-foreground));'">
                📋 Log
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
                ℹ️ Hanya company_admin yang dapat mengubah mode otorisasi.
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

            <!-- Scan buttons -->
            <div class="grid grid-cols-2 gap-3">
                <button @click="simulateScan('scan_in')"
                        :disabled="!simUid||isSimulating"
                        class="h-14 rounded-xl text-sm font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                        style="background:linear-gradient(135deg,#059669,#047857)">
                    <span v-if="isSimulating&&simAction==='scan_in'" class="text-xs">Memproses...</span>
                    <span v-else>📥 Scan IN</span>
                </button>
                <button @click="simulateScan('scan_out')"
                        :disabled="!simUid||isSimulating"
                        class="h-14 rounded-xl text-sm font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                        style="background:linear-gradient(135deg,#dc2626,#b91c1c)">
                    <span v-if="isSimulating&&simAction==='scan_out'" class="text-xs">Memproses...</span>
                    <span v-else>📤 Scan OUT</span>
                </button>
            </div>

            <!-- Result -->
            <Transition name="slide">
                <div v-if="simResult" class="rounded-xl border p-4"
                     :class="simResult.status==='success'?'border-emerald-300 dark:border-emerald-700/50':'border-red-300 dark:border-red-700/50'">
                    <div class="flex items-start gap-2 mb-2">
                        <span class="text-xl">{{ simResult.status==='success' ? '✅' : '❌' }}</span>
                        <div class="flex-1">
                            <p class="text-xs font-bold text-foreground">{{ simResult.message }}</p>
                            <p v-if="simResult.stage_label" class="text-[10px] text-muted-foreground mt-0.5">
                                Area: {{ simResult.stage_label }}
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
                </div>
            </Transition>
        </div>

        <!-- ── TAB: LOG ──────────────────────────────────────────────── -->
        <div v-show="activeTab==='log'" class="space-y-2">
            <p v-if="!recent_activity.length" class="text-center text-xs text-muted-foreground py-8">
                Belum ada aktivitas RFID.
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
