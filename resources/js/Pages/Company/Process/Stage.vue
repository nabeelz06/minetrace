<script setup>
/**
 * Stage.vue — Halaman detail satu tahap proses produksi.
 *
 * FIX UTAMA:
 * 1. TWO PANELS: "Incoming" (dari prev stage) + "Current" (di stage ini)
 * 2. Modal Terima Batch → POST process/{stage}/receive-batch
 * 3. Modal Checkpoint → POST process/{stage}/checkpoint
 * 4. Modal Forward → POST process/{stage}/forward-batch
 * 5. Modal Dry Transform → POST process/dry/transform (3 output)
 * 6. Modal Send to LTJ → POST process/warehouse/send-to-ltj
 * 7. onError handlers di semua form (fix white screen)
 */
import { ref, computed, inject, watch } from 'vue'
import { Head, Link, router, useForm }  from '@inertiajs/vue3'
import { usePage }                       from '@inertiajs/vue3'
import AppLayout                         from '@/Layouts/AppLayout.vue'
import StatusBadge                       from '@/components/shared/StatusBadge.vue'
import WeightDisplay                     from '@/components/shared/WeightDisplay.vue'
import EmptyState                        from '@/components/shared/EmptyState.vue'
import { useI18n }                       from '@/composables/core/useI18n.js'

const { lang } = useI18n()
const confirm  = inject('confirm', null)
const toast    = inject('toast', null)
const can      = inject('can', ref({}))
const page     = usePage()

// ─── Props ────────────────────────────────────────────────────────────────────
const props = defineProps({
    stage:                String,
    stage_config:         Object,
    batches:              Object,
    incoming_batches:     { type: Array, default: () => [] },
    stats:                Object,
    activity_log:         { type: Array, default: () => [] },
    threshold:            { type: Object, default: null },
    locations:            { type: Array, default: () => [] },
    next_stage_locations: { type: Array, default: () => [] },
    output_products:      { type: Array, default: () => [] },
    all_stages:           { type: Array, default: () => [] },
    filters:              { type: Object, default: () => ({}) },
    user_role:            String,
    current_time:         String,
})

// ─── Stage identity ───────────────────────────────────────────────────────────
const isReceiving    = computed(() => props.stage === 'receiving')
const isWet          = computed(() => props.stage === 'wet_process')
const isDry          = computed(() => props.stage === 'dry_process')
const isProjectPlant = computed(() => props.stage === 'project_plant')
const isWarehouse    = computed(() => props.stage === 'warehouse')
const hasIncoming    = computed(() => (props.incoming_batches?.length ?? 0) > 0)
const hasNext        = computed(() => !!props.stage_config?.next_stage)

// ─── Role capabilities ────────────────────────────────────────────────────────
const canReceive  = computed(() => {
    const roleMap = {
        'receiving':     ['company_admin', 'operator_wet'],
        'wet_process':   ['company_admin', 'operator_wet'],
        'dry_process':   ['company_admin', 'operator_dry'],
        'project_plant': ['company_admin', 'operator_lab_ltj'],
        'warehouse':     ['company_admin', 'operator_warehouse'],
    }
    const allowed = roleMap[props.stage] ?? ['company_admin']
    return allowed.includes(props.user_role)
})

const canCheckpoint = computed(() => [
    'company_admin', 'operator_lab_station', 'operator_lab_ltj',
    'operator_wet', 'operator_dry', 'operator_warehouse',
].includes(props.user_role))

const canDryTransform    = computed(() => ['company_admin', 'operator_dry'].includes(props.user_role))
const canSendToLtj       = computed(() => ['company_admin', 'operator_warehouse'].includes(props.user_role))
const canForward         = computed(() => canReceive.value && hasNext.value && !isDry.value)

// ─── Stage color mapping ──────────────────────────────────────────────────────
const stageColorMap = {
    lab_kimia:     { bg: 'bg-violet-100 dark:bg-violet-900/20', text: 'text-violet-700 dark:text-violet-300', dot: 'bg-violet-500',  border: 'border-violet-200 dark:border-violet-800/40' },
    receiving:     { bg: 'bg-blue-100 dark:bg-blue-900/20',     text: 'text-blue-700 dark:text-blue-300',     dot: 'bg-blue-500',    border: 'border-blue-200 dark:border-blue-800/40' },
    wet_process:   { bg: 'bg-cyan-100 dark:bg-cyan-900/20',     text: 'text-cyan-700 dark:text-cyan-300',     dot: 'bg-cyan-500',    border: 'border-cyan-200 dark:border-cyan-800/40' },
    dry_process:   { bg: 'bg-amber-100 dark:bg-amber-900/20',   text: 'text-amber-700 dark:text-amber-300',   dot: 'bg-amber-500',   border: 'border-amber-200 dark:border-amber-800/40' },
    project_plant: { bg: 'bg-emerald-100 dark:bg-emerald-900/20', text: 'text-emerald-700 dark:text-emerald-300', dot: 'bg-emerald-500', border: 'border-emerald-200 dark:border-emerald-800/40' },
    warehouse:     { bg: 'bg-teal-100 dark:bg-teal-900/20',     text: 'text-teal-700 dark:text-teal-300',     dot: 'bg-teal-500',    border: 'border-teal-200 dark:border-teal-800/40' },
}
function sc(stageName) { return stageColorMap[stageName] ?? stageColorMap.lab_kimia }
const currentColor = computed(() => sc(props.stage))

// ─── Filters ──────────────────────────────────────────────────────────────────
const search     = ref(props.filters?.search     ?? '')
const filterStatus = ref(props.filters?.status   ?? '')
const alertsOnly = ref(props.filters?.alerts_only ?? false)

let searchTimeout = null
function applyFilters() {
    router.get(
        `/company/process/${props.stage}`,
        { search: search.value || undefined, status: filterStatus.value || undefined, alerts_only: alertsOnly.value || undefined },
        { preserveState: true, replace: true, preserveScroll: true }
    )
}
function onSearch() { clearTimeout(searchTimeout); searchTimeout = setTimeout(applyFilters, 400) }

// ══════════════════════════════════════════════════════════════════════════════
// MODAL 1 — TERIMA BATCH (receive from previous stage)
// ══════════════════════════════════════════════════════════════════════════════
const showReceiveModal = ref(false)
const selectedForReceive = ref(null)

const receiveForm = useForm({ batch_id: '', location_id: '', notes: '' })

function openReceiveModal(batch) {
    selectedForReceive.value  = batch
    receiveForm.batch_id      = batch.id
    receiveForm.location_id   = props.locations?.[0]?.id ?? ''
    receiveForm.notes         = ''
    showReceiveModal.value    = true
}

function submitReceive() {
    receiveForm.post(`/company/process/${props.stage}/receive-batch`, {
        preserveScroll: true,
        onSuccess: () => {
            showReceiveModal.value = false
            receiveForm.reset()
        },
        onError: (errors) => {
            const msg = Object.values(errors)[0] ?? 'Terjadi kesalahan.'
            toast?.('error', msg, 5000)
        },
    })
}

// ══════════════════════════════════════════════════════════════════════════════
// MODAL 2 — CATAT CHECKPOINT (record weight, stay in stage)
// ══════════════════════════════════════════════════════════════════════════════
const showCheckpointModal = ref(false)
const selectedForCheckpoint = ref(null)

const checkpointForm = useForm({
    batch_id:        '',
    weight_recorded: '',
    location_id:     '',
    notes:           '',
    is_verified:     false,
})

