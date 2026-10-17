<script setup>
/**
 * Manajemen Batch — daftar batch (paginasi, pencarian & filter di SERVER), dwibahasa.
 * Tabel menjadi kartu di ponsel (DataTable). Status memakai ikon + teks + warna.
 */
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { useDebounceFn } from '@vueuse/core'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/components/app/PageHeader.vue'
import StatCard from '@/components/app/StatCard.vue'
import DataTable from '@/components/app/DataTable.vue'
import AppIcon from '@/components/app/AppIcon.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { useI18n } from '@/composables/core/useI18n.js'
import { formatDateTime, formatWeightKg } from '@/lib/format'

const props = defineProps({
    batches:      Object,
    stats:        Object,
    filters:      Object,
    productCodes: Array,
})

const { t, lang, loc } = useI18n()
const page = usePage()
const can = computed(() => page.props.can ?? {})

// ─── Filter (server-side) ─────────────────────────────────────────────────────
const search     = ref(props.filters?.search ?? '')
const status     = ref(props.filters?.status ?? 'all')
const stage      = ref(props.filters?.stage ?? 'all')
const alertsOnly = ref(!!props.filters?.alerts_only)

function applyFilters() {
    router.get('/company/batches', {
        search:      search.value || undefined,
        status:      status.value !== 'all' ? status.value : undefined,
        stage:       stage.value !== 'all' ? stage.value : undefined,
        alerts_only: alertsOnly.value || undefined,
    }, { preserveState: true, replace: true })
}
const onSearch = useDebounceFn(applyFilters, 400)
function toggleAlerts() { alertsOnly.value = !alertsOnly.value; applyFilters() }

const STATUSES = ['active', 'in_transit', 'in_process', 'in_warehouse', 'completed', 'split', 'exported']
const stages = computed(() => page.props.stages ?? [])
const stageName = key => { const s = stages.value.find(x => x.key === key); return s ? loc(s) : t(`nav.${key}`, {}, key) }
const stageIcon = key => stages.value.find(x => x.key === key)?.icon ?? 'package'

const columns = computed(() => [
    { key: 'batch_code', label: t('batchlist.col_code'), primary: true },
    { key: 'product', label: t('batchlist.col_product') },
    { key: 'process_stage', label: t('batchlist.col_stage') },
    { key: 'status', label: t('batchlist.col_status') },
    { key: 'current_weight', label: t('batchlist.col_weight'), align: 'right' },
    { key: 'location', label: t('batchlist.col_location'), hideOnMobile: true },
    { key: 'created_at_iso', label: t('batchlist.col_created'), hideOnMobile: true },
])

const rows = computed(() => props.batches?.data ?? [])
const rowClass = row => (row.has_discrepancy ? 'bg-danger-soft/60' : '')
const links = computed(() => (props.batches?.links ?? []).filter(l => !/previous|next|&laquo;|&raquo;/i.test(l.label)))
const prevUrl = computed(() => props.batches?.prev_page_url)
const nextUrl = computed(() => props.batches?.next_page_url)
</script>

