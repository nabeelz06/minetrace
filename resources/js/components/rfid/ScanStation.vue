<script setup>
/**
 * ScanStation — Scan Masuk / Scan Keluar dari satu stasiun.
 *
 * - Pilih stasiun SEKALI (reader terdaftar atau tahap manual); pilihan diingat per arah di perangkat ini.
 * - Banner mode permanen: ikon + teks + warna berbeda untuk MASUK vs KELUAR; konsekuensi mode otorisasi
 *   diterjemahkan ke kalimat dampak SEBELUM scan.
 * - Umpan langsung (polling sadar-visibilitas) memperlihatkan pembacaan reader; input manual/USB (UID + Enter)
 *   untuk fallback. Dedupe/idempotensi dijamin server (event_id per submit).
 * - Tag tidak dikenal → tawarkan "Daftarkan tag ini". Salah scan → "Batalkan" beralasan (koreksi, bukan hapus).
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useLocalStorage } from '@vueuse/core'
import AppIcon from '@/components/app/AppIcon.vue'
import ResultBanner from '@/components/app/ResultBanner.vue'
import VoidScanDialog from '@/components/rfid/VoidScanDialog.vue'
import { rfidApi, useApiText } from '@/composables/rfid/useRfidApi.js'
import { usePolling } from '@/composables/core/usePolling.js'
import { useI18n } from '@/composables/core/useI18n.js'
import { formatTime, groupUid, newEventId } from '@/lib/format'

const props = defineProps({
    direction:         { type: String, required: true },    // 'in' | 'out'
    readers:           { type: Array, default: () => [] },
    stages:            { type: Array, default: () => [] },
    modes:             { type: Object, default: () => ({}) },
    canOperate:        { type: Boolean, default: false },
    simulationEnabled: { type: Boolean, default: false },
    field:             { type: Boolean, default: false },
})
const emit = defineEmits(['register-uid'])

const { t, lang, loc } = useI18n()
const apiText = useApiText()
const isIn = computed(() => props.direction === 'in')

// ─── Stasiun ──────────────────────────────────────────────────────────────────
const stationKey = useLocalStorage(`minetrace-rfid-station-${props.direction}`, '')
const manualStage = useLocalStorage(`minetrace-rfid-stage-${props.direction}`, '')
const validReaders = computed(() => props.readers.filter(r => r.is_active && (r.direction === 'any' || r.direction === props.direction)))
const reader = computed(() => {
    const m = /^r:(\d+)$/.exec(stationKey.value || '')
    return m ? validReaders.value.find(r => r.id === Number(m[1])) ?? null : null
})
const stageKey = computed(() => reader.value?.stage || manualStage.value || '')
const needsStage = computed(() => !reader.value?.stage)
const stageObj = computed(() => props.stages.find(s => s.key === stageKey.value))
const stageLabel = key => { const s = props.stages.find(x => x.key === key); return s ? loc(s) : (key ?? '—') }

// Pilihan awal: reader terakhir yang valid, kalau tidak ada → reader pertama → manual
watch(validReaders, list => {
    if (stationKey.value && !/^r:\d+$/.test(stationKey.value)) return
    if (!reader.value) stationKey.value = list[0] ? `r:${list[0].id}` : ''
}, { immediate: true })

// ─── Mode otorisasi → kalimat dampak ──────────────────────────────────────────
const mode = computed(() => props.modes?.[stageKey.value]?.mode ?? 'audit_only')
const isAuto = computed(() => (isIn.value && mode.value === 'scan_to_receive') || (!isIn.value && mode.value === 'scan_to_dispatch'))
const modeText = computed(() => isAuto.value ? t(isIn.value ? 'rfid.scan.mode_receive' : 'rfid.scan.mode_dispatch') : t('rfid.scan.mode_audit'))

// ─── Simulasi ─────────────────────────────────────────────────────────────────
const simulate = ref(false)

// ─── Input manual & hasil ─────────────────────────────────────────────────────
const uid = ref('')
const inputRef = ref(null)
const submitting = ref(false)
const last = ref(null)          // { type, title, desc, extra, uid, code }

async function submit() {
    const value = uid.value.trim()
    if (value.length < 4 || submitting.value || !props.canOperate) return
    if (!stageKey.value && !reader.value) { last.value = { type: 'error', title: t('rfid.scan.station_none') }; return }
    submitting.value = true
    const res = await rfidApi.scan({
        uid: value, direction: props.direction, event_id: newEventId(), simulate: simulate.value,
        reader_id: reader.value?.id ?? null, stage: reader.value?.stage ? null : stageKey.value,
    })
    submitting.value = false

    if (res.ok) {
        const d = res.data
        const failed = d.transition_attempted && !d.transition_success
        last.value = {
            type: failed ? 'warning' : 'success',
            title: t('rfid.scan.ok_title'),
            desc: t('rfid.scan.ok_desc', { batch: d.batch_code, product: d.product ?? '—', stage: d.stage_label ?? stageLabel(d.scan_stage) }),
            extra: failed ? t('rfid.scan.transition_fail', { reason: d.transition_reason })
                : (d.transition_attempted ? t('rfid.scan.transition_ok', { msg: d.transition_message }) : (d.duplicate ? t('rfid.scan.duplicate_note') : null)),
        }
        navigator.vibrate?.(failed ? [80, 60, 80] : 60)
        uid.value = ''
        fetchFeed(false)
    } else {
        last.value = { type: 'error', title: apiText(res), code: res.code, uid: value }
        navigator.vibrate?.([120, 60, 120])
    }
    await nextTick()
    inputRef.value?.focus()
}

// ─── Umpan scan ───────────────────────────────────────────────────────────────
const feed = ref([])
const rejects = ref([])
const lastId = ref(0)

async function fetchFeed(initial = false) {
    if (!stageKey.value && !reader.value) return true
    const params = { direction: props.direction, limit: initial ? 10 : 20, include_rejects: 1 }
    if (reader.value) params.reader_id = reader.value.id; else params.stage = stageKey.value
    if (!initial && lastId.value) params.since_id = lastId.value
    const res = await rfidApi.scans(params)
    if (!res.ok) return false
    const rows = res.data
    if (initial) { feed.value = rows; lastId.value = rows[0]?.id ?? 0 }
    else if (rows.length) {
        const fresh = rows.filter(r => !feed.value.some(f => f.id === r.id))
        if (fresh.length) {
            feed.value = [...fresh, ...feed.value].slice(0, 10)
            lastId.value = rows[0].id
            if (!submitting.value) {
                const r = fresh[0]
                last.value = { type: 'success', title: t('rfid.scan.ok_title'), desc: t('rfid.scan.ok_desc', { batch: r.batch_code, product: r.product_code ?? '—', stage: stageLabel(r.stage) }) }
                navigator.vibrate?.(60)
            }
        }
    }
    rejects.value = (res.raw?.rejects ?? []).filter(r => r.direction === props.direction).slice(0, 5)
    return true
}

const poll = usePolling(() => fetchFeed(false), { interval: 2500, immediate: false })

watch([() => reader.value?.id, stageKey], async () => {
    feed.value = []; rejects.value = []; lastId.value = 0; last.value = null
    await fetchFeed(true)
}, { immediate: true })

onMounted(() => {
    poll.start()
    if (window.matchMedia?.('(pointer: fine)').matches) inputRef.value?.focus()   // tidak memunculkan keyboard di layar sentuh
})

// ─── Batalkan scan ────────────────────────────────────────────────────────────
const voidTarget = ref(null)
const voidOpen = ref(false)
function askVoid(item) { voidTarget.value = item; voidOpen.value = true }
function onVoided(updated) {
    feed.value = feed.value.map(f => f.id === updated.id ? { ...f, ...updated } : f)
    last.value = { type: 'info', title: t('rfid.err.scan_voided') }
}

const SRC = { simulation: 'rfid.hist.src_sim', manual: 'rfid.hist.src_manual' }
</script>

<template>
    <section :aria-label="t(isIn ? 'rfid.view.in' : 'rfid.view.out')" class="space-y-4">
        <!-- Pita simulasi -->
        <div v-if="simulate" role="status" class="rounded-lg bg-foreground px-4 py-2 text-center text-sm font-bold uppercase tracking-wider text-background">
            {{ t('rfid.sim_ribbon') }}
        </div>

        <!-- Banner mode permanen: ikon + teks + warna berbeda untuk MASUK vs KELUAR -->
        <div class="flex items-center gap-4 rounded-xl border-2 p-4" :class="isIn ? 'border-info/50 bg-info-soft text-info' : 'border-warning/50 bg-warning-soft text-warning'" role="status">
            <AppIcon :name="isIn ? 'arrow-down-to-line' : 'arrow-up-from-line'" :size="field ? 52 : 40" />
            <div class="min-w-0">
                <p class="text-sm font-bold uppercase tracking-widest">{{ t(isIn ? 'rfid.scan.banner_in' : 'rfid.scan.banner_out') }}</p>
                <p class="truncate font-bold" :class="field ? 'text-3xl' : 'text-2xl'">{{ stageKey ? stageLabel(stageKey) : '—' }}</p>
                <p v-if="reader" class="truncate text-sm opacity-90">{{ reader.name }} · {{ reader.code }}</p>
            </div>
        </div>

        <!-- Dampak mode otorisasi -->
        <div class="flex items-start gap-2 rounded-lg border p-3 text-sm" :class="isAuto ? 'border-warning/40 bg-warning-soft text-warning' : 'border-border bg-card text-foreground'">
            <AppIcon :name="isAuto ? 'triangle-alert' : 'info'" :size="18" class="mt-0.5" />
            <div>
                <p class="font-semibold">{{ modeText }}</p>
                <p v-if="isAuto" class="mt-0.5">{{ t('rfid.scan.auto_warning') }}</p>
            </div>
        </div>

        <!-- Stasiun -->
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="space-y-1.5">
                <label for="station" class="block text-sm font-medium">{{ t('rfid.scan.station') }}</label>
                <select id="station" v-model="stationKey" class="min-h-[48px] w-full rounded-lg border border-input bg-background px-3 text-base">
                    <option v-for="r in validReaders" :key="r.id" :value="`r:${r.id}`">{{ r.name }}{{ r.stage ? ' · ' + stageLabel(r.stage) : '' }}</option>
                    <option value="">{{ t('rfid.scan.station_none') }}</option>
                </select>
            </div>
            <div v-if="needsStage" class="space-y-1.5">
                <label for="stage" class="block text-sm font-medium">{{ t('rfid.scan.stage') }}</label>
                <select id="stage" v-model="manualStage" class="min-h-[48px] w-full rounded-lg border border-input bg-background px-3 text-base">
                    <option value="" disabled>—</option>
                    <option v-for="s in stages" :key="s.key" :value="s.key">{{ loc(s) }}</option>
                </select>
            </div>
        </div>

        <!-- Hasil terakhir (live region) -->
        <div aria-live="polite" class="min-h-[88px]">
            <ResultBanner v-if="last" :type="last.type" :title="last.title" :description="last.desc" :size="field ? 'xl' : 'md'">
                <p v-if="last.extra" class="mt-1 text-sm font-medium">{{ last.extra }}</p>
                <button v-if="last.code === 'tag_unknown' && canOperate" type="button"
                        class="mt-3 inline-flex min-h-[44px] items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90"
                        @click="emit('register-uid', last.uid)">
                    <AppIcon name="tag" :size="16" />{{ t('rfid.scan.register_this') }}
                </button>
            </ResultBanner>
            <div v-else class="flex items-center gap-3 rounded-xl border border-dashed border-border p-4 text-muted-foreground">
                <AppIcon name="nfc" :size="field ? 36 : 28" />
                <div><p class="font-semibold text-foreground">{{ t('rfid.scan.ready') }}</p><p class="text-sm">{{ t('rfid.scan.ready_hint') }}</p></div>
            </div>
        </div>

        <!-- Input manual / USB -->
        <form v-if="canOperate" class="space-y-3" @submit.prevent="submit">
            <div class="space-y-1.5">
                <label for="scan-uid" class="block text-sm font-medium">{{ t('rfid.scan.uid') }}</label>
                <div class="flex gap-2">
                    <input id="scan-uid" ref="inputRef" v-model="uid" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-describedby="scan-uid-hint"
                           class="min-h-[56px] min-w-0 flex-1 rounded-lg border border-input bg-background px-4 font-mono text-xl uppercase tracking-wider" />
                    <button type="submit" :disabled="submitting || uid.trim().length < 4"
                            class="inline-flex min-h-[56px] items-center gap-2 rounded-xl bg-primary px-6 text-base font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60">
                        <AppIcon v-if="submitting" name="loader" :size="20" spin /><AppIcon v-else name="scan-line" :size="20" />
                        {{ t('rfid.scan.record') }}
                    </button>
                </div>
                <p id="scan-uid-hint" class="text-sm text-muted-foreground">{{ t('rfid.scan.uid_hint') }}</p>
            </div>
            <label v-if="simulationEnabled" class="flex min-h-[44px] cursor-pointer items-start gap-2 text-sm">
                <input v-model="simulate" type="checkbox" class="mt-0.5 h-5 w-5 rounded border-input" />
                <span><span class="font-medium">{{ t('rfid.sim_toggle') }}</span><span class="block text-muted-foreground">{{ t('rfid.sim_toggle_hint') }}</span></span>
            </label>
        </form>
        <p v-else class="rounded-lg bg-muted p-3 text-sm text-muted-foreground">{{ t('rfid.readonly_note') }}</p>

        <!-- Pembacaan ditolak -->
        <div v-if="rejects.length" class="space-y-2">
            <h3 class="text-sm font-semibold text-danger">{{ t('rfid.scan.rejected') }}</h3>
            <ul class="space-y-2">
                <li v-for="(r, i) in rejects" :key="i" class="flex items-center gap-3 rounded-lg border border-danger/30 bg-danger-soft p-3 text-sm text-danger">
                    <AppIcon name="circle-x" :size="18" />
                    <span class="min-w-0 flex-1"><span class="font-mono font-semibold">{{ groupUid(r.uid) }}</span> — {{ t(`rfid.err.${r.code}`) }}</span>
                    <button v-if="r.code === 'tag_unknown' && canOperate" type="button" class="min-h-[44px] rounded-lg bg-card px-3 font-semibold text-foreground hover:bg-accent" @click="emit('register-uid', r.uid)">
                        {{ t('rfid.scan.register_this') }}
                    </button>
                </li>
            </ul>
        </div>

        <!-- Pemindaian terbaru -->
        <div class="space-y-2">
            <h3 class="text-sm font-semibold text-foreground">{{ t('rfid.scan.recent') }} <span class="font-normal text-muted-foreground">· {{ t('rfid.scan.live') }}</span></h3>
            <ul v-if="feed.length" class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                <li v-for="f in feed" :key="f.id" class="flex items-center gap-3 p-3" :class="f.is_voided ? 'opacity-60' : ''">
                    <AppIcon :name="f.is_voided ? 'ban' : 'circle-check'" :size="20" :class="f.is_voided ? 'text-muted-foreground' : 'text-success'" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-mono text-sm font-semibold" :class="f.is_voided ? 'line-through' : ''">{{ f.batch_code }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatTime(f.recorded_at, lang) }} · {{ groupUid(f.uid) }}
                            <template v-if="SRC[f.origin]"> · {{ t(SRC[f.origin]) }}</template>
                            <template v-if="f.is_voided"> · {{ t('rfid.scan.voided') }}</template>
                        </p>
                    </div>
                    <button v-if="canOperate && !f.is_voided" type="button" class="min-h-[44px] rounded-lg px-3 text-sm font-medium text-danger hover:bg-danger-soft" @click="askVoid(f)">
                        {{ t('rfid.scan.void') }}
                    </button>
                </li>
            </ul>
            <p v-else class="rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground">{{ t('rfid.scan.recent_empty') }}</p>
        </div>

        <VoidScanDialog v-model:open="voidOpen" :scan="voidTarget" @voided="onVoided" />
    </section>
</template>