// Live calculation: preview susut
const cpLossKg  = computed(() => {
    const prev = parseFloat(selectedForCheckpoint.value?.current_weight ?? 0)
    const curr = parseFloat(checkpointForm.weight_recorded ?? 0)
    return isNaN(prev - curr) ? 0 : Math.max(0, prev - curr)
})
const cpLossPct = computed(() => {
    const prev = parseFloat(selectedForCheckpoint.value?.current_weight ?? 0)
    if (!prev || !checkpointForm.weight_recorded) return 0
    return ((cpLossKg.value / prev) * 100).toFixed(2)
})
const cpIsWarning  = computed(() => parseFloat(cpLossPct.value) >= (props.threshold?.warning_pct ?? 5))
const cpIsCritical = computed(() => parseFloat(cpLossPct.value) >= (props.threshold?.critical_pct ?? 10))

function openCheckpointModal(batch) {
    selectedForCheckpoint.value      = batch
    checkpointForm.batch_id          = batch.id
    checkpointForm.weight_recorded   = batch.current_weight ?? ''
    checkpointForm.location_id       = batch.location?.id ?? props.locations?.[0]?.id ?? ''
    checkpointForm.notes             = ''
    checkpointForm.is_verified       = false
    showCheckpointModal.value        = true
}

async function submitCheckpoint() {
    if (cpIsWarning.value && confirm) {
        const ok = await confirm({
            title:        lang.value === 'en' ? 'Mass Balance Warning' : 'Peringatan Mass Balance',
            message:      lang.value === 'en'
                ? `Material loss is ${cpLossPct.value}% — above the ${cpIsCritical.value ? 'critical' : 'warning'} threshold.`
                : `Susut material ${cpLossPct.value}% — melebihi threshold ${cpIsCritical.value ? 'kritis' : 'peringatan'}.`,
            detail:       lang.value === 'en'
                ? 'Checkpoint will be flagged for review. Continue?'
                : 'Checkpoint akan ditandai untuk review. Lanjutkan?',
            confirmLabel: lang.value === 'en' ? 'Yes, Record' : 'Ya, Catat',
            cancelLabel:  lang.value === 'en' ? 'Cancel' : 'Batal',
            type:         cpIsCritical.value ? 'danger' : 'default',
        })
        if (!ok) return
    }

    checkpointForm.post(`/company/process/${props.stage}/checkpoint`, {
        preserveScroll: true,
        onSuccess: () => {
            showCheckpointModal.value = false
            checkpointForm.reset()
        },
        onError: (errors) => {
            const msg = Object.values(errors)[0] ?? 'Terjadi kesalahan saat mencatat checkpoint.'
            toast?.('error', msg, 5000)
        },
    })
}

// ══════════════════════════════════════════════════════════════════════════════
// MODAL 3 — KIRIM KE STAGE BERIKUTNYA (forward with weight update)
// ══════════════════════════════════════════════════════════════════════════════
const showForwardModal = ref(false)
const selectedForForward = ref(null)

const forwardForm = useForm({
    batch_id:        '',
    weight_recorded: '',
    location_id:     '',
    notes:           '',
    is_verified:     false,
})

const fwLossKg  = computed(() => {
    const prev = parseFloat(selectedForForward.value?.current_weight ?? 0)
    const curr = parseFloat(forwardForm.weight_recorded ?? 0)
    return isNaN(prev - curr) ? 0 : Math.max(0, prev - curr)
})
const fwLossPct = computed(() => {
    const prev = parseFloat(selectedForForward.value?.current_weight ?? 0)
    if (!prev || !forwardForm.weight_recorded) return 0
    return ((fwLossKg.value / prev) * 100).toFixed(2)
})
const fwIsWarning  = computed(() => parseFloat(fwLossPct.value) >= (props.threshold?.warning_pct ?? 5))
const fwIsCritical = computed(() => parseFloat(fwLossPct.value) >= (props.threshold?.critical_pct ?? 10))

const nextStageLabel = computed(() => {
    if (!props.stage_config?.next_stage) return ''
    const ns = props.all_stages?.find(s => s.stage === props.stage_config.next_stage)
    return lang.value === 'en' ? (ns?.label_en ?? ns?.label ?? '') : (ns?.label ?? '')
})

function openForwardModal(batch) {
    selectedForForward.value      = batch
    forwardForm.batch_id          = batch.id
    forwardForm.weight_recorded   = batch.current_weight ?? ''
    forwardForm.location_id       = props.next_stage_locations?.[0]?.id ?? ''
    forwardForm.notes             = ''
    forwardForm.is_verified       = false
    showForwardModal.value        = true
}

async function submitForward() {
    if (fwIsWarning.value && confirm) {
        const ok = await confirm({
            title:        lang.value === 'en' ? 'Mass Balance Warning' : 'Peringatan Mass Balance',
            message:      lang.value === 'en'
                ? `Material loss is ${fwLossPct.value}% when sending to ${nextStageLabel.value}.`
                : `Susut material ${fwLossPct.value}% saat mengirim ke ${nextStageLabel.value}.`,
            confirmLabel: lang.value === 'en' ? 'Yes, Send' : 'Ya, Kirim',
            cancelLabel:  lang.value === 'en' ? 'Cancel' : 'Batal',
            type:         fwIsCritical.value ? 'danger' : 'default',
        })
        if (!ok) return
    }

    forwardForm.post(`/company/process/${props.stage}/forward-batch`, {
        preserveScroll: true,
        onSuccess: () => {
            showForwardModal.value = false
            forwardForm.reset()
        },
        onError: (errors) => {
            const msg = Object.values(errors)[0] ?? 'Terjadi kesalahan saat mengirim batch.'
            toast?.('error', msg, 5000)
        },
    })
}

// ══════════════════════════════════════════════════════════════════════════════
// MODAL 4 — DRY PROCESS TRANSFORMATION (1 MIT → 3 konsentrat)
// ══════════════════════════════════════════════════════════════════════════════
const showTransformModal = ref(false)
const selectedForTransform = ref(null)

// Inisialisasi output dengan 3 slot (MON, ZIR, ILM)
function buildInitialOutputs() {
    const products = props.output_products ?? []
    // Sort: MON dulu, lalu ZIR, lalu ILM
    const order = ['MON', 'ZIR', 'ILM']
    const sorted = [...products].sort((a, b) => order.indexOf(a.code) - order.indexOf(b.code))
    return sorted.map(p => ({ product_code_id: p.id, weight_kg: '', percentage: '' }))
}

const transformForm = useForm({
    batch_id: '',
    notes:    '',
    outputs:  [],
})

const totalOutputWeight = computed(() =>
    transformForm.outputs.reduce((sum, o) => sum + (parseFloat(o.weight_kg) || 0), 0)
)
const inputBatchWeight = computed(() => parseFloat(selectedForTransform.value?.current_weight ?? 0))
const transformLossKg  = computed(() => Math.max(0, inputBatchWeight.value - totalOutputWeight.value))
const transformLossPct = computed(() =>
    inputBatchWeight.value > 0
        ? ((transformLossKg.value / inputBatchWeight.value) * 100).toFixed(2)
        : '0'
)
const transformIsValid = computed(() =>
    totalOutputWeight.value > 0 && totalOutputWeight.value <= inputBatchWeight.value &&
    transformForm.outputs.every(o => o.product_code_id && parseFloat(o.weight_kg) > 0)
)

function openTransformModal(batch) {
    selectedForTransform.value = batch
    transformForm.batch_id     = batch.id
    transformForm.notes        = ''
    transformForm.outputs      = buildInitialOutputs()
    showTransformModal.value   = true
}

// Update persentase otomatis saat berat diubah
function updatePercentage(idx) {
    const w = parseFloat(transformForm.outputs[idx].weight_kg) || 0
    if (inputBatchWeight.value > 0) {
        transformForm.outputs[idx].percentage = ((w / inputBatchWeight.value) * 100).toFixed(2)
    }
}

