<script setup>
/**
 * ScanHistory — riwayat pemindaian: filter (sumber, tahap, reader, arah, dibatalkan), tabel responsif
 * (kartu di ponsel), kursor "muat lebih banyak", dan pembaruan otomatis (polling sadar-visibilitas).
 * Status foto CCTV dijelaskan dengan bahasa awam: kegagalan kamera tidak berarti pemindaian gagal.
 */
import { computed, onMounted, ref, watch } from 'vue'
import AppIcon from '@/components/app/AppIcon.vue'
import DataTable from '@/components/app/DataTable.vue'
import VoidScanDialog from '@/components/rfid/VoidScanDialog.vue'
import { rfidApi } from '@/composables/rfid/useRfidApi.js'
import { usePolling } from '@/composables/core/usePolling.js'
import { useI18n } from '@/composables/core/useI18n.js'
import { formatDateTime, groupUid } from '@/lib/format'

const props = defineProps({
    readers:    { type: Array, default: () => [] },
    stages:     { type: Array, default: () => [] },
    canOperate: { type: Boolean, default: false },
})

const { t, lang, loc } = useI18n()

const filters = ref({ origin: '', stage: '', reader_id: '', direction: '', voided: false })
const rows = ref([])
const loading = ref(false)
const error = ref(null)
const nextBefore = ref(null)
const auto = ref(true)

function params(extra = {}) {
    const f = filters.value
    return {
        limit: 30, ...extra,
        origin: f.origin || undefined, stage: f.stage || undefined, reader_id: f.reader_id || undefined,
        direction: f.direction || undefined, voided: f.voided ? undefined : 0,
    }
}

async function load() {
    loading.value = true
    error.value = null
    const res = await rfidApi.scans(params())
    loading.value = false
    if (!res.ok) { error.value = t('rfid.err.network_error'); return false }
    rows.value = res.data
    nextBefore.value = res.raw?.next_before_id ?? null
    return true
}

async function more() {
    if (!nextBefore.value) return
    loading.value = true
    const res = await rfidApi.scans(params({ before_id: nextBefore.value }))
    loading.value = false
    if (res.ok) { rows.value = [...rows.value, ...res.data]; nextBefore.value = res.raw?.next_before_id ?? null }
}

const poll = usePolling(async () => {
    if (!auto.value || !rows.value.length || filters.value.voided) return true
    const res = await rfidApi.scans(params({ since_id: rows.value[0].id }))
    if (!res.ok) return false
    const fresh = res.data.filter(r => !rows.value.some(x => x.id === r.id))
    if (fresh.length) rows.value = [...fresh, ...rows.value]
    return true
}, { interval: 5000, immediate: false })

watch(filters, load, { deep: true })
onMounted(async () => { await load(); poll.start() })

const columns = computed(() => [
    { key: 'recorded_at', label: t('rfid.hist.time') },
    { key: 'batch_code', label: t('rfid.hist.batch'), primary: true },
    { key: 'uid', label: t('rfid.hist.uid'), hideOnMobile: false },
    { key: 'direction', label: t('rfid.hist.dir') },
    { key: 'stage', label: t('rfid.hist.stage') },
    { key: 'origin', label: t('rfid.hist.source') },
    { key: 'reader', label: t('rfid.hist.reader'), hideOnMobile: true },
    { key: 'snapshot_status', label: t('rfid.hist.cctv') },
])

const stageName = key => { const s = props.stages.find(x => x.key === key); return s ? loc(s) : (key ?? '—') }
const DIR = { in: 'rfid.hist.dir_in', out: 'rfid.hist.dir_out' }
const ORIGIN = { real_esp32: 'rfid.hist.src_real', real_uhf: 'rfid.hist.src_real', api: 'rfid.hist.src_real', simulation: 'rfid.hist.src_sim', manual: 'rfid.hist.src_manual' }
const CCTV = {
    pending:  { key: 'rfid.hist.cctv_pending',  icon: 'clock',      cls: 'text-info' },
    captured: { key: 'rfid.hist.cctv_captured', icon: 'camera',     cls: 'text-success' },
    failed:   { key: 'rfid.hist.cctv_failed',   icon: 'camera-off', cls: 'text-warning' },
}

const voidTarget = ref(null)
const voidOpen = ref(false)
const askVoid = row => { voidTarget.value = row; voidOpen.value = true }
const onVoided = updated => { rows.value = rows.value.map(r => r.id === updated.id ? { ...r, ...updated } : r) }
const rowClass = row => (row.is_voided ? 'opacity-60' : '')
</script>

