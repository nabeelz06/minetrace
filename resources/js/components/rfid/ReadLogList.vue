<script setup>
/**
 * ReadLogList — log SETIAP pembacaan RFID (rfid_read_logs): diterima, ditolak (mis. tag asing di gate),
 * dan duplikat, beserta foto CCTV yang diambil untuk tiap log. Kursor + pembaruan otomatis seperti ScanHistory.
 */
import { computed, onMounted, ref, watch } from 'vue'
import AppIcon from '@/components/app/AppIcon.vue'
import DataTable from '@/components/app/DataTable.vue'
import { rfidApi } from '@/composables/rfid/useRfidApi.js'
import { usePolling } from '@/composables/core/usePolling.js'
import { useI18n } from '@/composables/core/useI18n.js'
import { formatDateTime, groupUid } from '@/lib/format'

const props = defineProps({
    readers: { type: Array, default: () => [] },
    stages:  { type: Array, default: () => [] },
})

const { t, lang, loc } = useI18n()

const filters = ref({ result: '', action: '', reader_id: '', epc: '' })
const rows = ref([])
const loading = ref(false)
const error = ref(null)
const nextBefore = ref(null)
const auto = ref(true)
const preview = ref(null)

function params(extra = {}) {
    const f = filters.value
    return {
        limit: 30, ...extra,
        result: f.result || undefined, action: f.action || undefined,
        reader_id: f.reader_id || undefined, epc: f.epc.trim() || undefined,
    }
}

async function load() {
    loading.value = true
    error.value = null
    const res = await rfidApi.readLogs(params())
    loading.value = false
    if (!res.ok) { error.value = t('rfid.err.network_error'); return false }
    rows.value = res.data
    nextBefore.value = res.raw?.next_before_id ?? null
    return true
}

async function more() {
    if (!nextBefore.value) return
    loading.value = true
    const res = await rfidApi.readLogs(params({ before_id: nextBefore.value }))
    loading.value = false
    if (res.ok) { rows.value = [...rows.value, ...res.data]; nextBefore.value = res.raw?.next_before_id ?? null }
}

// Pembaruan: log baru + status foto yang masih "diproses" pada baris yang sudah tampil.
const poll = usePolling(async () => {
    if (!auto.value) return true
    const res = await rfidApi.readLogs(params({ limit: 50 }))
    if (!res.ok) return false
    const byId = new Map(res.data.map(r => [r.id, r]))
    const known = new Set(rows.value.map(r => r.id))
    rows.value = [...res.data.filter(r => !known.has(r.id)), ...rows.value.map(r => byId.get(r.id) ?? r)]
    return true
}, { interval: 5000, immediate: false })

let typing = null
watch(filters, () => { clearTimeout(typing); typing = setTimeout(load, 300) }, { deep: true })
onMounted(async () => { await load(); poll.start() })

const columns = computed(() => [
    { key: 'read_at', label: t('rfid.hist.time') },
    { key: 'epc', label: t('rfid.logs.epc'), primary: true },
    { key: 'result', label: t('rfid.logs.result') },
    { key: 'action', label: t('rfid.hist.dir') },
    { key: 'batch_code', label: t('rfid.hist.batch') },
    { key: 'reader', label: t('rfid.hist.reader'), hideOnMobile: true },
    { key: 'signal', label: t('rfid.logs.signal'), hideOnMobile: true },
    { key: 'snapshot_status', label: t('rfid.hist.cctv') },
])

const stageName = key => { const s = props.stages.find(x => x.key === key); return s ? loc(s) : (key ?? '') }
const RESULT = {
    accepted:  { key: 'rfid.logs.accepted',  cls: 'bg-success-soft text-success' },
    rejected:  { key: 'rfid.logs.rejected',  cls: 'bg-danger-soft text-danger' },
    duplicate: { key: 'rfid.logs.duplicate', cls: 'bg-neutral-soft text-neutral' },
}
const ACTION = {
    in:       { key: 'rfid.hist.dir_in',  icon: 'arrow-down-to-line', cls: 'text-info' },
    out:      { key: 'rfid.hist.dir_out', icon: 'arrow-up-from-line', cls: 'text-warning' },
    register: { key: 'rfid.hist.dir_reg', icon: 'tag',                cls: 'text-primary' },
}
const CCTV = {
    pending:  { key: 'rfid.hist.cctv_pending',  icon: 'clock',      cls: 'text-info' },
    captured: { key: 'rfid.hist.cctv_captured', icon: 'camera',     cls: 'text-success' },
    failed:   { key: 'rfid.hist.cctv_failed',   icon: 'camera-off', cls: 'text-warning' },
}
const rejectText = row => {
    const key = `rfid.err.${row.result_code}`
    const text = t(key)
    return text !== key ? text : (row.message || row.result_code)
}
</script>