async function submitTransform() {
    if (!transformIsValid.value) {
        toast?.('error', lang.value === 'en'
            ? 'Please fill all outputs with valid weights.'
            : 'Isi semua output dengan berat yang valid.', 4000)
        return
    }

    if (confirm) {
        const ok = await confirm({
            title:        lang.value === 'en' ? 'Confirm Dry Process Transformation' : 'Konfirmasi Transformasi Dry Process',
            message:      lang.value === 'en'
                ? `Batch ${selectedForTransform.value?.batch_code} (${inputBatchWeight.value} kg) will be permanently transformed into ${transformForm.outputs.length} concentrate batches. This action cannot be undone.`
                : `Batch ${selectedForTransform.value?.batch_code} (${inputBatchWeight.value} kg) akan ditransformasi menjadi ${transformForm.outputs.length} batch konsentrat. Tindakan ini tidak dapat dibatalkan.`,
            confirmLabel: lang.value === 'en' ? 'Yes, Execute' : 'Ya, Eksekusi',
            cancelLabel:  lang.value === 'en' ? 'Cancel' : 'Batal',
            type:         'danger',
        })
        if (!ok) return
    }

    transformForm.post('/company/process/dry/transform', {
        preserveScroll: true,
        onSuccess: () => {
            showTransformModal.value = false
            transformForm.reset()
        },
        onError: (errors) => {
            const msg = Object.values(errors)[0] ?? 'Terjadi kesalahan saat transformasi.'
            toast?.('error', msg, 5000)
        },
    })
}

// ══════════════════════════════════════════════════════════════════════════════
// MODAL 5 — KIRIM MON KE LAB LTJ
// ══════════════════════════════════════════════════════════════════════════════
const showSendLtjModal = ref(false)
const selectedForLtj = ref(null)

const sendLtjForm = useForm({ batch_id: '', location_id: '', notes: '' })

function openSendLtjModal(batch) {
    selectedForLtj.value      = batch
    sendLtjForm.batch_id      = batch.id
    sendLtjForm.location_id   = ''
    sendLtjForm.notes         = ''
    showSendLtjModal.value    = true
}