<template>
    <Head :title="t('batchlist.title')" />

    <AppLayout>
        <template #header>{{ t('batchlist.title') }}</template>

        <PageHeader :title="t('batchlist.title')" :description="t('batchlist.subtitle')" icon="boxes">
            <template v-if="can.create_batch" #actions>
                <Link href="/company/batches/create" class="inline-flex min-h-[44px] items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90">
                    <AppIcon name="plus" :size="18" />{{ t('batchlist.create') }}
                </Link>
            </template>
        </PageHeader>

        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
            <StatCard :label="t('batchlist.st_total')" :value="stats?.total ?? 0" :hint="t('batchlist.st_total_hint')" icon="boxes" />
            <StatCard :label="t('batchlist.st_active')" :value="stats?.active ?? 0" :hint="t('batchlist.st_active_hint')" icon="activity" />
            <StatCard :label="t('batchlist.st_warehouse')" :value="stats?.in_warehouse ?? 0" :hint="t('batchlist.st_warehouse_hint')" icon="warehouse" />
            <StatCard :label="t('batchlist.st_alert')" :value="stats?.has_alert ?? 0" :hint="t('batchlist.st_alert_hint')" icon="triangle-alert" :tone="(stats?.has_alert ?? 0) > 0 ? 'danger' : 'neutral'" />
            <StatCard :label="t('batchlist.st_tonnage')" :value="formatWeightKg(stats?.total_weight_kg ?? 0, lang)" :hint="t('batchlist.st_tonnage_hint')" icon="scale" />
        </div>

        <!-- Filter -->
        <form class="mb-4 flex flex-wrap items-center gap-3" role="search" @submit.prevent="applyFilters">
            <div class="relative min-w-[220px] max-w-sm flex-1">
                <AppIcon name="search" :size="18" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <input v-model="search" type="search" :aria-label="t('batchlist.search_label')" :placeholder="t('batchlist.search')" autocomplete="off"
                       class="min-h-[44px] w-full rounded-lg border border-input bg-background pl-10 pr-3" @input="onSearch" />
            </div>
            <select v-model="status" :aria-label="t('a11y.status_filter')" class="min-h-[44px] rounded-lg border border-input bg-background px-3" @change="applyFilters">
                <option value="all">{{ t('batchlist.all_status') }}</option>
                <option v-for="s in STATUSES" :key="s" :value="s">{{ t(`batchlist.status_${s}`) }}</option>
            </select>
            <select v-model="stage" :aria-label="t('a11y.stage_filter')" class="min-h-[44px] rounded-lg border border-input bg-background px-3" @change="applyFilters">
                <option value="all">{{ t('batchlist.all_stage') }}</option>
                <option v-for="s in stages" :key="s.key" :value="s.key">{{ loc(s) }}</option>
            </select>
            <button type="button" :aria-pressed="alertsOnly" class="inline-flex min-h-[44px] items-center gap-2 rounded-lg border px-3 text-sm font-semibold"
                    :class="alertsOnly ? 'border-danger/40 bg-danger-soft text-danger' : 'border-input hover:bg-accent'" @click="toggleAlerts">
                <AppIcon name="triangle-alert" :size="16" />{{ t('batchlist.alerts') }}
            </button>
        </form>

        <DataTable :columns="columns" :rows="rows" :caption="t('batchlist.caption')" empty-icon="boxes"
                   :empty-title="t('batchlist.empty')" :empty-text="search ? t('batchlist.empty_search', { q: search }) : t('batchlist.empty_hint')" :row-class="rowClass">
            <template #cell-batch_code="{ row }">
                <div class="flex items-center gap-2">
                    <AppIcon v-if="row.has_discrepancy" name="triangle-alert" :size="16" class="flex-shrink-0 text-danger" :label="t('batchlist.discrepancy')" />
                    <div class="min-w-0">
                        <Link :href="`/company/batches/${row.id}`" class="block truncate font-mono text-sm font-semibold text-primary underline-offset-2 hover:underline">{{ row.batch_code }}</Link>
                        <span v-if="row.lot_number" class="block font-mono text-xs text-muted-foreground">{{ row.lot_number }}</span>
                    </div>
                </div>
            </template>
            <template #cell-product="{ row }">
                <div class="flex items-center gap-2">
                    <AppIcon v-if="row.product?.is_hazardous" name="radiation" :size="16" class="flex-shrink-0 text-warning" :label="t('batchlist.hazardous')" />
                    <div class="min-w-0">
                        <p class="font-semibold">{{ row.product?.code }}</p>
                        <p class="max-w-[160px] truncate text-xs text-muted-foreground">{{ row.product?.name }}</p>
                        <span v-if="row.product?.is_export_blocked" class="mt-0.5 inline-flex items-center gap-1 rounded bg-danger-soft px-1.5 py-0.5 text-xs font-semibold text-danger"><AppIcon name="ban" :size="12" />{{ t('batchlist.export_blocked') }}</span>
                    </div>
                </div>
            </template>
            <template #cell-process_stage="{ value }">
                <span class="inline-flex items-center gap-1.5 text-sm"><AppIcon :name="stageIcon(value)" :size="16" class="text-muted-foreground" />{{ stageName(value) }}</span>
            </template>
            <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
            <template #cell-current_weight="{ row }">
                <p class="font-semibold tabular-nums">{{ formatWeightKg(row.current_weight, lang) }}</p>
                <p v-if="row.mass_loss_pct !== null" class="text-xs" :class="row.has_discrepancy ? 'font-semibold text-danger' : 'text-muted-foreground'">-{{ row.mass_loss_pct }}%</p>
            </template>
            <template #cell-location="{ row }">
                <template v-if="row.location"><p class="text-sm font-medium">{{ row.location.code }}</p><p class="text-xs text-muted-foreground">{{ row.location.name }}</p></template>
                <span v-else class="text-muted-foreground">—</span>
            </template>
            <template #cell-created_at_iso="{ value }"><span class="text-sm text-muted-foreground">{{ formatDateTime(value, lang) }}</span></template>
            <template #actions="{ row }">
                <Link :href="`/company/batches/${row.id}`" class="inline-flex min-h-[44px] items-center gap-1 rounded-lg px-3 text-sm font-semibold text-primary hover:bg-accent">
                    {{ t('batchlist.detail') }}<AppIcon name="chevron-right" :size="16" />
                </Link>
            </template>
        </DataTable>

        <nav v-if="batches?.total > batches?.per_page" class="mt-4 flex flex-wrap items-center justify-between gap-3" :aria-label="t('batchlist.pagination')">
            <p class="text-sm text-muted-foreground">{{ t('batchlist.showing', { from: batches.from, to: batches.to, total: batches.total }) }}</p>
            <div class="flex items-center gap-1">
                <component :is="prevUrl ? Link : 'span'" :href="prevUrl || undefined" :aria-label="t('batchlist.prev')" preserve-scroll
                           class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-input" :class="prevUrl ? 'hover:bg-accent' : 'opacity-40'">
                    <AppIcon name="chevron-left" :size="18" />
                </component>
                <template v-for="l in links" :key="l.label">
                    <Link v-if="l.url" :href="l.url" preserve-scroll :aria-label="t('batchlist.page', { n: l.label })" :aria-current="l.active ? 'page' : undefined"
                          class="inline-flex h-11 min-w-[44px] items-center justify-center rounded-lg px-2 text-sm font-semibold"
                          :class="l.active ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'">{{ l.label }}</Link>
                    <span v-else class="inline-flex h-11 min-w-[44px] items-center justify-center text-muted-foreground">…</span>
                </template>
                <component :is="nextUrl ? Link : 'span'" :href="nextUrl || undefined" :aria-label="t('batchlist.next')" preserve-scroll
                           class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-input" :class="nextUrl ? 'hover:bg-accent' : 'opacity-40'">
                    <AppIcon name="chevron-right" :size="18" />
                </component>
            </div>
        </nav>
    </AppLayout>
</template>