<template>
    <section :aria-label="t('rfid.view.logs')" class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="space-y-1">
                <label for="l-result" class="block text-sm font-medium">{{ t('rfid.logs.result') }}</label>
                <select id="l-result" v-model="filters.result" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3">
                    <option value="">{{ t('rfid.hist.filter_all') }}</option>
                    <option value="accepted">{{ t('rfid.logs.accepted') }}</option>
                    <option value="rejected">{{ t('rfid.logs.rejected') }}</option>
                    <option value="duplicate">{{ t('rfid.logs.duplicate') }}</option>
                </select>
            </div>
            <div class="space-y-1">
                <label for="l-action" class="block text-sm font-medium">{{ t('rfid.hist.dir') }}</label>
                <select id="l-action" v-model="filters.action" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3">
                    <option value="">{{ t('rfid.hist.filter_all') }}</option>
                    <option value="register">{{ t('rfid.hist.dir_reg') }}</option>
                    <option value="in">{{ t('rfid.hist.dir_in') }}</option>
                    <option value="out">{{ t('rfid.hist.dir_out') }}</option>
                </select>
            </div>
            <div class="space-y-1">
                <label for="l-reader" class="block text-sm font-medium">{{ t('rfid.hist.filter_reader') }}</label>
                <select id="l-reader" v-model="filters.reader_id" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3">
                    <option value="">{{ t('rfid.hist.filter_all') }}</option>
                    <option v-for="r in readers" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
            </div>
            <div class="space-y-1">
                <label for="l-epc" class="block text-sm font-medium">{{ t('rfid.logs.epc') }}</label>
                <input id="l-epc" v-model="filters.epc" autocomplete="off" spellcheck="false" :placeholder="t('rfid.logs.epc_search')"
                       class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3 font-mono uppercase" />
            </div>
        </div>

        <label class="flex min-h-[44px] w-fit cursor-pointer items-center gap-2 text-sm"><input v-model="auto" type="checkbox" class="h-5 w-5 rounded border-input" />{{ t('rfid.hist.auto_refresh') }}</label>

        <DataTable :columns="columns" :rows="rows" :loading="loading" :error="error" :caption="t('rfid.logs.caption')"
                   :empty-title="t('rfid.logs.empty')" :empty-text="t('rfid.logs.empty_hint')" empty-icon="clipboard-list" @retry="load">
            <template #cell-read_at="{ value }"><span class="whitespace-nowrap tabular-nums">{{ formatDateTime(value, lang) }}</span></template>
            <template #cell-epc="{ value }"><span class="font-mono text-xs font-semibold">{{ groupUid(value) }}</span></template>
            <template #cell-result="{ row }">
                <span class="rounded-md px-2 py-0.5 text-xs font-semibold" :class="RESULT[row.result]?.cls">{{ t(RESULT[row.result]?.key ?? 'rfid.logs.rejected') }}</span>
                <p v-if="row.result === 'rejected'" class="mt-1 max-w-[16rem] text-xs text-muted-foreground">{{ rejectText(row) }}</p>
            </template>
            <template #cell-action="{ row }">
                <span class="inline-flex items-center gap-1 text-sm font-medium" :class="ACTION[row.action]?.cls">
                    <AppIcon :name="ACTION[row.action]?.icon ?? 'tag'" :size="16" />{{ t(ACTION[row.action]?.key ?? 'rfid.hist.dir_reg') }}
                </span>
                <p v-if="row.stage" class="text-xs text-muted-foreground">{{ stageName(row.stage) }}</p>
            </template>
            <template #cell-batch_code="{ row }">
                <a v-if="row.batch_id" :href="`/company/batches/${row.batch_id}`" class="font-mono text-sm font-semibold text-primary underline-offset-2 hover:underline">{{ row.batch_code }}</a>
                <span v-else class="text-sm text-muted-foreground">—</span>
            </template>
            <template #cell-reader="{ row }">{{ row.reader?.name ?? '—' }}</template>
            <template #cell-signal="{ row }">
                <span class="text-xs tabular-nums text-muted-foreground">
                    {{ row.rssi != null ? `${row.rssi} dBm` : '—' }}<template v-if="row.antenna != null"> · {{ t('rfid.logs.antenna', { n: row.antenna }) }}</template>
                </span>
            </template>
            <template #cell-snapshot_status="{ row }">
                <button v-if="row.snapshot_url" type="button" class="group block overflow-hidden rounded-md border border-border" :aria-label="t('rfid.logs.view_photo')" @click="preview = row">
                    <img :src="row.snapshot_url" :alt="t('rfid.logs.photo_alt', { epc: row.epc })" loading="lazy" class="h-12 w-20 object-cover transition group-hover:opacity-80" />
                </button>
                <span v-else-if="CCTV[row.snapshot_status]" class="inline-flex items-center gap-1 text-sm font-medium" :class="CCTV[row.snapshot_status].cls"
                      :title="row.snapshot_status === 'failed' ? t('rfid.hist.cctv_failed_hint') : undefined">
                    <AppIcon :name="CCTV[row.snapshot_status].icon" :size="16" />{{ t(CCTV[row.snapshot_status].key) }}
                </span>
                <span v-else class="text-sm text-muted-foreground">{{ t('rfid.hist.cctv_none') }}</span>
            </template>
        </DataTable>

        <div v-if="nextBefore" class="text-center">
            <button type="button" :disabled="loading" class="min-h-[44px] rounded-lg border border-input px-5 text-sm font-semibold hover:bg-accent disabled:opacity-60" @click="more">{{ t('rfid.hist.load_more') }}</button>
        </div>

        <!-- Pratinjau foto -->
        <div v-if="preview" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" role="dialog" aria-modal="true" @click.self="preview = null" @keydown.esc="preview = null">
            <figure class="max-h-full w-full max-w-3xl overflow-hidden rounded-xl bg-card">
                <img :src="preview.snapshot_url" :alt="t('rfid.logs.photo_alt', { epc: preview.epc })" class="max-h-[75vh] w-full object-contain" />
                <figcaption class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
                    <span class="font-mono">{{ groupUid(preview.epc) }} · {{ formatDateTime(preview.read_at, lang) }}</span>
                    <button type="button" class="min-h-[44px] rounded-lg border border-input px-4 font-semibold hover:bg-accent" @click="preview = null">{{ t('app.close') }}</button>
                </figcaption>
            </figure>
        </div>
    </section>
</template>