function submitSendLtj() {
    sendLtjForm.post('/company/process/warehouse/send-to-ltj', {
        preserveScroll: true,
        onSuccess: () => {
            showSendLtjModal.value = false
            sendLtjForm.reset()
        },
        onError: (errors) => {
            const msg = Object.values(errors)[0] ?? 'Terjadi kesalahan.'
            toast?.('error', msg, 5000)
        },
    })
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function getStageLabel(stageName) {
    const s = props.all_stages?.find(x => x.stage === stageName)
    return lang.value === 'en' ? (s?.label_en ?? s?.label ?? stageName) : (s?.label ?? stageName)
}

function getProductLabel(code) {
    const map = { MON: 'Monasit', ZIR: 'Zirkon', ILM: 'Ilmenit' }
    return map[code] ?? code
}
</script>

<template>
<AppLayout>
    <Head :title="`${stage_config?.label ?? stage} — Proses Produksi`"/>
    <template #header>
        {{ lang === 'en' ? (stage_config?.label_en ?? stage) : stage_config?.label ?? stage }}
    </template>

    <div class="space-y-5">

        <!-- ══════════════════════════════════════════════════════════════
             STAGE NAVIGATION TABS
        ══════════════════════════════════════════════════════════════ -->
        <div class="flex gap-1 p-1 rounded-2xl border border-border bg-muted/30 overflow-x-auto animate-fade-in">
            <Link v-for="s in all_stages" :key="s.stage"
                  :href="s.href"
                  class="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold whitespace-nowrap flex-shrink-0 relative"
                  :style="s.is_current
                      ? 'background: hsl(var(--card)); color: hsl(var(--foreground)); box-shadow: 0 1px 3px rgba(0,0,0,0.1);'
                      : 'color: hsl(var(--muted-foreground));'"
            >
                <span class="text-sm">{{ s.icon }}</span>
                <span class="hidden sm:inline">{{ lang === 'en' ? s.label_en : s.label }}</span>
                <!-- Count badge -->
                <span v-if="s.count > 0"
                      class="inline-flex items-center justify-center w-4 h-4 rounded-full text-[9px] font-bold text-white"
                      style="background: hsl(var(--primary));">
                    {{ s.count > 99 ? '99+' : s.count }}
                </span>
            </Link>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             HEADER — Stage info + timestamp
        ══════════════════════════════════════════════════════════════ -->
        <div class="flex items-start justify-between gap-4 animate-fade-in">
            <div>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-2xl">{{ stage_config?.icon }}</span>
                    <h1 class="font-heading text-xl font-bold text-foreground">
                        {{ lang === 'en' ? stage_config?.label_en : stage_config?.label }}
                    </h1>
                </div>
                <p class="text-sm text-muted-foreground mt-0.5">
                    {{ lang === 'en' ? stage_config?.desc_en : stage_config?.desc }}
                </p>
            </div>
            <!-- Timestamp -->
            <div class="flex-shrink-0 hidden md:block">
                <div class="rounded-xl border border-border bg-card px-4 py-2 text-right">
                    <p class="text-[10px] text-muted-foreground uppercase tracking-wider">
                        {{ lang === 'en' ? 'System Time' : 'Waktu Sistem' }}
                    </p>
                    <p class="font-mono text-sm font-bold text-foreground">{{ current_time }}</p>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             STATS — 4 KPI cards
        ══════════════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 animate-fade-in delay-50">
            <!-- Incoming -->
            <div class="rounded-2xl border p-4"
                 :class="hasIncoming ? 'border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/10' : 'border-border bg-card'">
                <div class="flex items-start justify-between mb-2">
                    <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider leading-tight">
                        {{ lang === 'en' ? 'Incoming from' : 'Masuk dari' }}
                        {{ stage_config?.prev_stage ? getStageLabel(stage_config.prev_stage) : '—' }}
                    </p>
                    <span class="text-lg">📥</span>
                </div>
                <p class="font-heading text-3xl font-bold tabular-nums"
                   :class="hasIncoming ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'">
                    {{ stats?.incoming_count ?? incoming_batches?.length ?? 0 }}
                </p>
                <p v-if="hasIncoming" class="text-[10px] font-semibold text-amber-600 dark:text-amber-400 mt-1">
                    ⚠ {{ lang === 'en' ? 'Needs attention' : 'Perlu diproses' }}
                </p>
            </div>

            <!-- Current -->
            <div class="rounded-2xl border border-border bg-card p-4">
                <div class="flex items-start justify-between mb-2">
                    <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider leading-tight">
                        {{ lang === 'en' ? 'In This Stage' : 'Di Stage Ini' }}
                    </p>
                    <span class="text-lg">{{ stage_config?.icon }}</span>
                </div>
                <p class="font-heading text-3xl font-bold tabular-nums text-primary">
                    {{ stats?.current_count ?? 0 }}
                </p>
            </div>

            <!-- Alerts -->
            <div class="rounded-2xl border p-4"
                 :class="(stats?.alerts ?? 0) > 0 ? 'border-red-200 dark:border-red-800/40 bg-red-50 dark:bg-red-950/10' : 'border-border bg-card'">
                <div class="flex items-start justify-between mb-2">
                    <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider leading-tight">Alert</p>
                    <span class="text-lg">⚠️</span>
                </div>
                <p class="font-heading text-3xl font-bold tabular-nums"
                   :class="(stats?.alerts ?? 0) > 0 ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground'">
                    {{ stats?.alerts ?? 0 }}
                </p>
            </div>

            <!-- Weight -->
            <div class="rounded-2xl border border-border bg-card p-4">
                <div class="flex items-start justify-between mb-2">
                    <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider leading-tight">
                        {{ lang === 'en' ? 'Total Weight' : 'Total Berat' }}
                    </p>
                    <span class="text-lg">⚖️</span>
                </div>
                <WeightDisplay :kg="stats?.total_weight ?? 0" size="lg"/>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             MAIN PANELS — Incoming + Current
        ══════════════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">

            <!-- PANEL KIRI: INCOMING BATCHES ────────────────────────── -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-100">
                <div class="px-5 py-4 border-b border-border"
                     :class="hasIncoming ? 'bg-amber-50 dark:bg-amber-950/10' : ''">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div v-if="hasIncoming" class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"/>
                            <h3 class="font-heading text-sm font-bold text-foreground">
                                {{ lang === 'en' ? 'Incoming Batches' : 'Batch Masuk' }}
                                <span class="text-muted-foreground font-normal">
                                    ({{ lang === 'en' ? 'from' : 'dari' }}
                                    {{ stage_config?.prev_stage ? getStageLabel(stage_config.prev_stage) : '—' }})
                                </span>
                            </h3>
                        </div>
                        <span class="text-xs font-bold"
                              :class="hasIncoming ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'">
                            {{ incoming_batches?.length ?? 0 }} batch
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground mt-1">
                        {{ lang === 'en'
                            ? `These batches are ready to be received into ${stage_config?.label_en ?? stage}.`
                            : `Batch-batch ini siap diterima di ${stage_config?.label ?? stage}.` }}
                    </p>
                </div>

                <EmptyState v-if="!incoming_batches?.length"
                    icon="📥" size="sm"
                    :title="lang === 'en' ? 'No incoming batches' : 'Tidak ada batch masuk'"
                    :description="lang === 'en'
                        ? `No batches from ${stage_config?.prev_stage ? getStageLabel(stage_config.prev_stage) : 'previous stage'} yet.`
                        : `Belum ada batch dari ${stage_config?.prev_stage ? getStageLabel(stage_config.prev_stage) : 'stage sebelumnya'}.`"
                />

                <div v-else class="divide-y divide-border max-h-[420px] overflow-y-auto">
                    <div v-for="(batch, i) in incoming_batches" :key="batch.id"
                         class="flex items-start gap-3 px-5 py-3.5 hover:bg-muted/20"
                         :style="`animation: fade-in 0.25s ease-out ${i*35}ms both`">

                        <!-- Product badge -->
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center text-[10px] font-bold text-white flex-shrink-0 mt-0.5"
                             :style="{
                                 'MON': 'background:#ef4444',
                                 'ZIR': 'background:#3b82f6',
                                 'ILM': 'background:#f59e0b',
                             }[batch.product?.code] ?? 'background: hsl(var(--primary))'">
                            {{ (batch.product?.code ?? '??').slice(0, 3) }}
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <Link :href="`/company/batches/${batch.id}`"
                                      class="font-mono text-xs font-bold text-foreground hover:text-primary">
                                    {{ batch.batch_code }}
                                </Link>
                                <StatusBadge :status="batch.status" size="xs"/>
                                <span v-if="batch.product?.is_hazardous"
                                      class="text-[9px] font-bold px-1 rounded"
                                      style="background:rgba(239,68,68,0.1);color:#dc2626">
                                    ☢ RAD
                                </span>
                            </div>
                            <div class="flex items-center gap-2 mt-0.5">
                                <WeightDisplay :kg="batch.current_weight" size="xs"/>
                                <span class="text-[10px] text-muted-foreground">
                                    {{ batch.location?.name ?? '—' }}
                                </span>
                            </div>
                            <!-- Timestamp detail -->
                            <p class="text-[10px] text-muted-foreground mt-0.5">
                                {{ batch.created_at_diff }} · {{ batch.created_at_time }}
                            </p>
                        </div>

                        <!-- Action buttons -->
                        <div class="flex flex-col gap-1 flex-shrink-0">
                            <!-- Terima batch (receive-batch) -->
                            <button v-if="canReceive"
                                    @click="openReceiveModal(batch)"
                                    class="px-3 py-1.5 rounded-lg text-[10px] font-bold text-white hover:shadow-md active:scale-[0.97]"
                                    style="background: hsl(var(--primary));">
                                {{ lang === 'en' ? 'Receive →' : 'Terima →' }}
                            </button>
                            <!-- Special: Kirim ke Lab LTJ (hanya dari warehouse untuk MON) -->
                            <button v-if="isWarehouse && canSendToLtj && batch.product?.code === 'MON'"
                                    @click="openSendLtjModal(batch)"
                                    class="px-2 py-1.5 rounded-lg text-[10px] font-bold active:scale-[0.97]"
                                    style="background:rgba(124,58,237,0.12);color:#7c3aed">
                                {{ lang === 'en' ? '→ Lab LTJ' : '→ Lab LTJ' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PANEL KANAN: CURRENT BATCHES ────────────────────────── -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-150">
                <div class="px-5 py-4 border-b border-border">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full animate-pulse" :class="currentColor.dot"/>
                            <h3 class="font-heading text-sm font-bold text-foreground">
                                {{ lang === 'en' ? 'Active in Stage' : 'Aktif di Stage Ini' }}
                            </h3>
                        </div>
                        <!-- Search filter -->
                        <div class="relative">
                            <input v-model="search" @input="onSearch" type="text"
                                   :placeholder="lang === 'en' ? 'Search batch...' : 'Cari batch...'"
                                   class="h-8 pl-8 pr-3 rounded-lg border border-border bg-background text-xs text-foreground focus:outline-none focus:ring-1 w-36"/>
                            <svg class="absolute left-2.5 top-2 w-3.5 h-3.5 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                            </svg>
                        </div>
                    </div>
                </div>

                <EmptyState v-if="!batches?.data?.length"
                    :icon="stage_config?.icon ?? '📦'" size="sm"
                    :title="lang === 'en' ? `No batches in ${stage_config?.label_en ?? stage}` : `Tidak ada batch di ${stage_config?.label ?? stage}`"
                    :description="lang === 'en' ? 'Receive batches from the Incoming panel above.' : 'Terima batch dari panel Incoming di atas.'"
                />

                <div v-else class="divide-y divide-border max-h-[420px] overflow-y-auto">
                    <div v-for="(batch, i) in batches.data" :key="batch.id"
                         class="flex items-start gap-3 px-5 py-3.5 hover:bg-muted/20 group"
                         :style="`animation: fade-in 0.25s ease-out ${i*30}ms both`">

                        <!-- Product badge -->
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center text-[10px] font-bold text-white flex-shrink-0 mt-0.5"
                             :style="{
                                 'MON': 'background:#ef4444',
                                 'ZIR': 'background:#3b82f6',
                                 'ILM': 'background:#f59e0b',
                             }[batch.product?.code] ?? 'background: hsl(var(--primary))'">
                            {{ (batch.product?.code ?? '??').slice(0, 3) }}
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <Link :href="`/company/batches/${batch.id}`"
                                      class="font-mono text-xs font-bold text-foreground hover:text-primary">
                                    {{ batch.batch_code }}
                                </Link>
                                <StatusBadge :status="batch.status" size="xs"/>
                                <span v-if="batch.has_discrepancy"
                                      class="text-[9px] font-bold text-red-500">⚠ Alert</span>
                            </div>
                            <div class="flex items-center gap-3 mt-0.5">
                                <WeightDisplay :kg="batch.current_weight" size="xs"/>
                                <span v-if="batch.mass_loss_pct" class="text-[10px] text-muted-foreground">
                                    Susut {{ batch.mass_loss_pct }}%
                                </span>
                            </div>
                            <p class="text-[10px] text-muted-foreground mt-0.5">
                                {{ batch.updated_at_diff }} · {{ batch.created_at_date }}
                            </p>
                        </div>

                        <!-- Action buttons per stage -->
                        <div class="flex flex-col gap-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">

                            <!-- Catat Checkpoint (semua stage) -->
                            <button v-if="canCheckpoint && !isDry"
                                    @click="openCheckpointModal(batch)"
                                    class="px-2.5 py-1.5 rounded-lg text-[10px] font-bold active:scale-[0.97] border border-border text-muted-foreground hover:bg-muted hover:text-foreground">
                                {{ lang === 'en' ? 'Checkpoint' : 'Checkpoint' }}
                            </button>

                            <!-- Kirim ke Next Stage (receiving, wet) -->
                            <button v-if="canForward && (isReceiving || isWet)"
                                    @click="openForwardModal(batch)"
                                    class="px-2.5 py-1.5 rounded-lg text-[10px] font-bold text-white active:scale-[0.97]"
                                    style="background: hsl(207 40% 45%);">
                                {{ lang === 'en'
                                    ? `Send to ${stage_config?.next_stage ? getStageLabel(stage_config.next_stage) : 'Next'}`
                                    : `Kirim ke ${stage_config?.next_stage ? getStageLabel(stage_config.next_stage) : 'Berikutnya'}` }}
                            </button>

                            <!-- Dry Process: Transformasi -->
                            <button v-if="isDry && canDryTransform"
                                    @click="openTransformModal(batch)"
                                    class="px-2.5 py-1.5 rounded-lg text-[10px] font-bold text-white active:scale-[0.97]"
                                    style="background:linear-gradient(135deg,#f59e0b,#d97706)">
                                {{ lang === 'en' ? '⚡ Transform' : '⚡ Transformasi' }}
                            </button>

                            <!-- Warehouse: Kirim MON ke Lab LTJ -->
                            <button v-if="isWarehouse && canSendToLtj && batch.product?.code === 'MON'"
                                    @click="openSendLtjModal(batch)"
                                    class="px-2.5 py-1.5 rounded-lg text-[10px] font-bold active:scale-[0.97]"
                                    style="background:rgba(124,58,237,0.12);color:#7c3aed">
                                {{ lang === 'en' ? '→ Lab LTJ' : '→ Lab LTJ' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="batches?.last_page > 1"
                     class="px-5 py-3 border-t border-border flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">
                        {{ batches.from }}–{{ batches.to }} / {{ batches.total }}
                    </p>
                    <div class="flex gap-1">
                        <Link v-if="batches.prev_page_url" :href="batches.prev_page_url"
                              class="px-2.5 py-1 rounded-lg text-xs border border-border hover:bg-muted">← {{ lang === 'en' ? 'Prev' : 'Sebelum' }}</Link>
                        <Link v-if="batches.next_page_url" :href="batches.next_page_url"
                              class="px-2.5 py-1 rounded-lg text-xs border border-border hover:bg-muted">{{ lang === 'en' ? 'Next' : 'Berikut' }} →</Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             DRY PROCESS INFO BOX
        ══════════════════════════════════════════════════════════════ -->
        <div v-if="isDry && canDryTransform"
             class="rounded-2xl border border-amber-200 dark:border-amber-800/40 p-5 animate-fade-in delay-200"
             style="background:rgba(245,158,11,0.05)">
            <div class="flex items-start gap-3">
                <span class="text-2xl flex-shrink-0">🔥</span>
                <div>
                    <p class="font-heading text-sm font-bold text-foreground">
                        {{ lang === 'en' ? 'Dry Process — Magnetic Separator' : 'Dry Process — Magnetic Separator' }}
                    </p>
                    <p class="text-xs text-muted-foreground mt-1 leading-relaxed">
                        {{ lang === 'en'
                            ? 'Click "Transform" on an active batch to separate Tin By-Product (MIT) into 3 concentrates: Monazite (MON), Zircon (ZIR), and Ilmenite (ILM). All output batches will automatically go to the Warehouse.'
                            : 'Klik "Transformasi" pada batch yang aktif untuk memisahkan Mineral Ikutan Timah (MIT) menjadi 3 konsentrat: Monasit (MON), Zirkon (ZIR), dan Ilmenit (ILM). Semua batch output akan otomatis masuk ke Warehouse.' }}
                    </p>
                    <div class="flex gap-4 mt-2">
                        <span class="text-xs font-bold text-red-600 dark:text-red-400">🔴 MON — Lab LTJ</span>
                        <span class="text-xs font-bold text-blue-600 dark:text-blue-400">🔵 ZIR — Ekspor</span>
                        <span class="text-xs font-bold text-amber-600 dark:text-amber-400">🟡 ILM — Ekspor</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             THRESHOLD MASS BALANCE INFO
        ══════════════════════════════════════════════════════════════ -->
        <div v-if="threshold" class="rounded-2xl border border-border bg-card p-4 animate-fade-in delay-250">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0012 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52l2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 01-2.031.352 5.988 5.988 0 01-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971z"/>
                </svg>
                <p class="text-xs font-semibold text-muted-foreground">
                    {{ lang === 'en' ? 'Mass Balance Threshold' : 'Threshold Mass Balance' }}
                </p>
            </div>
            <div class="flex gap-6">
                <div>
                    <p class="text-[10px] text-muted-foreground">{{ lang === 'en' ? 'Warning' : 'Peringatan' }}</p>
                    <p class="text-sm font-bold text-amber-600 dark:text-amber-400">≥ {{ threshold.warning_pct }}%</p>
                </div>
                <div>
                    <p class="text-[10px] text-muted-foreground">{{ lang === 'en' ? 'Critical' : 'Kritis' }}</p>
                    <p class="text-sm font-bold text-red-600 dark:text-red-400">≥ {{ threshold.critical_pct }}%</p>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             ACTIVITY LOG
        ══════════════════════════════════════════════════════════════ -->
        <div v-if="activity_log?.length > 0"
             class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-300">
            <div class="px-5 py-4 border-b border-border">
                <h3 class="font-heading text-sm font-bold text-foreground">
                    {{ lang === 'en' ? 'Activity Log' : 'Log Aktivitas' }}
                </h3>
            </div>
            <div class="divide-y divide-border max-h-[280px] overflow-y-auto">
                <div v-for="(cp, i) in activity_log" :key="cp.id"
                     class="flex items-start gap-3 px-5 py-3 hover:bg-muted/20"
                     :style="`animation: fade-in 0.22s ease-out ${i*20}ms both`">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center text-[10px] flex-shrink-0 mt-0.5"
                         :class="cp.has_discrepancy
                             ? 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400'
                             : 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400'">
                        {{ cp.has_discrepancy ? '⚠' : '✓' }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <Link :href="`/company/batches/${cp.batch_id}`"
                                  class="font-mono text-[11px] font-bold text-foreground hover:text-primary">
                                {{ cp.batch_code }}
                            </Link>
                            <span class="text-[10px] text-muted-foreground">{{ cp.stage?.replace(/_/g,' ') }}</span>
                            <span v-if="cp.weight_loss_pct" class="text-[10px] font-semibold"
                                  :class="parseFloat(cp.weight_loss_pct) >= 5 ? 'text-red-500' : 'text-muted-foreground'">
                                Susut {{ cp.weight_loss_pct }}%
                            </span>
                        </div>
                        <p class="text-[10px] text-muted-foreground mt-0.5">
                            {{ cp.location }} · {{ cp.recorded_by }}
                        </p>
                    </div>
                    <div class="text-right flex-shrink-0">
                        <p class="text-[10px] font-semibold text-foreground">{{ cp.recorded_at_diff }}</p>
                        <p class="text-[10px] text-muted-foreground">{{ cp.recorded_at }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════
         MODAL 1 — TERIMA BATCH
    ══════════════════════════════════════════════════════════════════ -->
    <Transition name="modal">
        <div v-if="showReceiveModal"
             class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
             style="background:rgba(0,0,0,0.4);backdrop-filter:blur(6px);"
             @click.self="showReceiveModal = false">
            <div class="w-full max-w-md rounded-2xl border border-border bg-card shadow-xl p-6 space-y-4 dialog-in">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="font-heading text-base font-bold text-foreground">
                            📥 {{ lang === 'en' ? 'Receive Batch' : 'Terima Batch' }}
                        </h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ lang === 'en' ? 'Moving batch into' : 'Pindahkan batch ke' }}
                            {{ lang === 'en' ? stage_config?.label_en : stage_config?.label }}
                        </p>
                    </div>
                    <button @click="showReceiveModal = false" class="text-muted-foreground hover:text-foreground">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Batch info -->
                <div v-if="selectedForReceive" class="rounded-xl border border-border bg-muted/30 p-3">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center text-[10px] font-bold text-white"
                             :style="{'MON':'background:#ef4444','ZIR':'background:#3b82f6','ILM':'background:#f59e0b'}[selectedForReceive.product?.code] ?? 'background:hsl(var(--primary))'">
                            {{ (selectedForReceive.product?.code ?? '??').slice(0,3) }}
                        </div>
                        <div>
                            <p class="text-xs font-bold text-foreground font-mono">{{ selectedForReceive.batch_code }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ selectedForReceive.product?.name ?? selectedForReceive.product?.code }}
                                · <WeightDisplay :kg="selectedForReceive.current_weight" size="xs" inline/>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Location select -->
                <div v-if="locations?.length" class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground">
                        {{ lang === 'en' ? 'Destination Location' : 'Lokasi Tujuan' }}
                    </label>
                    <select v-model="receiveForm.location_id"
                            class="w-full h-9 px-3 rounded-xl border border-border bg-background text-sm text-foreground">
                        <option value="">{{ lang === 'en' ? 'Select location...' : 'Pilih lokasi...' }}</option>
                        <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                            {{ loc.full_name ?? loc.name }}
                        </option>
                    </select>
                </div>

                <!-- Notes -->
                <div class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground">
                        {{ lang === 'en' ? 'Notes (optional)' : 'Catatan (opsional)' }}
                    </label>
                    <textarea v-model="receiveForm.notes" rows="2"
                              :placeholder="lang === 'en' ? 'Receipt notes...' : 'Catatan penerimaan...'"
                              class="w-full px-3 py-2 rounded-xl border border-border bg-background text-sm text-foreground resize-none"/>
                </div>

                <!-- Errors -->
                <p v-if="receiveForm.errors.batch_id" class="text-xs text-red-500">{{ receiveForm.errors.batch_id }}</p>

                <div class="flex gap-3 pt-1">
                    <button @click="showReceiveModal = false"
                            class="flex-1 h-10 rounded-xl text-sm font-semibold border border-border text-muted-foreground hover:bg-muted">
                        {{ lang === 'en' ? 'Cancel' : 'Batal' }}
                    </button>
                    <button @click="submitReceive"
                            :disabled="receiveForm.processing"
                            class="flex-[2] h-10 rounded-xl text-sm font-bold text-white disabled:opacity-60"
                            style="background:linear-gradient(135deg,hsl(var(--primary)),hsl(207 30% 28%))">
                        {{ receiveForm.processing
                            ? (lang === 'en' ? 'Processing...' : 'Memproses...')
                            : (lang === 'en' ? '✓ Receive Batch' : '✓ Terima Batch') }}
                    </button>
                </div>
            </div>
        </div>
    </Transition>

    <!-- ══════════════════════════════════════════════════════════════════
         MODAL 2 — CATAT CHECKPOINT
    ══════════════════════════════════════════════════════════════════ -->
    <Transition name="modal">
        <div v-if="showCheckpointModal"
             class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
             style="background:rgba(0,0,0,0.4);backdrop-filter:blur(6px);"
             @click.self="showCheckpointModal = false">
            <div class="w-full max-w-md rounded-2xl border border-border bg-card shadow-xl p-6 space-y-4 dialog-in">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="font-heading text-base font-bold text-foreground">
                            📋 {{ lang === 'en' ? 'Record Checkpoint' : 'Catat Checkpoint' }}
                        </h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ lang === 'en' ? 'Update weight without changing stage' : 'Update berat tanpa mengubah stage' }}
                        </p>
                    </div>
                    <button @click="showCheckpointModal = false" class="text-muted-foreground hover:text-foreground">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Batch info row -->
                <div v-if="selectedForCheckpoint" class="rounded-xl border border-border bg-muted/30 p-3 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-bold font-mono text-foreground">{{ selectedForCheckpoint.batch_code }}</p>
                        <p class="text-xs text-muted-foreground">{{ selectedForCheckpoint.product?.code }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-muted-foreground">{{ lang === 'en' ? 'Current weight' : 'Berat saat ini' }}</p>
                        <p class="text-sm font-bold text-foreground">{{ selectedForCheckpoint.current_weight?.toLocaleString('id-ID') }} kg</p>
                    </div>
                </div>

                <!-- Weight input -->
                <div class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground">
                        {{ lang === 'en' ? 'Weight Recorded (kg)' : 'Berat Tercatat (kg)' }}
                        <span class="text-red-500">*</span>
                    </label>
                    <input v-model="checkpointForm.weight_recorded" type="number" step="0.01" min="0"
                           :placeholder="lang === 'en' ? 'Enter weight...' : 'Masukkan berat...'"
                           class="w-full h-10 px-3 rounded-xl border text-sm font-mono text-foreground"
                           :class="cpIsWarning ? 'border-amber-400 bg-amber-50 dark:bg-amber-950/10' : 'border-border bg-background'"/>
                    <!-- Live preview -->
                    <div v-if="checkpointForm.weight_recorded" class="flex items-center gap-3 text-xs">
                        <span class="text-muted-foreground">{{ lang === 'en' ? 'Loss:' : 'Susut:' }}</span>
                        <span class="font-mono font-bold" :class="cpIsWarning ? 'text-amber-600' : 'text-emerald-600'">
                            {{ cpLossKg.toFixed(2) }} kg ({{ cpLossPct }}%)
                        </span>
                        <span v-if="cpIsCritical" class="font-bold text-red-500">⚠ KRITIS</span>
                        <span v-else-if="cpIsWarning" class="font-bold text-amber-500">⚠ Peringatan</span>
                    </div>
                </div>

                <!-- Location -->
                <div v-if="locations?.length" class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground">{{ lang === 'en' ? 'Location' : 'Lokasi' }}</label>
                    <select v-model="checkpointForm.location_id"
                            class="w-full h-9 px-3 rounded-xl border border-border bg-background text-sm text-foreground">
                        <option value="">{{ lang === 'en' ? 'Select...' : 'Pilih...' }}</option>
                        <option v-for="loc in locations" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
                    </select>
                </div>

                <!-- Verified toggle -->
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="checkbox" v-model="checkpointForm.is_verified"
                           class="w-4 h-4 rounded border-border"/>
                    <span class="text-xs font-semibold text-foreground">
                        {{ lang === 'en' ? 'Mark as verified (physical count confirmed)' : 'Tandai sebagai terverifikasi (sesuai pengecekan fisik)' }}
                    </span>
                </label>

                <!-- Notes -->
                <div class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground">{{ lang === 'en' ? 'Notes (optional)' : 'Catatan (opsional)' }}</label>
                    <textarea v-model="checkpointForm.notes" rows="2"
                              :placeholder="lang === 'en' ? 'Relevant notes...' : 'Catatan yang relevan...'"
                              class="w-full px-3 py-2 rounded-xl border border-border bg-background text-sm text-foreground resize-none"/>
                </div>

                <div class="flex gap-3 pt-1">
                    <button @click="showCheckpointModal = false"
                            class="flex-1 h-10 rounded-xl text-sm font-semibold border border-border text-muted-foreground hover:bg-muted">
                        {{ lang === 'en' ? 'Cancel' : 'Batal' }}
                    </button>
                    <button @click="submitCheckpoint"
                            :disabled="checkpointForm.processing || !checkpointForm.weight_recorded"
                            class="flex-[2] h-10 rounded-xl text-sm font-bold text-white disabled:opacity-60"
                            :class="cpIsCritical ? 'bg-red-600 hover:bg-red-700' : cpIsWarning ? 'bg-amber-600 hover:bg-amber-700' : ''"
                            :style="!cpIsWarning ? 'background:linear-gradient(135deg,hsl(var(--primary)),hsl(207 30% 28%))' : ''">
                        {{ checkpointForm.processing
                            ? (lang === 'en' ? 'Recording...' : 'Menyimpan...')
                            : (lang === 'en' ? 'Record Checkpoint' : 'Catat Checkpoint') }}
                    </button>
                </div>
            </div>
        </div>
    </Transition>

    <!-- ══════════════════════════════════════════════════════════════════
         MODAL 3 — FORWARD BATCH (kirim ke stage berikutnya)
    ══════════════════════════════════════════════════════════════════ -->
    <Transition name="modal">
        <div v-if="showForwardModal"
             class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
             style="background:rgba(0,0,0,0.4);backdrop-filter:blur(6px);"
             @click.self="showForwardModal = false">
            <div class="w-full max-w-md rounded-2xl border border-border bg-card shadow-xl p-6 space-y-4 dialog-in">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="font-heading text-base font-bold text-foreground">
                            📤 {{ lang === 'en' ? `Send to ${nextStageLabel}` : `Kirim ke ${nextStageLabel}` }}
                        </h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ lang === 'en'
                                ? 'Record final weight and advance batch to next stage.'
                                : 'Catat berat akhir dan pindahkan batch ke stage berikutnya.' }}
                        </p>
                    </div>
                    <button @click="showForwardModal = false" class="text-muted-foreground hover:text-foreground">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Batch info -->
                <div v-if="selectedForForward" class="rounded-xl border border-border bg-muted/30 p-3 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-bold font-mono text-foreground">{{ selectedForForward.batch_code }}</p>
                        <p class="text-xs text-muted-foreground">{{ selectedForForward.product?.code ?? '—' }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-muted-foreground">Berat saat ini</p>
                        <p class="text-sm font-bold text-foreground">{{ selectedForForward.current_weight?.toLocaleString('id-ID') }} kg</p>
                    </div>
                </div>
                
                <div class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground">
                        {{ lang === 'en' ? 'Weight After Process (kg)' : 'Berat Setelah Proses (kg)' }}
                        <span class="text-red-500">*</span>
                    </label>
                    <input v-model="forwardForm.weight_recorded" type="number" step="0.01" min="0.01"
                        class="w-full h-10 px-3 rounded-xl border text-sm font-mono text-foreground"
                        :class="fwIsWarning ? 'border-amber-400 bg-amber-50 dark:bg-amber-950/10' : 'border-border bg-background'"/>
                    <div v-if="forwardForm.weight_recorded" class="flex items-center gap-3 text-xs">
                        <span class="text-muted-foreground">Susut:</span>
                        <span class="font-mono font-bold" :class="fwIsWarning ? 'text-amber-600' : 'text-emerald-600'">
                            {{ fwLossKg.toFixed(2) }} kg ({{ fwLossPct }}%)
                        </span>
                        <span v-if="fwIsCritical" class="font-bold text-red-500">⚠ KRITIS</span>
                    </div>
                </div>

                <div v-if="next_stage_locations?.length" class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground">
                        {{ lang === 'en' ? 'Destination Location' : 'Lokasi Tujuan' }}
                        ({{ nextStageLabel }})
                    </label>
                    <select v-model="forwardForm.location_id"
                            class="w-full h-9 px-3 rounded-xl border border-border bg-background text-sm text-foreground">
                        <option value="">{{ lang === 'en' ? 'Select...' : 'Pilih...' }}</option>
                        <option v-for="loc in next_stage_locations" :key="loc.id" :value="loc.id">{{ loc.full_name ?? loc.name }}</option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground">{{ lang === 'en' ? 'Notes' : 'Catatan' }}</label>
                    <textarea v-model="forwardForm.notes" rows="2"
                            class="w-full px-3 py-2 rounded-xl border border-border bg-background text-sm text-foreground resize-none"/>
                </div>

                <div class="flex gap-3 pt-1">
                    <button @click="showForwardModal = false"
                            class="flex-1 h-10 rounded-xl text-sm font-semibold border border-border text-muted-foreground hover:bg-muted">
                        {{ lang === 'en' ? 'Cancel' : 'Batal' }}
                    </button>
                    <button @click="submitForward"
                            :disabled="forwardForm.processing || !forwardForm.weight_recorded"
                            class="flex-[2] h-10 rounded-xl text-sm font-bold text-white disabled:opacity-60"
                            style="background:linear-gradient(135deg,hsl(207 40% 45%),hsl(207 35% 35%))">
                        {{ forwardForm.processing ? 'Mengirim...' : `→ Kirim ke ${nextStageLabel}` }}
                    </button>
                </div>
            </div>
        </div>
    </Transition>

    <Transition name="modal">
        <div v-if="showTransformModal"
            class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
            style="background:rgba(0,0,0,0.4);backdrop-filter:blur(6px);"
            @click.self="showTransformModal = false">
            <div class="w-full max-w-lg rounded-2xl border border-border bg-card shadow-xl p-6 space-y-5 dialog-in">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="font-heading text-base font-bold text-foreground">
                            🔥 {{ lang === 'en' ? 'Dry Process — Magnetic Separator' : 'Dry Process — Magnetic Separator' }}
                        </h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ lang === 'en'
                                ? '1 MIT batch → 3 mineral concentrate batches (MON + ZIR + ILM)'
                                : '1 batch Mineral Ikutan Timah → 3 batch konsentrat (MON + ZIR + ILM)' }}
                        </p>
                    </div>
                    <button @click="showTransformModal = false" class="text-muted-foreground hover:text-foreground">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div v-if="selectedForTransform"
                    class="rounded-xl border border-amber-200 dark:border-amber-800/40 bg-amber-50 dark:bg-amber-950/10 p-3">
                    <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider mb-1">
                        {{ lang === 'en' ? 'Input Batch (MIT)' : 'Batch Input (MIT)' }}
                    </p>
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-bold font-mono text-foreground">{{ selectedForTransform.batch_code }}</p>
                        <p class="text-sm font-bold text-amber-700 dark:text-amber-300">
                            {{ selectedForTransform.current_weight?.toLocaleString('id-ID') }} kg
                        </p>
                    </div>
                </div>

                <div class="space-y-3">
                    <p class="text-xs font-semibold text-foreground">
                        {{ lang === 'en' ? 'Output Concentrates (Magnetic Separator Result)' : 'Konsentrat Output (Hasil Magnetic Separator)' }}
                    </p>

                    <div v-for="(output, idx) in transformForm.outputs" :key="idx"
                        class="rounded-xl border p-3 space-y-2"
                        :class="{
                            'border-red-200 dark:border-red-800/40 bg-red-50 dark:bg-red-950/10':   output_products?.[idx]?.code === 'MON',
                            'border-blue-200 dark:border-blue-800/40 bg-blue-50 dark:bg-blue-950/10': output_products?.[idx]?.code === 'ZIR',
                            'border-amber-200 dark:border-amber-800/40 bg-amber-50 dark:bg-amber-950/10': output_products?.[idx]?.code === 'ILM',
                        }">
                        <div class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center text-[10px] font-bold text-white"
                                :style="{'MON':'background:#ef4444','ZIR':'background:#3b82f6','ILM':'background:#f59e0b'}[output_products?.[idx]?.code ?? ''] ?? 'background:#6b7280'">
                                {{ (output_products?.[idx]?.code ?? `#${idx+1}`).slice(0,3) }}
                            </div>
                            <div>
                                <p class="text-xs font-bold text-foreground">{{ getProductLabel(output_products?.[idx]?.code) }}</p>
                                <p class="text-[10px] text-muted-foreground">
                                    {{ output_products?.[idx]?.is_export_blocked
                                        ? (lang === 'en' ? '🔒 Export blocked — send to LTJ Lab' : '🔒 Diblokir ekspor — kirim ke Lab LTJ')
                                        : (lang === 'en' ? '✓ Ready for export' : '✓ Siap ekspor') }}
                                </p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="space-y-1">
                                <label class="text-[10px] font-semibold text-muted-foreground">
                                    {{ lang === 'en' ? 'Weight (kg)' : 'Berat (kg)' }} <span class="text-red-500">*</span>
                                </label>
                                <input v-model="output.weight_kg" type="number" step="0.01" min="0.01"
                                    @input="updatePercentage(idx)"
                                    :placeholder="`0.00 kg`"
                                    class="w-full h-8 px-2.5 rounded-lg border border-border bg-background text-xs font-mono text-foreground"/>
                            </div>
                            <div class="space-y-1">
                                <label class="text-[10px] font-semibold text-muted-foreground">% (auto)</label>
                                <input v-model="output.percentage" type="number" step="0.01" min="0" max="100"
                                    readonly
                                    class="w-full h-8 px-2.5 rounded-lg border border-border bg-muted text-xs font-mono text-muted-foreground cursor-not-allowed"/>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-border bg-muted/30 p-3 space-y-2">
                    <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {{ lang === 'en' ? 'Mass Balance Summary' : 'Ringkasan Mass Balance' }}
                    </p>
                    <div class="grid grid-cols-3 gap-3 text-xs">
                        <div>
                            <p class="text-muted-foreground">Input</p>
                            <p class="font-bold font-mono text-foreground">{{ inputBatchWeight.toFixed(2) }} kg</p>
                        </div>
                        <div>
                            <p class="text-muted-foreground">Output</p>
                            <p class="font-bold font-mono text-foreground">{{ totalOutputWeight.toFixed(2) }} kg</p>
                        </div>
                        <div>
                            <p class="text-muted-foreground">{{ lang === 'en' ? 'Loss' : 'Susut' }}</p>
                            <p class="font-bold font-mono" :class="parseFloat(transformLossPct) > 5 ? 'text-amber-600' : 'text-emerald-600'">
                                {{ transformLossKg.toFixed(2) }} kg ({{ transformLossPct }}%)
                            </p>
                        </div>
                    </div>
                    <p v-if="totalOutputWeight > inputBatchWeight" class="text-xs font-bold text-red-500">
                        ⚠ {{ lang === 'en' ? 'Output exceeds input weight!' : 'Total output melebihi berat input!' }}
                    </p>
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground">{{ lang === 'en' ? 'Notes' : 'Catatan' }}</label>
                    <textarea v-model="transformForm.notes" rows="2"
                            :placeholder="lang === 'en' ? 'Transformation notes...' : 'Catatan transformasi...'"
                            class="w-full px-3 py-2 rounded-xl border border-border bg-background text-sm text-foreground resize-none"/>
                </div>

                <div class="flex gap-3">
                    <button @click="showTransformModal = false"
                            class="flex-1 h-10 rounded-xl text-sm font-semibold border border-border text-muted-foreground hover:bg-muted">
                        {{ lang === 'en' ? 'Cancel' : 'Batal' }}
                    </button>
                    <button @click="submitTransform"
                            :disabled="transformForm.processing || !transformIsValid"
                            class="flex-[2] h-10 rounded-xl text-sm font-bold text-white disabled:opacity-60"
                            style="background:linear-gradient(135deg,#f59e0b,#d97706)">
                        {{ transformForm.processing
                            ? (lang === 'en' ? 'Executing...' : 'Memproses...')
                            : (lang === 'en' ? '⚡ Execute Transformation' : '⚡ Eksekusi Transformasi') }}
                    </button>
                </div>
            </div>
        </div>
    </Transition>

    <Transition name="modal">
        <div v-if="showSendLtjModal"
            class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
            style="background:rgba(0,0,0,0.4);backdrop-filter:blur(6px);"
            @click.self="showSendLtjModal = false">
            <div class="w-full max-w-md rounded-2xl border border-border bg-card shadow-xl p-6 space-y-4 dialog-in">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="font-heading text-base font-bold text-foreground">
                            ⚗️ {{ lang === 'en' ? 'Send MON to LTJ Lab' : 'Kirim MON ke Lab LTJ' }}
                        </h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ lang === 'en'
                                ? 'Send Monazite to Project Plant for LTJ element analysis.'
                                : 'Kirim Monasit ke Project Plant untuk analisis kandungan unsur LTJ.' }}
                        </p>
                    </div>
                    <button @click="showSendLtjModal = false" class="text-muted-foreground hover:text-foreground">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div v-if="selectedForLtj"
                    class="rounded-xl border border-red-200 dark:border-red-800/40 bg-red-50 dark:bg-red-950/10 p-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded-lg bg-red-500 flex items-center justify-center text-[10px] font-bold text-white">MON</div>
                            <div>
                                <p class="text-xs font-bold font-mono text-foreground">{{ selectedForLtj.batch_code }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ lang === 'en' ? 'Monazite — Radioactive' : 'Monasit — Radioaktif' }}
                                </p>
                            </div>
                        </div>
                        <p class="text-sm font-bold text-red-700 dark:text-red-300">
                            {{ selectedForLtj.current_weight?.toLocaleString('id-ID') }} kg
                        </p>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground">{{ lang === 'en' ? 'Notes (optional)' : 'Catatan (opsional)' }}</label>
                    <textarea v-model="sendLtjForm.notes" rows="2"
                            class="w-full px-3 py-2 rounded-xl border border-border bg-background text-sm text-foreground resize-none"/>
                </div>

                <div class="flex gap-3">
                    <button @click="showSendLtjModal = false"
                            class="flex-1 h-10 rounded-xl text-sm font-semibold border border-border text-muted-foreground hover:bg-muted">
                        {{ lang === 'en' ? 'Cancel' : 'Batal' }}
                    </button>
                    <button @click="submitSendLtj"
                            :disabled="sendLtjForm.processing"
                            class="flex-[2] h-10 rounded-xl text-sm font-bold text-white disabled:opacity-60"
                            style="background:linear-gradient(135deg,#7c3aed,#5b21b6)">
                        {{ sendLtjForm.processing
                            ? (lang === 'en' ? 'Sending...' : 'Mengirim...')
                            : (lang === 'en' ? '→ Send to LTJ Lab' : '→ Kirim ke Lab LTJ') }}
                    </button>
                </div>
            </div>
        </div>
    </Transition>
    </AppLayout> 
    </template> 

    <style scoped> 
    @keyframes fade-in { 
        from { opacity: 0; transform: translateY(5px); } 
        to { opacity: 1; transform: translateY(0); } 
    } 
    .animate-fade-in { animation: fade-in 0.28s ease-out both; } 
    .delay-50 { animation-delay: 50ms; } 
    .delay-100 { animation-delay: 100ms; } 
    .delay-150 { animation-delay: 150ms; } 
    .delay-200 { animation-delay: 200ms; } 
    .delay-250 { animation-delay: 250ms; } 
    .delay-300 { animation-delay: 300ms; } 

    /* Modals */ 
    .modal-enter-active { transition: opacity 0.2s ease; } 
    .modal-leave-active { transition: opacity 0.15s ease; } 
    .modal-enter-from, .modal-leave-to { opacity: 0; } 

    @keyframes dialog-in { 
        from { opacity: 0; transform: scale(0.94) translateY(6px); } 
        to { opacity: 1; transform: scale(1) translateY(0); } 
    } 
    .dialog-in { animation: dialog-in 0.22s cubic-bezier(0.175, 0.885, 0.32, 1.275); } 
    </style>