<template>
    <section :aria-label="t('rfid.view.history')" class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="space-y-1">
                <label for="f-origin" class="block text-sm font-medium">{{ t('rfid.hist.filter_origin') }}</label>
                <select id="f-origin" v-model="filters.origin" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3">
                    <option value="">{{ t('rfid.hist.filter_all') }}</option>
                    <option value="real">{{ t('rfid.hist.src_real') }}</option>
                    <option value="manual">{{ t('rfid.hist.src_manual') }}</option>
                    <option value="simulation">{{ t('rfid.hist.src_sim') }}</option>
                </select>
            </div>
            <div class="space-y-1">
                <label for="f-stage" class="block text-sm font-medium">{{ t('rfid.hist.filter_stage') }}</label>
                <select id="f-stage" v-model="filters.stage" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3">
                    <option value="">{{ t('rfid.hist.filter_all') }}</option>
                    <option v-for="s in stages" :key="s.key" :value="s.key">{{ loc(s) }}</option>
                </select>
            </div>
            <div class="space-y-1">
                <label for="f-reader" class="block text-sm font-medium">{{ t('rfid.hist.filter_reader') }}</label>
                <select id="f-reader" v-model="filters.reader_id" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3">
                    <option value="">{{ t('rfid.hist.filter_all') }}</option>
                    <option v-for="r in readers" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
            </div>
            <div class="space-y-1">
                <label for="f-dir" class="block text-sm font-medium">{{ t('rfid.hist.dir') }}</label>
                <select id="f-dir" v-model="filters.direction" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3">
                    <option value="">{{ t('rfid.hist.filter_all') }}</option>
                    <option value="in">{{ t('rfid.hist.dir_in') }}</option>
                    <option value="out">{{ t('rfid.hist.dir_out') }}</option>
                </select>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-6 gap-y-1">
            <label class="flex min-h-[44px] cursor-pointer items-center gap-2 text-sm"><input v-model="filters.voided" type="checkbox" class="h-5 w-5 rounded border-input" />{{ t('rfid.hist.filter_voided') }}</label>
            <label class="flex min-h-[44px] cursor-pointer items-center gap-2 text-sm"><input v-model="auto" type="checkbox" class="h-5 w-5 rounded border-input" />{{ t('rfid.hist.auto_refresh') }}</label>
        </div>

        <DataTable :columns="columns" :rows="rows" :loading="loading" :error="error" :caption="t('rfid.hist.caption')"
                   :empty-title="t('rfid.hist.empty')" :empty-text="t('rfid.hist.empty_hint')" empty-icon="history" :row-class="rowClass" @retry="load">
            <template #cell-recorded_at="{ value }"><span class="whitespace-nowrap tabular-nums">{{ formatDateTime(value, lang) }}</span></template>
            <template #cell-batch_code="{ row }">
                <a :href="`/company/batches/${row.batch_id}`" class="font-mono text-sm font-semibold text-primary underline-offset-2 hover:underline" :class="row.is_voided ? 'line-through' : ''">{{ row.batch_code }}</a>
                <span v-if="row.is_voided" class="ml-2 rounded bg-neutral-soft px-1.5 py-0.5 text-xs font-medium text-neutral" :title="row.void_reason">{{ t('rfid.scan.voided') }}</span>
            </template>
            <template #cell-uid="{ value }"><span class="font-mono text-xs">{{ groupUid(value) }}</span></template>
            <template #cell-direction="{ row }">
                <span v-if="row.direction" class="inline-flex items-center gap-1 text-sm font-medium" :class="row.direction === 'in' ? 'text-info' : 'text-warning'">
                    <AppIcon :name="row.direction === 'in' ? 'arrow-down-to-line' : 'arrow-up-from-line'" :size="16" />{{ t(DIR[row.direction]) }}
                </span>
                <span v-else class="text-sm text-muted-foreground">{{ t('rfid.hist.dir_reg') }}</span>
            </template>
            <template #cell-stage="{ value }">{{ stageName(value) }}</template>
            <template #cell-origin="{ value }">
                <span class="rounded-md px-2 py-0.5 text-xs font-semibold" :class="value === 'simulation' ? 'bg-warning-soft text-warning' : 'bg-neutral-soft text-neutral'">{{ t(ORIGIN[value] ?? 'rfid.hist.src_real') }}</span>
            </template>
            <template #cell-reader="{ row }">{{ row.reader?.name ?? '—' }}</template>
            <template #cell-snapshot_status="{ value }">
                <span v-if="CCTV[value]" class="inline-flex items-center gap-1 text-sm font-medium" :class="CCTV[value].cls" :title="value === 'failed' ? t('rfid.hist.cctv_failed_hint') : undefined">
                    <AppIcon :name="CCTV[value].icon" :size="16" />{{ t(CCTV[value].key) }}
                </span>
                <span v-else class="text-sm text-muted-foreground">{{ t('rfid.hist.cctv_none') }}</span>
            </template>
            <template v-if="canOperate" #actions="{ row }">
                <button v-if="!row.is_voided && !row.is_registration" type="button" class="min-h-[44px] rounded-lg px-3 text-sm font-medium text-danger hover:bg-danger-soft" @click="askVoid(row)">{{ t('rfid.scan.void') }}</button>
            </template>
        </DataTable>

        <div v-if="nextBefore" class="text-center">
            <button type="button" :disabled="loading" class="min-h-[44px] rounded-lg border border-input px-5 text-sm font-semibold hover:bg-accent disabled:opacity-60" @click="more">{{ t('rfid.hist.load_more') }}</button>
        </div>

        <VoidScanDialog v-model:open="voidOpen" :scan="voidTarget" @voided="onVoided" />
    </section>
</template>
